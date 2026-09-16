<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Backup;

use SrvPanel\Agent\AgentException;

/**
 * Was eine Sicherung über sich selbst weiss.
 *
 * ## Warum es überhaupt eines gibt
 *
 * **Gemessen** (`docs/116` M1b): Weder `ZipArchive` noch `PharData` legen
 * Eigentümer, Verweisziel oder das setgid-Bit ab — von fünf geprüften
 * Eigenschaften trägt ein Zip genau **eine**. Nur `tar(1)` trägt alle fünf, und
 * `tar` steht nicht auf der Positivliste des Runners. Ein Archiv allein ist
 * damit die Sicherung der **Dateinamen** eines Abonnements und nicht die des
 * Abonnements.
 *
 * Was fehlt, trägt deshalb dieses Verzeichnis: **Rechte** und
 * **Verweisziele**, je Eintrag.
 *
 * ## Was es ausdrücklich *nicht* trägt: den Eigentümer
 *
 * Er ändert sich beim Zurückspielen ohnehin — Form A vergibt einen neuen
 * Systembenutzer (`docs/117 §3`, entschieden am 16. September 2026) — und wird
 * aus diesem **neu gesetzt** statt aus dem Archiv gelesen.
 *
 * > **Ein Wert, der sich beim Zurückspielen sowieso ändert, gehört nicht in die
 * > Sicherung — er gehört neu gerechnet.**
 *
 * Die **ursprüngliche** Nummer steht trotzdem darin, und zwar als Auskunft und
 * nicht als Anweisung: Die Wiederherstellung sagt dem Kunden, dass sein
 * SFTP-Benutzername von `p1000` auf `p1005` wechselt, und dafür muss sie den
 * alten kennen.
 *
 * ## Warum es *im* Archiv liegt und nicht über den Socket reist
 *
 * **Gemessen** (`docs/116` M4): Ein Verzeichnis, das je Datei Rechte und
 * Verweisziel trägt, ist bei rund **14 000 Einträgen** am Ende von
 * `Connection::CONTENT_MAX`, während `Files\Packer::MAX_ENTRIES` **20 000**
 * zulässt.
 *
 * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
 *
 * Der Agent schreibt es also in das Archiv, und das Panel liest aus dem Archiv,
 * was es anzeigen muss. **Der Wächter dazu gehört an den Packer** und entsteht
 * mit ihm: Dass das Verzeichnis die Leitung nicht nimmt, lässt sich erst dort
 * an der Wirkung halten, wo es eine Operation gibt, deren Ergebnis es *nicht*
 * enthält. Hier stünde er als Name ohne Gegenstand.
 *
 * ## Warum die Rechte als Zeichenkette dastehen
 *
 * `"0644"` und nicht `420`. Ein Verzeichnis ist eine Datei, die ein Mensch
 * aufmacht, wenn etwas schiefgegangen ist — und `420` liest niemand als
 * `rw-r--r--`. Wer die Zahl dezimal sieht und sie in ein `chmod` tippt, setzt
 * `0644` auf `0420`. Die Umrechnung steht deshalb an **einer** Stelle, hier.
 *
 * ## Der Dateiname ist englisch
 *
 * `manifest.json`. `docs/117 §4` schrieb `verzeichnis.json`; ausgezählt am
 * 16. September sind alle Pfade dieses Servers englisch, und ein Eintragsname
 * in einem Archiv ist ein Bezeichner (`docs/19 §4a`). Das Wort „Verzeichnis"
 * bleibt im Fliesstext — dort meint es diese Datei und nicht einen Ordner.
 */
final class Manifest
{
    /**
     * Der Name im Archiv.
     *
     * Mit einem Punkt davor, damit er beim Entpacken in ein Kundenverzeichnis
     * nicht zwischen dessen Dateien steht — und weil das Entpacken ihn
     * ohnehin ausnimmt statt ihn mitzuschreiben.
     */
    public const ENTRY = '.srvpanel-manifest.json';

    /**
     * Wohin die Datenbankdumps im Archiv kommen.
     *
     * Sie liegen ausserhalb des Kundenbaums (`/var/lib/srvpanel/dumps`) und
     * bekommen deshalb einen eigenen Ort im Archiv, statt sich unter die
     * Dateien des Kunden zu mischen.
     */
    public const DUMPS = '.srvpanel-databases';

    /**
     * Namen, die die Sicherung selbst belegt — und die der Kunde deshalb nicht
     * mitbringen darf.
     *
     * **Gemessen am 16. September 2026, und es ist kein Schönheitsfehler:**
     * `ZipArchive::addFromString()` auf einen Namen, den `addFile()` schon
     * belegt hat, **überschreibt ihn wortlos**. Kein zweiter Eintrag, keine
     * Warnung, `close()` gibt `true`. Eine Datei `.srvpanel-manifest.json` im
     * Wurzelverzeichnis eines Kunden wäre aus seiner eigenen Sicherung
     * verschwunden, und aufgefallen wäre es erst beim Zurückspielen — also
     * dann, wenn er schon darauf wartet.
     *
     * > **Ein Schreiber, der einen vorhandenen Eintrag ersetzt und Erfolg
     * > meldet, verliert Daten mit einem Rückgabewert, der wie ein Beleg
     * > aussieht.**
     *
     * Deshalb wird beim **Packen** abgewiesen und nicht beim Entpacken
     * geflickt. Laut und mit dem Pfad in der Meldung: Ein Archiv, das eine
     * Datei still weglässt, ist das Problem, vor dem `Files\Packer` seit P6
     * warnt.
     *
     * @var list<string>
     */
    public const RESERVED = [
        self::ENTRY,
        self::DUMPS,
    ];

    /**
     * Die Fassung des Panels, die eine Sicherung geschrieben hat.
     *
     * **Der Agent kennt sie nicht, und das ist kein Versehen.** Er liegt im
     * Fassungsverzeichnis und wird mit ausgetauscht; eine Zahl, die er über
     * *das Panel* führte, wäre eine zweite Fassung derselben Angabe. Sie kommt
     * aus dem Aufruf.
     *
     * Sie ist eine Auskunft für den, der später eine alte Sicherung ansieht —
     * gelesen wird sie von nichts, und {@see self::FORMAT} entscheidet, ob eine
     * Sicherung lesbar ist.
     *
     * **Die Prüfung steht hier und nicht in der Operation**, damit die Naht
     * messbar ist: Was das Panel als Fassung hinausgibt, geht durch dieselbe
     * Tür, durch die der Agent sie nimmt. Eine Prüfung, die nur im Agenten
     * steht, lässt sich vom Panel aus nicht anders belegen als durch einen
     * zweiten Ausdruck — und der zweite ist die Fassung, die veraltet.
     *
     * Gemessen am 16. September 2026: In einem Quellbaum gibt
     * `config('app.version')` das Wort `Quellbaum` zurück, auf einem Server die
     * Freigabe. Beide kommen durch.
     */
    public static function panelVersion(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw AgentException::badRequest('Die Fassung des Panels fehlt.');
        }

        if (! preg_match('/^[A-Za-z0-9._+-]{1,64}$/D', $value)) {
            throw AgentException::badRequest('Die Fassung des Panels hat eine unerwartete Form.', [
                'panel' => mb_substr($value, 0, 64),
            ]);
        }

        return $value;
    }

    /**
     * Belegt die Sicherung diesen Pfad selbst?
     *
     * Gefragt wird am **ersten Namensteil**: `.srvpanel-databases/shop.sql.gz`
     * kollidiert genauso wie das Verzeichnis selbst. Ein Pfad, der nur so
     * *anfängt* — `.srvpanel-databases-alt` —, kollidiert nicht und kommt
     * durch; deshalb wird an `/` zerlegt und nicht mit `str_starts_with()`
     * verglichen.
     */
    public static function reserves(string $relative): bool
    {
        $first = explode('/', $relative)[0];

        return in_array($first, self::RESERVED, true);
    }

    /**
     * Die Fassung dieses Formats.
     *
     * Sie steht in jeder Sicherung und wird beim Lesen geprüft. Eine Sicherung
     * aus einer künftigen Fassung wird **abgewiesen** und nicht nach bestem
     * Wissen gedeutet: Was ein Leser nicht kennt, kann er nicht überspringen,
     * ohne zu verschweigen, dass er etwas übersprungen hat.
     */
    public const FORMAT = 1;

    /**
     * Die drei Arten eines Eintrags.
     *
     * Mehr gibt es nicht — Gerätedateien, Sockets und FIFOs kommen im Baum
     * eines Abonnements nicht vor, und ein Zip kann sie ohnehin nicht tragen.
     * Sie werden beim Packen **gemeldet und übersprungen** statt stillschweigend
     * zu fehlen (dieselbe Regel wie in `Files\Packer`).
     */
    public const KIND_FILE = 'file';

    public const KIND_DIRECTORY = 'dir';

    public const KIND_LINK = 'link';

    /**
     * Ein Eintrag — Pfad, Art, Rechte, bei einem Verweis das Ziel.
     *
     * Der Pfad ist **relativ zur Wurzel des Abonnements** und beginnt nie mit
     * einem Schrägstrich: Ein absoluter Pfad in einem Archiv ist die Einladung,
     * beim Entpacken irgendwohin zu schreiben.
     *
     * @return array{path: string, kind: string, mode: string, target?: string}
     */
    public static function entry(string $path, string $kind, int $mode, ?string $target = null): array
    {
        $row = [
            'path' => self::relative($path),
            'kind' => $kind,
            'mode' => self::octal($mode),
        ];

        if ($kind === self::KIND_LINK) {
            // Ein Verweis ohne Ziel ist kein Verweis. Er hier durchzulassen
            // hiesse, den Fehler beim Entpacken zu finden — also dann, wenn
            // der Kunde schon auf die Wiederherstellung wartet.
            if ($target === null || $target === '') {
                throw AgentException::execFailed('Ein Verweis ohne Ziel gehört nicht in eine Sicherung.', [
                    'path' => $path,
                ]);
            }

            $row['target'] = $target;
        }

        return $row;
    }

    /**
     * Die Rechte als vierstellige Oktalzahl — `0644`, nicht `420`.
     *
     * Maskiert auf die zwölf Bits, die `chmod` setzt: neun für die Rechte, drei
     * für setuid, setgid und sticky. Was `stat()` darüber hinaus in `mode`
     * liefert, ist die **Art** der Datei, und die steht schon in `kind`.
     */
    public static function octal(int $mode): string
    {
        return sprintf('%04o', $mode & 07777);
    }

    /**
     * Und zurück — aus `"0644"` wird der Wert für `chmod`.
     *
     * **Streng, weil das Ergebnis Rechte setzt.** Eine Zeichenkette, die keine
     * Oktalzahl ist, wird abgewiesen statt auf `0` ausgelegt: `octdec()` gibt
     * für Unsinn eine `0` zurück, und eine Datei mit `0000` sähe nach einer
     * gelungenen Wiederherstellung aus, bis jemand sie öffnen will.
     */
    public static function modeFrom(string $value): int
    {
        if (! preg_match('/^[0-7]{3,4}$/D', $value)) {
            throw AgentException::badRequest('Unzulässige Rechteangabe in der Sicherung.', ['mode' => $value]);
        }

        return (int) octdec($value);
    }

    /**
     * Ein Pfad relativ zur Wurzel und ohne Ausbruch.
     *
     * Führende Schrägstriche fallen, `..` wird abgewiesen. Das ist dieselbe
     * Frage wie bei `Files\Archive` aus P6, und sie wird hier ein zweites Mal
     * gestellt: Ein Verzeichnis kommt aus einem Archiv, und ein Archiv kommt
     * unter Umständen von aussen — ein hochgeladenes zum Beispiel. Wer sich
     * darauf verlässt, dass der eigene Packer keine `..` schreibt, prüft den
     * Packer und nicht die Datei, die vor ihm liegt.
     */
    public static function relative(string $path): string
    {
        $clean = ltrim($path, '/');

        if ($clean === '' || $clean === '.') {
            throw AgentException::badRequest('Ein leerer Pfad gehört nicht in eine Sicherung.');
        }

        foreach (explode('/', $clean) as $part) {
            if ($part === '..') {
                // `denied()` nimmt keine Einzelheiten — der Pfad gehört deshalb
                // in den Satz. Eine Ablehnung, die den Gegenstand nicht nennt,
                // schickt den Leser in ein Archiv mit zehntausend Einträgen.
                throw AgentException::denied(sprintf('Der Pfad "%s" steigt auf und gehört nicht in eine Sicherung.', $path));
            }
        }

        return $clean;
    }

    /**
     * Das ganze Verzeichnis als JSON.
     *
     * `JSON_UNESCAPED_UNICODE` und `JSON_UNESCAPED_SLASHES`, damit ein Pfad
     * mit Umlaut im Verzeichnis so aussieht wie im Dateisystem. Der Grund ist
     * nicht Schönheit: Wer eine Sicherung von Hand ansieht, vergleicht Pfade,
     * und `hände\/bild.png` gegen `hände/bild.png` ist ein Vergleich, den
     * man falsch macht.
     *
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @param  array<string,mixed>  $description
     */
    public static function encode(
        string $subscription,
        ?int $systemUser,
        ?string $dbPrefix,
        string $panelVersion,
        array $entries,
        array $description,
    ): string {
        $json = json_encode([
            'format' => self::FORMAT,
            'panel' => $panelVersion,
            'created_at' => gmdate('c'),
            'subscription' => $subscription,
            'system_user' => $systemUser,
            'db_prefix' => $dbPrefix,
            'entries' => $entries,
            'description' => $description,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw AgentException::execFailed('Das Verzeichnis der Sicherung liess sich nicht schreiben.');
        }

        return $json;
    }

    /**
     * Und zurück — mit jeder Prüfung, die ein Leser braucht.
     *
     * **Eine unbekannte Fassung wird abgewiesen.** Der Grund ist der aus
     * `docs/113`: Eine Anzeige, die zwei verschiedene Zustände gleich aussehen
     * lässt, behauptet etwas, das sie nicht weiss. Eine Sicherung aus einer
     * neueren Fassung teilweise zu lesen hiesse, eine unvollständige
     * Wiederherstellung als vollständige auszugeben.
     *
     * @return array{format: int, panel: string, created_at: string, subscription: string, system_user: int|null, db_prefix: string|null, entries: list<array{path: string, kind: string, mode: string, target?: string}>, description: array<string,mixed>}
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw AgentException::badRequest('Das Verzeichnis der Sicherung ist unlesbar.');
        }

        $format = $data['format'] ?? null;

        if (! is_int($format)) {
            throw AgentException::badRequest('Das Verzeichnis der Sicherung nennt keine Fassung.');
        }

        if ($format > self::FORMAT) {
            throw AgentException::badRequest(sprintf(
                'Diese Sicherung stammt aus einer neueren Fassung des Panels (Format %d, gelesen wird bis %d). '
                .'Sie teilweise einzuspielen wäre schlimmer als sie abzuweisen.',
                $format,
                self::FORMAT,
            ));
        }

        $subscription = $data['subscription'] ?? null;

        if (! is_string($subscription) || $subscription === '') {
            throw AgentException::badRequest('Das Verzeichnis der Sicherung nennt kein Abonnement.');
        }

        $entries = $data['entries'] ?? null;

        if (! is_array($entries)) {
            throw AgentException::badRequest('Das Verzeichnis der Sicherung führt keine Einträge.');
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Ein Eintrag der Sicherung ist unlesbar.');
            }

            $path = $entry['path'] ?? null;
            $kind = $entry['kind'] ?? null;
            $mode = $entry['mode'] ?? null;

            if (! is_string($path) || ! is_string($kind) || ! is_string($mode)) {
                throw AgentException::badRequest('Ein Eintrag der Sicherung ist unvollständig.');
            }

            if (! in_array($kind, [self::KIND_FILE, self::KIND_DIRECTORY, self::KIND_LINK], true)) {
                throw AgentException::badRequest('Unbekannte Art eines Eintrags.', ['kind' => $kind]);
            }

            // Der Pfad wird beim Lesen **noch einmal** geprüft und nicht nur
            // beim Schreiben. Wer sich darauf verlässt, dass der eigene Packer
            // keine `..` schreibt, prüft den Packer und nicht die Datei.
            $row = [
                'path' => self::relative($path),
                'kind' => $kind,
                'mode' => self::octal(self::modeFrom($mode)),
            ];

            if ($kind === self::KIND_LINK) {
                $target = $entry['target'] ?? null;

                if (! is_string($target) || $target === '') {
                    throw AgentException::badRequest('Ein Verweis der Sicherung nennt kein Ziel.', ['path' => $path]);
                }

                $row['target'] = $target;
            }

            $rows[] = $row;
        }

        $systemUser = $data['system_user'] ?? null;
        $prefix = $data['db_prefix'] ?? null;
        $panel = $data['panel'] ?? null;
        $created = $data['created_at'] ?? null;

        return [
            'format' => $format,
            'panel' => is_string($panel) ? $panel : '',
            'created_at' => is_string($created) ? $created : '',
            'subscription' => $subscription,
            'system_user' => is_int($systemUser) ? $systemUser : null,
            'db_prefix' => is_string($prefix) && $prefix !== '' ? $prefix : null,
            'entries' => $rows,
            'description' => is_array($data['description'] ?? null) ? $data['description'] : [],
        ];
    }
}
