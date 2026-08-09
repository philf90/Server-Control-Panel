<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\PgUsage;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Shielding;
use Tests\Support\WithoutPhpComments;

/**
 * Die Absperrung einer neuen Datenbank — als Text geprüft.
 *
 * Derselbe Zuschnitt wie {@see DbIsolationTest}, `SiteTemplateTest` und
 * `PhpIsolationTest`: *Der Schutz ist eine Eigenschaft der erzeugten
 * Zeichenkette.* Dass er im Betrieb wirkt, kann nur ein Lauf gegen einen
 * echten Server sagen — der steht im Abnahmelauf (`docs/38 §19`, Schritt 3 und
 * 3b). Was hier gemessen wird, ist die Voraussetzung dafür.
 */
final class PgShieldingTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Elf Sichten führen einen Datenbanknamen, und sie heissen je nach Fassung
     * anders — das ist die Antwort, die ein PostgreSQL 16.13 gegeben hat.
     *
     * @return list<string>
     */
    private function channels(): array
    {
        return [
            'pg_database',
            'pg_hba_file_rules',
            'pg_locks',
            'pg_prepared_xacts',
            'pg_replication_slots',
            'pg_stat_activity',
            'pg_stat_database',
            'pg_stat_database_conflicts',
            'pg_stat_progress_analyze',
            'pg_stat_progress_cluster',
            'pg_stat_progress_copy',
            'pg_stat_progress_create_index',
            'pg_stat_progress_vacuum',
        ];
    }

    private function statements(): string
    {
        return implode("\n", Shielding::statements(Names::database(Names::newPrefix(), 'shop'), $this->channels()));
    }

    /**
     * Die Verbindung wird PUBLIC genommen.
     *
     * Diese eine Zeile trägt zwei Lasten: die Namenssichtbarkeit **und** die
     * Eindämmung des Zurückspielens. Ein `\connect fremde_datenbank`, das ein
     * Klartext-Dump von `pg_dump` regulär enthält, scheitert genau hier
     * (gemessen, `docs/38 §2.2` M8).
     */
    public function test_the_connection_is_taken_from_public(): void
    {
        $database = Names::database(Names::newPrefix(), 'shop');

        $this->assertContains(
            'REVOKE CONNECT ON DATABASE "'.$database.'" FROM PUBLIC',
            Shielding::statements($database, $this->channels()),
        );
    }

    /**
     * Und das Schema `public` ebenso — auch wo die Fassung es von selbst tut.
     *
     * Bis PostgreSQL 14 darf `PUBLIC` dort anlegen; Ubuntu 22.04 liefert eine
     * solche Fassung. Ab PG 15 ist der Entzug die Vorgabe, und dann ändert die
     * Zeile nichts. **Sie steht trotzdem da, weil eine Zusage, die von einer
     * Vorgabe abhängt, keine ist** — dieselbe Begründung wie bei der Maskierung
     * in `Db\Sql::grantTarget()`.
     */
    public function test_the_public_schema_is_closed_on_every_version(): void
    {
        $this->assertStringContainsString('REVOKE ALL ON SCHEMA public FROM PUBLIC', $this->statements());
    }

    /**
     * Jeder erfragte Kanal wird geschlossen — ausser den begründeten.
     */
    public function test_every_channel_is_closed(): void
    {
        $statements = $this->statements();
        $closed = 0;

        foreach ($this->channels() as $channel) {
            if (isset(Shielding::EXEMPT[$channel])) {
                continue;
            }

            $this->assertStringContainsString(
                'REVOKE SELECT ON "pg_catalog"."'.$channel.'" FROM PUBLIC',
                $statements,
                sprintf('%s bleibt offen — das ist ein Kanal, der Namen führt.', $channel),
            );

            $closed++;
        }

        // Die Untergrenze zählt, wo die Regel stehen darf: Fällt eine
        // Fortschrittssicht einer Fassung weg, soll das hier kein Rot geben.
        $this->assertGreaterThan(5, $closed, 'Es wird kaum etwas geschlossen — dann prüft dieser Test nichts.');
    }

    /**
     * `pg_database` bleibt offen, und zwar mit Grund.
     *
     * **Das ist die Gegenprobe zum Abnahmekriterium und keine Nachlässigkeit.**
     * Der Entzug wäre möglich und schlösse das Aufzählen vollständig — er nimmt
     * dem Kunden aber `pg_dump`, auch für den schlichten Export seiner eigenen
     * Datenbank (gemessen, `docs/38 §2.2` M6). Wer diese Zeile eines Tages
     * streicht, soll hier stolpern und den Grund lesen, statt es zu bemerken,
     * wenn ein Kunde seine Sicherung nicht mehr ziehen kann.
     */
    public function test_pg_database_stays_readable_with_a_reason(): void
    {
        $this->assertStringNotContainsString('"pg_database"', $this->statements());

        $this->assertArrayHasKey('pg_database', Shielding::EXEMPT);
        $this->assertStringContainsString('pg_dump', Shielding::EXEMPT['pg_database']);
    }

    /**
     * Die Liste der Kanäle wird **erfragt** und nicht verdrahtet.
     *
     * Sie ist fassungsabhängig — PostgreSQL 17 hat mehr `pg_stat_progress_*` als
     * 14, und die Zielplattformen spannen 14 bis 17. Eine feste Liste wäre auf
     * der nächsten Fassung unvollständig, und unvollständig heisst hier: ein
     * offener Kanal, den niemand bemerkt.
     *
     * Geprüft wird deshalb der Quelltext: Ausser den begründeten Ausnahmen darf
     * dort kein Sichtname stehen. Die Abfrage sucht nach der **Eigenschaft** —
     * einer Spalte `datname` oder `database` —, und das ist genau dieselbe
     * Eigenschaft, die den Kanal ausmacht.
     *
     * **Ohne Kommentare, und das hat dieser Test beim ersten Lauf selbst
     * gelernt.** Er war rot — auf den Klassenkopf von {@see Shielding}, der
     * `pg_stat_database` als Beispiel nennt, um zu erklären, warum die Liste
     * erfragt wird. Ein Wächter, der verbietet, seine eigene Regel zu erklären,
     * wird umformuliert statt befolgt. {@see WithoutPhpComments} beantwortet
     * die Frage über `token_get_all()` und nicht über einen Ausdruck.
     */
    public function test_the_channels_are_asked_for_and_not_written_down(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Pg/Shielding.php'),
        );

        // Die Ausnahmen und ihre Begründungen stehen dort und dürfen es.
        foreach (array_keys(Shielding::EXEMPT) as $exempt) {
            $source = str_replace($exempt, '', $source);
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\bpg_stat_\w+/',
            $source,
            'In Shielding steht ein Sichtname — die Liste gehört erfragt, sonst ist sie auf der '
            .'nächsten Fassung von PostgreSQL unvollständig.',
        );

        $this->assertStringContainsString("attname IN ('datname', 'database')", Shielding::DISCOVERY);
    }

    /**
     * Nichts davon steht unangeführt in einer Anweisung.
     */
    public function test_every_identifier_is_quoted(): void
    {
        foreach (Shielding::statements(Names::database(Names::newPrefix(), 'shop'), $this->channels()) as $statement) {
            if (str_contains($statement, 'SCHEMA public')) {
                // `public` ist ein Schlüsselwort in dieser Anweisung und kein
                // Bezeichner, den jemand von aussen wählt.
                continue;
            }

            $this->assertMatchesRegularExpression('/"[^"]+"/', $statement, $statement.' führt keinen Bezeichner an.');
        }
    }

    /**
     * Die Messung nennt nur, was dem Panel gehört.
     *
     * **Der Fehlschlag, den das verhindert, ist kein Absturz, sondern eine
     * Zahl.** `pg.usage` fragt alle Datenbanken des Clusters — auch die des
     * Betreibers und die Vorlagen. Käme davon etwas durch, stünde die Grösse
     * fremder Daten in der Antwort des Agenten, und von dort ginge sie an
     * `Usage` und in einen Kundenbericht.
     *
     * Ausgesondert wird über {@see Names::isPanelName()}
     * und nicht über einen zweiten regulären Ausdruck in der Abfrage. Der Plan
     * (`docs/38 §12`) schreibt ihn dort hin; das wäre die zweite Fassung
     * desselben Musters, und die zweite ist die, die veraltet.
     */
    public function test_the_measurement_only_names_what_belongs_to_the_panel(): void
    {
        $rows = [
            ['postgres', '8000000'],
            ['template1', '7000000'],
            ['xdeadbeefdeadbeef_shop', '1234'],
            ['betreiber_eigenes', '9999'],
            ['xdeadbeefdeadbeef_r1a2b3c4d', '42'],
        ];

        $gemessen = PgUsage::parse($rows);

        /*
         * **Der befristete Name bleibt drin, und das ist eine Berichtigung.**
         * Dieser Wächter erwartete zuerst, dass `…_r1a2b3c4d` — die Datenbank,
         * die ein Zurückspielen anlegt — herausfällt. Das war meine Annahme und
         * keine Regel: Sie gehört dem Panel, belegt Platz, und im Bestand gibt
         * es keine Zeile, auf die sie passt — sie läuft also ins Leere und
         * kostet einen Eintrag in einer Ablage.
         *
         * Worum es hier geht, ist die andere Richtung: `postgres`, `template1`
         * und die Datenbank des Betreibers dürfen **nicht** in der Antwort
         * stehen. Ihre Grösse ginge sonst an `Usage` und von dort in einen
         * Kundenbericht.
         */
        $this->assertSame([
            'xdeadbeefdeadbeef_shop' => 1234,
            'xdeadbeefdeadbeef_r1a2b3c4d' => 42,
        ], $gemessen);
    }
}
