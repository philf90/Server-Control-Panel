<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;
use SrvPanel\Agent\Pg\Sql;
use SrvPanel\Agent\Runner;

/**
 * Eine Seite Zeilen.
 *
 * Die Operation, für die es die Stufe gibt — und die einzige, deren Argumente
 * einen **Wert des Kunden** tragen: die rechte Seite des Filters. Sie geht durch
 * {@see Sql::text()} und durch nichts sonst
 * (`docs/46 §7`).
 *
 * **`offset` und keine Seitennummer.** Eine Seitennummer wäre eine zweite
 * Fassung der Rechnung `nummer × ROWS_PER_PAGE`, und die zweite Fassung ist die,
 * die veraltet, sobald jemand die Seitengrösse ändert.
 *
 * Nicht verändernd — sie liest.
 */
final class PgConsoleRows implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.rows';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $schema = Guard::string($args['schema'] ?? null, 'schema');
        $table = Guard::string($args['table'] ?? null, 'table');
        $order = Guard::string($args['order'] ?? null, 'order');
        $descending = ($args['direction'] ?? 'asc') === 'desc';
        $offset = Guard::int($args['offset'] ?? 0, 'offset');

        if ($offset < 0) {
            throw AgentException::badRequest('Ein Versatz vor der ersten Zeile ergibt keine Seite.', [
                'offset' => $offset,
            ]);
        }

        $filter = self::filter($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->rows(
                $context,
                $as,
                $database,
                $schema,
                $table,
                $order,
                $descending,
                $offset,
                $filter,
            ),
        );
    }

    /**
     * Der Filter, geprüft — oder keiner.
     *
     * **Der Vergleich kommt aus einer Positivliste und nicht aus der Anfrage**
     * ({@see Console::OPERATORS}). Das ist dieselbe Bauart wie
     * {@see Runner}: Was nicht genannt ist, gibt es nicht.
     *
     * @param  array<string, mixed>  $args
     * @return array{column: string, operator: string, value: string}|null
     */
    public static function filter(array $args): ?array
    {
        $filter = $args['filter'] ?? null;

        if ($filter === null) {
            return null;
        }

        if (! is_array($filter)) {
            throw AgentException::badRequest('Der Filter ist kein Feld.');
        }

        return [
            'column' => Guard::string($filter['column'] ?? null, 'filter.column'),
            'operator' => Guard::enum($filter['operator'] ?? null, Console::OPERATORS, 'filter.operator'),
            // **Ohne Wert bei `empty`.** Die Prüfung darüber lässt ihn zu, weil
            // ein leerer Wert dort keine Bedeutung hat; `Console::condition()`
            // liest ihn für diesen Vergleich gar nicht erst.
            'value' => is_string($filter['value'] ?? null) ? $filter['value'] : '',
        ];
    }
}
