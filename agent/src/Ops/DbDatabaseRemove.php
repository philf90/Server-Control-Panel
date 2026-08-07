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

        $accounts = $this->accounts($args['users'] ?? [], $prefix);

        // **Der Server wird hier nicht verlangt.** `Server::require()` steht vor
        // dem Anlegen und nicht vor dem Entfernen: Wer eine Datenbank auf einem
        // Server loswerden will, dessen Version wir inzwischen für zu alt
        // halten, soll das können. Eine Vorbedingung, die den Rückbau
        // blockiert, hinterlässt genau das, was sie verhindern soll.
        $existed = $this->exists($context, $database);

        $context->progress(30, $existed ? 'Datenbank entfernen' : 'Datenbank ist bereits fort');

        $statements = ['DROP DATABASE IF EXISTS '.Sql::identifier($database)];

        foreach ($accounts as [$user, $host]) {
            $statements[] = 'DROP USER IF EXISTS '.Sql::account($user, $host);
        }

        $this->session->execute($context, $statements);

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'removed' => $existed,
            'users_removed' => array_map(
                static fn (array $account): string => $account[0].'@'.$account[1],
                $accounts,
            ),
        ];
    }

    /**
     * Die Benutzer, die mitgehen — jeder einzeln geprüft.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function accounts(mixed $value, string $prefix): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('users muss eine Liste sein.');
        }

        $accounts = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jeder Eintrag in users ist eine Ablage aus name und host.');
            }

            $user = Names::existing($entry['name'] ?? null, 'users.name');

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

    private function exists(Context $context, string $database): bool
    {
        $rows = $this->session->query(
            $context,
            'SELECT schema_name FROM information_schema.schemata WHERE schema_name = '.Sql::text($database),
        );

        return $rows !== [];
    }
}
