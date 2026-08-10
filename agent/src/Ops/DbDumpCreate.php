<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Op;

/**
 * Eine Datenbank sichern.
 *
 * **Sie heisst `db.dump.create` und nicht `db.dump`.** Der erste Entwurf nannte
 * sie so, und damit wäre `db.dump.remove` ein *Kind* statt eines Geschwisters
 * gewesen: `RemovalPathTest` sucht die Gegenoperation über die Wurzel, und die
 * von `db.dump` ist `db`. Der Wächter hätte nichts gemeldet — nicht weil alles
 * stimmt, sondern weil `.dump` kein anlegendes Verb ist. Ein Wächter, den man
 * durch eine Benennung umgeht, ist genau das, wogegen er gebaut ist.
 *
 * Jetzt heisst das Paar wie alle anderen: `db.dump.create` und
 * `db.dump.remove`, wie `db.database.*` und `db.user.*`.
 *
 * **`mysqldump --result-file=` schreibt unmittelbar in eine Datei**, und über
 * den Ausgabepfad des Runners läuft nichts. Der Grund steht in
 * {@see Dump} und in `docs/36 §22.3`: `Runner` deckelt die gesammelte Ausgabe
 * bei 4 MiB und zerschneidet den Rückkanal an der 64-KiB-Lesegrenze statt an
 * der Zeilengrenze. Eine Sicherung durch dieses Rohr wäre abgeschnitten — und
 * eine abgeschnittene Sicherung ist schlimmer als keine, weil sie aussieht wie
 * eine.
 *
 * Danach läuft {@see Dump::compress()} über die Rohdatei: DEFINER streichen,
 * komprimieren, und die Rohdatei fällt. Der Speicherbedarf ist eine Zeile.
 *
 * **Der Platz wird vorher geprüft, und zwar für beide Fassungen.** Zwischen
 * `--result-file` und der komprimierten Datei liegen sie kurz nebeneinander;
 * ein Panel, das beim Sichern den Datenträger füllt, nimmt jeden anderen Kunden
 * mit. Die Schätzung kommt aus `information_schema` und ist grosszügig
 * bemessen — sie ist eine Schranke gegen das Offensichtliche und keine
 * Buchhaltung.
 */
final class DbDumpCreate implements Op
{
    /**
     * Wie viel freier Platz mindestens übrig bleiben muss.
     *
     * Nicht null: Ein Dateisystem, das auf das letzte Byte vollläuft, nimmt
     * alles mit, was gerade schreibt — auch die Protokolle, in denen der Grund
     * stünde.
     */
    private const RESERVE_BYTES = 512 * 1024 * 1024;

    /**
     * Der Sicherheitsaufschlag auf die geschätzte Grösse.
     *
     * Roh und komprimiert liegen kurz nebeneinander, und die Rohfassung eines
     * Dumps ist regelmässig grösser als das Schema — Textdarstellung statt
     * Binärformat. Faktor drei ist grob und in die richtige Richtung grob.
     */
    private const HEADROOM = 3;

    /** Wie lange ein `mysqldump` laufen darf. Vier Stunden für sehr grosse Schemata. */
    private const TIMEOUT = 14_400;

    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.dump.create';
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
        $database = Names::existing($args['name'] ?? null, 'name');
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $storage = Dump::storageName(is_string($args['storage'] ?? null) ? $args['storage'] : '');

        if (! Names::belongsTo($database, $prefix)) {
            throw AgentException::denied(sprintf(
                'Die Datenbank %s gehört nicht zum Abonnement %s.',
                $database,
                $prefix,
            ));
        }

        $context->progress(10, 'Platz prüfen');
        $this->requireSpace($context, $database);

        $directory = Dump::prepare($subscription);
        $target = Dump::path($subscription, $storage);
        $raw = $directory.'/.'.$storage.'.sql';

        try {
            $context->progress(25, 'Datenbank auslesen');
            $this->run($context, $database, $raw);

            $context->progress(70, 'DEFINER streichen und komprimieren');
            // Der Filter steht seit P5b im Aufruf und nicht in der Schleife —
            // `pg.dump.create` schreibt dieselbe Datei ohne ihn. Die Begründung
            // steht bei {@see Dump::compress()}.
            $bytes = Dump::compress(
                $raw,
                $target,
                fn (): bool => $context->abandoned(),
                Dump::withoutDefiner(...),
            );

            $this->handOver($target);
        } finally {
            // Die Rohfassung geht in jedem Fall — auch nach einem Abbruch. Sie
            // ist die grössere von beiden und die, die niemand mehr braucht.
            @unlink($raw);
        }

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'storage' => $storage,
            'bytes' => $bytes,
        ];
    }

    /**
     * Der Lauf von `mysqldump`.
     *
     * **`--single-transaction` statt `--lock-tables`**: Eine Sicherung, die
     * eine laufende Webseite für die Dauer des Dumps sperrt, ist eine
     * Betriebsunterbrechung. InnoDB liefert damit einen konsistenten Stand ohne
     * Sperre; für MyISAM gälte das nicht, aber ein neues Schema dieses Panels
     * ist utf8mb4/InnoDB.
     *
     * **`--quick`**: Zeile für Zeile statt tabellenweise in den Speicher.
     *
     * **`--no-tablespaces`**: Ohne die Angabe verlangt `mysqldump` das globale
     * Recht `PROCESS`. Der Agent hat es als root, aber die Zeile spart es ein —
     * und was nicht gebraucht wird, wird nicht verlangt.
     */
    private function run(Context $context, string $database, string $raw): void
    {
        $result = $context->runner->run('mysqldump', [
            '--protocol=socket',
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--events',
            '--result-file='.$raw,
            $database,
        ], self::TIMEOUT, null, null, fn (): bool => $context->abandoned());

        if (! $result->successful()) {
            throw AgentException::execFailed('Die Sicherung ist gescheitert: '.$result->message());
        }
    }

    /**
     * Die fertige Datei an das Panel übergeben — `root:srvpanel 0640`.
     *
     * Das Panel liest sie und reicht sie an den Browser durch. Der Weg über den
     * Socket wäre der, auf dem der Agent zwei Gigabyte in den Speicher legt.
     */
    private function handOver(string $path): void
    {
        // Eine Gruppe, die es nicht gibt, ist kein Grund zum Abbruch — dieselbe
        // Vorsicht wie in `SubscriptionProvision::directory()`. Die Datei
        // gehört dann root allein: enger als vorgesehen, nicht weiter.
        if (posix_getgrnam(Dump::GROUP) !== false) {
            chgrp($path, Dump::GROUP);
        }

        chown($path, 'root');
        chmod($path, Dump::FILE_MODE);
    }

    /**
     * Genug Platz für Rohfassung und komprimierte Fassung?
     *
     * Die Grösse kommt aus `information_schema` — bei InnoDB der zugeteilte
     * Platz in den Tabellendateien, also die Zahl, die auf dem Datenträger
     * liegt.
     */
    private function requireSpace(Context $context, string $database): void
    {
        $rows = $this->session->query($context, sprintf(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = %s',
            Sql::text($database),
        ));

        $size = (int) ($rows[0][0] ?? 0);
        $needed = $size * self::HEADROOM + self::RESERVE_BYTES;
        $free = @disk_free_space(Dump::ROOT) ?: @disk_free_space('/var/lib');

        if ($free === false || $free >= $needed) {
            return;
        }

        throw AgentException::execFailed(sprintf(
            'Zu wenig Platz für die Sicherung: %d MB frei, geschätzt %d MB nötig (Rohfassung und komprimierte Fassung liegen kurz nebeneinander).',
            (int) ($free / 1048576),
            (int) ($needed / 1048576),
        ));
    }
}
