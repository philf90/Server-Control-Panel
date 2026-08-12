<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Support\Databases\Engines\EngineDriver;
use App\Support\Databases\Engines\MariaDbDriver;
use App\Support\Databases\Engines\PostgresDriver;
use SrvPanel\Agent\Client;

/**
 * Das Datenbankmanagement — die Seite des Panels.
 *
 * Der Plan ist `docs/46`. Diese Klasse ist schmal, und das ist ihr Zweck: Sie
 * setzt die Nutzlast zusammen, ruft den Agenten und gibt zurück, was er sagt.
 * **Was eine Anweisung wird, entscheidet der Agent** (`Pg\Console`,
 * `Db\Console`) — hier steht kein SQL, und hier steht auch keine zweite Fassung
 * der Prüfungen von dort.
 *
 * ## Drei Regeln, und keine davon ist eine Formsache
 *
 * **1. Kein Aufruf geht durch die Warteschlange.** Ein eingereihter Vorgang legt
 * seine Argumente in `operations.payload` ab, und dort stünde ein Filterwert
 * oder der Inhalt einer Kundenzeile (`docs/46 §12`). Das ist dieselbe Regel wie
 * für Passwörter (`docs/36 §4`) mit einem weiter gefassten Anlass:
 *
 * > **Was nicht in der Warteschlange stehen darf, ist nicht nur ein Geheimnis —
 * > es ist alles, was dem Kunden gehört.**
 *
 * Alle fünf laufen deshalb als unmittelbarer Aufruf ({@see Client::call()}),
 * wie `db.user.create` und `pg.remote.access`. Ein Vorgang mit
 * Fortschrittsanzeige wäre für eine Anzeige, die in fünfzig Millisekunden
 * fertig ist, ohnehin die falsche Bauform.
 *
 * **2. Die Datenbank kommt als Modell und nicht als Name.** Damit ist sie durch
 * die Mandantenklammer gekommen, bevor diese Klasse sie sieht — und der Agent
 * prüft danach ein zweites Mal gegen das Präfix des Abonnements
 * ({@see \SrvPanel\Agent\Pg\Console::within()}). Zwei Wände, und die zweite
 * gehört uns nicht.
 *
 * **3. Auf das System wird an einer Stelle verzweigt.** {@see self::driver()}
 * ist dieselbe Bauform wie {@see Databases::driver()}, und der Griff kommt als
 * kurzer Name — `tables`, `rows` —, den der Treiber zu `db.console.rows` oder
 * `pg.console.rows` macht. Fünf `match` über die Aufzählung wären fünf Stellen,
 * an denen ein drittes System vergessen werden kann.
 */
final class Console
{
    public function __construct(
        private readonly Client $agent,
        private readonly MariaDbDriver $mariadb,
        private readonly PostgresDriver $postgres,
    ) {}

    /**
     * Die Tabellen einer Datenbank.
     *
     * @return list<array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}>
     */
    public function tables(Database $database): array
    {
        /** @var list<array{schema: string, name: string, kind: string, rows: int|null, bytes: int, key: bool}> $tables */
        $tables = $this->call($database, 'tables')['tables'] ?? [];

        return $tables;
    }

    /**
     * Die Struktur einer Tabelle.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}>
     */
    public function columns(Database $database, string $table): array
    {
        /** @var list<array{name: string, type: string, nullable: bool, default: string|null, key: bool, binary: bool}> $columns */
        $columns = $this->call($database, 'columns', ['table' => $table])['columns'] ?? [];

        return $columns;
    }

    /**
     * Eine Seite Zeilen.
     *
     * **`offset` und keine Seitennummer**, und **kein `total`.** Was
     * zurückkommt, ist `more` — die einundfünfzigste Zeile, die gezählt und
     * nicht ausgeliefert wurde. Eine Trefferzahl unter einem Filter wäre ein
     * `count(*)` bei jedem Aufruf (`docs/46 §9`).
     *
     * @param  array{column: string, operator: string, value: string}|null  $filter
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: list<string>, more: bool}
     */
    public function rows(
        Database $database,
        string $table,
        string $order,
        bool $descending = false,
        int $offset = 0,
        ?array $filter = null,
    ): array {
        /** @var array{columns: list<string>, rows: list<array<string, mixed>>, truncated: list<string>, more: bool} $page */
        $page = $this->call($database, 'rows', array_filter([
            'table' => $table,
            'order' => $order,
            'direction' => $descending ? 'desc' : 'asc',
            'offset' => $offset,
            'filter' => $filter,
        ], static fn (mixed $value): bool => $value !== null));

        return $page;
    }

    /**
     * Der ganze Wert einer Zelle.
     *
     * @param  array<string, string>  $key
     * @return array{value: string|null, truncated: bool, bytes: int}
     */
    public function cell(Database $database, string $table, array $key, string $column): array
    {
        /** @var array{value: string|null, truncated: bool, bytes: int} $cell */
        $cell = $this->call($database, 'cell', [
            'table' => $table,
            'key' => $key,
            'column' => $column,
        ]);

        return $cell;
    }

    /**
     * Eine Zeile anlegen, ändern oder löschen.
     *
     * **`values` trägt `null` als `null`** und nicht als leere Zeichenkette —
     * der Unterschied ist der ganze Punkt von `docs/46 §10.1`, und er geht
     * unverändert bis in die Anweisung. Deshalb steht hier auch kein
     * `array_filter`: Es würfe genau die Spalten weg, die auf `NULL` gesetzt
     * werden sollen.
     *
     * @param  array<string, string>  $key
     * @param  array<string, string|null>  $values
     * @return array{affected: int}
     */
    public function write(Database $database, string $table, string $mode, array $key, array $values): array
    {
        /** @var array{affected: int} $result */
        $result = $this->call($database, 'row.write', [
            'table' => $table,
            'mode' => $mode,
            'key' => $key,
            'values' => $values,
        ]);

        return $result;
    }

    /**
     * Der Aufruf, den alle fünf teilen.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(Database $database, string $handle, array $arguments = []): array
    {
        $driver = $this->driver($database->engine);
        $subscription = $database->subscription;

        if ($subscription === null) {
            // Eine verwaiste Datenbank hat kein Präfix mehr, gegen das der
            // Agent prüfen könnte — sie ist die Spur eines gescheiterten
            // Rückbaus (`docs/36 §5`) und gehört in `srvpanel db prune`, nicht
            // in die Konsole.
            throw new \RuntimeException('Zu dieser Datenbank gibt es kein Abonnement mehr.');
        }

        return $this->agent->call($driver->consoleOperation($handle), array_merge([
            'prefix' => $driver->prefix($subscription),
            'database' => (string) $database->name,
            'schema' => $driver->consoleSchema($database),
        ], $arguments));
    }

    /**
     * Der Treiber zum System.
     *
     * Wortgleich {@see Databases::driver()}, und der `match` ist aus demselben
     * Grund vollständig ohne `default`: Käme ein drittes System dazu, meldet
     * PHP es hier und nicht erst zur Laufzeit an einer Datenbank, die niemand
     * öffnen kann.
     */
    private function driver(DatabaseEngine $engine): EngineDriver
    {
        return match ($engine) {
            DatabaseEngine::MariaDb => $this->mariadb,
            DatabaseEngine::Postgres => $this->postgres,
        };
    }
}
