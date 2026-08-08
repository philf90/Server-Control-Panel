<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Eine mitgebrachte Sicherung übernehmen.
 *
 * **Die Datei kommt über einen Pfad und nicht über den Socket.** Ein halbes
 * Gigabyte durch den Unix-Socket zu schieben wäre der Weg, auf dem der Agent
 * den Speicher des Servers füllt — dieselbe Begründung, aus der eine Sicherung
 * beim Herunterladen nicht zurückgereicht wird ({@see Dump::FILE_MODE}). Das
 * Panel legt die hochgeladene Datei in seinem eigenen Schreibbereich ab und
 * nennt hier ihren Pfad; {@see Guard::pathInside()} löst ihn auf und weist
 * alles ab, was danach nicht mehr darunter liegt — ein Symlink, der aus dem
 * Bereich herauszeigt, ist genau der Ausbruch, den die Auflösung verhindert.
 *
 * **Vier Prüfungen, und jede hat einen eigenen Anlass** (`docs/36 §22.3f`):
 *
 * 1. **Die Magic Bytes.** Eine Datei heisst `.sql.gz`, weil jemand sie so
 *    genannt hat. Was sie ist, sagen ihre ersten beiden Bytes. Ohne diese
 *    Prüfung landete eine ZIP-Datei im Verzeichnis der Sicherungen, sähe dort
 *    aus wie eine, und der Fehler käme erst beim Zurückspielen — an einer
 *    Datenbank, die dabei schon geleert ist.
 * 2. **Die ausgepackte Grösse.** 400 MB gepackt können 40 GB werden; eine
 *    Grenze auf die gepackte Datei ist deshalb keine. Gezählt wird beim
 *    Auspacken nach `/dev/null`, mit einem Deckel — die Zahl im Gzip-Trailer
 *    wäre billiger und ist es nicht wert: Sie steht modulo 2³² darin und ist
 *    von jedem fälschbar, der die Datei schreibt.
 * 3. **Der freie Platz.** Nicht dort, wo die Datei liegt, sondern dort, wo die
 *    Daten hinkommen: Ein Zurückspielen füllt das Datenverzeichnis des
 *    Datenbankservers, und ein volles Dateisystem mitten im Einspielen ist der
 *    Zustand, in dem eine Datenbank halb ist.
 * 4. **Die Herkunft bleibt sichtbar.** Der Bestand des Panels führt `kind`, und
 *    diese Operation liefert `imported` zurück — eine mitgebrachte Sicherung
 *    ist etwas anderes als eine, die dieser Server selbst geschrieben hat, und
 *    beim Zurückspielen soll man das sehen.
 *
 * **Am Ende gehört die Datei root:srvpanel 0640**, wie jede andere Sicherung.
 * Sie wird verschoben und nicht kopiert, wo das geht: Zwei Kopien eines halben
 * Gigabytes sind ein voller Datenträger aus Versehen.
 */
final class DbDumpImport implements Op
{
    /**
     * Woran eine gzip-Datei zu erkennen ist.
     *
     * `1f 8b` steht am Anfang jedes gzip-Stroms (RFC 1952). Mehr wird nicht
     * geprüft: Ob der Inhalt danach SQL ist, entscheidet der Datenbankserver
     * beim Einspielen, und ein Agent, der SQL zu erkennen versucht, baut einen
     * Parser, den niemand geprüft hat.
     */
    private const MAGIC = "\x1f\x8b";

    /**
     * Der Deckel für die ausgepackte Grösse.
     *
     * Zwanzig Gibibyte sind mehr, als ein Formular sinnvoll entgegennimmt, und
     * weniger, als ein Dateisystem stillschweigend verkraftet. Wer mehr
     * zurückspielen muss, ist bei den Sicherungszielen aus P8 richtig und nicht
     * bei einem Hochladefeld.
     */
    public const MAX_UNPACKED = 20 * 1024 * 1024 * 1024;

    /** Wie viel am Stück ausgepackt wird, während gezählt wird. */
    private const CHUNK = 1024 * 1024;

    /** Wo die Daten des Datenbankservers liegen — dort muss der Platz sein. */
    public const DATA_DIRECTORY = '/var/lib/mysql';

    /**
     * Der Bereich, aus dem eine hochgeladene Datei kommen darf.
     *
     * **Der Schreibbereich des Panels und kein eigenes Verzeichnis.** Dort darf
     * das Panel ohnehin schreiben, dort liegt die Datei nach dem Upload, und
     * das Paket legt ihn beim Einrichten schon an. Ein weiteres Verzeichnis
     * unter `/var/lib/srvpanel` wäre eine Zeile mehr im postinst — und die
     * vergessene Zeile fällt erst beim ersten Schreibversuch auf dem Server
     * auf.
     */
    public const STAGING_ROOT = '/var/lib/srvpanel/storage/app/private/imports';

    public static function name(): string
    {
        return 'db.dump.import';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $prefix = Names::prefix($args['user'] ?? null);
        $subscription = Guard::string($args['subscription'] ?? null, 'subscription');
        $storage = Dump::storageName(Guard::string($args['storage'] ?? null, 'storage'));
        $source = Guard::pathInside($args['source'] ?? null, [self::STAGING_ROOT]);

        if (! is_file($source)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Die hochgeladene Datei ist nicht da.', ['source' => $source]);
        }

        $context->progress(10, 'Datei prüfen');
        $this->requireGzip($source);

        $context->progress(25, 'ausgepackte Grösse messen');
        $unpacked = $this->unpackedSize($source);

        $context->progress(60, 'Platz prüfen');
        $this->requireSpace($unpacked);

        $context->progress(80, 'Sicherung übernehmen');

        $directory = Dump::prepare($subscription);
        $target = Dump::path($subscription, $storage);

        // Der Name ist eindeutig gegen den Bestand des Panels; existiert die
        // Datei trotzdem, ist etwas anderes schiefgelaufen, und Überschreiben
        // wäre die falsche Antwort.
        if (is_file($target)) {
            throw AgentException::denied('Unter diesem Namen liegt schon eine Sicherung: '.$target);
        }

        $this->move($source, $target);

        chmod($target, Dump::FILE_MODE);
        @chown($target, 'root');
        @chgrp($target, Dump::GROUP);

        $context->progress(100, 'fertig');

        return [
            'path' => $target,
            'directory' => $directory,
            'prefix' => $prefix,
            'bytes' => (int) filesize($target),
            'unpacked_bytes' => $unpacked,
            'kind' => 'imported',
        ];
    }

    /** Die ersten Bytes — sonst ist es keine gzip-Datei, wie sie auch heisse. */
    private function requireGzip(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Die hochgeladene Datei ist nicht lesbar: '.$path);
        }

        $head = (string) fread($handle, 2);
        fclose($handle);

        if ($head !== self::MAGIC) {
            throw AgentException::badRequest(
                'Das ist keine gzip-Datei. Erwartet wird eine Sicherung, wie dieses Panel sie schreibt '
                .'(.sql.gz) — die Endung allein genügt nicht.',
            );
        }
    }

    /**
     * Wie gross die Datei ausgepackt ist — gezählt, nicht abgelesen.
     *
     * Gelesen wird nach nirgendwo: Was zählt, ist die Zahl, und die entsteht
     * beim Durchlauf. Über dem Deckel wird abgebrochen, ohne den Rest zu lesen
     * — eine Zip-Bombe soll Sekunden kosten und nicht Stunden.
     */
    private function unpackedSize(string $path): int
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::badRequest('Die hochgeladene Datei liess sich nicht auspacken.');
        }

        $bytes = 0;

        while (! gzeof($handle)) {
            $chunk = gzread($handle, self::CHUNK);

            if ($chunk === false) {
                gzclose($handle);

                throw AgentException::badRequest('Die hochgeladene Datei bricht mittendrin ab — sie ist unvollständig.');
            }

            $bytes += strlen($chunk);

            if ($bytes > self::MAX_UNPACKED) {
                gzclose($handle);

                throw AgentException::denied(sprintf(
                    'Ausgepackt ist die Sicherung grösser als %d GB. So etwas gehört nicht durch ein '
                    .'Formularfeld — dafür gibt es Sicherungsziele.',
                    intdiv(self::MAX_UNPACKED, 1024 * 1024 * 1024),
                ));
            }
        }

        gzclose($handle);

        return $bytes;
    }

    /** Platz dort, wo die Daten hinkommen — und nicht dort, wo die Datei liegt. */
    private function requireSpace(int $unpacked): void
    {
        $free = @disk_free_space(self::DATA_DIRECTORY);

        if ($free === false) {
            // Keine Auskunft ist kein Grund abzuweisen: Der Pfad kann auf einem
            // Server anders liegen, und ein Hochladen, das an einer fehlenden
            // Messung scheitert, wäre eine Grenze ohne Gegenstand.
            return;
        }

        if ($free < $unpacked) {
            throw AgentException::denied(sprintf(
                'Auf %s sind %d MB frei, die Sicherung braucht ausgepackt %d MB. Ein Einspielen, dem '
                .'mittendrin der Platz ausgeht, hinterlässt eine halbe Datenbank.',
                self::DATA_DIRECTORY,
                intdiv((int) $free, 1024 * 1024),
                intdiv($unpacked, 1024 * 1024),
            ));
        }
    }

    /**
     * Verschieben, und wenn das nicht geht, kopieren.
     *
     * `rename()` scheitert über Dateisystemgrenzen hinweg — der Schreibbereich
     * des Panels und `/var/lib/srvpanel/dumps` können auf verschiedenen
     * Einhängepunkten liegen. Dann bleibt Kopieren, und die Quelle geht
     * hinterher: Zwei Kopien eines halben Gigabytes sind ein voller Datenträger
     * aus Versehen.
     */
    private function move(string $source, string $target): void
    {
        if (@rename($source, $target)) {
            return;
        }

        if (! @copy($source, $target)) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht übernehmen: '.$target);
        }

        @unlink($source);
    }
}
