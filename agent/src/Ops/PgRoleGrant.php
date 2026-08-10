<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Owner;
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
 * selbst welche geben. Kein `SUPERUSER`, `CREATEDB`, `CREATEROLE`,
 * `REPLICATION`, `BYPASSRLS`, und **keine Mitgliedschaft in einer fremden
 * Rolle**. `PgGrantTest` liest die erzeugten Anweisungen als Text und besteht
 * darauf.
 *
 * ## Eine vierte Ebene ist dazugekommen: als wer der Zugang arbeitet
 *
 * Hier stand, es gebe in dieser Datei „kein `ALTER ROLE` und keine Rolle als
 * Mitglied einer anderen". Das ist seit der Eigentümerrolle nicht mehr wahr,
 * und der Satz hat die Lücke gedeckt, die er beschrieb: **Die drei Ebenen
 * reichten nicht.** Rechte lösen nicht, wem eine Tabelle gehört, und ein
 * zweiter Zugang bekam `permission denied for table` auf alles, was der erste
 * angelegt hatte (gemessen, `docs/39` Punkt 7).
 *
 * {@see Owner} kommt deshalb dazu — als Mitgliedschaft im **eigenen**
 * Abonnement und einer Sitzungsrolle je Datenbank. Die Grenze verschiebt das
 * nicht: Jeder Name, der hier durchkommt, trägt das Präfix des Abonnements, in
 * dessen Auftrag die Operation läuft, und {@see self::owned()} weist alles
 * andere ab.
 */
final class PgRoleGrant implements Op
{
    public function __construct(
        private readonly Session $session = new Session,
        private readonly Owner $owner = new Owner,
    ) {}

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
         *
         * **Die Sitzungsrolle wechselt mit der Freigabe, die Mitgliedschaft
         * nicht.** Sie gilt clusterweit und hängt am Zugang
         * ({@see PgRoleCreate}); was hier umgestellt wird, ist die Frage, *als
         * wer* dieser Zugang in **dieser** Datenbank arbeitet. Ein Entzug, der
         * die Mitgliedschaft nähme, träfe die übrigen Datenbanken desselben
         * Abonnements mit.
         *
         * `ensure()` steht hier, weil eine Freigabe auch ein Abonnement
         * erreichen kann, das vor dieser Fassung entstanden ist — dieselbe
         * Regel wie in {@see PgRestore}: *Was eine Operation braucht, stellt sie
         * selbst sicher.*
         */
        $owner = $this->owner->ensure($context, $prefix);

        $this->session->execute($context, [
            self::databaseStatement($role, $database, $granted),
            Owner::sessionRole($owner, $role, $database, $granted),
        ]);
        $this->session->execute($context, self::schemaStatements($role, $granted), $database);

        if ($granted) {
            $this->owner->adopt($context, $prefix, $database);
        }

        $context->progress(100, 'fertig');

        return ['name' => $role, 'database' => $database, 'granted' => $granted, 'owner' => $owner];
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
     * ## Hier stand `ALTER DEFAULT PRIVILEGES`, und der Kommentar daneben war
     * falsch
     *
     * Er sagte, diese Zeile löse das Problem des zweiten Zugangs: Ohne sie habe
     * er keine Rechte an den Tabellen, die der erste anlegt. **Gemessen am
     * 10. August 2026 tat sie das nicht** — `x_cron` bekam `permission denied
     * for table` auf alles von `x_web`. Der Grund steht im Handbuch und ist
     * leicht zu überlesen: `ALTER DEFAULT PRIVILEGES` **ohne `FOR ROLE` gilt nur
     * für Objekte, die die ausführende Rolle anlegt** — und das ist hier der
     * Agent. Für Tabellen eines Kunden hat sie nie gegolten.
     *
     * > **Ein Kommentar, der eine Wirkung behauptet, ist keine Messung.** Er hat
     * > zwei Fassungen lang wie eine ausgesehen.
     *
     * Was das Problem wirklich löst, ist {@see Owner}: Alle Zugänge eines
     * Abonnements laufen als dieselbe Rolle, und was einer anlegt, gehört ihr.
     *
     * ## Was hier trotzdem bleibt, und warum
     *
     * Die drei `GRANT`-Zeilen — als **zweiter Boden**, nicht als Mechanik. Was
     * einem einzelnen Zugang noch gehört, holt {@see Owner::adoption()} mit
     * einem `REASSIGN OWNED BY` ins Abonnement; die Rechte hier greifen
     * dazwischen und für alles, was ausserhalb von `public` liegt.
     *
     * **Und die Rücknahme nimmt mehr weg, als diese Fassung vergibt.** Die zwei
     * `ALTER DEFAULT PRIVILEGES … REVOKE` bleiben stehen, obwohl es die
     * Gegenstücke nicht mehr gibt: Auf jedem Server, der von einer früheren
     * Fassung stammt, stehen sie noch in `pg_default_acl`. Wer etwas nicht mehr
     * anlegt, baut den Weg zurück trotzdem — sonst räumt es niemand mehr weg.
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
