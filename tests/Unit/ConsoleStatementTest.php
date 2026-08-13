<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Pg\Console;

/**
 * Die Anweisungen der Konsole — als Text geprüft, ohne Datenbank.
 *
 * **Dieselbe Bauform wie `SiteTemplateTest` und `PhpIsolationTest`**, und aus
 * demselben Grund: Der Schutz ist eine Eigenschaft der erzeugten Zeichenkette,
 * und dieser Container hat für MariaDB keinen Server. Was hier steht, gilt
 * deshalb auch für das Gegenstück aus Schritt 2.
 *
 * Geprüft werden die drei Regeln aus `docs/46`, und jede hat ihren Anlass:
 *
 * 1. **Ein Bezeichner wird nachgeschlagen** (§7) — sonst kommt beim Kunden eine
 *    Meldung des Servers über eine Relation an, die es anderswo geben könnte.
 * 2. **Eine binäre Spalte erreicht `row_to_json` gar nicht erst** (§8.2) —
 *    sonst macht ein `BLOB` mit ungültigem UTF-8 die **ganze Zeile** unlesbar,
 *    und die Meldung lautet „Malformed UTF-8", also nach einem Fehler des
 *    Panels.
 * 3. **Der Schreibweg fasst nur an, was der Kunde geändert hat** (§10.1) —
 *    sonst überträgt das Formular jede Kürzung und jedes `''` aus einem `NULL`
 *    in die Daten, und **an der Zeile sieht man es hinterher nicht.**
 */
final class ConsoleStatementTest extends TestCase
{
    /**
     * Eine Tabelle, wie {@see Console::columns()} sie liefert.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    private function columns(): array
    {
        return [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'key' => true, 'binary' => false],
            ['name' => 'notiz', 'type' => 'text', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => false],
            ['name' => 'bild', 'type' => 'bytea', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => true],
        ];
    }

    public function test_a_binary_column_never_reaches_the_json(): void
    {
        $list = Console::selectList($this->columns(), Console::CELL_LIMIT);

        $this->assertSame('octet_length("bild") AS "bild"', $list[2]);

        foreach ($list as $entry) {
            $this->assertStringNotContainsString(
                'left("bild"',
                $entry,
                'Die binäre Spalte steht als Wert in der Abfrage. Ein BLOB mit ungültigem UTF-8 macht '
                .'damit die ganze Zeile unlesbar — nicht nur diese Zelle (docs/46 §8.2).',
            );
        }
    }

    public function test_every_other_column_is_cut_in_the_statement(): void
    {
        $list = Console::selectList($this->columns(), Console::CELL_LIMIT);

        // **Ein Zeichen mehr als erlaubt**, und daran erkennt die Antwort, dass
        // gekürzt wurde. Ohne das eine Zeichen liesse sich „genau 512 lang" und
        // „abgeschnitten" nicht unterscheiden.
        $this->assertSame('left("id"::text, 513) AS "id"', $list[0]);
        $this->assertSame('left("notiz"::text, 513) AS "notiz"', $list[1]);
    }

    public function test_an_unknown_identifier_never_becomes_a_statement(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Diese Spalte gibt es in dieser Tabelle nicht.');

        Console::column($this->columns(), 'gibtsnicht');
    }

    public function test_a_filter_value_is_quoted_and_never_a_pattern(): void
    {
        $column = Console::column($this->columns(), 'notiz');

        $this->assertSame(
            "strpos(\"notiz\"::text, '%wert%') > 0",
            Console::condition($column, 'contains', '%wert%'),
            'Der Filter benutzt LIKE. Dann ist ein Prozentzeichen im Wert des Kunden ein Platzhalter, '
            .'und seine Maskierung bräuchte ein eigenes Fluchtzeichen (docs/46 §7).',
        );

        $this->assertSame(
            "\"notiz\"::text = 'x'')'",
            Console::condition($column, 'equals', "x')"),
        );
    }

    public function test_empty_covers_null_and_the_empty_string(): void
    {
        $this->assertSame(
            '("notiz" IS NULL OR "notiz"::text = \'\')',
            Console::condition(Console::column($this->columns(), 'notiz'), 'empty', ''),
        );
    }

    public function test_the_write_touches_only_the_changed_columns(): void
    {
        $statement = Console::writeStatement(
            'public',
            'kunden',
            $this->columns(),
            'update',
            ['id' => '1'],
            ['notiz' => 'neu'],
        );

        $this->assertStringContainsString('SET "notiz" = \'neu\'', $statement);
        $this->assertStringNotContainsString(
            '"bild"',
            $statement,
            'Eine Spalte, die der Kunde nicht geändert hat, steht in der Anweisung. Damit schreibt das '
            .'Formular zurück, was es nur angezeigt hat — und jede Kürzung wird zu einem Datenverlust, '
            .'den man an der Zeile hinterher nicht sieht (docs/46 §10.1).',
        );
    }

    public function test_null_and_the_empty_string_are_two_values_on_the_way_back(): void
    {
        $this->assertSame('NULL', Console::literal(null));
        $this->assertSame("''", Console::literal(''));
    }

    public function test_the_write_counts_what_it_hit(): void
    {
        $statement = Console::writeStatement(
            'public',
            'kunden',
            $this->columns(),
            'delete',
            ['id' => '1'],
            [],
        );

        $this->assertStringContainsString('GET DIAGNOSTICS', $statement);
        $this->assertStringContainsString('RAISE EXCEPTION', $statement);

        /*
         * **Die Marke und nicht der Satz.** Hier stand `nicht genau eine` — der
         * Satz für den Kunden, wörtlich in der Anweisung. Er kam damit als
         * Datenbankfehler verkleidet zurück, mit `CONTEXT: PL/pgSQL function
         * inline_code_block line 7 at RAISE` und einem Vorspann, der sagt, es
         * habe jemand anders gesprochen (`docs/47 §6`, Befund 2).
         *
         * > **Eine Verpackung, die für eine fremde Meldung richtig ist, ist für
         * > die eigene falsch.**
         *
         * Der Block schickt jetzt die Zahl, den Satz baut PHP — und dieser Test
         * prüft beide Richtungen, denn nur eine davon wäre zu umgehen.
         */
        $this->assertStringContainsString(
            Console::MISS_MARKER.'=',
            $statement,
            'Der Schreibvorgang zählt nicht nach. Ein UPDATE, das keine oder mehrere Zeilen trifft, '
            .'meldete dann Erfolg (docs/46 §10).',
        );

        $this->assertStringNotContainsString(
            'nicht genau eine',
            $statement,
            'Der Satz für den Kunden steht in der Anweisung. Er kommt damit als Datenbankfehler '
            .'verkleidet zurück, mit einer Zeilennummer auf eine Datei, die es nicht gibt.',
        );

        $this->assertStringContainsString(
            'nicht genau eine',
            Console::missed(0),
            'Der Satz entsteht nicht mehr in PHP — dann steht er nirgends, und der Kunde liest die '
            .'nackte Marke.',
        );
    }

    public function test_a_key_column_must_be_part_of_the_key(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('gehört nicht zum Primärschlüssel');

        Console::keyCondition($this->columns(), ['notiz' => 'x']);
    }

    public function test_without_a_key_there_is_no_statement(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Ohne Primärschlüssel');

        Console::keyCondition($this->columns(), []);
    }

    /**
     * `-1` heisst „unbekannt" und nicht „leer".
     *
     * Der Anlass steht in `docs/46 §2.2` (M19): PostgreSQL setzt es für eine nie
     * analysierte Tabelle. Wer die Zahl auf `max(0, …)` klemmt, schreibt
     * „0 Zeilen" — und das sieht aus wie eine Antwort.
     */
    public function test_an_unknown_row_count_stays_unknown(): void
    {
        $this->assertNull(Console::table(['public', 'kunden', 'r', '-1', '8192', 'f'])['rows']);
        $this->assertSame(3, Console::table(['public', 'kunden', 'r', '3', '8192', 'f'])['rows']);
        $this->assertSame(Console::TABLE, Console::table(['public', 'kunden', 'r', '3', '8192', 'f'])['kind']);
        $this->assertSame(Console::VIEW, Console::table(['public', 'sicht', 'm', '3', '8192', 'f'])['kind']);

        // Eine Sicht liefert gar keine Zahl — in MariaDB ist das der einzige
        // Fall, in dem es „unbekannt" gibt (`docs/46 §2.3`, N10).
        $this->assertNull(Console::table(['public', 'sicht', 'v', '', '0', 'f'])['rows']);
    }

    /**
     * Die einundfünfzigste Zeile wird gezählt und nicht ausgeliefert.
     *
     * Sie ist die ganze Auskunft „es geht weiter" — an ihrer Stelle stünde sonst
     * ein `count(*)` über den Filter, und der liefe bei jedem Aufruf
     * (`docs/46 §9`).
     */
    public function test_the_extra_row_only_says_there_is_more(): void
    {
        $lines = [];

        for ($i = 0; $i <= Console::ROWS_PER_PAGE; $i++) {
            $lines[] = json_encode(['id' => (string) $i], JSON_THROW_ON_ERROR);
        }

        $page = Console::page($lines, $this->columns());

        $this->assertCount(Console::ROWS_PER_PAGE, $page['rows']);
        $this->assertTrue($page['more']);
        $this->assertFalse(Console::page(array_slice($lines, 0, 3), $this->columns())['more']);
    }

    public function test_a_cut_cell_says_so(): void
    {
        $line = json_encode(
            ['id' => '1', 'notiz' => str_repeat('y', Console::CELL_LIMIT + 1)],
            JSON_THROW_ON_ERROR,
        );

        $page = Console::page([$line], $this->columns());

        $this->assertSame(['notiz'], $page['truncated']);
        $this->assertSame(Console::CELL_LIMIT, mb_strlen((string) $page['rows'][0]['notiz']));
    }

    /**
     * Ein unlesbares Ergebnis nennt seinen wahrscheinlichsten Grund.
     *
     * „Ungültiges JSON" schickte den Suchenden in die Bibliothek; der Grund ist
     * in aller Regel eine binäre Spalte, die in die Abfrage geraten ist
     * (`docs/46 §8.2`).
     */
    public function test_an_unreadable_answer_names_its_suspect(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('binäre Spalte');

        Console::decode("{\"a\": \"\xFF\x80\"}");
    }
}
