<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;

/**
 * Das Datenbankmanagement — Katalogfragen und der Bau der Anweisungen.
 *
 * Der Plan ist `docs/46`. Diese Klasse ist seine Mitte, und sie beantwortet die
 * eine Frage, an der die ganze Stufe hängt: **Wie wird aus einer typisierten
 * Frage eine Anweisung, ohne dass irgendwo Text des Kunden zu SQL wird?**
 *
 * ## Was hier nicht ankommt
 *
 * **Kein SQL.** Entscheidung 2 des Betreibers (`docs/46 §3`) nimmt der Konsole
 * das Eingabefeld für Anweisungen, und das ist der Grund, warum diese Stufe
 * überhaupt über den Agenten laufen darf: Was über die Prozessgrenze geht, ist
 * eine Frage mit Feldern — Tabelle, Spalte, Richtung, Grenze — und kein Text,
 * der zu einer Kommandozeile wird (Plan §4.2).
 *
 * ## Die drei Regeln, aus denen alles andere folgt
 *
 * **1. Ein Bezeichner wird nachgeschlagen, nicht nur maskiert** (`docs/46 §7`).
 * {@see Sql::identifier()} beantwortet nur, ob sich ein Name gefahrlos anführen
 * lässt. Ob es ihn *gibt*, beantwortet der Katalog — und jede Anweisung dieser
 * Klasse baut ihre Bezeichner aus {@see self::columns()} und nicht aus dem, was
 * die Anwendung geschickt hat. Steht ein Name nicht darin, gibt es die Anweisung
 * nicht, und der Kunde bekommt eine Meldung des Panels statt einer des Servers
 * über eine Relation, die es anderswo geben könnte.
 *
 * **2. Eine binäre Spalte erreicht `row_to_json` gar nicht erst**
 * (`docs/46 §8.2`). Für PostgreSQL wäre sie unschädlich — `bytea` wird zu einer
 * gültigen Hex-Zeichenkette —, für MariaDB nicht: Dort landen die rohen Bytes in
 * der JSON-Zeichenkette, `JSON_VALID()` sagt `1` und `json_decode()` gibt `null`
 * für die **ganze Zeile** zurück (`docs/46 §2.3`, N3 bis N5). Beide Systeme
 * bauen die Spaltenliste deshalb gleich, und der Grund steht im schwächeren.
 *
 * **3. Gekürzt wird in der Anweisung und nicht in der Antwort.** Eine einzelne
 * Zelle mit 3 MB sprengt die Anfragegrenze des Agenten von 1 MiB allein
 * (`docs/46 §2.2`, M21). Wer erst holt und dann kürzt, hat die Grenze schon
 * gerissen.
 *
 * ## Wie die Kürzung sich selbst meldet
 *
 * Gefragt wird nach {@see self::CELL_LIMIT} **plus einem** Zeichen. Kommt genau
 * diese Länge zurück, war der Wert länger — und die Antwort trägt den Namen der
 * Spalte in `truncated`. Das spart die zweite Spalte je Zelle, die ein
 * `length()` daneben kostete, und es kann sich nicht widersprechen: Die Marke
 * *ist* die Länge.
 */
final class Console
{
    /**
     * Zeilen je Seite.
     *
     * Gemessen: fünfzig Zeilen à 200 Zeichen wiegen als JSON 11 KB
     * (`docs/46 §2.2`, M20), die Anfragegrenze des Agenten liegt bei 1 MiB. Das
     * ist Raum um den Faktor 90 für breite Tabellen — und mit der Kürzung je
     * Zelle ist die Obergrenze einer Seite rechenbar statt gehofft.
     */
    public const ROWS_PER_PAGE = 50;

    /**
     * Zeichen je Zelle in der Tabellenansicht.
     *
     * Wer mehr sehen will, öffnet die Zelle einzeln ({@see self::cell()}).
     */
    public const CELL_LIMIT = 512;

    /**
     * Zeichen einer einzeln geöffneten Zelle.
     *
     * Weit unter der Anfragegrenze und weit über allem, was eine Tabellenzeile
     * trägt. Auch sie kann kürzen — ein `text` hat keine Obergrenze, und eine
     * Zelle, die den Agenten sprengt, wäre der teuerste Weg, das zu erfahren.
     */
    public const CELL_FULL_LIMIT = 65_536;

    /**
     * Wie lange eine Konsolenabfrage laufen darf, in Millisekunden.
     *
     * **Durchsetzbar, weil es kein freies SQL gibt.** Ein Rolleninhaber kann
     * `statement_timeout` selbst zurücknehmen, auch gegen `ALTER ROLE … SET`
     * (`docs/46 §2.2`, M11) — er kann es nur, wenn er ein `SET` schicken darf,
     * und genau das nimmt ihm Entscheidung 2.
     */
    public const TIMEOUT_MS = 5_000;

    /**
     * Der Aliasname der Tabelle in der Zeilenabfrage.
     *
     * Er steht als Konstante da, weil er zweimal in dieselbe Anweisung geht —
     * einmal hinter `FROM` und einmal vor der Sortierspalte — und die beiden
     * denselben Namen tragen müssen. Er ist kein Bezeichner aus einer Anfrage
     * und geht deshalb nicht durch `Sql::identifier()`; ein Tabellen- oder
     * Spaltenname, der ebenfalls `src` heisst, wird von ihm nur verdeckt und
     * nicht verwechselt.
     */
    private const SOURCE = 'src';

    /**
     * Die Vergleiche, die der Filter kennt.
     *
     * **Drei und nicht acht** (`docs/46 §3`, Entscheidung 5). Die Filterzeile ist
     * das dichteste Bedienelement der Fläche und steht bei 390 px über einer
     * Tabelle, die schon waagerecht rollt — und jeder Operator ist ein weiterer
     * Weg, auf dem die Maskierung falsch sein kann.
     *
     * `empty` deckt `NULL` **und** die leere Zeichenkette ab. Die beiden
     * auseinanderzuhalten ist Aufgabe der Anzeige und des Schreibwegs, nicht des
     * Filters; wer nach der einen sucht, sucht in aller Regel nach beiden.
     */
    public const OPERATORS = ['equals', 'contains', 'empty'];

    /** Was `mode` bei einem Schreibvorgang sein darf. */
    public const MODES = ['insert', 'update', 'delete'];

    /**
     * Die Marke, mit der der `DO`-Block seine Trefferzahl zurückschickt.
     *
     * **Sie steht hier, weil sie an zwei Stellen gebraucht wird** — beim Bauen
     * in {@see self::writeStatement()} und beim Lesen in
     * {@see self::missedCount()}. Zwei Zeichenketten, die aufeinander zeigen,
     * ohne dass etwas den Bezug prüft, sind der Fehler, den dieses Projekt
     * mindestens sechsmal teuer bezahlt hat; `RowKeyTest` prüft, dass beide
     * Seiten diese Konstante benutzen und keine abgeschriebene Fassung.
     *
     * Englisch und ohne Umlaut: Sie ist eine Kennung und kein Text der
     * Oberfläche (`docs/19 §4a`).
     */
    public const MISS_MARKER = 'SRVPANEL_ROWS';

    /**
     * Die zwei Arten, die die Oberfläche unterscheidet.
     *
     * **Nicht `relkind` und nicht `TABLE_TYPE`.** Beide Systeme nennen dieselbe
     * Sache verschieden — `r` gegen `BASE TABLE` —, und keines der beiden Wörter
     * gehört in eine Vue-Datei. Übersetzt wird hier und im Gegenstück, damit die
     * Oberfläche eine Antwort bekommt und nicht zwei.
     */
    public const TABLE = 'table';

    public const VIEW = 'view';

    /**
     * `relkind` in die zwei Arten.
     *
     * `p` ist eine partitionierte Tabelle und für den Kunden eine Tabelle; `m`
     * ist eine materialisierte Sicht und für ihn eine Sicht — sie hat Zeilen auf
     * der Platte, aber keinen Weg, eine davon zu ändern.
     *
     * @var array<string, string>
     */
    public const KINDS = ['r' => self::TABLE, 'p' => self::TABLE, 'v' => self::VIEW, 'm' => self::VIEW];

    /**
     * Die Tabellen einer Datenbank.
     *
     * **Fest verdrahtet und ohne eine Zeile aus der Anfrage** — es gibt nichts
     * einzusetzen. Drei Entscheidungen stecken darin:
     *
     * - **`has_table_privilege` filtert mit.** Eine Tabelle anzuzeigen, die der
     *   Zugang nicht lesen darf, wäre ein Verweis ins Leere.
     * - **`reltuples` ist eine Schätzung und kann `-1` sein** — für eine nie
     *   analysierte Tabelle, und das heisst „unbekannt" und nicht „leer"
     *   (`docs/46 §2.2`, M19). Umgesetzt wird sie in {@see self::table()}.
     * - **Sichten kommen mit.** Sie sind lesbar, haben aber keinen Schlüssel und
     *   damit keine Zeilenzahl; `relkind` trägt die Unterscheidung bis in die
     *   Oberfläche.
     */
    /**
     * Was hier als **Schlüssel** einer Tabelle gilt — einmal, für beide Abfragen.
     *
     * ## Warum das eine Konstante ist
     *
     * **Weil es zwei Abfragen gibt und die Regel eine ist.** Geteilt wird die
     * **Bedingung** und nicht die ganze Abfrage: Die Tabellenliste fragt
     * `SELECT 1` (gibt es so einen Index?), die Spaltenliste `SELECT i.indkey`
     * (welche Spalten gehören dazu?). Der erste Versuch teilte das ganze
     * `SELECT`, und PostgreSQL wies es ab — `cannot cast type integer to
     * smallint[]`.
     *
     * > **Zwei Stellen, die dieselbe Regel brauchen, brauchen nicht dieselbe
     * > Abfrage.**
     *
     * Welche Relation gemeint ist, sagt jede Abfrage selbst: `i.indrelid = c.oid`
     * beziehungsweise `= a.attrelid`.
     *
     * Beim Bau von §10 Regel 2 (`docs/46 §20.35`) habe ich nur `columnsQuery()`
     * angefasst. Die Tabellenliste fragte weiter nach `indisprimary` — und auf
     * `cloudsrv24` stand deshalb über einer Tabelle, die sich ändern liess:
     *
     *     Tabelle nur_unique · Zeilenzahl unbekannt · 32 KB · ohne Schlüssel
     *
     * Daneben die Spalte „Zeile" mit „Ändern" und darunter „Zeile anlegen". Die
     * Beizeile widersprach der Seite, auf der sie stand. Gefunden hat es der
     * Betreiber auf dem Bild zu Bild 6 — kein Test, denn beide Abfragen waren
     * für sich genommen richtig.
     *
     * > **Eine Regel an zwei Stellen ist keine Regel, sondern eine Absprache —
     * > und sie hält genau bis zur ersten Änderung.**
     *
     * ## Die vier Ausschlüsse
     *
     * Jeder einzeln gegen einen Wegwerf-Cluster belegt (16.13), Begründung in
     * {@see self::columnsQuery()}: kein Teilindex, kein Ausdrucksindex, keine
     * nullbare Spalte, und der Primärschlüssel gewinnt vor dem zuerst angelegten.
     */
    private const KEY_INDEX = <<<'SQL'
        i.indisunique
               AND i.indpred IS NULL
               AND 0 <> ALL (i.indkey::int2[])
               AND NOT EXISTS (SELECT 1 FROM unnest(i.indkey::int2[]) k
                                 JOIN pg_attribute x
                                   ON x.attrelid = i.indrelid AND x.attnum = k
                                WHERE NOT x.attnotnull)
        SQL;

    /**
     * Die Abfrage nach den Tabellen.
     *
     * **Eine Methode und keine Konstante**, seit der Schlüsselbegriff aus
     * {@see self::KEY_INDEX} kommt: Eine Konstante kann nicht einsetzen.
     */
    public static function tablesQuery(): string
    {
        return sprintf(<<<'SQL'
            SELECT n.nspname,
                   c.relname,
                   c.relkind,
                   CASE WHEN c.relkind IN ('r', 'p', 'm') THEN c.reltuples::bigint ELSE NULL END,
                   pg_total_relation_size(c.oid),
                   EXISTS (SELECT 1 FROM pg_index i
                            WHERE i.indrelid = c.oid AND %s)
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r', 'p', 'v', 'm')
               AND n.nspname NOT IN ('pg_catalog', 'information_schema')
               AND n.nspname NOT LIKE 'pg\_%%'
               AND has_table_privilege(c.oid, 'SELECT')
             ORDER BY 1, 2
            SQL, self::KEY_INDEX);
    }

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Ephemeral $ephemeral = new Ephemeral,
    ) {}

    /**
     * Der Rahmen jeder Konsolenoperation — und die Stelle, an der die Trennung sitzt.
     *
     * **Alle fünf Operationen gehen hier hindurch, und das ist die Regel selbst**
     * (`docs/46 §14.2`). Wer daran vorbei {@see Session::query()} ruft, führt die
     * Abfrage unter der Kennung des Agenten aus — als Superuser —, und dann ruht
     * die ganze Mandantentrennung auf {@see Names::belongsTo()} weiter unten in
     * dieser Methode, also auf **unserer** Prüfung. Das Ergebnis sähe dabei
     * genau gleich aus.
     *
     * Zwei Wände stehen deshalb hintereinander, und nur die zweite gehört uns
     * nicht:
     *
     * 1. **{@see Names::belongsTo()}** weist ab, was nicht zum Präfix des
     *    Abonnements gehört — dieselbe Zeile, an der seit P5 ein
     *    `DROP DATABASE postgres` scheitert.
     * 2. **Die befristete Rolle** ist Mitglied der Eigentümerrolle dieses
     *    Abonnements und sonst nichts. Was sie nicht darf, weist PostgreSQL ab,
     *    und die Meldung kommt vom Server.
     *
     * **Sie ist Mitglied der Eigentümerrolle und nicht eines Kundenzugangs.**
     * Ein Abonnement kann mehrere Zugänge mit verschiedenen Rechten haben; die
     * Konsole zeigt dem Kunden **seine Datenbank** und nicht „was einer seiner
     * Zugänge sehen dürfte". Die Grenze, die zählt, ist das Abonnement
     * (`docs/46 §6`).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function within(Context $context, array $args, callable $work): array
    {
        $prefix = Names::prefix($args['prefix'] ?? null);
        $database = Names::existing($args['database'] ?? null, 'database');

        if (! Names::belongsTo($database, $prefix)) {
            throw AgentException::badRequest('Diese Datenbank gehört nicht zu diesem Abonnement.', [
                'database' => $database,
            ]);
        }

        /** @var array<string, mixed> $result */
        $result = $this->ephemeral->with(
            $context,
            $prefix,
            $database,
            fn (Credentials $as): array => $work($as, $database),
            Names::KIND_CONSOLE,
        );

        return $result;
    }

    /**
     * Die Tabellen, wie die Oberfläche sie zeigt.
     *
     * @return list<array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}>
     */
    public function tables(Context $context, Credentials $as, string $database): array
    {
        $tables = [];

        foreach ($this->session->queryAs($context, $as, $database, self::tablesQuery(), self::TIMEOUT_MS) as $row) {
            $tables[] = self::table($row);
        }

        return $tables;
    }

    /**
     * Die Spalten einer Tabelle — und damit die Liste, gegen die alles geprüft wird.
     *
     * **Sie ist nicht nur Anzeige.** Jede andere Methode dieser Klasse schlägt
     * hier nach, bevor sie einen Bezeichner in eine Anweisung schreibt (Regel 1
     * im Klassenkopf). Deshalb steht sie hier und nicht in der Operation: Zwei
     * Fassungen dieser Liste wären zwei Fassungen der Prüfung.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    public function columns(Context $context, Credentials $as, string $database, string $schema, string $table): array
    {
        $rows = $this->session->queryAs($context, $as, $database, self::columnsQuery($schema, $table), self::TIMEOUT_MS);

        if ($rows === []) {
            throw AgentException::badRequest('Diese Tabelle gibt es in dieser Datenbank nicht.', [
                'schema' => $schema,
                'table' => $table,
            ]);
        }

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => (string) ($row[0] ?? ''),
                'type' => (string) ($row[1] ?? ''),
                'nullable' => ($row[2] ?? '') === 't',
                'default' => ($row[3] ?? '') === '' ? null : (string) $row[3],
                'key' => ($row[4] ?? '') === 't',
                'binary' => ($row[5] ?? '') === 't',
            ];
        }

        return $columns;
    }

    /**
     * Die Abfrage nach den Spalten.
     *
     * **Ohne `regclass` und mit zwei Textliteralen.** `'schema.tabelle'::regclass`
     * wäre kürzer und legte die Trennung in die Hand von PostgreSQL — bei einem
     * Tabellennamen mit einem Punkt darin läse es die falsche Hälfte als Schema.
     * Der Unterausdruck unten vergleicht beide Namen einzeln, und damit gibt es
     * den Fall nicht.
     *
     * Der Primärschlüssel kommt aus `pg_index`, nicht aus
     * `information_schema.key_column_usage`: Dieselbe Auskunft, aber ohne die
     * Sichten, die je Fassung anders heissen können.
     *
     * ## Der Schlüssel ist nicht nur der Primärschlüssel
     *
     * **Hier stand `i.indisprimary`, und das war Regel 1 aus `docs/46 §10` ohne
     * Regel 2.** Eine Tabelle ohne Primärschlüssel, aber mit einem eindeutigen
     * Index über Spalten ohne `NULL`, war damit nur lesbar — obwohl sich eine
     * Zeile über diesen Index genauso eindeutig ansprechen lässt.
     *
     * **Aufgefallen ist es an MariaDB, die es längst richtig machte.** Sie
     * befördert den ersten solchen Index zum impliziten Primärschlüssel und
     * meldet seine Spalten in `COLUMN_KEY` als `PRI` — gemessen am 13. August
     * 2026 gegen 10.11.14. Der MariaDB-Zweig dieses Panels erfüllt Regel 2 also,
     * seit es ihn gibt, und niemand hat es aufgeschrieben; der PostgreSQL-Zweig
     * erfüllte sie nicht. Zwei Systeme, dieselbe Regel, zwei Antworten — und
     * `EngineReachTest` kann das nicht sehen, weil er Namen vergleicht und kein
     * Verhalten.
     *
     * > **Ein Unterschied zwischen zwei Umsetzungen derselben Regel ist kein
     * > Unterschied der Systeme, solange ihn niemand gemessen hat.**
     *
     * Vier Ausschlüsse, jeder einzeln gegen einen Wegwerf-Cluster (16.13) belegt:
     *
     * - `indisunique` **ohne** `indpred` — ein Teilindex ist nur für die Zeilen
     *   eindeutig, die seine Bedingung erfüllen, und sagt über die anderen
     *   nichts.
     * - `0 <> ALL (indkey)` — eine `0` in `indkey` steht für einen **Ausdruck**
     *   (`lower(kennung)`), und zu einem Ausdruck gibt es keine Spalte, in die
     *   sich ein `WHERE` schreiben liesse.
     * - keine nullbare Spalte darunter — `NULL = NULL` ist nicht wahr, ein
     *   `WHERE` darüber träfe die Zeile nicht.
     * - `ORDER BY i.indisprimary DESC, i.indexrelid` — der Primärschlüssel
     *   gewinnt, sonst der **zuerst angelegte**. Die zweite Hälfte ist keine
     *   freie Wahl: MariaDB nimmt ebenfalls den zuerst angelegten, und ein
     *   „der mit den wenigsten Spalten" hätte die beiden Systeme bei zwei
     *   tauglichen Indexen auseinanderlaufen lassen. Auch das gemessen, nicht
     *   überlegt.
     *
     * `COALESCE(…, false)` und nicht der nackte Vergleich: Ohne Treffer gibt der
     * Unterausdruck `NULL`, und `= ANY (NULL)` ist `NULL`. Über `psql -A -t`
     * sähe das aus wie ein leeres Feld — also wie `''`, wie `NULL` und wie
     * `false` zugleich (`docs/46 §2`). Der Leser unten prüft auf `'t'` und läge
     * damit zufällig richtig; das ist kein Grund, es dabei zu belassen.
     */
    public static function columnsQuery(string $schema, string $table): string
    {
        return sprintf(<<<'SQL'
            SELECT a.attname,
                   format_type(a.atttypid, a.atttypmod),
                   NOT a.attnotnull,
                   pg_get_expr(d.adbin, d.adrelid),
                   COALESCE(a.attnum = ANY ((
                     SELECT i.indkey FROM pg_index i
                      WHERE i.indrelid = a.attrelid AND %s
                      ORDER BY i.indisprimary DESC, i.indexrelid
                      LIMIT 1)::int2[]), false),
                   a.atttypid = 'pg_catalog.bytea'::regtype
              FROM pg_attribute a
              LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE a.attrelid = (SELECT c.oid FROM pg_class c
                                   JOIN pg_namespace n ON n.oid = c.relnamespace
                                  WHERE n.nspname = %s AND c.relname = %s)
               AND a.attnum > 0
               AND NOT a.attisdropped
             ORDER BY a.attnum
            SQL, self::KEY_INDEX, Sql::text($schema), Sql::text($table));
    }

    /**
     * Die Indexe einer Tabelle.
     *
     * **Anzeige und sonst nichts.** Keine andere Methode dieser Klasse schlägt
     * hier nach — die Prüfliste für Bezeichner ist und bleibt
     * {@see self::columns()}. Der Kunde sieht hier, warum eine Sortierung schnell
     * ist und eine andere ins Zeitlimit läuft (`docs/46 §9`), und das ist der
     * ganze Zweck.
     *
     * @return list<array{name: string, columns: string, unique: bool, primary: bool}>
     */
    public function indexes(Context $context, Credentials $as, string $database, string $schema, string $table): array
    {
        // **Ohne die Prüfung auf „gibt es nicht".** Eine Tabelle ohne einen
        // einzigen Index ist ein gewöhnlicher Fall und keine falsche Angabe;
        // `columns()` hat die Existenz der Tabelle bereits beantwortet, und ein
        // zweites Mal `[]` als Fehler zu lesen wäre hier schlicht falsch.
        $indexes = [];

        foreach ($this->session->queryAs($context, $as, $database, self::indexesQuery($schema, $table), self::TIMEOUT_MS) as $row) {
            $indexes[] = [
                'name' => (string) ($row[0] ?? ''),
                'columns' => (string) ($row[3] ?? ''),
                'unique' => ($row[1] ?? '') === 't',
                'primary' => ($row[2] ?? '') === 't',
            ];
        }

        return $indexes;
    }

    /**
     * Die Abfrage nach den Indexen.
     *
     * **Der Unterausdruck auf `pg_class`/`pg_namespace` ist wortgleich der aus
     * {@see self::columnsQuery()}**, und aus demselben Grund: `'schema.tabelle'::regclass`
     * läse bei einem Tabellennamen mit einem Punkt die falsche Hälfte als Schema.
     *
     * **`pg_get_indexdef()` je Spalte statt `indkey` gegen `pg_attribute`.** Ein
     * Index kann über einem **Ausdruck** liegen — `lower(name)` —, und der hat
     * keine `attnum`. Ein Verbund auf `pg_attribute` liesse ihn lautlos aus der
     * Spaltenliste fallen, und dann stünde ein Index ohne Spalten da.
     *
     * **`generate_series(1, indnkeyatts)` und nicht `generate_subscripts(indkey, 1)`.**
     * Hier stand das zweite, und es war falsch — gemessen am 12. August 2026
     * gegen PostgreSQL 16: `indkey` ist ein `int2vector` und zählt **ab 0**,
     * `pg_get_indexdef(oid, colno, …)` zählt **ab 1**, und `colno = 0` bedeutet
     * dort „die ganze Definition". In der Spaltenliste eines Index stand deshalb
     * ein vollständiges `CREATE INDEX …`, und die **letzte Spalte fehlte**: aus
     * `(ort, name)` wurde `CREATE INDEX … (ort, name), ort`.
     *
     * > **Zwei Zählweisen im selben Ausdruck, und keine der beiden ist falsch —
     * > falsch ist, sie füreinander zu halten.**
     *
     * `indnkeyatts` statt `indnatts` lässt die `INCLUDE`-Spalten weg: Sie stehen
     * im Index, aber die Sortierung folgt ihnen nicht, und genau danach sieht
     * hier jemand.
     */
    public static function indexesQuery(string $schema, string $table): string
    {
        return sprintf(<<<'SQL'
            SELECT i.relname,
                   ix.indisunique,
                   ix.indisprimary,
                   array_to_string(array(
                     SELECT pg_get_indexdef(ix.indexrelid, k.n, true)
                       FROM generate_series(1, ix.indnkeyatts) AS k(n)
                      ORDER BY k.n), ', ')
              FROM pg_index ix
              JOIN pg_class i ON i.oid = ix.indexrelid
             WHERE ix.indrelid = (SELECT c.oid FROM pg_class c
                                    JOIN pg_namespace n ON n.oid = c.relnamespace
                                   WHERE n.nspname = %s AND c.relname = %s)
             ORDER BY ix.indisprimary DESC, i.relname
            SQL, Sql::text($schema), Sql::text($table));
    }

    /**
     * Eine Seite Zeilen.
     *
     * @param  array{column: string, operator: string, value: string}|null  $filter
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: list<string>, more: bool}
     */
    public function rows(
        Context $context,
        Credentials $as,
        string $database,
        string $schema,
        string $table,
        string $order,
        bool $descending,
        int $offset,
        ?array $filter = null,
    ): array {
        $columns = $this->columns($context, $as, $database, $schema, $table);

        $sql = self::rowsQuery($schema, $table, $columns, $order, $descending, $offset, $filter);

        $lines = $this->session->jsonAs($context, $as, $database, $sql, self::TIMEOUT_MS);

        return self::page($lines, $columns);
    }

    /**
     * Die Anweisung für eine Seite.
     *
     * **`LIMIT` ist um eins höher als die Seite** — daran und an nichts anderem
     * erkennt die Oberfläche, ob es weitergeht (`docs/46 §3`, Entscheidung 5,
     * Punkt 2). Ein `count(*)` über einen Filter liefe bei jedem Aufruf und ist
     * genau die Abfrage, die ins Zeitlimit läuft.
     *
     * **Das `ORDER BY` trägt den Tabellennamen, und daran hängt mehr als die
     * Reihenfolge.** `selectList()` gibt jeder Spalte ihren eigenen Namen als
     * Alias — `left(id::text, 513) AS "id"` —, und PostgreSQL löst einen
     * einfachen Namen im `ORDER BY` gegen die **Ausgabespalte** auf, nicht
     * gegen die Eingangsspalte (so steht es auch in seiner Dokumentation zu
     * `SELECT`). Sortiert wurde damit der gekürzte **Text**: Eine
     * `bigint`-Spalte kam als `1, 10, 100, 101` zurück.
     *
     * Der zweite Schaden ist der schwerere und war auf keinem Bild zu sehen:
     * Ein Sortierschlüssel `left(id::text, 513)` passt auf keinen Index. Gemessen
     * auf PostgreSQL 16.13 mit 200.000 Zeilen:
     *
     *     vorher:  Parallel Seq Scan on t  ->  Sort (left((t.id)::text, 513))
     *     nachher: Index Only Scan using t_pkey on t src
     *
     * Damit war die Zusage der Oberfläche hinfällig, über den Schlüssel zu
     * sortieren, *weil* dort ein Index liegt und die erste Seite deshalb nicht
     * ins Zeitlimit läuft. Er wurde nie benutzt.
     *
     * > **Ein Alias, der wie seine Spalte heisst, ist keine Umbenennung — er ist
     * > eine zweite Bedeutung desselben Namens.**
     *
     * Ein qualifizierter Name kann keine Ausgabespalte treffen; deshalb bekommt
     * die Tabelle einen Aliasnamen und das `ORDER BY` ihn davor. Die
     * Auswahlliste und das `WHERE` bleiben unqualifiziert — beide sehen
     * Ausgabenamen ohnehin nicht.
     *
     * **MariaDB war hier zufällig richtig** (`Db\Console::rowsQuery()`): Dort
     * steht die Kürzung in einem `JSON_OBJECT(...)`, das gar keinen Alias je
     * Spalte erzeugt, und `ORDER BY id` konnte nur die Spalte meinen. Zwei
     * Systeme, dieselbe Absicht, und nur eines hatte den Fehler.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array{column: string, operator: string, value: string}|null  $filter
     */
    public static function rowsQuery(
        string $schema,
        string $table,
        array $columns,
        string $order,
        bool $descending,
        int $offset,
        ?array $filter,
    ): string {
        $where = $filter === null
            ? ''
            : ' WHERE '.self::condition(self::column($columns, $filter['column']), $filter['operator'], $filter['value']);

        $direction = $descending ? 'DESC' : 'ASC';

        $terms = array_map(
            static fn (string $name): string => sprintf('%s.%s %s', self::SOURCE, Sql::identifier($name), $direction),
            self::orderColumns($columns, $order),
        );

        return sprintf(
            'SELECT row_to_json(t) FROM (SELECT %s FROM %s AS %s%s ORDER BY %s LIMIT %d OFFSET %d) t',
            implode(', ', self::selectList($columns, self::CELL_LIMIT)),
            Sql::qualified($schema, $table),
            self::SOURCE,
            $where,
            implode(', ', $terms),
            self::ROWS_PER_PAGE + 1,
            $offset,
        );
    }

    /**
     * Die Spalten, nach denen sortiert wird — die gewählte, dann der Schlüssel.
     *
     * **Eine Sortierung über eine mehrdeutige Spalte ist keine Reihenfolge.**
     * Der Server sagt zu, nach `ort` sortiert zu liefern, und über Zeilen mit
     * demselben `ort` sagt er **nichts** — die Reihenfolge darf sich zwischen
     * zwei Aufrufen ändern, und sie tut es, sobald der Plan sich ändert. Mit
     * `OFFSET` ist das kein Schönheitsfehler: Was beim zweiten Aufruf weiter
     * vorn liegt, erscheint auf Seite 2 noch einmal, und was nach hinten
     * rutscht, sieht der Kunde nie.
     *
     * Gemessen auf PostgreSQL 16.13, 120 Zeilen und drei Werten in `ort` —
     * Seite 1 ohne Index, Seite 2 mit:
     *
     *     ORDER BY ort       →  5 Zeilen doppelt, 25 Zeilen nie gesehen
     *     ORDER BY ort, id   →  0 doppelt, und die 20 offenen sind Seite 3
     *
     * > **Eine Sortierung ohne eindeutigen Schluss ist beim Blättern keine
     * > Sortierung, sondern eine Stichprobe.**
     *
     * **Ohne Schlüssel bleibt es dabei**, und das ist eine benannte Lücke: Es
     * gibt dann keine Spalte, die eine Zeile eindeutig macht. Sie trifft
     * dieselben Tabellen, die nach `docs/46 §10` ohnehin nur lesbar sind.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @return list<string>
     */
    public static function orderColumns(array $columns, string $order): array
    {
        $first = self::column($columns, $order)['name'];
        $names = [$first];

        foreach ($columns as $column) {
            if ($column['key'] && $column['name'] !== $first) {
                $names[] = $column['name'];
            }
        }

        return $names;
    }

    /**
     * Der ganze Wert einer Zelle.
     *
     * @param  array<string, string>  $key
     * @return array{value: string|null, truncated: bool, bytes: int}
     */
    public function cell(
        Context $context,
        Credentials $as,
        string $database,
        string $schema,
        string $table,
        array $key,
        string $column,
    ): array {
        $columns = $this->columns($context, $as, $database, $schema, $table);
        $wanted = self::column($columns, $column);

        if ($wanted['binary']) {
            throw AgentException::badRequest(
                'Eine binäre Spalte lässt sich nicht öffnen; die Tabellenansicht nennt ihre Länge.',
                ['column' => $column],
            );
        }

        $sql = sprintf(
            'SELECT row_to_json(t) FROM (SELECT left(%s::text, %d) AS "value", octet_length(%s::text) AS "bytes" FROM %s WHERE %s) t',
            Sql::identifier($wanted['name']),
            self::CELL_FULL_LIMIT + 1,
            Sql::identifier($wanted['name']),
            Sql::qualified($schema, $table),
            self::keyCondition($columns, $key),
        );

        $lines = $this->session->jsonAs($context, $as, $database, $sql, self::TIMEOUT_MS);

        if ($lines === []) {
            throw AgentException::badRequest('Diese Zeile gibt es nicht mehr.');
        }

        /** @var array{value: string|null, bytes: int|null} $decoded */
        $decoded = self::decode($lines[0]);
        $value = $decoded['value'] ?? null;
        $truncated = is_string($value) && mb_strlen($value) > self::CELL_FULL_LIMIT;

        return [
            'value' => $truncated ? mb_substr((string) $value, 0, self::CELL_FULL_LIMIT) : $value,
            'truncated' => $truncated,
            'bytes' => (int) ($decoded['bytes'] ?? 0),
        ];
    }

    /**
     * Eine Zeile anlegen, ändern oder löschen.
     *
     * @param  array<string, string>  $key
     * @param  array<string, string|null>  $values
     * @return array{affected: int}
     */
    public function write(
        Context $context,
        Credentials $as,
        string $database,
        string $schema,
        string $table,
        string $mode,
        array $key,
        array $values,
    ): array {
        $columns = $this->columns($context, $as, $database, $schema, $table);

        try {
            $this->session->executeAs(
                $context,
                $as,
                $database,
                self::writeStatement($schema, $table, $columns, $mode, $key, $values),
                self::TIMEOUT_MS,
            );
        } catch (AgentException $error) {
            /*
             * **Nur die eigene Meldung wird ausgepackt.** Alles andere — das
             * Zeitlimit, eine verletzte Fremdschlüsselbedingung, ein Trigger des
             * Kunden — behält seine Verpackung, denn dort *hat* die Datenbank
             * gesprochen und `docs/36 §17` verlangt ihren Wortlaut.
             */
            $getroffen = self::missedCount($error->getMessage());

            throw $getroffen === null ? $error : AgentException::execFailed(self::missed($getroffen));
        }

        // Der Block oben wirft, wenn es nicht genau eine Zeile war — hier
        // anzukommen heisst, dass es eine war.
        return ['affected' => 1];
    }

    /**
     * Der Schreibvorgang als eine Anweisung, die sich selbst nachzählt.
     *
     * ## Warum ein `DO`-Block
     *
     * **Weil „genau eine Zeile" sonst niemand durchsetzen kann.** Ein `UPDATE`
     * meldet über `psql` seine Trefferzahl nicht zurück, wenn `-q` läuft, und
     * eine zweite Anweisung, die nachzählt, käme aus einer zweiten Verbindung —
     * also aus einer zweiten Transaktion. `GET DIAGNOSTICS` fragt denselben
     * Vorgang, und `RAISE EXCEPTION` nimmt ihn mit zurück.
     *
     * {@see Ephemeral::group()} hat gegen `DO $$ … $$` argumentiert, und das
     * Argument gilt dort weiter: Dollar-Anführung durch drei Ebenen ist mehr
     * Erklärung als zwei Anweisungen wert, **wenn es auch ohne geht.** Hier geht
     * es nicht ohne, und die Anführung geht durch zwei Ebenen und nicht durch
     * drei — {@see Sql} sieht den Block nie, weil er nicht aus einem Wert
     * entsteht.
     *
     * **Und `plpgsql` ist da, auch in einer Datenbank aus `TEMPLATE template0`**
     * — gemessen am 12. August 2026, weil `docs/38 §10` jede Datenbank so anlegt
     * und `template0` sonst als „nackt" gilt. `initdb` legt die Sprache in
     * template0 selbst an; die Annahme, sie fehle dort, wäre die naheliegende
     * und die falsche gewesen.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array<string, string>  $key
     * @param  array<string, string|null>  $values
     */
    public static function writeStatement(
        string $schema,
        string $table,
        array $columns,
        string $mode,
        array $key,
        array $values,
    ): string {
        $target = Sql::qualified($schema, $table);

        $statement = match ($mode) {
            'insert' => sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $target,
                implode(', ', array_map(
                    static fn (string $name): string => Sql::identifier(self::column($columns, $name)['name']),
                    array_keys($values),
                )),
                implode(', ', array_map(self::literal(...), $values)),
            ),
            'update' => sprintf(
                'UPDATE %s SET %s WHERE %s',
                $target,
                implode(', ', array_map(
                    static fn (string $name): string => Sql::identifier(self::column($columns, $name)['name'])
                        .' = '.self::literal($values[$name]),
                    array_keys($values),
                )),
                self::keyCondition($columns, $key),
            ),
            'delete' => sprintf('DELETE FROM %s WHERE %s', $target, self::keyCondition($columns, $key)),
            default => throw AgentException::badRequest('Unbekannte Schreibart.', ['mode' => $mode]),
        };

        if ($mode !== 'delete' && $values === []) {
            throw AgentException::badRequest('Ein Schreibvorgang ohne eine einzige Spalte ändert nichts.');
        }

        /*
         * **`%%` und nicht `%`.** Das Prozentzeichen ist in `RAISE` der
         * Platzhalter für `getroffen` — und in {@see sprintf()} der Platzhalter
         * für das nächste Argument. Ohne die Verdoppelung zählt PHP zwei
         * Platzhalter und bekommt ein Argument; der Fehler heisst dann
         * `ArgumentCountError` und nennt eine Zeilennummer in dieser Datei,
         * nicht das Prozentzeichen.
         *
         * Gefunden hat ihn kein Nachdenken, sondern der erste Lauf gegen einen
         * echten Cluster — `php -l` sieht davon nichts, weil die Formatzeichen-
         * kette erst zur Laufzeit gezählt wird.
         */
        return sprintf(<<<'SQL'
            DO $srvpanel$
            DECLARE getroffen bigint;
            BEGIN
              %s;
              GET DIAGNOSTICS getroffen = ROW_COUNT;
              IF getroffen <> 1 THEN
                RAISE EXCEPTION '%s=%%', getroffen;
              END IF;
            END
            $srvpanel$
            SQL, $statement, self::MISS_MARKER);
    }

    /**
     * Was ein Schreibvorgang meldet, der nicht genau eine Zeile getroffen hat.
     *
     * **Der Satz steht in PHP und in keiner Anweisung** — und das ist die
     * Antwort auf Befund 2 aus `docs/47`.
     *
     * Vorher stand er wörtlich im `RAISE EXCEPTION` des `DO`-Blocks. Er kam
     * damit als Datenbankfehler zurück und trug die Verpackung dafür:
     *
     *     Die Datenbank hat abgewiesen: ERROR:  Der Vorgang hat 0 Zeilen …
     *     CONTEXT:  PL/pgSQL function inline_code_block line 7 at RAISE
     *
     * Ein Satz, den dieses Panel selbst geschrieben hat, mit einer Zeilennummer
     * auf eine Datei, die es nicht gibt — und mit einem Vorspann, der sagt, es
     * habe jemand anders gesprochen. MariaDB machte es von Anfang an richtig
     * (der Satz entsteht dort in PHP), und niemand hat entschieden, dass die
     * beiden es verschieden machen.
     *
     * > **Eine Verpackung, die für eine fremde Meldung richtig ist, ist für die
     * > eigene falsch.** (`docs/47 §6`, Befund 2.)
     *
     * Der Block schickt jetzt nur noch die **Zahl**, gekennzeichnet mit
     * {@see self::MISS_MARKER}; den Satz baut {@see self::write()} daraus. Für
     * jede andere Meldung bleibt die Verpackung, wo sie hingehört: Beim
     * Zeitlimit *ist* es die Meldung des Servers, und `docs/36 §17` verlangt sie
     * wörtlich.
     */
    public static function missed(int $affected): string
    {
        return sprintf(
            'Der Vorgang hat %d Zeilen getroffen und nicht genau eine; nichts wurde geändert.',
            max(0, $affected),
        );
    }

    /**
     * Die Zahl aus der Meldung des Blocks — oder `null`, wenn sie nicht von uns ist.
     *
     * **Streng verankert, und der Grund ist Kundentext.** Ein `str_contains`
     * über die ganze Meldung träfe auch eine Kennung, die zufällig so heisst und
     * in einer `DETAIL`-Zeile einer Eindeutigkeitsverletzung steht. Gesucht wird
     * deshalb eine `ERROR:`-Zeile, auf der nach der Marke **nichts mehr folgt** —
     * die Form, die `RAISE EXCEPTION '<Marke>=%'` erzeugt und die kein Wert
     * nachbauen kann, ohne selbst eine Fehlermeldung zu sein.
     *
     * **Nach vorn ist der Ausdruck ausdrücklich *nicht* verankert, und das war
     * ein Fehler.** Der erste Wurf schrieb `^ERROR:` — und traf nichts, weil
     * {@see Session} `Die Datenbank hat abgewiesen: ` davorsetzt und die Meldung
     * damit mitten in der Zeile beginnt. Der Fall sah aus wie „keine eigene
     * Meldung" und lief still in die alte Verpackung zurück.
     *
     * > **Ein Ausdruck, der nichts findet, sieht aus wie einer, der nichts zu
     * > finden hatte.**
     *
     * Gefunden hat es kein Nachdenken, sondern der Lauf gegen einen
     * Wegwerf-Cluster mit der Meldung, die dabei wirklich entsteht.
     */
    public static function missedCount(string $message): ?int
    {
        return preg_match('/ERROR:\s+'.preg_quote(self::MISS_MARKER, '/').'=(\d+)\s*$/m', $message, $treffer) === 1
            ? (int) $treffer[1]
            : null;
    }

    /**
     * Die Spaltenliste einer Abfrage.
     *
     * **Eine binäre Spalte kommt als Länge hinein und nie als Wert** — Regel 2
     * im Klassenkopf. Das ist eine Filterung der *Frage* und nicht des
     * *Ergebnisses*, und der Unterschied ist der ganze Punkt: Eine vergessene
     * Filterung der Frage zeigt eine Spalte zu viel, eine vergessene des
     * Ergebnisses zerstört die Seite.
     *
     * **Gefragt wird nach einem Zeichen mehr als erlaubt.** Kommt genau diese
     * Länge zurück, war der Wert länger — siehe Klassenkopf.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @return list<string>
     */
    public static function selectList(array $columns, int $limit): array
    {
        $list = [];

        foreach ($columns as $column) {
            $identifier = Sql::identifier($column['name']);

            $list[] = $column['binary']
                ? sprintf('octet_length(%s) AS %s', $identifier, $identifier)
                : sprintf('left(%s::text, %d) AS %s', $identifier, $limit + 1, $identifier);
        }

        return $list;
    }

    /**
     * Die Bedingung eines Filters.
     *
     * **`::text` auf beiden Seiten, und der Grund ist keine Bequemlichkeit.**
     * Ohne den Umweg über Text hiesse ein Filter `= 'abc'` auf einer
     * `integer`-Spalte `invalid input syntax for type integer` — eine Meldung des
     * Servers über eine Eingabe des Kunden, und für den Kunden ein Fehlschlag,
     * wo er eine leere Trefferliste erwartet.
     *
     * **`strpos` und nicht `LIKE`.** `LIKE '%wert%'` müsste `%` und `_` im Wert
     * maskieren, und diese Maskierung hätte ihr eigenes Fluchtzeichen — drei
     * Ebenen für eine Suche nach einer Zeichenkette. `strpos` kennt keine
     * Sonderzeichen.
     *
     * @param  array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}  $column
     */
    public static function condition(array $column, string $operator, string $value): string
    {
        $identifier = Sql::identifier($column['name']);

        return match ($operator) {
            'equals' => sprintf('%s::text = %s', $identifier, Sql::text($value)),
            'contains' => sprintf('strpos(%s::text, %s) > 0', $identifier, Sql::text($value)),
            'empty' => sprintf('(%s IS NULL OR %s::text = \'\')', $identifier, $identifier),
            default => throw AgentException::badRequest('Unbekannter Vergleich.', ['operator' => $operator]),
        };
    }

    /**
     * Die Bedingung, die genau eine Zeile trifft.
     *
     * **Jede Spalte des Schlüssels, und nichts sonst.** Ein `WHERE` über alle
     * angezeigten Spalten träfe zwei gleiche Zeilen beide — und über eine
     * gekürzte Zelle träfe es gar keine (`docs/46 §10.1`).
     *
     * **Ohne `::text`**, anders als im Filter: Ein Schlüssel hat einen Index, und
     * ein Umweg über Text nähme ihn. Ein Wert, der nicht zum Typ passt, ist hier
     * kein Fall — er kommt aus einer Zeile, die diese Tabelle gerade geliefert
     * hat.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array<string, string>  $key
     */
    public static function keyCondition(array $columns, array $key): string
    {
        $parts = [];

        foreach (self::checkedKey($columns, $key) as $name => $value) {
            $parts[] = Sql::identifier($name).' = '.Sql::text($value);
        }

        return implode(' AND ', $parts);
    }

    /**
     * Der geprüfte Schlüssel — die Regel, die beide Systeme teilen.
     *
     * **Sie stand zweimal da, und das ist genau das Muster, vor dem dieses
     * Projekt an zehn Stellen warnt:** {@see Db\Console::keyCondition()} war
     * Zeile für Zeile dieselbe Prüfung mit anderer Maskierung. Zwei Fassungen
     * einer Regel heissen, dass eine gepflegt wird und die andere nicht — und
     * bei der Prüfung auf die Vollständigkeit des Schlüssels wäre die zweite
     * gerade die gewesen, die es nicht bekommt.
     *
     * Was hier bleibt, ist die **Prüfung**; was dort bleibt, ist die
     * **Maskierung**. Die ist wirklich verschieden — `"` gegen `` ` ``.
     *
     * ## Drei Prüfungen, und die dritte ist neu
     *
     * 1. Der Schlüssel ist nicht leer. Ohne ihn ist die Tabelle nur lesbar
     *    (`docs/46 §10`).
     * 2. Jede genannte Spalte gehört zum Schlüssel. Sonst wäre die Bedingung
     *    ein `WHERE` über eine beliebige Spalte.
     * 3. **Jede Spalte des Schlüssels ist genannt.** Bei einem zusammengesetzten
     *    Schlüssel ist das der Unterschied: `WHERE b = '1'` über einem Schlüssel
     *    `(b, c)` trifft jede Zeile mit diesem `b`.
     *
     * Gefährlich ist der dritte Fall nicht — die Anweisung zählt nach und nimmt
     * zurück, was nicht genau eine Zeile war. Aber sie meldet dann „hat 3 Zeilen
     * getroffen", und das liest sich wie ein Nebenläufigkeitsproblem statt wie
     * ein unvollständiger Aufruf.
     *
     * > **Eine Sicherung, die den Schaden verhindert, erklärt ihn nicht.**
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array<string, string>  $key
     * @return array<string, string>
     */
    public static function checkedKey(array $columns, array $key): array
    {
        if ($key === []) {
            throw AgentException::badRequest(
                'Ohne Primärschlüssel lässt sich eine einzelne Zeile nicht eindeutig ansprechen.',
            );
        }

        $checked = [];

        foreach ($key as $name => $value) {
            $column = self::column($columns, (string) $name);

            if (! $column['key']) {
                throw AgentException::badRequest(
                    'Diese Spalte gehört nicht zum Primärschlüssel.',
                    ['column' => $column['name']],
                );
            }

            $checked[$column['name']] = (string) $value;
        }

        $expected = [];

        foreach ($columns as $column) {
            if ($column['key']) {
                $expected[] = $column['name'];
            }
        }

        $given = array_keys($checked);
        sort($expected);
        sort($given);

        if ($expected !== $given) {
            throw AgentException::badRequest(
                'Der Schlüssel dieser Zeile ist unvollständig.',
                ['expected' => implode(', ', $expected), 'given' => implode(', ', $given)],
            );
        }

        return $checked;
    }

    /**
     * Ein Wert, wie er in eine Anweisung kommt.
     *
     * **`null` ist ein eigener Zustand und keine leere Eingabe** (`docs/46
     * §10.1`). Wer die beiden gleich behandelt, macht aus jedem `NULL` einer
     * nullbaren Spalte lautlos eine leere Zeichenkette — beim Speichern einer
     * Zeile, an der niemand diese Spalte anfassen wollte.
     */
    public static function literal(?string $value): string
    {
        return $value === null ? 'NULL' : Sql::text($value);
    }

    /**
     * Die Spalte mit diesem Namen — oder gar keine Anweisung.
     *
     * **Das ist Regel 1 aus dem Klassenkopf, und sie steht an genau einer
     * Stelle.** Jeder Bezeichner, der aus einer Anfrage kommt, geht hier
     * hindurch; wer daran vorbei maskiert, baut die zweite Fassung der Prüfung.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @return array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}
     */
    public static function column(array $columns, string $name): array
    {
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        throw AgentException::badRequest('Diese Spalte gibt es in dieser Tabelle nicht.', ['column' => $name]);
    }

    /**
     * Eine Zeile der Tabellenliste.
     *
     * **`-1` heisst „unbekannt" und nicht „leer".** PostgreSQL setzt es für eine
     * Tabelle, die noch nie analysiert wurde (`docs/46 §2.2`, M19). Wer die Zahl
     * unbesehen weitergibt, schreibt „−1 Zeilen"; wer sie auf `max(0, …)`
     * klemmt, schreibt „0 Zeilen", und das ist schlimmer, weil es aussieht wie
     * eine Antwort.
     *
     * @param  list<string>  $row
     * @return array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}
     */
    public static function table(array $row): array
    {
        $estimate = ($row[3] ?? '') === '' ? -1 : (int) $row[3];

        return [
            'schema' => (string) ($row[0] ?? ''),
            'name' => (string) ($row[1] ?? ''),
            'kind' => self::KINDS[(string) ($row[2] ?? '')] ?? self::VIEW,
            'rows' => $estimate < 0 ? null : $estimate,
            'bytes' => max(0, (int) ($row[4] ?? 0)),
            'key' => ($row[5] ?? '') === 't',
        ];
    }

    /**
     * Die Antwort einer Seite, aus den JSON-Zeilen.
     *
     * **Die einundfünfzigste Zeile wird gezählt und nicht ausgeliefert.** Sie ist
     * die ganze Auskunft „es geht weiter" (`docs/46 §12`).
     *
     * @param  list<string>  $lines
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: list<string>, more: bool}
     */
    public static function page(array $lines, array $columns): array
    {
        $more = count($lines) > self::ROWS_PER_PAGE;
        $rows = [];
        $truncated = [];

        foreach (array_slice($lines, 0, self::ROWS_PER_PAGE) as $line) {
            $row = self::decode($line);

            foreach ($row as $name => $value) {
                if (is_string($value) && mb_strlen($value) > self::CELL_LIMIT) {
                    $row[$name] = mb_substr($value, 0, self::CELL_LIMIT);

                    if (! in_array((string) $name, $truncated, true)) {
                        $truncated[] = (string) $name;
                    }
                }
            }

            $rows[] = $row;
        }

        return [
            'columns' => array_map(static fn (array $column): string => $column['name'], $columns),
            'rows' => $rows,
            'truncated' => $truncated,
            'more' => $more,
        ];
    }

    /**
     * Eine JSON-Zeile in ein Feld.
     *
     * **Ein Fehlschlag hier ist kein Datenfehler, sondern ein Baufehler** — und
     * zwar genau der aus `docs/46 §8.2`: eine binäre Spalte, die in die
     * Spaltenliste geraten ist. Deshalb nennt die Meldung den Verdacht und nicht
     * nur „ungültiges JSON".
     *
     * @return array<string, mixed>
     */
    public static function decode(string $line): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($line, true);

        if (! is_array($decoded)) {
            /*
             * **Zwei Ursachen und nicht eine.**
             *
             * Hier stand nur die Frage nach der binären Spalte. Sie war für
             * ihren Fall richtig (`docs/46 §8.2`) — und im Abnahmelauf vom
             * 12. August 2026 war es die andere: `mysql` lief ohne
             * `--default-character-set`, gab ein `ü` als latin1-Byte `FC` aus,
             * und `json_decode()` scheiterte an ungültigem UTF-8. Eine binäre
             * Spalte gab es in der Abfrage nicht.
             *
             * > **Ein Hinweis, der genau eine Ursache nennt, ist eine Diagnose —
             * > und eine falsche Diagnose ist teurer als keine.**
             */
            throw AgentException::execFailed(sprintf(
                'Die Antwort der Datenbank liess sich nicht lesen (%s). Zwei Ursachen kommen in '
                .'Frage: eine binäre Spalte in der Abfrage, oder eine Verbindung, die nicht auf '
                .'utf8mb4 steht.',
                json_last_error_msg(),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
