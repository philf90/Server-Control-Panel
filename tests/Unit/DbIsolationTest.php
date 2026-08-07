<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Ops\DbUserCreate;
use SrvPanel\Agent\Ops\DbUserGrant;

/**
 * „Rechte begrenzt" — an den erzeugten Anweisungen geprüft.
 *
 * **Das ist die Vorbedingung des Abnahmekriteriums von P5**: „ein
 * Datenbankbenutzer sieht nachweislich keine fremde Datenbank". Nachgewiesen
 * wird das am Ende auf einem echten Server (`srvpanel acceptance-db`); hier
 * steht, warum es dort gelingen kann, und es ist eine Eigenschaft von drei
 * Zeilen Text.
 *
 * Dieselbe Bauart und derselbe Grund wie bei {@see PhpIsolationTest}: Dieser
 * Container hat keine MariaDB, aber die Anweisung, die er erzeugt, lässt sich
 * lesen.
 *
 * Zwei Dinge dürfen nicht darin vorkommen, und beide aus je einem Grund:
 *
 * - **`*.*`** ist die globale Ebene. Dort stehen `SUPER`, `FILE`, `PROCESS`,
 *   `SHUTDOWN`, `RELOAD` und `CREATE USER` — ein Kunde mit `FILE` liest jede
 *   Datei, die der Datenbankserver lesen darf.
 * - **`WITH GRANT OPTION`** lässt einen Kunden Rechte weiterreichen, und damit
 *   sich selbst welche geben.
 */
final class DbIsolationTest extends TestCase
{
    /** @return list<string> */
    private function creation(string $suffix = 'web', string $host = 'localhost'): array
    {
        return DbUserCreate::statements(
            Names::user('p1001', $suffix),
            $host,
            'Ab12Cd34Ef56Gh78',
            [Names::database('p1001', 'shop'), Names::database('p1001', 'blog')],
        );
    }

    public function test_no_statement_reaches_the_global_level(): void
    {
        foreach ($this->creation() as $statement) {
            $this->assertStringNotContainsString('*.*', $statement, 'Keine Rechtevergabe auf der globalen Ebene.');
            $this->assertStringNotContainsString('ON *', $statement);
        }

        $this->assertStringNotContainsString('*.*', DbUserGrant::statement('p1001_web', 'localhost', 'p1001_shop', true));
        $this->assertStringNotContainsString('*.*', DbUserGrant::statement('p1001_web', 'localhost', 'p1001_shop', false));
    }

    public function test_no_statement_hands_the_grant_option_on(): void
    {
        foreach ($this->creation() as $statement) {
            $this->assertStringNotContainsStringIgnoringCase('GRANT OPTION', $statement);
        }

        $this->assertStringNotContainsStringIgnoringCase(
            'GRANT OPTION',
            DbUserGrant::statement('p1001_web', 'localhost', 'p1001_shop', true),
        );
    }

    /**
     * Jede Rechtevergabe nennt genau eine Datenbank — und maskiert sie.
     *
     * Die Begründung steht in {@see GrantPatternTest}: In `GRANT … ON <db>.*`
     * ist `<db>` ein Muster. Hier wird geprüft, dass die Operation selbst das
     * Ergebnis von {@see Sql::grantTarget()} benutzt und nicht irgendwann eine
     * eigene Zusammensetzung daneben stellt.
     */
    public function test_every_grant_names_exactly_one_escaped_database(): void
    {
        $grants = array_values(array_filter(
            $this->creation(),
            static fn (string $statement): bool => str_starts_with($statement, 'GRANT'),
        ));

        $this->assertCount(2, $grants, 'Zwei Datenbanken, zwei Anweisungen — nicht eine mit einem Muster.');

        foreach ($grants as $statement) {
            $this->assertMatchesRegularExpression('/ ON `p1001\\\\_[a-z]+`\.\* TO /', $statement);
            $this->assertStringNotContainsString('%', $statement);
        }
    }

    /**
     * Der Zugang gilt für einen Wirt und nicht für alle.
     *
     * `'p1001_web'@'localhost'` und nicht `'p1001_web'@'%'`. Die Prüfung
     * dagegen sitzt in {@see Names::host()}; hier steht die Gegenprobe, dass
     * der geprüfte Wert auch tatsächlich in der Anweisung landet — eine
     * Prüfung, deren Ergebnis niemand benutzt, ist keine.
     */
    public function test_the_account_is_bound_to_one_host(): void
    {
        foreach ($this->creation(host: '203.0.113.5') as $statement) {
            $this->assertStringContainsString("'p1001_web'@'203.0.113.5'", $statement);
            $this->assertStringNotContainsString("@'%'", $statement);
        }
    }

    /**
     * Das Passwort steht in der Anweisung und nirgends sonst — auch nicht im
     * Ergebnis.
     *
     * Die zweite Hälfte prüft `SecretsStayOutOfTheQueueTest` am Weg; hier steht die erste: Es gibt genau eine Sorte Anweisung, die es
     * trägt, und das sind `CREATE USER` und `ALTER USER`.
     */
    public function test_only_the_account_statements_carry_the_password(): void
    {
        $carrying = array_values(array_filter(
            $this->creation(),
            static fn (string $statement): bool => str_contains($statement, 'Ab12Cd34Ef56Gh78'),
        ));

        $this->assertCount(2, $carrying);

        foreach ($carrying as $statement) {
            $this->assertMatchesRegularExpression('/^(CREATE|ALTER) USER /', $statement);
        }
    }
}
