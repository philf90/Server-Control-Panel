<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Indexe einer Tabelle in MariaDB.
 *
 * Das Gegenstück zu {@see PgConsoleIndexes}, und die Begründung dort gilt
 * wörtlich: eine eigene Operation, weil die Strukturansicht sie braucht und
 * sonst niemand.
 *
 * Nicht verändernd — sie liest.
 */
final class DbConsoleIndexes implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.indexes';
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

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => [
                'indexes' => $this->console->indexes(
                    $context,
                    $as,
                    Console::schema($schema, $database),
                    $table,
                ),
            ],
        );
    }
}
