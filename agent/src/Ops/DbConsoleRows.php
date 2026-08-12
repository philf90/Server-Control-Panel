<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Eine Seite Zeilen aus MariaDB.
 *
 * Das Gegenstück zu {@see PgConsoleRows}; die Prüfung des Filters kommt von
 * dort ({@see PgConsoleRows::filter()}), damit es die Positivliste der
 * Vergleiche einmal gibt und nicht zweimal.
 *
 * Nicht verändernd — sie liest.
 */
final class DbConsoleRows implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.rows';
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
        $schema = Guard::string($args['schema'] ?? '', 'schema');
        $table = Guard::string($args['table'] ?? null, 'table');
        $order = Guard::string($args['order'] ?? null, 'order');
        $descending = ($args['direction'] ?? 'asc') === 'desc';
        $offset = Guard::int($args['offset'] ?? 0, 'offset');

        if ($offset < 0) {
            throw AgentException::badRequest('Ein Versatz vor der ersten Zeile ergibt keine Seite.', [
                'offset' => $offset,
            ]);
        }

        $filter = PgConsoleRows::filter($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->rows(
                $context,
                $as,
                Console::schema($schema, $database),
                $table,
                $order,
                $descending,
                $offset,
                $filter,
            ),
        );
    }
}
