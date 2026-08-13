<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Pg\Console as PgConsole;

/**
 * Das Datenbankmanagement für MariaDB — das Gegenstück zu {@see PgConsole}.
 *
 * Der Plan ist `docs/46`. **Die drei Regeln des Klassenkopfs dort gelten hier
 * wörtlich** — ein Bezeichner wird nachgeschlagen und nicht nur maskiert, eine
 * binäre Spalte erreicht das JSON gar nicht erst, und gekürzt wird in der
 * Anweisung. Die Grenzen kommen aus {@see PgConsole}, damit es sie einmal gibt
 * und nicht zweimal.
 *
 * ## Vier Unterschiede, und jeder ist gemessen
 *
 * **1. `--raw` und nicht `--batch` allein** (`docs/46 §8.1`, N1/N2). `--batch`
 * maskiert in der Ausgabe Tabulator, Zeilenumbruch **und den Rückstrich** — und
 * eine JSON-Zeichenkette besteht aus maskierten Rückstrichen. Aus `"a\tb"` wird
 * `"a\\tb"`, und das ist gültiges JSON mit einem falschen Wert. Der Schalter
 * steht in {@see Session::jsonAs()} und nirgends sonst.
 *
 * **2. Eine binäre Spalte ist hier keine Geschmacksfrage** (`docs/46 §8.2`,
 * N3–N5). `JSON_OBJECT()` schreibt die rohen Bytes eines `BLOB` in die
 * Zeichenkette; MariaDBs `JSON_VALID()` sagt dazu `1`, und PHPs `json_decode()`
 * gibt `null` zurück — für die **ganze Zeile**. In PostgreSQL wäre dieselbe
 * Spalte harmlos.
 *
 * **3. Es gibt kein Schema neben der Datenbank.** In MariaDB *ist* die Datenbank
 * das Schema. Die Operationen tragen das Feld trotzdem, damit die Anwendung für
 * beide Systeme eine Frage stellt und nicht zwei — hier muss es gleich dem
 * Namen der Datenbank sein, und {@see self::schema()} besteht darauf.
 *
 * **4. Der Schreibvorgang zählt anders nach.** PostgreSQL bekommt einen
 * `DO`-Block mit `GET DIAGNOSTICS`; MariaDB kennt keinen anonymen Block
 * ausserhalb einer gespeicherten Routine. An seine Stelle treten zwei Dinge, die
 * zusammen dasselbe leisten: **`LIMIT 1`** macht mehr als eine Zeile unmöglich,
 * und **`ROW_COUNT()`** sagt, ob es null waren. Siehe {@see self::writeStatement()}.
 */
final class Console
{
    /**
     * Die Zeitgrenze, in Sekunden.
     *
     * MariaDB rechnet `max_statement_time` in Sekunden und nimmt Bruchteile;
     * PostgreSQL rechnet `statement_timeout` in Millisekunden. Die Zahl steht
     * einmal in {@see PgConsole::TIMEOUT_MS} und wird hier umgerechnet — zwei
     * Zahlen für dieselbe Grenze liefen auseinander.
     */
    public const TIMEOUT_SECONDS = PgConsole::TIMEOUT_MS / 1000;

    /**
     * Die Typen, die nicht als Wert in eine Abfrage dürfen.
     *
     * **Aus `DATA_TYPE` und nicht aus `COLUMN_TYPE`.** Das zweite trägt die
     * Länge mit (`varbinary(255)`) und liesse sich nur mit einem Muster prüfen;
     * das erste ist der nackte Typname und passt in eine Positivliste.
     *
     * `bit` steht dabei, obwohl es kein Blob ist: Auch dort kommen Bytes und
     * keine Zeichen zurück.
     *
     * @var list<string>
     */
    public const BINARY_TYPES = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit'];

    /** `TABLE_TYPE` in die zwei Arten aus {@see PgConsole::KINDS}. */
    public const KINDS = ['BASE TABLE' => PgConsole::TABLE, 'SYSTEM VIEW' => PgConsole::VIEW, 'VIEW' => PgConsole::VIEW];

    /**
     * Der Name, unter dem MariaDB den Primärschlüssel führt.
     *
     * Er ist eine Zeichenkette und kein Kennzeichen: `information_schema.STATISTICS`
     * hat keine Spalte „ist Primärschlüssel", der Index heisst schlicht so. Die
     * Konstante steht hier, damit der Vergleich einmal dasteht und nicht in der
     * Abfrage und noch einmal im Lesen der Antwort.
     */
    public const PRIMARY = 'PRIMARY';

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Ephemeral $ephemeral = new Ephemeral,
    ) {}

    /**
     * Der Rahmen jeder Konsolenoperation.
     *
     * Wortgleich {@see PgConsole::within()}, und die Begründung steht dort: Läuft
     * die Abfrage unter der Kennung des Agenten, sieht die Antwort genau gleich
     * aus, und die Mandantentrennung ruht allein auf {@see Names::belongsTo()}.
     *
     * **Das Feld heisst `prefix` und nicht `user`**, anders als bei den älteren
     * `db.*`-Operationen aus P5. Die fünf Konsolenoperationen sind für beide
     * Systeme zusammen entworfen, und die Anwendung soll **eine** Nutzlast bauen
     * statt zweier, die sich in einem Feldnamen unterscheiden. Die alten
     * Operationen behalten `user`: Sie umzubenennen wäre eine Änderung an einer
     * Schnittstelle, über die Vorgänge in der Warteschlange liegen können —
     * genau der Fall, für den `docs/19 §4a` sagt, dass eine echte Schnittstelle
     * bleibt, wie sie ist.
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
     * Die Tabellen einer Datenbank.
     *
     * @return list<array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}>
     */
    public function tables(Context $context, Credentials $as, string $database): array
    {
        $tables = [];

        foreach ($this->session->queryAs($context, $as, self::tablesQuery($database)) as $row) {
            $tables[] = self::table($row);
        }

        return $tables;
    }

    /**
     * Die Abfrage nach den Tabellen.
     *
     * **`information_schema` filtert nach Rechten** — gemessen (`docs/46 §2.3`,
     * N11): Der Kundenbenutzer sieht seine vier Tabellen und null fremde. Ein
     * `has_table_privilege` wie in PostgreSQL gibt es hier nicht und wird auch
     * nicht gebraucht.
     *
     * ## Der Schlüssel kommt aus derselben Quelle wie in {@see self::columns()}
     *
     * **Hier stand `STATISTICS.INDEX_NAME = 'PRIMARY'`, und das ist nicht,
     * was §10 Regel 2 meint.** MariaDB befördert den ersten eindeutigen Index
     * über Spalten ohne `NULL` zum impliziten Primärschlüssel und meldet ihn in
     * `COLUMNS.COLUMN_KEY` als `PRI` — **den Index selbst benennt sie dabei
     * nicht um.** `nur_unique` hatte also `COLUMN_KEY = 'PRI'` und keinen Index
     * namens `PRIMARY`; die Spaltenansicht sagte „Schlüssel: ja", die
     * Tabellenliste „ohne Schlüssel", und beide lasen denselben Katalog.
     *
     * > **Zwei Abfragen an dieselbe Frage sind zwei Antworten, solange sie nicht
     * > dieselbe Spalte lesen.**
     *
     * Gefunden auf `cloudsrv24` am 13. August 2026 (`docs/46 §20.46`) — von
     * keinem Test, denn beide Abfragen waren für sich genommen richtig.
     */
    public static function tablesQuery(string $database): string
    {
        return sprintf(<<<'SQL'
            SELECT t.TABLE_SCHEMA,
                   t.TABLE_NAME,
                   t.TABLE_TYPE,
                   t.TABLE_ROWS,
                   COALESCE(t.DATA_LENGTH, 0) + COALESCE(t.INDEX_LENGTH, 0),
                   EXISTS (SELECT 1 FROM information_schema.COLUMNS k
                            WHERE k.TABLE_SCHEMA = t.TABLE_SCHEMA
                              AND k.TABLE_NAME = t.TABLE_NAME
                              AND k.COLUMN_KEY = 'PRI')
              FROM information_schema.TABLES t
             WHERE t.TABLE_SCHEMA = %s
             ORDER BY 2
            SQL, Sql::text($database));
    }

    /**
     * Die Spalten einer Tabelle — und die Liste, gegen die alles geprüft wird.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    public function columns(Context $context, Credentials $as, string $database, string $table): array
    {
        $rows = $this->session->queryAs($context, $as, self::columnsQuery($database, $table));

        if ($rows === []) {
            throw AgentException::badRequest('Diese Tabelle gibt es in dieser Datenbank nicht.', [
                'table' => $table,
            ]);
        }

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => (string) ($row[0] ?? ''),
                'type' => (string) ($row[1] ?? ''),
                'nullable' => ($row[2] ?? '') === 'YES',
                // **`NULL` und die leere Zeichenkette sind auch hier zwei
                // Dinge.** `--batch` gibt einen echten NULL-Wert als `NULL`
                // aus, eine leere Vorgabe als leeres Feld — und `DEFAULT ''`
                // gibt es (`docs/46 §10.1`).
                'default' => ($row[3] ?? 'NULL') === 'NULL' ? null : (string) $row[3],
                'key' => ($row[4] ?? '') === 'PRI',
                'binary' => in_array(strtolower((string) ($row[5] ?? '')), self::BINARY_TYPES, true),
            ];
        }

        return $columns;
    }

    public static function columnsQuery(string $database, string $table): string
    {
        return sprintf(<<<'SQL'
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, DATA_TYPE
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             ORDER BY ORDINAL_POSITION
            SQL, Sql::text($database), Sql::text($table));
    }

    /**
     * Die Indexe einer Tabelle — das Gegenstück zu {@see PgConsole::indexes()}.
     *
     * @return list<array{name: string, columns: string, unique: bool, primary: bool}>
     */
    public function indexes(Context $context, Credentials $as, string $database, string $table): array
    {
        $indexes = [];

        foreach ($this->session->queryAs($context, $as, self::indexesQuery($database, $table)) as $row) {
            $name = (string) ($row[0] ?? '');

            $indexes[] = [
                'name' => $name,
                'columns' => (string) ($row[2] ?? ''),
                // `NON_UNIQUE` ist 0 für eindeutig — die Spalte fragt nach dem
                // Gegenteil dessen, was hier steht, und das ist die Sorte
                // Umkehrung, die man einmal falsch herum liest.
                'unique' => ($row[1] ?? '1') === '0',
                'primary' => $name === self::PRIMARY,
            ];
        }

        return $indexes;
    }

    /**
     * Die Abfrage nach den Indexen.
     *
     * **`GROUP_CONCAT` mit `ORDER BY SEQ_IN_INDEX`, und die Reihenfolge ist
     * nicht kosmetisch:** Ein Index über `(kunde, datum)` hilft einer Sortierung
     * nach `kunde`, einer nach `datum` nicht. Ohne `ORDER BY` gibt MariaDB die
     * Spalten in einer Reihenfolge zurück, die nichts bedeutet — und die Anzeige
     * behauptete etwas über die Tabelle, das nicht stimmt.
     *
     * `information_schema.STATISTICS` führt je Spalte eine Zeile; die Gruppierung
     * macht daraus je Index eine.
     */
    public static function indexesQuery(string $database, string $table): string
    {
        return sprintf(<<<'SQL'
            SELECT INDEX_NAME, NON_UNIQUE,
                   GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ')
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             GROUP BY INDEX_NAME, NON_UNIQUE
             ORDER BY INDEX_NAME = %s DESC, INDEX_NAME
            SQL, Sql::text($database), Sql::text($table), Sql::text(self::PRIMARY));
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
        string $table,
        string $order,
        bool $descending,
        int $offset,
        ?array $filter = null,
    ): array {
        $columns = $this->columns($context, $as, $database, $table);

        $lines = $this->session->jsonAs(
            $context,
            $as,
            self::rowsQuery($database, $table, $columns, $order, $descending, $offset, $filter),
        );

        return PgConsole::page($lines, $columns);
    }

    /**
     * Die Anweisung für eine Seite.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array{column: string, operator: string, value: string}|null  $filter
     */
    public static function rowsQuery(
        string $database,
        string $table,
        array $columns,
        string $order,
        bool $descending,
        int $offset,
        ?array $filter,
    ): string {
        $where = $filter === null
            ? ''
            : ' WHERE '.self::condition(PgConsole::column($columns, $filter['column']), $filter['operator'], $filter['value']);

        $direction = $descending ? 'DESC' : 'ASC';

        // Die gewählte Spalte, dann der Schlüssel — die Begründung steht bei
        // `PgConsole::orderColumns()` und gilt hier genauso: MariaDB sagt über
        // die Reihenfolge gleicher Werte ebenfalls nichts zu, und mit `OFFSET`
        // sieht der Kunde dann Zeilen doppelt, während andere ausfallen.
        $terms = array_map(
            static fn (string $name): string => Sql::identifier($name).' '.$direction,
            PgConsole::orderColumns($columns, $order),
        );

        return sprintf(
            'SELECT %s FROM %s%s ORDER BY %s LIMIT %d OFFSET %d',
            self::jsonObject($columns, PgConsole::CELL_LIMIT),
            Sql::qualified($database, $table),
            $where,
            implode(', ', $terms),
            PgConsole::ROWS_PER_PAGE + 1,
            $offset,
        );
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
        string $table,
        array $key,
        string $column,
    ): array {
        $columns = $this->columns($context, $as, $database, $table);
        $wanted = PgConsole::column($columns, $column);

        if ($wanted['binary']) {
            throw AgentException::badRequest(
                'Eine binäre Spalte lässt sich nicht öffnen; die Tabellenansicht nennt ihre Länge.',
                ['column' => $column],
            );
        }

        $identifier = Sql::identifier($wanted['name']);

        $lines = $this->session->jsonAs($context, $as, sprintf(
            'SELECT JSON_OBJECT(%s, LEFT(CAST(%s AS CHAR), %d), %s, OCTET_LENGTH(%s)) FROM %s WHERE %s',
            Sql::text('value'),
            $identifier,
            PgConsole::CELL_FULL_LIMIT + 1,
            Sql::text('bytes'),
            $identifier,
            Sql::qualified($database, $table),
            self::keyCondition($columns, $key),
        ));

        if ($lines === []) {
            throw AgentException::badRequest('Diese Zeile gibt es nicht mehr.');
        }

        /** @var array{value: string|null, bytes: int|null} $decoded */
        $decoded = PgConsole::decode($lines[0]);
        $value = $decoded['value'] ?? null;
        $truncated = is_string($value) && mb_strlen($value) > PgConsole::CELL_FULL_LIMIT;

        return [
            'value' => $truncated ? mb_substr((string) $value, 0, PgConsole::CELL_FULL_LIMIT) : $value,
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
        string $table,
        string $mode,
        array $key,
        array $values,
    ): array {
        $columns = $this->columns($context, $as, $database, $table);

        /*
         * **Zwei Anweisungen in einem Lauf und damit in einer Verbindung.**
         * `ROW_COUNT()` bezieht sich auf die vorige Anweisung derselben
         * Sitzung; ein zweiter Aufruf wäre eine zweite Verbindung und hätte
         * darauf keine Antwort mehr.
         */
        $rows = $this->session->queryAs($context, $as, implode(";\n", [
            self::writeStatement($database, $table, $columns, $mode, $key, $values),
            'SELECT ROW_COUNT()',
        ]));

        $affected = (int) ($rows[0][0] ?? -1);

        if ($affected !== 1) {
            // Der Satz steht in {@see PgConsole::missed()} und hier nicht noch
            // einmal: Beide Systeme melden denselben Fall und sollen ihn nicht in
            // zwei Fassungen melden (`docs/47 §6`, Befund 2).
            throw AgentException::execFailed(PgConsole::missed($affected));
        }

        return ['affected' => 1];
    }

    /**
     * Der Schreibvorgang, so gebaut, dass er nicht mehr als eine Zeile treffen kann.
     *
     * ## Warum kein Block wie in PostgreSQL
     *
     * `Pg\Console::writeStatement()` baut einen `DO`-Block mit
     * `GET DIAGNOSTICS` und `RAISE EXCEPTION`. **MariaDB kennt keinen anonymen
     * Block ausserhalb einer gespeicherten Routine** — ein `BEGIN … END` mit
     * `SIGNAL` bräuchte eine Prozedur, also ein Ding, das den Lauf überlebt und
     * aufgeräumt werden müsste. Das ist genau die Sorte Rest, die dieses Projekt
     * sonst einsammelt.
     *
     * ## Was an seine Stelle tritt, und warum es zusammen reicht
     *
     * **`LIMIT 1` macht „mehr als eine" unmöglich.** MariaDB erlaubt es an
     * `UPDATE` und `DELETE`; PostgreSQL nicht, und genau deshalb steht dort der
     * Block. Es ist kein Ersatz für {@see self::keyCondition()}, sondern ein
     * Riegel dahinter: Die Bedingung nennt ohnehin nur Schlüsselspalten, und
     * `LIMIT 1` deckt den einen Fall ab, den sie nicht kann — dass der
     * eindeutige Index zwischen Anzeige und Änderung verschwindet.
     *
     * **`ROW_COUNT()` sagt, ob es null waren**, und darauf antwortet
     * {@see self::write()} mit derselben Meldung, die PostgreSQL aus dem Block
     * schickt. Null Zeilen heisst: Es wurde nichts geändert — da ist nichts
     * zurückzunehmen, und die Auskunft ist der ganze Zweck.
     *
     * > **Zwei Systeme dürfen dieselbe Zusage auf zwei Wegen halten. Sie dürfen
     * > sie nicht auf einem halten und auf dem anderen behaupten.**
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array<string, string>  $key
     * @param  array<string, string|null>  $values
     */
    public static function writeStatement(
        string $database,
        string $table,
        array $columns,
        string $mode,
        array $key,
        array $values,
    ): string {
        if ($mode !== 'delete' && $values === []) {
            throw AgentException::badRequest('Ein Schreibvorgang ohne eine einzige Spalte ändert nichts.');
        }

        $target = Sql::qualified($database, $table);

        return match ($mode) {
            'insert' => sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $target,
                implode(', ', array_map(
                    static fn (string $name): string => Sql::identifier(PgConsole::column($columns, $name)['name']),
                    array_keys($values),
                )),
                implode(', ', array_map(PgConsole::literal(...), $values)),
            ),
            'update' => sprintf(
                'UPDATE %s SET %s WHERE %s LIMIT 1',
                $target,
                implode(', ', array_map(
                    static fn (string $name): string => Sql::identifier(PgConsole::column($columns, $name)['name'])
                        .' = '.PgConsole::literal($values[$name]),
                    array_keys($values),
                )),
                self::keyCondition($columns, $key),
            ),
            'delete' => sprintf('DELETE FROM %s WHERE %s LIMIT 1', $target, self::keyCondition($columns, $key)),
            default => throw AgentException::badRequest('Unbekannte Schreibart.', ['mode' => $mode]),
        };
    }

    /**
     * Die Spaltenliste als `JSON_OBJECT`.
     *
     * **Der Name der Spalte steht als Textliteral und nicht als Bezeichner** —
     * er ist hier ein Schlüssel im JSON und keine Relation. Deshalb geht er
     * durch {@see Sql::text()} und nicht durch {@see Sql::identifier()}; der
     * Wert daneben umgekehrt.
     *
     * **Eine binäre Spalte kommt als Länge hinein und nie als Wert** — der Grund
     * steht im Klassenkopf, Unterschied 2, und er ist in diesem System kein
     * Geschmack, sondern die Bedingung dafür, dass die Zeile überhaupt lesbar
     * ankommt.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     */
    public static function jsonObject(array $columns, int $limit): string
    {
        $parts = [];

        foreach ($columns as $column) {
            $identifier = Sql::identifier($column['name']);

            $parts[] = Sql::text($column['name']);
            $parts[] = $column['binary']
                ? sprintf('OCTET_LENGTH(%s)', $identifier)
                : sprintf('LEFT(CAST(%s AS CHAR), %d)', $identifier, $limit + 1);
        }

        return 'JSON_OBJECT('.implode(', ', $parts).')';
    }

    /**
     * Die Bedingung eines Filters.
     *
     * **`LOCATE` und nicht `LIKE`** — dieselbe Überlegung wie `strpos` im
     * Gegenstück: `LIKE '%wert%'` müsste `%` und `_` im Wert maskieren, und die
     * Maskierung hätte ihr eigenes Fluchtzeichen.
     *
     * **`CAST(… AS CHAR)` auf beiden Seiten**, damit ein Filter auf einer
     * Zahlenspalte eine leere Trefferliste ergibt und keine Meldung des Servers.
     *
     * @param  array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}  $column
     */
    public static function condition(array $column, string $operator, string $value): string
    {
        $identifier = Sql::identifier($column['name']);

        return match ($operator) {
            'equals' => sprintf('CAST(%s AS CHAR) = %s', $identifier, Sql::text($value)),
            'contains' => sprintf('LOCATE(%s, CAST(%s AS CHAR)) > 0', Sql::text($value), $identifier),
            'empty' => sprintf('(%s IS NULL OR CAST(%s AS CHAR) = \'\')', $identifier, $identifier),
            default => throw AgentException::badRequest('Unbekannter Vergleich.', ['operator' => $operator]),
        };
    }

    /**
     * Die Bedingung, die genau eine Zeile trifft.
     *
     * **Die Prüfung steht in {@see PgConsole::checkedKey()} und hier nur noch
     * die Maskierung.** Vorher war das Zeile für Zeile dieselbe Methode mit
     * `` ` `` statt `"` — und als die Prüfung auf die *Vollständigkeit* des
     * Schlüssels dazukam, wäre diese Fassung die gewesen, die sie nicht bekommt.
     * Verschieden ist an den beiden wirklich nur, wie ein Bezeichner
     * eingefasst wird.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>  $columns
     * @param  array<string, string>  $key
     */
    public static function keyCondition(array $columns, array $key): string
    {
        $parts = [];

        foreach (PgConsole::checkedKey($columns, $key) as $name => $value) {
            $parts[] = Sql::identifier($name).' = '.Sql::text($value);
        }

        return implode(' AND ', $parts);
    }

    /**
     * Das Schema, das hier die Datenbank ist.
     *
     * In MariaDB gibt es kein Schema neben der Datenbank. Die Anwendung schickt
     * das Feld trotzdem, weil sie **eine** Frage für beide Systeme stellt; hier
     * darf es nur die Datenbank selbst nennen. Ein anderer Wert wäre kein Fehler
     * des Kunden, sondern einer im Panel — und er soll auffallen, statt still
     * ignoriert zu werden.
     */
    public static function schema(string $schema, string $database): string
    {
        if ($schema !== '' && $schema !== $database) {
            throw AgentException::badRequest(
                'In MariaDB ist die Datenbank das Schema; ein anderes gibt es nicht.',
                ['schema' => $schema, 'database' => $database],
            );
        }

        return $database;
    }

    /**
     * Eine Zeile der Tabellenliste.
     *
     * **`TABLE_ROWS` ist eine Schätzung und für eine Sicht `NULL`**
     * (`docs/46 §2.3`, N9/N10). Das ist die MariaDB-Fassung der `-1`-Falle aus
     * PostgreSQL, nur an einer anderen Stelle: Eine Basistabelle liefert hier
     * immer eine Zahl, auch eine nie analysierte — und wer nur die prüft,
     * schreibt unter jede Sicht „0 Zeilen".
     *
     * @param  list<string>  $row
     * @return array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}
     */
    public static function table(array $row): array
    {
        $estimate = $row[3] ?? 'NULL';

        return [
            'schema' => (string) ($row[0] ?? ''),
            'name' => (string) ($row[1] ?? ''),
            'kind' => self::KINDS[(string) ($row[2] ?? '')] ?? PgConsole::VIEW,
            'rows' => $estimate === 'NULL' || $estimate === '' ? null : max(0, (int) $estimate),
            'bytes' => max(0, (int) ($row[4] ?? 0)),
            'key' => ($row[5] ?? '') === '1',
        ];
    }
}
