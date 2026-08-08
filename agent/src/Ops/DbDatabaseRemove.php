<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Op;

/**
 * Eine Datenbank entfernen — samt der Benutzer, die nur an ihr hingen.
 *
 * **Diese Datei ist vor {@see DbDatabaseCreate} entstanden, und das ist keine
 * Koketterie.** `docs/35` hat freigelegt, dass dieses System ein Zertifikat nie
 * löschen konnte: Jedes zurückgebaute Abonnement liess seinen privaten
 * Schlüssel auf der Platte liegen, und gemerkt hat es niemand, weil ein
 * Grabstein die Zeile am Leben hielt. Wer `create` zuerst schreibt, hat danach
 * etwas, das funktioniert, und `remove` wird zur Nacharbeit — genau die
 * Mechanik, aus der diese Lücke entstanden ist. Hier legt eine Datenbank
 * Kundendaten an, und die liegen unter `/var/lib/mysql`, also ausserhalb von
 * allem, was `subscription.remove` anfasst.
 *
 * **Der Name kommt aus der abgelegten Zeile, nicht aus einer Anfrage** — und
 * er wird trotzdem zweimal geprüft: {@see Names::existing()} auf die Form, und
 * {@see Names::belongsTo()} darauf, dass er zum genannten Abonnement gehört.
 * Die zweite Prüfung ist die Mandantengrenze im Agenten. Ohne sie wäre ein
 * Fehler in der Anwendung ein `DROP DATABASE` auf das Schema eines fremden
 * Kunden; mit ihr ist er ein abgewiesener Aufruf.
 *
 * **Wiederholbar.** Eine Datenbank, die es nicht mehr gibt, ist der gewünschte
 * Zustand; der Aufruf meldet das und scheitert nicht. Sonst hinge ein
 * fehlgeschlagener Rückbau für immer, weil sein zweiter Versuch an dem
 * scheitert, was der erste schon geschafft hat — dieselbe Zusage wie in
 * {@see SubscriptionRemove}.
 *
 * **Welche Benutzer mitgehen, sagt die Anwendung.** Ein Datenbankbenutzer kann
 * an mehreren Datenbanken hängen; welcher nach diesem `DROP` keine mehr hat,
 * weiss nur der Bestand des Panels. Eine Operation, die selbst in `mysql.db`
 * nachsähe, wäre eine zweite Fassung dieser Regel — und die zweite ist die, die
 * veraltet. Wörtlich dieselbe Begründung wie in {@see AcmeCertificateRemove}.
 *
 * **Und wer bleibt, verliert sein Recht auf dieses Schema.** `DROP DATABASE`
 * entfernt in MariaDB die auf das Schema vergebenen Rechte **nicht**; sie
 * stehen in `mysql.db` und bleiben dort. Bis zum 8. August 2026 nannte die
 * Anwendung nur die Benutzer, die mitgehen, und ein Zugang, der an einer
 * zweiten Datenbank hing, behielt sein `GRANT ALL` auf die entfernte — auf
 * `cloudsrv24` gefunden als eine Rechtezeile für `p1118_demo`, ein Schema, das
 * es nicht mehr gab (`docs/36 §22.3p`). Entsteht der Name später wieder, hätte
 * dieser Zugang sofort alle Rechte darauf, ohne dass sie ihm jemand gegeben
 * hat.
 */
final class DbDatabaseRemove implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.database.remove';
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

        if (! Names::belongsTo($database, $prefix)) {
            throw AgentException::denied(sprintf(
                'Die Datenbank %s gehört nicht zum Abonnement %s.',
                $database,
                $prefix,
            ));
        }

        $accounts = $this->accounts($args['users'] ?? [], $prefix, 'users');
        $staying = $this->accounts($args['revoke'] ?? [], $prefix, 'revoke');

        // **Der Server wird hier nicht verlangt.** `Server::require()` steht vor
        // dem Anlegen und nicht vor dem Entfernen: Wer eine Datenbank auf einem
        // Server loswerden will, dessen Version wir inzwischen für zu alt
        // halten, soll das können. Eine Vorbedingung, die den Rückbau
        // blockiert, hinterlässt genau das, was sie verhindern soll.
        $existed = $this->exists($context, $database);

        $context->progress(30, $existed ? 'Datenbank entfernen' : 'Datenbank ist bereits fort');

        $this->session->execute($context, self::statements($database, $accounts, $staying));

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'removed' => $existed,
            'users_removed' => self::labels($accounts),
            'users_revoked' => self::labels($staying),
        ];
    }

    /**
     * Die Anweisungen — als reine Funktion, aus demselben Grund wie in
     * {@see DbUserGrant::statement()}: Der Schutz ist eine Eigenschaft des
     * erzeugten Textes, und geprüft wird er ohne Datenbank.
     *
     * **Die Reihenfolge ist Rechte, Zugänge, Schema** — die aus `docs/36
     * §22.3e`, und sie zählt, weil `Session::execute()` beim ersten Fehler
     * stehenbleibt: Ein Schema ohne Zugang ist ein Rest ohne Weg dorthin, ein
     * Zugang auf einem noch stehenden Schema ist ein offener Weg zu Daten. Von
     * den beiden Zwischenzuständen nach einem Abbruch ist der erste der
     * harmlosere.
     *
     * @param  list<array{0: string, 1: string}>  $doomed
     * @param  list<array{0: string, 1: string}>  $staying
     * @return list<string>
     */
    public static function statements(string $database, array $doomed, array $staying): array
    {
        $statements = [];

        foreach ($staying as [$user, $host]) {
            $statements[] = DbUserGrant::statement($user, $host, $database, false);
        }

        foreach ($doomed as [$user, $host]) {
            $statements[] = 'DROP USER IF EXISTS '.Sql::account($user, $host);
        }

        $statements[] = 'DROP DATABASE IF EXISTS '.Sql::identifier($database);

        return $statements;
    }

    /**
     * Die Benutzer einer Liste — jeder einzeln geprüft.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function accounts(mixed $value, string $prefix, string $field): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest($field.' muss eine Liste sein.');
        }

        $accounts = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jeder Eintrag in '.$field.' ist eine Ablage aus name und host.');
            }

            $user = Names::existing($entry['name'] ?? null, $field.'.name');

            if (! Names::belongsTo($user, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Der Datenbankbenutzer %s gehört nicht zum Abonnement %s.',
                    $user,
                    $prefix,
                ));
            }

            $accounts[] = [$user, Names::host($entry['host'] ?? 'localhost')];
        }

        return $accounts;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $accounts
     * @return list<string>
     */
    private static function labels(array $accounts): array
    {
        return array_map(
            static fn (array $account): string => $account[0].'@'.$account[1],
            $accounts,
        );
    }

    private function exists(Context $context, string $database): bool
    {
        $rows = $this->session->query(
            $context,
            'SELECT schema_name FROM information_schema.schemata WHERE schema_name = '.Sql::text($database),
        );

        return $rows !== [];
    }
}
