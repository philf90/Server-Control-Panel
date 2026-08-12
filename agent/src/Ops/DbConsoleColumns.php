<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Struktur einer Tabelle in MariaDB.
 *
 * Das Gegenstück zu {@see PgConsoleColumns}. **Das Feld `schema` kommt mit und
 * muss die Datenbank selbst nennen** — in MariaDB gibt es kein Schema daneben,
 * und die Anwendung stellt für beide Systeme eine Frage und nicht zwei
 * ({@see Console::schema()}).
 *
 * Nicht verändernd — sie liest.
 */
final class DbConsoleColumns implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.columns';
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
                'columns' => $this->console->columns(
                    $context,
                    $as,
                    Console::schema($schema, $database),
                    $table,
                ),
            ],
        );
    }
}
