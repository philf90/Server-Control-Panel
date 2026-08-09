<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Shielding;
use SrvPanel\Agent\Pg\Sql;

/**
 * Einer Rolle eine Datenbank freigeben — oder sie ihr nehmen.
 *
 * ## `GRANT ALL ON DATABASE` reicht in PostgreSQL nicht
 *
 * **Das ist der Unterschied, den `docs/36 §14` angekündigt hat**, und er ist
 * der Grund, warum die Isolationszusage aus P5 hier neu bewiesen wird statt
 * übertragen. In MariaDB gibt `GRANT ALL PRIVILEGES ON schema.*` alles, was ein
 * Kunde braucht: Tabellen anlegen, lesen, schreiben. In PostgreSQL sind das
 * **drei verschiedene Ebenen**, und die Rechte auf der Datenbank sind die
 * schwächste davon:
 *
 * | Ebene | was sie erlaubt |
 * |---|---|
 * | `DATABASE` | sich verbinden, temporäre Tabellen anlegen |
 * | `SCHEMA` | in `public` Objekte anlegen und sie sehen |
 * | die Objekte selbst | lesen und schreiben |
 *
 * Wer nur die erste vergibt, hat einen Kunden, der sich verbindet und nichts
 * tun kann. Deshalb stehen hier drei Anweisungen, wo P5 eine hat.
 *
 * **`CONNECT` wird ausdrücklich vergeben und nicht vorausgesetzt.** Die
 * Absperrung in {@see Shielding} nimmt sie `PUBLIC` weg —
 * ohne diese Zeile käme niemand mehr an seine eigene Datenbank.
 *
 * ## Es gibt keine Unterstrich-Falle
 *
 * `docs/36 §3.1` ist der teuerste Fund jenes Entwurfs: In MariaDB ist
 * ``GRANT … ON `p1001_%`.*`` ein Muster, `_` trifft ein beliebiges Zeichen, und
 * der Name muss maskiert werden. **In PostgreSQL ist das Ziel ein Bezeichner.**
 * Gemessen: `GRANT CONNECT ON DATABASE m29_a` gibt Zugang zu `m29_a` und nicht
 * zu `m29xa`. Es gibt hier deshalb kein Gegenstück zu `Db\Sql::grantTarget()`,
 * und das ist ein Befund und keine Auslassung.
 *
 * ## Was nie vergeben wird
 *
 * `WITH GRANT OPTION` — ein Kunde, der Rechte weiterreichen darf, kann sich
 * selbst welche geben. Und nichts auf Clusterebene: Es gibt in dieser Datei
 * kein `ALTER ROLE`, keine Rolle als Mitglied einer anderen und kein
 * `SUPERUSER`. `PgGrantTest` liest die erzeugten Anweisungen als Text und
 * besteht darauf.
 */
final class PgRoleGrant implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'pg.role.grant';
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
        $prefix = Names::prefix($args['prefix'] ?? null);
        $role = self::owned($args['name'] ?? null, $prefix, 'name');
        $database = self::owned($args['database'] ?? null, $prefix, 'database');
        $granted = Guard::enum($args['mode'] ?? null, ['grant', 'revoke'], 'mode') === 'grant';

        $context->progress(40, $granted ? 'Datenbank freigeben' : 'Freigabe zurücknehmen');

        /*
         * **Die Rechte auf der Datenbank in `postgres`, die im Schema in ihr.**
         * `GRANT … ON DATABASE` ist eine clusterweite Angabe und lässt sich von
         * überall setzen; `GRANT … ON SCHEMA public` gilt für das Schema *der
         * verbundenen Datenbank* und muss deshalb dort laufen. Wer beides in
         * einem Lauf schickt, berechtigt am Schema `public` der falschen
         * Datenbank — und das fiele erst auf, wenn ein Kunde seine Tabellen
         * nicht sieht.
         */
        $this->session->execute($context, [self::databaseStatement($role, $database, $granted)]);
        $this->session->execute($context, self::schemaStatements($role, $granted), $database);

        $context->progress(100, 'fertig');

        return ['name' => $role, 'database' => $database, 'granted' => $granted];
    }

    /** Die Anweisung auf der Datenbank selbst. */
    public static function databaseStatement(string $role, string $database, bool $granted): string
    {
        return sprintf(
            $granted ? 'GRANT CONNECT ON DATABASE %s TO %s' : 'REVOKE ALL ON DATABASE %s FROM %s',
            Sql::identifier($database),
            Sql::identifier($role),
        );
    }

    /**
     * Und die im Schema — sie laufen **in** der Datenbank.
     *
     * `ALTER DEFAULT PRIVILEGES` ist die Zeile, die es in MariaDB nicht gibt:
     * Ohne sie hätte ein zweiter Zugang desselben Abonnements keine Rechte an
     * den Tabellen, die der erste anlegt. In MariaDB gilt ein Schemarecht für
     * alles, was im Schema entsteht; in PostgreSQL gehört jede Tabelle dem, der
     * sie angelegt hat, und die Rechte daran werden **beim Anlegen** vergeben.
     *
     * @return list<string>
     */
    public static function schemaStatements(string $role, bool $granted): array
    {
        $name = Sql::identifier($role);

        if (! $granted) {
            return [
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM '.$name,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM '.$name,
                'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM '.$name,
                'REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM '.$name,
                'REVOKE ALL ON SCHEMA public FROM '.$name,
            ];
        }

        return [
            'GRANT ALL ON SCHEMA public TO '.$name,
            'GRANT ALL ON ALL TABLES IN SCHEMA public TO '.$name,
            'GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO '.$name,
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO '.$name,
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO '.$name,
        ];
    }

    private static function owned(mixed $value, string $prefix, string $field): string
    {
        $name = Names::existing($value, $field);

        if (! Names::belongsTo($name, $prefix)) {
            throw AgentException::denied(sprintf('%s gehört nicht zum Abonnement %s.', $name, $prefix));
        }

        return $name;
    }
}
