<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\PgDatabaseRemove;
use SrvPanel\Agent\Ops\PgRoleCreate;
use SrvPanel\Agent\Ops\PgRoleGrant;
use SrvPanel\Agent\Ops\PgRoleLock;
use SrvPanel\Agent\Pg\Names;

/**
 * Was eine Rolle bekommt — und was sie nie bekommt.
 *
 * Das Gegenstück zu {@see DbIsolationTest} für PostgreSQL, und wie dort eine
 * **Textprüfung**: *Der Schutz ist eine Eigenschaft der erzeugten
 * Zeichenkette.* Anders als in P5 gibt es hier zwar einen echten Server zum
 * Gegenprüfen — aber nicht in der CI, und ein Wächter, der eine Datenbank
 * braucht, ist auf dem Rechner, auf dem er zubeissen soll, keiner.
 *
 * **Drei Ebenen statt einer.** `GRANT ALL ON DATABASE` erlaubt in PostgreSQL
 * kein Lesen der Tabellen; das läuft über `SCHEMA` und `ALTER DEFAULT
 * PRIVILEGES`. `docs/36 §14` hat genau das als den Grund genannt, warum die
 * Isolationszusage von P5 hier **neu bewiesen** werden muss statt übertragen.
 */
final class PgGrantTest extends TestCase
{
    private function prefix(): string
    {
        return Names::newPrefix();
    }

    /**
     * Alle Anweisungen, die eine Rolle mit Rechten versorgen — an einer Stelle.
     *
     * @return list<string>
     */
    private function grantingStatements(): array
    {
        $prefix = $this->prefix();
        $role = Names::role($prefix, 'web');
        $database = Names::database($prefix, 'shop');

        return array_merge(
            [PgRoleCreate::statement($role, 'geheimesPasswort123', false)],
            [PgRoleCreate::statement($role, 'geheimesPasswort123', true)],
            [PgRoleGrant::databaseStatement($role, $database, true)],
            PgRoleGrant::schemaStatements($role, true),
        );
    }

    /**
     * Keine Rechte über die Datenbank hinaus.
     *
     * `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS` sind
     * Eigenschaften der **Rolle** und gelten clusterweit — sie sind das
     * Gegenstück zu `*.*` in MariaDB. `CREATEDB` ist dabei der, den man
     * versehentlich vergibt: Ein Kunde legte damit Datenbanken an, die im
     * Bestand des Panels fehlen und deren Absperrung nie gelaufen ist.
     */
    public function test_no_statement_grants_anything_cluster_wide(): void
    {
        $forbidden = [
            ' SUPERUSER',
            ' CREATEDB',
            ' CREATEROLE',
            ' REPLICATION',
            ' BYPASSRLS',
            'pg_read_server_files',
            'pg_execute_server_program',
            'pg_write_server_files',
        ];

        foreach ($this->grantingStatements() as $statement) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    // `NOSUPERUSER` enthält `SUPERUSER` — geprüft wird das
                    // Recht und nicht seine Verneinung, also fällt das `NO`
                    // vorher weg.
                    str_replace(['NOSUPERUSER', 'NOCREATEDB', 'NOCREATEROLE', 'NOREPLICATION', 'NOBYPASSRLS'], '', $statement),
                    sprintf('Diese Anweisung vergibt %s: %s', trim($needle), $statement),
                );
            }
        }
    }

    /**
     * Und nichts wird weitergereicht.
     *
     * `WITH GRANT OPTION` in PostgreSQL, `WITH ADMIN OPTION` für Rollen, und
     * eine Mitgliedschaft über `IN ROLE` — jeder der drei Wege macht aus einem
     * Kunden jemanden, der sich selbst Rechte geben kann.
     */
    public function test_nothing_is_passed_on(): void
    {
        foreach ($this->grantingStatements() as $statement) {
            foreach (['GRANT OPTION', 'ADMIN OPTION', ' IN ROLE', ' ROLE ADMIN'] as $needle) {
                $this->assertStringNotContainsString($needle, $statement, sprintf(
                    'Diese Anweisung reicht Rechte weiter: %s',
                    $statement,
                ));
            }
        }
    }

    /**
     * Eine neue Rolle sagt ausdrücklich, was sie nicht ist.
     *
     * **Auch wenn PostgreSQL es ohnehin nicht vergibt.** Eine Zusage, die von
     * einer Vorgabe abhängt, ist keine — dieselbe Begründung wie beim
     * `REVOKE ALL ON SCHEMA public` in `Pg\Shielding`, das ab PostgreSQL 15
     * nichts mehr ändert und trotzdem dasteht.
     */
    public function test_a_new_role_declares_what_it_is_not(): void
    {
        $statement = PgRoleCreate::statement(Names::role($this->prefix(), 'web'), 'geheimesPasswort123', false);

        foreach (['NOSUPERUSER', 'NOCREATEDB', 'NOCREATEROLE', 'NOREPLICATION', 'NOBYPASSRLS'] as $expected) {
            $this->assertStringContainsString($expected, $statement);
        }
    }

    /**
     * Die Freigabe deckt alle drei Ebenen.
     *
     * Wer nur die Datenbank freigibt, hat einen Kunden, der sich verbindet und
     * nichts tun kann — und wer `ALTER DEFAULT PRIVILEGES` vergisst, hat einen
     * zweiten Zugang, der die Tabellen des ersten nicht sieht.
     */
    public function test_a_grant_reaches_database_schema_and_objects(): void
    {
        $prefix = $this->prefix();
        $role = Names::role($prefix, 'web');
        $schema = implode("\n", PgRoleGrant::schemaStatements($role, true));

        $this->assertStringContainsString(
            'GRANT CONNECT ON DATABASE',
            PgRoleGrant::databaseStatement($role, Names::database($prefix, 'shop'), true),
        );
        $this->assertStringContainsString('GRANT ALL ON SCHEMA public TO', $schema);
        $this->assertStringContainsString('GRANT ALL ON ALL TABLES IN SCHEMA public TO', $schema);
        $this->assertStringContainsString('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO', $schema);
    }

    /**
     * Und die Rücknahme nimmt dasselbe wieder weg.
     *
     * **Beide Richtungen, und zwar auf denselben Ebenen.** Eine Rücknahme, die
     * eine Ebene auslässt, ist der Zustand, in dem ein entzogener Zugang die
     * Tabellen weiter liest — und `ALTER DEFAULT PRIVILEGES` ist die Ebene, die
     * man dabei übersieht, weil sie erst für Objekte gilt, die es noch nicht
     * gibt.
     */
    public function test_a_revoke_reaches_the_same_three_levels(): void
    {
        $schema = implode("\n", PgRoleGrant::schemaStatements(Names::role($this->prefix(), 'web'), false));

        $this->assertStringContainsString('REVOKE ALL ON SCHEMA public FROM', $schema);
        $this->assertStringContainsString('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM', $schema);
        $this->assertStringContainsString('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM', $schema);
    }

    /**
     * Der Rückbau nimmt offene Verbindungen mit.
     *
     * **Ohne `WITH (FORCE)` scheitert er an jedem Kunden mit einem
     * Verbindungspool** — gemessen am 9. August 2026: *ERROR: database "probe"
     * is being accessed by other users.* MariaDB kennt das nicht. Ein Rückbau,
     * der davon abhängt, ob gerade jemand verbunden ist, ist keiner.
     */
    public function test_dropping_a_database_does_not_wait_for_idle_connections(): void
    {
        $this->assertStringContainsString(
            'WITH (FORCE)',
            PgDatabaseRemove::statement(Names::database($this->prefix(), 'shop')),
        );
    }

    /**
     * Die Datenbank geht **vor** den Rollen, und das ist die ganze Regel.
     *
     * **Gemessen am 9. August 2026, und die Messung hat die Bauform
     * umgeworfen.** `DROP ROLE` verweigert, solange eine Rolle etwas besitzt —
     * also müsste man erst aufräumen. Für die Rollen, die *mit dieser
     * Datenbank* verschwinden, ist das gegenstandslos:
     *
     *     vor  DROP DATABASE   pg_shdepend: 3 Zeilen für die Rolle
     *     nach DROP DATABASE   pg_shdepend: 0 Zeilen
     *     DROP ROLE ohne DROP OWNED BY → geht
     *
     * `DROP DATABASE` nimmt alles mit, was in ihr wurzelt. Umgekehrt — Rollen
     * zuerst — verweigerte PostgreSQL, und ein Rückbau in zwei Vorgängen liesse
     * bei einem Abbruch die Daten ohne Zugang stehen.
     *
     * Der Wächter liest die Reihenfolge im Quelltext, weil sie sich sonst nur
     * gegen einen laufenden Server prüfen liesse — und in der CI gibt es keinen.
     */
    public function test_the_database_goes_before_its_roles(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/PgDatabaseRemove.php',
        );

        $datenbank = strpos($source, '$this->session->execute($context, [self::statement($database)])');
        $rollen = strpos($source, 'PgRoleRemove::statement($role)');

        $this->assertNotFalse($datenbank, 'Das DROP DATABASE steht nicht mehr da.');
        $this->assertNotFalse($rollen, 'Das DROP ROLE steht nicht mehr da.');

        $this->assertLessThan(
            $rollen,
            $datenbank,
            'Die Rollen werden vor der Datenbank geworfen. PostgreSQL weist das ab, solange sie noch '
            .'etwas darin besitzen — und in zwei Vorgängen getrennt hiesse: Bricht das DROP DATABASE '
            .'ab, sind die Zugänge fort und die Daten da.',
        );
    }

    /**
     * Und es gibt keine zweite Liste für die Rollen, die bleiben.
     *
     * **In MariaDB gibt es sie**, und sie hat einen Anlass: Eine Rechtezeile in
     * `mysql.db` überlebt ihr Schema (`docs/36 §22.3p`). In PostgreSQL liegt
     * dasselbe Recht in `pg_database.datacl` und geht mit der Datenbank —
     * gemessen. Eine `revoke`-Liste hier wäre Arbeit für einen Zustand, den es
     * nicht gibt, und sie sähe aus wie eine Zusage.
     */
    public function test_no_second_list_for_the_roles_that_stay(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/PgDatabaseRemove.php',
        );

        $this->assertStringNotContainsString("\$args['revoke']", $source);
    }

    /**
     * Die Sperre ist umkehrbar und lässt die Daten stehen.
     */
    public function test_the_lock_is_a_login_switch_and_nothing_else(): void
    {
        $role = Names::role($this->prefix(), 'web');

        $this->assertSame('ALTER ROLE "'.$role.'" NOLOGIN', PgRoleLock::statement($role, true));
        $this->assertSame('ALTER ROLE "'.$role.'" LOGIN', PgRoleLock::statement($role, false));
    }
}
