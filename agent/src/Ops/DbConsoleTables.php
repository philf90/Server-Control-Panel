<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Op;

/**
 * Die Tabellen einer MariaDB-Datenbank.
 *
 * Das Gegenstück zu {@see PgConsoleTables}; die Begründungen stehen dort und
 * gelten wörtlich. Ein Unterschied gehört hierher: **`information_schema`
 * filtert nach Rechten**, gemessen (`docs/46 §2.3`, N11) — eine
 * `has_table_privilege`-Bedingung wie in PostgreSQL gibt es hier nicht und wird
 * nicht gebraucht.
 *
 * Nicht verändernd — sie liest.
 */
final class DbConsoleTables implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.tables';
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
        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => [
                'tables' => $this->console->tables($context, $as, $database),
            ],
        );
    }
}
