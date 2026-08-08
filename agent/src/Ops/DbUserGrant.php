<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Einem bestehenden Benutzer eine Datenbank freigeben oder wieder nehmen.
 *
 * **Ein Paar je Aufruf, kein Abgleich einer ganzen Liste.** Der bequeme Weg
 * wäre gewesen, die gewünschte Menge zu schicken und den Agenten den
 * Unterschied ausrechnen zu lassen. Dann müsste er lesen, was gerade gilt — und
 * damit gäbe es zwei Stellen, die wissen, welcher Benutzer an welcher Datenbank
 * hängt: `mysql.db` und die Zuordnungstabelle des Panels. Die zweite Wahrheit
 * ist die, die veraltet, und hier fiele es erst auf, wenn ein Kunde Rechte hat,
 * die im Panel nicht stehen.
 *
 * Die Anwendung nennt deshalb genau ein Paar und eine Richtung; ihr Bestand ist
 * die Wahrheit, und dieser Aufruf ist die Ausführung. Dieselbe Aufteilung wie
 * bei {@see AcmeCertificateRemove} und {@see DbDatabaseRemove}.
 *
 * **`REVOKE` auf ein Recht, das nicht besteht, ist in MariaDB ein Fehler** —
 * `ERROR 1141`. Damit der Lauf wiederholbar bleibt, steht `IF EXISTS` davor;
 * es gibt die Klausel seit MariaDB 10.1.3 und in MySQL seit 8.0.
 */
final class DbUserGrant implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.user.grant';
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
        $account = Names::existing($args['name'] ?? null, 'name');
        $host = Names::host($args['host'] ?? 'localhost');
        $database = Names::existing($args['database'] ?? null, 'database');
        $granted = Guard::enum($args['mode'] ?? null, ['grant', 'revoke'], 'mode') === 'grant';

        foreach ([[$account, 'Datenbankbenutzer'], [$database, 'Datenbank']] as [$name, $what]) {
            if (! Names::belongsTo($name, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Die %s %s gehört nicht zum Abonnement %s.',
                    $what,
                    $name,
                    $prefix,
                ));
            }
        }

        $context->progress(50, $granted ? 'Recht vergeben' : 'Recht zurücknehmen');

        $this->session->execute($context, [self::statement($account, $host, $database, $granted)]);

        $context->progress(100, 'fertig');

        return [
            'name' => $account,
            'host' => $host,
            'database' => $database,
            'granted' => $granted,
        ];
    }

    /**
     * Die Anweisung — als reine Funktion, aus demselben Grund wie in
     * {@see DbUserCreate::statements()}: Der Schutz ist eine Eigenschaft des
     * erzeugten Textes, und geprüft wird er ohne Datenbank.
     */
    public static function statement(string $account, string $host, string $database, bool $granted): string
    {
        return sprintf(
            $granted
                ? 'GRANT ALL PRIVILEGES ON %s TO %s'
                : 'REVOKE IF EXISTS ALL PRIVILEGES ON %s FROM %s',
            Sql::grantTarget($database),
            Sql::account($account, $host),
        );
    }
}
