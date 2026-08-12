<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Console as DbConsole;
use SrvPanel\Agent\Pg\Console as PgConsole;

/**
 * Wie ein Ergebnis über die Leitung kommt — die beiden Funde aus Schritt 0.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Beide Regeln hier stehen für einen Fehler, der **keine Ausnahme wirft**.
 *
 * **`--raw` fehlt.** `mysql --batch` maskiert in der Ausgabe Tabulator,
 * Zeilenumbruch und **Rückstrich** — und eine JSON-Zeichenkette besteht aus
 * maskierten Rückstrichen. Aus `"a\tb"` wird `"a\\tb"`: **gültiges JSON mit
 * einem falschen Wert**, fehlerfrei gelesen, vier Zeichen statt drei. Gemessen
 * am 12. August 2026 (`docs/46 §2.3`, N1/N2).
 *
 * > **Eine Maskierung über einer Maskierung ist schlimmer als ein
 * > Parserfehler.** Der fiele auf.
 *
 * **`--raw` steht an der falschen Stelle.** In der bestehenden `query()` ist die
 * Maskierung des Klienten genau die Sicherung, die die Zeilentrennung trägt. Wer
 * sie dort entfernt, macht aus einer richtigen Methode eine kaputte — und auch
 * das fällt erst an einem Wert mit einem Zeilenumbruch auf.
 *
 * **Eine binäre Spalte steht als Wert in der Abfrage.** Dann macht ein `BLOB`
 * mit ungültigem UTF-8 nicht seine Zelle unlesbar, sondern die **ganze Zeile**:
 * `json_decode()` gibt `null` zurück, und MariaDBs eigenes `JSON_VALID()` hält
 * die Ausgabe für gültig (N3–N5).
 *
 * > **Eine Gültigkeitsprüfung des einen Systems sagt nichts über den Leser im
 * > anderen.**
 *
 * ## Warum als Textprüfung
 *
 * Dieselbe Bauform wie `SiteTemplateTest` und `PhpIsolationTest`: Der Schutz ist
 * eine Eigenschaft der erzeugten Zeichenkette. Ein Test an einer Verbindung
 * prüfte ausserdem nur das System, das gerade läuft — und die MariaDB-Hälfte ist
 * die, an der es hängt.
 *
 * **Die Brüche dazu** (`tests/waechter-brechen.sh`): `--raw` aus `jsonAs()`
 * entfernen; `--raw` in die Argumentliste von `run()` setzen; die Umsetzung auf
 * `octet_length` in einer der beiden Konsolen entfernen.
 */
final class ResultEncodingTest extends TestCase
{
    public function test_the_json_way_asks_for_raw_output(): void
    {
        $source = $this->source('agent/src/Db/Session.php');
        $jsonAs = $this->method($source, 'linesAs');

        $this->assertStringContainsString(
            "'--raw'",
            $jsonAs,
            'Der JSON-Weg für MariaDB ruft ohne --raw. Dann maskiert der Klient die Maskierung des '
            .'Formats, und es kommt gültiges JSON mit falschen Werten an (docs/46 §8.1).',
        );
    }

    public function test_the_text_way_never_asks_for_raw_output(): void
    {
        $run = $this->method($this->source('agent/src/Db/Session.php'), 'run');

        $this->assertStringNotContainsString(
            "'--raw'",
            $run,
            'Die bestehende query() ruft mit --raw. Dort ist die Maskierung des Klienten die Sicherung, '
            .'die die Zeilentrennung trägt — ohne sie bricht ein Wert mit einem Zeilenumbruch die Zeile.',
        );
    }

    public function test_the_timeout_travels_with_the_statement(): void
    {
        foreach (['agent/src/Db/Session.php', 'agent/src/Pg/Session.php'] as $path) {
            $this->assertMatchesRegularExpression(
                '/SET (max_statement_time|statement_timeout) = /',
                $this->method($this->source($path), 'linesAs'),
                sprintf(
                    '%s setzt die Zeitgrenze nicht in derselben Sitzung wie die Abfrage. Jeder Aufruf ist '
                    .'eine eigene Verbindung; was in einer anderen gilt, gilt für diese nicht.',
                    basename($path),
                ),
            );
        }
    }

    public function test_a_binary_column_is_a_length_in_both_engines(): void
    {
        $columns = [
            ['name' => 'bild', 'type' => 'blob', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => true],
        ];

        $postgres = PgConsole::selectList($columns, PgConsole::CELL_LIMIT);
        $mariadb = DbConsole::jsonObject($columns, PgConsole::CELL_LIMIT);

        $this->assertStringContainsString('octet_length(', $postgres[0]);
        $this->assertStringContainsString(
            'OCTET_LENGTH(',
            $mariadb,
            'Die binäre Spalte steht als Wert in der Abfrage. Ein BLOB mit ungültigem UTF-8 macht damit '
            .'die ganze Zeile unlesbar — und JSON_VALID() hält die Ausgabe für gültig (docs/46 §8.2).',
        );

        $this->assertStringNotContainsString('LEFT(CAST(`bild`', $mariadb);
    }

    public function test_both_engines_carry_the_same_limits(): void
    {
        // Zwei Zahlen für dieselbe Grenze liefen auseinander; die eine steht in
        // `Pg\Console`, die andere rechnet sie um.
        $this->assertSame(PgConsole::TIMEOUT_MS / 1000, DbConsole::TIMEOUT_SECONDS);
    }

    /**
     * Der Rumpf einer Methode.
     *
     * Grob und absichtlich so: Gesucht wird der Text zwischen der Signatur und
     * der nächsten Methode auf derselben Einrückung. Ein Parser dafür wäre eine
     * zweite Fassung von PHP.
     */
    private function method(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name.'(');

        $this->assertIsInt($start, sprintf('Es gibt keine Methode %s() mehr — dann prüft dieser Wächter nichts.', $name));

        $rest = substr($source, $start);
        $end = strpos($rest, "\n    }");

        return $end === false ? $rest : substr($rest, 0, $end);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
