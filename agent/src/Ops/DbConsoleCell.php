<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Console;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Der ganze Wert einer einzelnen Zelle in MariaDB.
 *
 * Das Gegenstück zu {@see PgConsoleCell}. Auch hier nimmt sie den **Schlüssel**
 * und nicht die Zeilennummer, und der Grund steht dort: Eine Nummer wäre eine
 * Aussage über eine Seite, und zwischen ihrem Abruf und dem Öffnen der Zelle
 * kann jemand eine Zeile einfügen.
 *
 * Nicht verändernd — sie liest.
 */
final class DbConsoleCell implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'db.console.cell';
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
        $column = Guard::string($args['column'] ?? null, 'column');
        $key = PgConsoleRowWrite::key($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->cell(
                $context,
                $as,
                Console::schema($schema, $database),
                $table,
                $key,
                $column,
            ),
        );
    }
}
