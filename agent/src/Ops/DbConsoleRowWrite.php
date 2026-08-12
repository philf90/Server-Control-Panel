<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Eine Zeile in MariaDB anlegen, ändern oder löschen.
 *
 * Das Gegenstück zu {@see PgConsoleRowWrite}, und **die drei Regeln des
 * Schreibwegs stehen dort** — `null` ist ein eigener Zustand, nur die
 * geänderten Spalten kommen in die Anweisung, und es muss genau eine Zeile
 * sein.
 *
 * **Die dritte hält dieses System auf einem anderen Weg** (`Db\Console::
 * writeStatement()`): PostgreSQL bekommt einen `DO`-Block mit
 * `GET DIAGNOSTICS`, MariaDB kennt keinen anonymen Block ausserhalb einer
 * Routine. An seiner Stelle stehen `LIMIT 1` — mehr als eine Zeile ist damit
 * unmöglich — und `ROW_COUNT()`, das sagt, ob es null waren.
 *
 * > **Zwei Systeme dürfen dieselbe Zusage auf zwei Wegen halten. Sie dürfen sie
 * > nicht auf einem halten und auf dem anderen behaupten.**
 */
final class DbConsoleRowWrite implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.row.write';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $schema = Guard::string($args['schema'] ?? '', 'schema');
        $table = Guard::string($args['table'] ?? null, 'table');
        $mode = Guard::enum($args['mode'] ?? null, \SrvPanel\Agent\Pg\Console::MODES, 'mode');
        $key = $mode === 'insert' ? [] : PgConsoleRowWrite::key($args);
        $values = $mode === 'delete' ? [] : PgConsoleRowWrite::values($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->write(
                $context,
                $as,
                Console::schema($schema, $database),
                $table,
                $mode,
                $key,
                $values,
            ),
        );
    }
}
