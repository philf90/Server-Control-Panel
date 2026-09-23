<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Packer;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use ZipArchive;

/**
 * Eine Sicherung prüfen — und **nichts** anlegen.
 *
 * Entscheidung 1 des Betreibers (`docs/117 §2`): Der Prüflauf prüft Archiv und
 * Verzeichnis auf Vollständigkeit und Lesbarkeit und spielt nichts zurück.
 * {@see self::mutating()} gibt deshalb `false`, und diese Klasse schreibt keine
 * Datei, legt kein Verzeichnis an und ruft kein Programm.
 *
 * ## Was sie prüft, und warum genau das
 *
 * **Den CRC jedes Eintrags gegen den, den das Archiv selbst führt.** Gemessen
 * am 16. September 2026 (`docs/117 §13` M8) gegen drei Arten von Schaden:
 *
 * | Schaden | `open()` | `CHECKCONS` | jeden Eintrag lesen | CRC |
 * |---|---|---|---|---|
 * | ein Byte in den Daten gekippt | ok | **ok** | **ok** | **findet ihn** |
 * | die letzten 4 KiB abgeschnitten | rc=19 | rc=19 | — | — |
 * | die ersten 100 Bytes genullt | ok | rc=19 | 1 unlesbar | 1 unlesbar |
 *
 * **Jeden Eintrag zu lesen findet ein gekipptes Byte nicht.** PHPs
 * `getStream()` gibt die entpackten Bytes zurück, ohne die Prüfsumme zu prüfen
 * — die teuerste der vier Prüfungen sieht ausgerechnet den Schaden nicht, vor
 * dem eine Sicherung schützen soll.
 *
 * > **Eine Prüfung, die teurer ist, ist deshalb nicht gründlicher — und welche
 * > Schäden sie findet, sagt erst der Prüfkörper, der sie herstellt.**
 *
 * ### Diese Zeile hängt an libzip, und seit dem 23. September 2026 ist das
 * ### gemessen
 *
 * Dieselbe Spalte, derselbe Prüfkörper, zwei Antworten:
 *
 * | Umgebung | `getFromIndex()` | `getStreamIndex()` |
 * |---|---|---|
 * | libzip 1.22.7, Container | 0 unlesbar | 0 unlesbar |
 * | GitHub-Läufer, 23. September 2026 | **1 unlesbar** | nicht gemessen |
 *
 * Gemessen an **demselben Commit**, der zwei Tage vorher grün durchlief
 * (`ci.yml` auf `main` @ `180ee276`: Lauf 1006 grün, Lauf 1008 rot). Geändert
 * hat sich die Fassung der Bibliothek und nicht dieses Repo. Welche Fassung
 * der Läufer führt, ist **nicht** gemessen — und dass die Tabelle oben mit
 * `getStream()` begründet, der Prüfkörper aber `getFromIndex()` gemessen hat,
 * ist der zweite Teil desselben Befundes: zwei Funktionen, eine Zeile.
 *
 * > **Eine Begründung, die eine Fremdbibliothek trägt, ist so haltbar wie
 * > deren Fassung — und sie sagt nicht selbst, wann sie abgelaufen ist.**
 *
 * **Was davon unberührt bleibt:** der Speicher. Die strömende Fassung braucht
 * ein Hundertfünfzigstel (unten gemessen), und das hängt an keiner Fassung von
 * libzip. Auch wenn Lesen den Schaden inzwischen fände, wäre es die teurere
 * der beiden Prüfungen und nicht die gründlichere.
 *
 * **Offen ist damit die erste Hälfte der Begründung**: ob der CRC-Vergleich auf
 * heutigem libzip noch einen Schaden findet, den Lesen übersieht. Die Antwort
 * braucht eine Messung auf dem Läufer — für beide Funktionen, denn gemessen ist
 * dort bisher nur `getFromIndex()`.
 * {@see BackupVerifyTest::test_reading_every_entry_finds_no_more_than_the_checksum()}
 * sichert deshalb nur noch zu, was in beiden Welten gilt: Der Prüfling findet
 * den Schaden, und das Lesen findet nie etwas, das der Prüfling übersieht.
 *
 * ## Und warum sie strömt
 *
 * `getFromIndex()` lädt einen Eintrag **ganz** in den Speicher. Gemessen an
 * einem Archiv mit einem Eintrag von 300 MiB, ein Fall je Prozess:
 *
 * | Art | Zeit | Spitze |
 * |---|---|---|
 * | strömend | 248–257 ms | **2 MiB** |
 * | ganz | 394–426 ms | **302 MiB** |
 *
 * Die strömende Fassung ist schneller **und** braucht ein Hundertfünfzigstel.
 * `srvpanel-agentd.service` trägt `MemoryMax=512M`; ein Kunde mit einer Datei
 * von 600 MB im Archiv hätte den Vorgang wortlos getötet — dieselbe Grenze, an
 * der schon {@see Packer::MAX_ENTRIES} hängt.
 *
 * > **Ein Vorgang, der wortlos stirbt, sieht aus wie ein hängender Agent.**
 *
 * ## Zwei Hälften, und die Naht dazwischen ist Absicht
 *
 * {@see self::execute()} ist die **Grenze**: Sie prüft die Argumente und baut
 * den Pfad aus zwei geprüften Hälften. {@see self::verify()} ist die
 * **Mechanik** und nimmt einen fertigen Pfad — damit ein Wächter ein echtes
 * Archiv bauen und beschädigen kann, statt `/var/lib/srvpanel/backups`
 * nachzustellen. Dieselbe Teilung wie `Packer::pack()` gegen
 * `Packer::packTree()`.
 *
 * ## Was sie nicht kann
 *
 * Sie sagt **nicht**, ob sich die Sicherung zurückspielen lässt. Ein Archiv,
 * dessen Bytes stimmen, kann eine Beschreibung tragen, die zu keinem Server
 * dieser Fassung mehr passt. Das zu beantworten hiesse zurückzuspielen, und
 * genau das hat der Betreiber ausgeschlossen.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.**
 */
final class BackupVerify implements Op
{
    /**
     * Wie gross ein Stück beim Strömen ist.
     *
     * 256 KiB — dieselbe Grösse, mit der die Messung gefahren wurde. Kleiner
     * kostet Systemaufrufe, grösser bringt nichts: Die Spitze lag bei 2 MiB und
     * damit weit unter allem, was hier bindet.
     */
    private const CHUNK = 262144;

    /**
     * Wie oft der Fortschritt gemeldet wird.
     *
     * Je Eintrag zu melden wäre bei 100 000 Einträgen 100 000 Schreibvorgänge
     * auf einen Kanal, den ein Mensch liest — dieselbe Zahl und derselbe Grund
     * wie im Packer.
     */
    private const REPORT_EVERY = 500;

    /** Der Befund: Das Archiv liegt nicht. */
    public const MISSING = 'missing';

    /** Es lässt sich nicht öffnen — abgeschnitten, kein Zip, Rechte. */
    public const UNREADABLE = 'unreadable';

    /** Das Verzeichnis fehlt, ist unlesbar oder aus einer neueren Fassung. */
    public const NO_MANIFEST = 'no_manifest';

    /** Ein Eintrag, den das Verzeichnis nennt, liegt nicht im Archiv. */
    public const ENTRY_MISSING = 'entry_missing';

    /** Ein Eintrag im Archiv, den das Verzeichnis nicht kennt. */
    public const ENTRY_UNEXPECTED = 'entry_unexpected';

    /** Die Bytes eines Eintrags stimmen nicht mit seiner Prüfsumme überein. */
    public const CORRUPT = 'corrupt';

    /**
     * Jeder Grund, den diese Operation ausspricht.
     *
     * `DiagnoseSeamTest` hält sie gegen den Katalog des Panels — in beide
     * Richtungen, damit ein umbenannter Grund nicht als toter Eintrag
     * liegenbleibt.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::MISSING, self::UNREADABLE, self::NO_MANIFEST,
        self::ENTRY_MISSING, self::ENTRY_UNEXPECTED, self::CORRUPT,
    ];

    public static function name(): string
    {
        return 'backup.verify';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{
     *     storage: string,
     *     healthy: bool,
     *     entries: int,
     *     bytes: int,
     *     findings: list<array{subject: string, reason: string, detail: null|string}>,
     * }
     */
    public function execute(array $args, Context $context): array
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $storage = Store::storageName(is_string($args['storage'] ?? null) ? $args['storage'] : '');

        // Der Pfad entsteht aus zwei geprüften Hälften und kommt nicht von
        // aussen — die erste Grenze (`docs/20 §4.1`).
        return self::verify(
            Store::path($subscription, $storage),
            $storage,
            static fn (int $percent, string $text) => $context->progress($percent, $text),
            static fn (): bool => $context->abandoned(),
        );
    }

    /**
     * Ein Archiv an einem fertigen Pfad prüfen.
     *
     * @return array{
     *     storage: string, healthy: bool, entries: int, bytes: int,
     *     findings: list<array{subject: string, reason: string, detail: null|string}>,
     * }
     */
    public static function verify(
        string $path,
        string $storage,
        ?callable $progress = null,
        ?callable $abort = null,
    ): array {
        $melden = static function (int $percent, string $text) use ($progress): void {
            if ($progress !== null) {
                $progress($percent, $text);
            }
        };

        $melden(5, 'Sicherung öffnen');

        if (! is_file($path)) {
            return self::verdict($storage, [self::finding(
                $storage,
                self::MISSING,
                'Die Zeile im Panel führt eine Sicherung, deren Datei nicht liegt.',
            )], 0, 0);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            return self::verdict($storage, [self::finding(
                $storage,
                self::UNREADABLE,
                sprintf('Das Archiv liess sich nicht öffnen (libzip-Code %d).', (int) $opened),
            )], 0, 0);
        }

        $bytes = @filesize($path);

        try {
            return self::inspect($zip, $storage, $bytes === false ? 0 : $bytes, $melden, $abort);
        } finally {
            $zip->close();
        }
    }

    /**
     * Das offene Archiv durchsehen.
     *
     * **Erst das Verzeichnis, dann die Bytes.** Ohne das Verzeichnis weiss
     * niemand, was drin sein *sollte* — und ein Lauf, der nur die Prüfsummen
     * vergleicht, meldet ein Archiv mit der Hälfte der Dateien als heil.
     *
     * > **Ein Archiv, das stillschweigend weniger enthält, ist schlimmer als
     * > keines: Es sieht aus wie eines.**
     *
     * @return array{
     *     storage: string, healthy: bool, entries: int, bytes: int,
     *     findings: list<array{subject: string, reason: string, detail: null|string}>,
     * }
     */
    private static function inspect(
        ZipArchive $zip,
        string $storage,
        int $bytes,
        callable $melden,
        ?callable $abort,
    ): array {
        $json = $zip->getFromName(Manifest::ENTRY);

        if (! is_string($json)) {
            return self::verdict($storage, [self::finding(
                $storage,
                self::NO_MANIFEST,
                'Das Archiv trägt kein Verzeichnis — was fehlt, lässt sich damit nicht sagen.',
            )], 0, $bytes);
        }

        try {
            $manifest = Manifest::decode($json);
        } catch (AgentException $e) {
            return self::verdict($storage, [self::finding(
                $storage,
                self::NO_MANIFEST,
                $e->getMessage(),
            )], 0, $bytes);
        }

        $melden(15, 'Verzeichnis gegen das Archiv halten');

        $findings = [];

        /*
         * **Nur Dateien.** Ein Verzeichnis und ein Verweis stehen im
         * Verzeichnis der Sicherung, aber nicht als Eintrag mit Inhalt im
         * Archiv — ein Zip trägt keine Verweise (`docs/116` M1b), und
         * `addEmptyDir()` legt keinen lesbaren Eintrag an.
         *
         * **Und die Dumps stehen hier mit drin.** Der erste Wurf hat sie über
         * {@see Manifest::reserves()} herausgefiltert, so wie der Packer und
         * der Unpacker es tun — dort zu Recht, denn dort geht es um den Baum
         * des Kunden. Hier geht es um den Inhalt des Archivs, und
         * `.srvpanel-databases/shop.sql.gz` ist eine Datei wie jede andere:
         * {@see BackupCreate::addDumps()} schreibt für jede einen Eintrag ins
         * Verzeichnis. Gefiltert hätte diese Prüfung eine Sicherung, der
         * **jede** Datenbank fehlt, als heil gemeldet.
         *
         * > **Dieselbe Frage an zwei Stellen hat nicht dieselbe Antwort, wenn
         * > die Stellen verschiedene Gegenstände haben — und die übernommene
         * > Zeile sieht aus wie Sorgfalt.**
         */
        $erwartet = [];

        foreach ($manifest['entries'] as $entry) {
            if ($entry['kind'] !== Manifest::KIND_FILE) {
                continue;
            }

            $erwartet[$entry['path']] = true;
        }

        $imArchiv = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            // `addEmptyDir()` schreibt einen Eintrag mit Schrägstrich am Ende.
            // Er ist kein Inhalt und hat keine Prüfsumme, die etwas bedeutet.
            if (str_ends_with($name, '/')) {
                continue;
            }

            $imArchiv[$name] = $i;
        }

        foreach (array_keys($erwartet) as $pfad) {
            if (! isset($imArchiv[$pfad])) {
                $findings[] = self::finding($storage, self::ENTRY_MISSING, sprintf(
                    'Das Verzeichnis nennt %s, und das Archiv trägt sie nicht.',
                    $pfad,
                ));
            }
        }

        foreach (array_keys($imArchiv) as $pfad) {
            /*
             * Das Verzeichnis selbst steht nicht in seiner eigenen Liste
             * ({@see BackupCreate::addManifest()}) — es gehört der Sicherung
             * und nicht dem Baum des Kunden. Seine Bytes werden trotzdem
             * geprüft: Es liegt in `$imArchiv` und damit in der Schleife
             * darunter. Ein gekipptes Byte darin ändert einen Modus oder einen
             * Pfad, ohne dass `decode()` etwas merkt.
             */
            if ($pfad === Manifest::ENTRY) {
                continue;
            }

            if (! isset($erwartet[$pfad])) {
                $findings[] = self::finding($storage, self::ENTRY_UNEXPECTED, sprintf(
                    'Das Archiv trägt %s, und das Verzeichnis kennt sie nicht.',
                    $pfad,
                ));
            }
        }

        $melden(25, 'Bytes prüfen');

        $geprueft = 0;

        foreach ($imArchiv as $pfad => $index) {
            if ($abort !== null && $abort()) {
                throw new AgentException(AgentException::CANCELLED, 'Die Prüfung wurde abgebrochen.');
            }

            $grund = self::checksum($zip, $index, $pfad);

            if ($grund !== null) {
                $findings[] = self::finding($storage, self::CORRUPT, $grund);
            }

            $geprueft++;

            if ($geprueft % self::REPORT_EVERY === 0) {
                // Der Balken bleibt zwischen 25 und 95 stehen und rechnet
                // nicht: Wie viele Einträge es sind, weiss der Lauf zwar — aber
                // nicht, wie gross sie sind, und die Zeit hängt an den Bytes.
                // Die Einzahl wird am **Wert** entschieden und nicht am Wort.
                // Dass `REPORT_EVERY` bei 500 steht und die Eins deshalb nie
                // vorkommt, ist eine Eigenschaft der Konstante und keine des
                // Satzes — wer sie auf 1 stellt, liest sonst „1 Einträge".
                $melden(60, $geprueft === 1
                    ? '1 Eintrag geprüft'
                    : sprintf('%d Einträge geprüft', $geprueft));
            }
        }

        $melden(100, 'fertig');

        return self::verdict($storage, $findings, $geprueft, $bytes);
    }

    /**
     * Die Prüfsumme eines Eintrags — strömend, und der Grund steht im Kopf.
     *
     * Gibt `null` zurück, wenn alles stimmt, sonst den Satz für den Befund.
     */
    private static function checksum(ZipArchive $zip, int $index, string $pfad): ?string
    {
        $stat = $zip->statIndex($index);

        if ($stat === false) {
            return sprintf('Zu %s gibt es keinen Eintrag im Verzeichnis des Archivs.', $pfad);
        }

        // **Über den Index und nicht über den Namen.** Ein Zip kann denselben
        // Namen zweimal führen; `getStream()` gäbe dann einen der beiden, und
        // verglichen würde er gegen die Prüfsumme des anderen.
        $stream = @$zip->getStreamIndex($index);

        if ($stream === false) {
            return sprintf('%s liess sich nicht lesen.', $pfad);
        }

        $ctx = hash_init('crc32b');
        $bytes = 0;

        while (! feof($stream)) {
            $stueck = fread($stream, self::CHUNK);

            if ($stueck === false) {
                fclose($stream);

                return sprintf('%s bricht beim Lesen ab.', $pfad);
            }

            $bytes += strlen($stueck);
            hash_update($ctx, $stueck);
        }

        fclose($stream);

        /*
         * **Verglichen wird als Zeichenkette und nicht als Zahl.**
         * `hash_final()` gibt acht Hexzeichen, `statIndex()['crc']` eine Zahl;
         * ein `hexdec()` dazwischen gibt `int|float` und machte aus einem
         * Vergleich mit `!==` eine Frage nach dem Typ. `%08x` füllt die
         * führenden Nullen auf, an denen ein naiver Vergleich scheitert.
         *
         * Gemessen am 16. September 2026 (`docs/117 §13` M10): `crc32()`,
         * `hash('crc32b')`, der fortlaufende Kontext und `statIndex()['crc']`
         * eines echten Zips geben alle vier denselben Wert — und ein gekipptes
         * Byte gibt einen anderen.
         */
        if (hash_final($ctx) !== sprintf('%08x', $stat['crc'])) {
            return sprintf('Die Bytes von %s stimmen nicht mit ihrer Prüfsumme überein.', $pfad);
        }

        // Die Länge dazu, weil eine Prüfsumme über die falsche Länge zufällig
        // passen kann — sie ist 32 Bit, und ein Archiv hat viele Einträge.
        if ($bytes !== (int) $stat['size']) {
            return sprintf(
                '%s ist %d Bytes lang, das Verzeichnis nennt %d.',
                $pfad,
                $bytes,
                (int) $stat['size'],
            );
        }

        return null;
    }

    /**
     * @param  list<array{subject: string, reason: string, detail: null|string}>  $findings
     * @return array{
     *     storage: string, healthy: bool, entries: int, bytes: int,
     *     findings: list<array{subject: string, reason: string, detail: null|string}>,
     * }
     */
    private static function verdict(string $storage, array $findings, int $entries, int $bytes): array
    {
        return [
            'storage' => $storage,
            'healthy' => $findings === [],
            'entries' => $entries,
            'bytes' => $bytes,
            'findings' => $findings,
        ];
    }

    /**
     * Ein Befund in der Form, die der Nachtlauf kennt.
     *
     * **Der Gegenstand ist der Ablagename und nicht der Pfad im Archiv.** Wer
     * den Befund in der Liste sieht, fragt zuerst „welche Sicherung" — welche
     * Datei darin es war, steht im Text daneben.
     *
     * @return array{subject: string, reason: string, detail: null|string}
     */
    private static function finding(string $storage, string $reason, ?string $detail): array
    {
        return ['subject' => $storage, 'reason' => $reason, 'detail' => $detail];
    }
}
