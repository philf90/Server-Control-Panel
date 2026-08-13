<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Console as DbConsole;
use SrvPanel\Agent\Db\Session as DbSession;
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
 * **Der Klient nennt seinen Zeichensatz nicht.** Dann handelt er unter
 * `LC_ALL=C` latin1 aus, der Server konvertiert `JSON_OBJECT()` am Ausgang, und
 * aus `ü` wird das einzelne Byte `FC` — wieder kein Parserfehler an der Zelle,
 * sondern eine **ganze Zeile**, die `json_decode()` nicht liest. Gemessen auf
 * `cloudsrv24` am 12. August 2026, gefunden vom Abnahmelauf und von keinem
 * Test: Die Testdoppel dieses Projekts sind ASCII.
 *
 * > **Ein Testdatensatz aus ASCII prüft keine Kodierung.**
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
 * `octet_length` in einer der beiden Konsolen entfernen; `--default-character-set`
 * aus {@see DbSession::CLIENT} nehmen; die Argumentliste in einer der beiden
 * Methoden wieder ausschreiben.
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

    /**
     * Der Zeichensatz gehört zur Abfrage, nicht zur Umgebung.
     *
     * **Gemessen auf `cloudsrv24` am 12. August 2026**, im Abnahmelauf von P5c
     * und von keinem Test: `mysql` ohne `--default-character-set` handelt unter
     * `LC_ALL=C` **latin1** aus, der Server konvertiert `JSON_OBJECT()` am
     * Ausgang, und aus `ü` wird das einzelne Byte `FC` — kein gültiges UTF-8.
     * `json_decode()` gibt `null` zurück, und damit ist **die ganze Zeile**
     * unlesbar, nicht nur die Zelle.
     *
     * Geprüft wird die Konstante und dass **beide** Aufrufwege sie benutzen. Der
     * zweite Teil ist der eigentliche: Die Argumentliste stand zweimal da, und
     * die Angabe fehlte in beiden Fassungen.
     *
     * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
     * > beiden ist der Ort, an dem man nachsieht.**
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): `--default-character-set`
     * aus {@see DbSession::CLIENT} entfernen, oder in einer der beiden Methoden
     * die Argumentliste wieder ausschreiben.
     */
    public function test_the_client_always_speaks_utf8mb4(): void
    {
        $this->assertContains(
            '--default-character-set=utf8mb4',
            DbSession::CLIENT,
            'Der MariaDB-Klient bekommt keinen Zeichensatz genannt. Unter LC_ALL=C fällt er dann auf '
            .'latin1 zurück, und eine Zelle mit einem Umlaut macht die ganze Zeile unlesbar.',
        );

        $source = $this->source('agent/src/Db/Session.php');

        foreach (['run', 'linesAs'] as $name) {
            $this->assertStringContainsString(
                'self::CLIENT',
                $this->method($source, $name),
                sprintf(
                    '%s() baut seine Argumentliste selbst, statt Session::CLIENT zu benutzen. Genau so '
                    .'ist der Zeichensatz in beiden Fassungen gefehlt.',
                    $name,
                ),
            );
        }
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

    /**
     * Der Name hinter `ORDER BY` kann nur die Spalte meinen.
     *
     * **Der Fund kam von einem Bildschirmfoto und war eine Reihenfolge.** Eine
     * `bigint`-Spalte kam als `1, 10, 100, 101` zurück: `selectList()` gibt
     * jeder Spalte ihren eigenen Namen als Alias — `left(id::text, 513) AS
     * "id"` —, und PostgreSQL löst einen einfachen Namen im `ORDER BY` gegen
     * die **Ausgabespalte** auf. Sortiert wurde der gekürzte Text.
     *
     * > **Ein Alias, der wie seine Spalte heisst, ist keine Umbenennung — er
     * > ist eine zweite Bedeutung desselben Namens.**
     *
     * **Der zweite Schaden war auf keinem Bild zu sehen**, und er ist der
     * schwerere: Ein Sortierschlüssel `left(id::text, 513)` passt auf keinen
     * Index. Gemessen auf PostgreSQL 16.13 mit 200.000 Zeilen — vorher
     * `Parallel Seq Scan` plus `Sort` über die ganze Tabelle, nachher
     * `Index Only Scan using t_pkey`. Damit war die Begründung der Oberfläche
     * hinfällig, über den Schlüssel zu sortieren, *weil* dort ein Index liegt.
     *
     * **Zwei Systeme, dieselbe Absicht, und nur eines hatte den Fehler.**
     * MariaDB kürzt in einem `JSON_OBJECT(...)`, das gar keinen Alias je Spalte
     * erzeugt; dort konnte `ORDER BY id` nur die Spalte meinen. Deshalb prüft
     * dieser Wächter je System das, was den Namen dort eindeutig macht — eine
     * Regel, zwei Belege.
     */
    public function test_the_sort_key_can_only_mean_the_column(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'key' => true, 'binary' => false],
            ['name' => 'ort', 'type' => 'text', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => false],
        ];

        $postgres = PgConsole::rowsQuery('public', 'kunden', $columns, 'id', false, 0, null);

        // In PostgreSQL trägt die Auswahlliste die Aliase — der Sortiername muss
        // deshalb qualifiziert sein. Ein qualifizierter Name kann keine
        // Ausgabespalte treffen.
        $this->assertMatchesRegularExpression(
            '/ORDER BY [A-Za-z_][A-Za-z0-9_]*\."id"/',
            $postgres,
            'Das ORDER BY der PostgreSQL-Zeilenabfrage nennt die Spalte unqualifiziert. Die Auswahlliste '
            .'gibt ihr denselben Namen als Alias, PostgreSQL nimmt den Ausgabenamen — sortiert wird dann '
            .'der gekürzte Text, und kein Index trägt die Sortierung mehr (docs/46 §20.18).',
        );

        // Und der Alias muss auch wirklich vergeben sein, sonst zeigt der
        // qualifizierte Name auf nichts.
        $this->assertMatchesRegularExpression(
            '/FROM "public"."kunden" AS [A-Za-z_][A-Za-z0-9_]*/',
            $postgres,
            'Die Tabelle bekommt keinen Aliasnamen — dann kann das ORDER BY ihn nicht davorsetzen.',
        );

        $mariadb = DbConsole::rowsQuery('p1000_shop', 'kunden', $columns, 'id', false, 0, null);

        $this->assertStringContainsString('ORDER BY `id`', $mariadb);

        // Der Grund, aus dem MariaDB ohne Qualifizierung auskommt: Die Kürzung
        // steht in einem JSON_OBJECT und vergibt keinen Alias je Spalte. Fiele
        // das weg, träte derselbe Fehler dort auf — und niemand hätte hier eine
        // Zeile geändert.
        $this->assertStringNotContainsString(
            'AS `id`',
            $mariadb,
            'Die MariaDB-Zeilenabfrage vergibt der Spalte ihren eigenen Namen als Alias. Damit hätte der '
            .'Name hinter ORDER BY dort zwei Bedeutungen — genau der Fehler, den PostgreSQL hatte.',
        );
    }

    /**
     * Eine Sortierung endet auf etwas Eindeutigem — sonst blättert sie nicht.
     *
     * **Gefunden auf Bild 7 des Durchgangs zu Schritt 5.** Sortiert nach `ort`,
     * und innerhalb von „Grünheide" standen die IDs als `116, 5, 92, 113, 47`
     * da. Das ist keine Nachlässigkeit des Servers: Er sagt zu, nach `ort` zu
     * sortieren, und über Zeilen mit demselben `ort` sagt er **nichts**.
     *
     * Mit `OFFSET` ist das kein Schönheitsfehler. Gemessen auf PostgreSQL 16.13,
     * 120 Zeilen und drei Werten in `ort`, Seite 1 ohne und Seite 2 mit Index:
     *
     *     ORDER BY ort       →  5 Zeilen doppelt, 25 Zeilen nie gesehen
     *     ORDER BY ort, id   →  0 doppelt, und die 20 offenen sind Seite 3
     *
     * > **Eine Sortierung ohne eindeutigen Schluss ist beim Blättern keine
     * > Sortierung, sondern eine Stichprobe.**
     *
     * Der Plan wechselt in echten Beständen von allein — wenn ein Index
     * dazukommt, wenn `ANALYZE` läuft, wenn die Tabelle wächst. Der Kunde sieht
     * dann Zeilen doppelt, während andere ausfallen, und nichts daran sieht nach
     * einem Fehler aus.
     *
     * **Ohne Schlüssel bleibt es dabei**, und das ist eine benannte Lücke —
     * dieselben Tabellen, die nach `docs/46 §10` ohnehin nur lesbar sind.
     */
    public function test_a_sort_ends_on_the_key_in_both_engines(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'key' => true, 'binary' => false],
            ['name' => 'ort', 'type' => 'text', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => false],
        ];

        $this->assertSame(
            ['ort', 'id'],
            PgConsole::orderColumns($columns, 'ort'),
            'Eine Sortierung über eine mehrdeutige Spalte endet nicht auf dem Schlüssel. Beim Blättern '
            .'sieht der Kunde dann Zeilen doppelt, während andere ausfallen (docs/46 §20.19).',
        );

        // Und die Sortierung über den Schlüssel selbst nennt ihn nicht zweimal.
        $this->assertSame(['id'], PgConsole::orderColumns($columns, 'id'));

        foreach ([
            'PostgreSQL' => PgConsole::rowsQuery('public', 'g', $columns, 'ort', false, 0, null),
            'MariaDB' => DbConsole::rowsQuery('p1000_shop', 'g', $columns, 'ort', false, 0, null),
        ] as $engine => $sql) {
            $this->assertMatchesRegularExpression(
                '/ORDER BY [^L]*"?`?ort`?"? ASC, [^L]*"?`?id`?"? ASC LIMIT/',
                $sql,
                sprintf('%s sortiert nicht zuletzt über den Schlüssel: %s', $engine, $sql),
            );
        }

        // Ohne Schlüssel gibt es nichts anzuhängen — das ist die benannte Lücke
        // und kein Grund, hier etwas zu erfinden.
        $keyless = [
            ['name' => 'a', 'type' => 'int', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => false],
        ];

        $this->assertSame(['a'], PgConsole::orderColumns($keyless, 'a'));
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
