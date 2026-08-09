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
 *    Datenbankservers, und ein volles Dateisystem mitten im Zurückspielen ist der
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
     * Wo die Daten des Datenbankservers liegen — dort muss der Platz sein.
     *
     * Die Prüfung selbst steht seit P5b in {@see Dump::requireSpace()}, weil
     * PostgreSQL dieselbe braucht und sein Datenverzeichnis woanders hat. Was
     * hier bleibt, ist der Ort — und der ist für MariaDB fest.
     */
    public const DATA_DIRECTORY = '/var/lib/mysql';

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
        $source = Guard::pathInside($args['source'] ?? null, [Dump::STAGING_ROOT]);

        if (! is_file($source)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Die hochgeladene Datei ist nicht da.', ['source' => $source]);
        }

        $context->progress(10, 'Datei prüfen');
        Dump::requireGzip($source);

        $context->progress(25, 'ausgepackte Grösse messen');
        $unpacked = Dump::unpackedSize($source);

        $context->progress(60, 'Platz prüfen');
        Dump::requireSpace($unpacked, self::DATA_DIRECTORY);

        $context->progress(80, 'Sicherung übernehmen');

        $directory = Dump::prepare($subscription);
        $target = Dump::path($subscription, $storage);

        // Der Name ist eindeutig gegen den Bestand des Panels; existiert die
        // Datei trotzdem, ist etwas anderes schiefgelaufen, und Überschreiben
        // wäre die falsche Antwort.
        if (is_file($target)) {
            throw AgentException::denied('Unter diesem Namen liegt schon eine Sicherung: '.$target);
        }

        Dump::moveInto($source, $target);

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
}
