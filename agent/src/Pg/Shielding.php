<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

/**
 * Die Absperrung einer neu angelegten Datenbank.
 *
 * **Das ist das Abnahmekriterium von P5b als Zeichenkette** (`docs/38 §3`), und
 * es ist eine eigene Klasse, weil drei der vier Zeilen darin Befunde sind und
 * keine Formsache. Geprüft wird sie als Text (`PgShieldingTest`) — derselbe
 * Zuschnitt wie `SiteTemplateTest` und `PhpIsolationTest`: *Der Schutz ist eine
 * Eigenschaft der erzeugten Zeichenkette.*
 *
 * ## Warum das je Datenbank läuft und nicht einmal je Cluster
 *
 * **Der teuerste Fund der Messung vom 9. August 2026** (`docs/38 §2.2`, M2b).
 * `pg_database` ist ein geteilter Katalog, seine Rechte stehen aber in
 * `pg_class` — und das ist je Datenbank. Eine Absperrung, die einmal gesetzt
 * wird, ist beim nächsten `CREATE DATABASE … TEMPLATE template0` wieder fort,
 * und `template0` ist **Pflicht**, sobald eine Sortierung gesetzt wird.
 *
 * Gemessen sah das so aus: dieselbe Rolle sah in der einen Datenbank nichts und
 * in der nächsten sieben Namen. Von aussen sahen beide gleich aus. Deshalb
 * gehören diese Anweisungen in dieselbe Operation wie das Anlegen und nicht in
 * ein Einrichtungsskript.
 *
 * ## Warum die Sichten erfragt und nicht verdrahtet werden
 *
 * Elf Katalogsichten führen einen Datenbanknamen und sind für jeden lesbar —
 * `pg_stat_database` nennt *alle* Datenbanken, auch die ohne jede Aktivität.
 * **Welche elf, hängt von der Fassung ab:** PostgreSQL 17 hat mehr
 * `pg_stat_progress_*` als 14, und die vier Zielplattformen spannen 14 bis 17.
 *
 * Eine feste Liste wäre auf der nächsten Fassung unvollständig, und
 * unvollständig hiesse hier: ein offener Kanal, den niemand bemerkt. Gefragt
 * wird deshalb der Katalog selbst — {@see self::DISCOVERY} —, und zwar nach
 * derselben Eigenschaft, die den Kanal ausmacht: eine Spalte, die einen
 * Datenbanknamen führt.
 *
 * ## Was ausdrücklich **nicht** entzogen wird
 *
 * `pg_database` selbst. Der Entzug wäre möglich und schlösse das Aufzählen
 * vollständig — er nimmt dem **Kunden** aber `pg_dump`, und zwar nicht nur mit
 * `--create`, sondern für den schlichten Export seiner eigenen Datenbank
 * (gemessen, M6). Ein Panel, das die Abschottung durchsetzt, indem es dem
 * Kunden das Sicherungswerkzeug nimmt, hat einen Sicherheitsgewinn gegen einen
 * Datenverlust getauscht. Die Namen sagen statt dessen nichts ({@see Names}).
 */
final class Shielding
{
    /**
     * Die Frage nach den Kanälen, die einen Datenbanknamen führen.
     *
     * Gesucht wird nach der **Eigenschaft** und nicht nach Namen: jede Tabelle
     * oder Sicht in `pg_catalog`, die eine Spalte `datname` oder `database`
     * hat. Genau dieser Ausdruck hat die elf gefunden, von denen im Plan die
     * Rede ist.
     *
     * `pg_database` steht in der Antwort mit drin und wird von
     * {@see self::EXEMPT} ausgenommen — nicht aus dem Ausdruck herausgehalten.
     * Der Unterschied ist der zwischen „wir sehen den Kanal und lassen ihn
     * offen" und „wir sehen ihn nicht".
     */
    public const DISCOVERY = <<<'SQL'
        SELECT c.relname
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
          JOIN pg_attribute a ON a.attrelid = c.oid
         WHERE n.nspname = 'pg_catalog'
           AND a.attname IN ('datname', 'database')
           AND c.relkind IN ('r', 'v')
         GROUP BY c.relname
         ORDER BY c.relname
        SQL;

    /**
     * Kanäle, die offen bleiben — mit ihrem Grund.
     *
     * Der Grund steht **im Wert** und nicht in einem Kommentar daneben:
     * dieselbe Form wie `RemovalPathTest::WITHOUT_REMOVAL`. Eine Liste ohne
     * Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'pg_database' => 'Der Entzug nähme dem Kunden pg_dump, auch für seine eigene Datenbank '
            .'(docs/38 §2.2, M6). Die Namen verraten statt dessen nichts (docs/38 §4).',
        'pg_hba_file_rules' => 'Ist ohnehin nur für Superuser lesbar — gemessen. Ein REVOKE darauf '
            .'wäre eine Anweisung, die nichts ändert, und damit eine Zeile, die eine Regel behauptet.',
    ];

    /**
     * Die Anweisungen für eine frisch angelegte Datenbank.
     *
     * Sie laufen **in** dieser Datenbank, nicht in `postgres` — die Rechte auf
     * die Katalogsichten stehen je Datenbank, siehe Klassenkopf.
     *
     * @param  list<string>  $channels  Was {@see self::DISCOVERY} geliefert hat
     * @return list<string>
     */
    public static function statements(string $database, array $channels): array
    {
        $statements = [
            // Nimmt die Verbindung. Trägt in P5b zwei Lasten: die
            // Namenssichtbarkeit und die Eindämmung des Zurückspielens — ein
            // `\connect` in einem mitgebrachten Dump scheitert genau hier
            // (docs/38 §13.4).
            'REVOKE CONNECT ON DATABASE '.Sql::identifier($database).' FROM PUBLIC',

            // **Bis PostgreSQL 14 darf PUBLIC im Schema `public` anlegen.**
            // Ubuntu 22.04 liefert eine solche Fassung. Ab PG 15 ist es die
            // Vorgabe, und dann ändert diese Zeile nichts — sie steht trotzdem
            // da, weil eine Zusage, die von einer Vorgabe abhängt, keine ist.
            'REVOKE ALL ON SCHEMA public FROM PUBLIC',
        ];

        foreach ($channels as $channel) {
            if (isset(self::EXEMPT[$channel])) {
                continue;
            }

            $statements[] = 'REVOKE SELECT ON '.Sql::qualified('pg_catalog', $channel).' FROM PUBLIC';
        }

        return $statements;
    }
}
