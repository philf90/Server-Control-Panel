<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Backup;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use ZipArchive;

/**
 * Den Baum eines Abonnements in ein Archiv legen — mit dem, was das Archiv
 * nicht tragen kann, daneben im Verzeichnis.
 *
 * ## Was `ZipArchive` verliert, und warum das Verzeichnis nicht optional ist
 *
 * **Gemessen** (`docs/116` M1b), an fünf Eigenschaften: Von einer Datei `0644`,
 * einem Schlüssel `0600` mit fremder UID, einem Symlink, einem Verzeichnis mit
 * setgid und einem leeren Verzeichnis kommt über `ZipArchive` genau **eine**
 * unverändert zurück. Es fehlen der Eigentümer und der Verweis, und
 * **Verzeichnisse kommen als `0777` zurück**.
 *
 * Deshalb kommt jeder Modus aus {@see Manifest} und nicht aus dem Archiv — auch
 * der von Dateien, bei denen das Archiv ihn zufällig trüge. **Eine Grösse hat
 * eine Quelle**; zwei wären zwei Fassungen derselben Wahrheit, und die zweite
 * ist die, die veraltet.
 *
 * `setExternalAttributesName()` ist dabei **nicht** gemessen worden (`docs/116`
 * M1b sagt das ausdrücklich). Es hier zu benutzen hiesse, sich auf eine
 * Eigenschaft zu verlassen, die niemand nachgesehen hat.
 *
 * ## Verweisen wird nicht gefolgt — und das ist die tragende Schranke
 *
 * Der Agent läuft als root über einen Baum, in den der **Kunde** schreibt. Ein
 * Symlink `httpdocs/x` nach `/etc/shadow` läge sonst im Archiv, das der Kunde
 * anschliessend herunterlädt.
 *
 * `RecursiveDirectoryIterator::hasChildren()` steigt ohne Argument **nicht** in
 * einen Verweis ab, und gelesen wird eine Datei nur über einen Pfad, dessen
 * Bestandteile der Lauf selbst besucht hat. Ein Verweis wird deshalb
 * **notiert und nicht geöffnet**: Sein Ziel steht im Verzeichnis, und die
 * Wiederherstellung legt ihn neu an.
 *
 * > **Ein Archiv, das einem Verweis folgt, enthält, worauf er zeigt — und der
 * > Kunde bestimmt, worauf er zeigt.**
 *
 * ## Was nicht mitkommt, steht im Verzeichnis
 *
 * Drei Verzeichnisse des Schemas aus `SubscriptionProvision::TREE` bleiben
 * draussen, jedes mit seinem Grund in {@see self::SKIPPED}. Sie bleiben nicht
 * stillschweigend draussen: Was fehlt, steht als `skipped` im Verzeichnis.
 *
 * > **Was ein Archiv nicht enthält, muss es sagen.** (`Files\Packer`, P6)
 */
final class Packer
{
    /**
     * Wie viele Einträge in eine Sicherung gehen — **gemessen, nicht gesetzt**.
     *
     * Die Grenze ist nicht das Format: `ZipArchive` hat am 16. September 2026
     * **70 000** Einträge geschrieben und dieselben 70 000 wieder gelesen, in
     * 0,5 s und mit 4 MiB Spitze — die klassischen 65 535 binden hier nicht,
     * libzip schreibt zip64. *(Gegenprobe: ein Archiv mit fünf Einträgen liest
     * fünf, der Zähler meldet also keine feste Zahl.)*
     *
     * Die Grenze ist der **Speicher**, und zwar der des Agenten:
     * `srvpanel-agentd.service` trägt `MemoryMax=512M`, und darüber tötet der
     * Kernel den Prozess — ein Vorgang, der wortlos stirbt, sieht aus wie ein
     * hängender Agent. Gemessen am Verzeichnis, das vor dem Schreiben im
     * Speicher steht, **ein Fall je Prozess**:
     *
     * |   Einträge | Spitze | von 512M |
     * |---|---|---|
     * |  25 000 |  30 MiB |  6 % |
     * |  50 000 |  60 MiB | 12 % |
     * | **100 000** | **122 MiB** | **24 %** |
     *
     * 100 000 lässt drei Viertel für alles andere.
     *
     * **Und die Spitze hängt nicht an der Länge der Pfade** — gemessen am
     * 16. September mit zwei Prüfkörpern, einem kurzen (`httpdocs/datei-…`,
     * 125 B je Eintrag als JSON) und einem in der Tiefe eines WordPress-Baums
     * (171 B): **beide 122 MiB**. Was den Speicher füllt, ist das Feld aus
     * 100 000 kleinen Feldern und nicht die Zeichenkette daraus; in PHPs
     * eigener Rechnung sind es **1042 Bytes je Eintrag**, gleich welcher Pfad
     * darin steht.
     *
     * > **Zwei Grössen, die man zusammen misst, sehen verbunden aus — und
     * > welche von beiden die Zahl treibt, sagt erst der Prüfkörper, der nur
     * > eine von ihnen ändert.**
     *
     * Die erste Fassung dieser Messung lief alle Fälle in **einem** Prozess und
     * gab Faktoren zwischen 1,5 und 9,7 aus — der Heap wächst über die Fälle
     * hinweg, und `memory_get_peak_usage(true)` misst ihn mit.
     *
     * > **Ein Prüfkörper, der sich am gegenwärtigen Zustand bemisst, verändert
     * > den Zustand, an dem er sich bemisst.**
     *
     * `BackupEntryLimitTest` rechnet das Verhältnis gegen die Unit-Datei nach,
     * statt die Zahl zu glauben — wer `MemoryMax` senkt oder dem Verzeichnis
     * ein Feld gibt, bekommt es dort gesagt.
     */
    public const MAX_ENTRIES = 100_000;

    /**
     * Was aus dem Schema draussen bleibt — und warum.
     *
     * Die Schlüssel sind die obersten Verzeichnisse aus
     * `SubscriptionProvision::TREE`. `httpdocs`, `.ssh` und `mail` fehlen hier
     * mit Absicht: Das sind die Daten des Kunden.
     *
     * @var array<string, string>
     */
    public const SKIPPED = [
        // Protokolle rotieren über Nacht und wachsen dazwischen — gemessen 8393
        // Zeilen abends gegen 481 am Morgen (`docs/921`). Sie sind der Teil des
        // Baums, der am schnellsten wächst und am wenigsten
        // wiederherstellenswert ist: Eine Sicherung, die sie mitnimmt, ist
        // grösstenteils eine Sicherung von Protokollen.
        'logs' => 'Protokolle rotieren und werden nicht zurückgespielt',

        // Temporär heisst temporär. Was dort liegt, hat den letzten Lauf nicht
        // überlebt und soll den nächsten nicht sehen.
        'tmp' => 'temporäre Dateien',

        // **Erzeugt und nicht zurückgespielt.** Hier liegen die
        // `<domain>.include`-Dateien, die `Site` aus der Vorlage schreibt. Eine
        // wörtlich zurückgespielte Fassung aus einer älteren Version meldet der
        // Nachtlauf als `directive_lost` (`docs/116` M6) — gemessen an der
        // echten Vorlage und am echten Leser.
        'conf' => 'wird aus der Beschreibung erzeugt',
    ];

    /**
     * Wie oft der Fortschritt gemeldet wird.
     *
     * Je Eintrag zu melden wäre bei 100 000 Einträgen 100 000 Schreibvorgänge
     * auf einen Kanal, den ein Mensch liest — und die Zahl änderte sich
     * schneller, als eine Seite sie anzeigen kann.
     */
    private const REPORT_EVERY = 500;

    /**
     * Packen — und zurückgeben, was das Verzeichnis braucht.
     *
     * Der Fortschritt wird gemeldet und nicht gerechnet: Wie viele Einträge es
     * am Ende sind, weiss vorher niemand, und ein Balken, der aus einer
     * geschätzten Gesamtzahl entsteht, springt zurück.
     *
     * @param  null|callable(int, string):void  $progress  Zahl der Einträge, gerade gelesener Pfad
     * @param  null|callable():bool  $abort
     * @return array{entries: list<array{path: string, kind: string, mode: string, target?: string}>, skipped: array<string,string>, files: int, bytes: int}
     */
    public static function pack(
        string $subscription,
        string $target,
        ?callable $progress = null,
        ?callable $abort = null,
    ): array {
        $root = SubscriptionProvision::VHOSTS.'/'.SubscriptionProvision::subscriptionName($subscription);

        if (! is_dir($root)) {
            throw AgentException::badRequest('Das Abonnement hat kein Verzeichnis.', ['path' => $root]);
        }

        // Der aufgelöste Pfad muss derselbe sein — dieselbe Schranke wie beim
        // Rückbau. Wäre die Wurzel selbst ein Verweis, liefe der Packer über
        // einen Baum, den jemand anderes bestimmt hat.
        if (realpath($root) !== $root) {
            throw AgentException::denied('Der aufgelöste Pfad des Abonnements weicht ab — es wird nichts gepackt.');
        }

        return self::packTree($root, $target, $progress, $abort);
    }

    /**
     * Dieselbe Arbeit an einer Wurzel, die schon geprüft ist.
     *
     * **Der Pfad kommt aus {@see self::pack()} und nicht von aussen.** Die
     * Grenze aus `docs/20 §4.1` — eine *Operation* nimmt keinen Pfad entgegen,
     * sie baut ihn — sitzt an der Operation und nicht an dieser Klasse;
     * `Db\Dump::compress()` nimmt seit P5 aus demselben Grund zwei Pfade.
     *
     * Getrennt ist es, weil `/var/www/vhosts` in diesem Container nicht
     * existiert und eine Zusage, die sich nur auf einem Server messen lässt,
     * eine Zusage ist, die niemand misst. `BackupPromiseTest` fährt den
     * Rundlauf gegen einen Wegwerfbaum.
     *
     * @param  null|callable(int, string):void  $progress
     * @param  null|callable():bool  $abort
     * @return array{entries: list<array{path: string, kind: string, mode: string, target?: string}>, skipped: array<string,string>, files: int, bytes: int}
     */
    public static function packTree(
        string $root,
        string $target,
        ?callable $progress = null,
        ?callable $abort = null,
    ): array {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht anlegen.', ['path' => $target]);
        }

        $entries = [];
        $files = 0;

        try {
            foreach (self::walk($root) as $relative => $info) {
                if (count($entries) >= self::MAX_ENTRIES) {
                    throw AgentException::denied(sprintf(
                        'Dieses Abonnement hat mehr Einträge, als eine Sicherung fassen kann. Darüber passt '
                        .'das Verzeichnis nicht in den Speicher, den der Agent hat (MemoryMax=512M), und der '
                        .'Vorgang würde wortlos abgebrochen. Die Grenze liegt bei %d.',
                        self::MAX_ENTRIES,
                    ));
                }

                if ($abort !== null && count($entries) % self::REPORT_EVERY === 0 && $abort()) {
                    throw new AgentException(AgentException::CANCELLED, 'Die Sicherung wurde abgebrochen.');
                }

                /*
                 * **Was die Sicherung selbst belegt, darf der Kunde nicht
                 * mitbringen.** `addFromString()` auf einen Namen, den
                 * `addFile()` schon geschrieben hat, überschreibt ihn wortlos
                 * — die Datei des Kunden wäre aus seiner eigenen Sicherung
                 * fort, und `close()` meldete Erfolg. Die Begründung samt
                 * Messung steht bei {@see Manifest::RESERVED}.
                 *
                 * Abgewiesen wird hier und nicht beim Entpacken: Ein Archiv,
                 * das eine Datei still weglässt, ist genau das, was diese
                 * Klasse nicht sein darf.
                 */
                if (Manifest::reserves($relative)) {
                    throw AgentException::denied(sprintf(
                        'Der Pfad %s gehört der Sicherung selbst und kann nicht mitgesichert werden. '
                        .'Wer ihn im Baum des Abonnements braucht, benennt ihn um — sonst verlöre die '
                        .'Sicherung ihn wortlos.',
                        $relative,
                    ));
                }

                // **`isLink()` zuerst.** Ein Verweis auf ein Verzeichnis ist für
                // `isDir()` ein Verzeichnis, und dann läge sein Ziel im Archiv.
                if ($info->isLink()) {
                    $target = @readlink($info->getPathname());

                    if ($target === false || $target === '') {
                        // Ein Verweis, dessen Ziel sich nicht lesen lässt, ist
                        // kein Eintrag — und kein Grund, die ganze Sicherung
                        // abzubrechen. Er fehlt und wird gemeldet.
                        continue;
                    }

                    // **`getPerms()` wird hier nicht gerufen, und das ist
                    // gemessen** (16. September 2026): An einem *heilen*
                    // Verweis gibt es `100600` zurück — den Modus des **Ziels**,
                    // nicht des Verweises —, und an einem **toten** wirft es
                    // eine `RuntimeException`. Ein Kunde mit einem kaputten
                    // Symlink im Baum hätte damit jede Sicherung zum Absturz
                    // gebracht.
                    //
                    // Ein Symlink trägt unter Linux immer `0777`, und `chmod`
                    // auf einen Verweis folgt ihm — den Modus des Ziels hier
                    // festzuhalten hiesse, ihn beim Zurückspielen am Ziel zu
                    // setzen.
                    $entries[] = Manifest::entry($relative, Manifest::KIND_LINK, 0777, $target);

                    continue;
                }

                if ($info->isDir()) {
                    $entries[] = Manifest::entry($relative, Manifest::KIND_DIRECTORY, $info->getPerms());

                    // Auch ein leeres Verzeichnis kommt mit — `tmp` und `mail`
                    // sind im Grundzustand leer, und `PharData` lässt genau die
                    // fallen (`docs/116` M1b).
                    $zip->addEmptyDir($relative);

                    continue;
                }

                if (! $info->isFile()) {
                    // Gerätedateien, Sockets, FIFOs. Ein Zip kann sie nicht
                    // tragen, und im Baum eines Abonnements haben sie nichts zu
                    // suchen — gemeldet statt stillschweigend ausgelassen.
                    continue;
                }

                $entries[] = Manifest::entry($relative, Manifest::KIND_FILE, $info->getPerms());

                if (! $zip->addFile($info->getPathname(), $relative)) {
                    throw AgentException::execFailed('Eine Datei liess sich nicht sichern.', ['path' => $relative]);
                }

                $files++;

                if ($progress !== null && $files % self::REPORT_EVERY === 0) {
                    $progress($files, $relative);
                }
            }

            if ($zip->close() !== true) {
                throw AgentException::execFailed('Die Sicherung liess sich nicht abschliessen.', ['path' => $target]);
            }
        } catch (AgentException $e) {
            // Ein halbes Archiv ist schlimmer als keines: Es sieht aus wie
            // eines. `close()` vor dem Entfernen, sonst hält libzip die Datei.
            @$zip->close();
            @unlink($target);

            throw $e;
        }

        $size = @filesize($target);

        return [
            'entries' => $entries,
            'skipped' => self::SKIPPED,
            'files' => $files,
            'bytes' => $size === false ? 0 : $size,
        ];
    }

    /**
     * Wie gross der Baum ist, den {@see self::pack()} packen würde.
     *
     * **Durch dieselbe `walk()` und nicht durch eine zweite Zählung.** Eine
     * eigene Rechnung wäre die zweite Fassung derselben Regel, und die zweite
     * ist die, die veraltet: Käme ein Verzeichnis zu {@see self::SKIPPED}
     * dazu, schätzte sie weiter mit — und ein Abonnement mit grossen
     * Protokollen fiele an einer Schranke, die für es gar nicht gilt.
     *
     * **Der zweite Lauf ist gemessen und nicht geschätzt:** 32 813 Einträge in
     * 135 bis 149 ms (zwei Läufe, also nicht der Zwischenspeicher). Gegen einen
     * Zip-Lauf über denselben Baum ist das nichts.
     *
     * Was sie **nicht** sagt: wie gross das Archiv wird. Es wird kleiner —
     * komprimiert —, die Schätzung fällt also zur sicheren Seite. Sie ist eine
     * Schranke gegen das Offensichtliche und keine Buchhaltung.
     */
    public static function estimate(string $subscription): int
    {
        $root = SubscriptionProvision::VHOSTS.'/'.SubscriptionProvision::subscriptionName($subscription);

        if (! is_dir($root)) {
            return 0;
        }

        $bytes = 0;

        foreach (self::walk($root) as $info) {
            // **`isLink()` zuerst, und zwar aus demselben Grund wie beim
            // Packen:** `getSize()` folgt dem Verweis und wirft an einem toten.
            // Ein Kunde mit einem kaputten Symlink brächte sonst schon die
            // Platzprüfung zu Fall — vor der ersten geschriebenen Zeile.
            if ($info->isLink() || ! $info->isFile()) {
                continue;
            }

            $size = @$info->getSize();

            if ($size !== false) {
                $bytes += $size;
            }
        }

        return $bytes;
    }

    /**
     * Der Baumlauf — ohne die ausgenommenen Verzeichnisse und ohne Verweisen zu folgen.
     *
     * **`hasChildren()` ohne Argument folgt keinem Verweis**, und darauf ruht
     * die Schranke aus dem Kopf dieser Klasse. Es steht hier als eigener
     * Durchlauf und nicht als Filter darüber: Ein Filter sähe die Kinder eines
     * Verweises erst, nachdem der Lauf sie schon besucht hat.
     *
     * @return iterable<string, SplFileInfo>
     */
    private static function walk(string $root): iterable
    {
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        /*
         * **Gefiltert wird beim Absteigen und nicht beim Ausgeben.** Ein
         * `continue` in der Schleife darunter liesse den Lauf durch jede
         * Protokolldatei laufen, bevor er sie verwirft — und `logs` ist das
         * Verzeichnis, das am schnellsten wächst. Der Rückgabewert `false`
         * hält `RecursiveIteratorIterator` davon ab, `getChildren()` überhaupt
         * zu rufen.
         */
        $filtered = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $info) use ($root): bool {
                $relative = substr($info->getPathname(), strlen($root) + 1);

                return ! isset(self::SKIPPED[explode('/', $relative)[0]]);
            },
        );

        $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            $relative = substr($info->getPathname(), strlen($root) + 1);

            if ($relative === '') {
                continue;
            }

            yield $relative => $info;
        }
    }
}
