<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;

/**
 * Die Struktur einer Tabelle.
 *
 * Spalten mit Typ, `NULL`-Zulässigkeit, Vorgabe, Schlüsselzugehörigkeit — und
 * `binary`, ohne das die Oberfläche nicht wüsste, welche Zelle sie als Länge
 * zeigt statt als Wert (`docs/46 §8.2`).
 *
 * **Sie ist auch die Prüfliste.** Dieselbe Abfrage steht hinter jedem Bezeichner,
 * den die drei folgenden Operationen in eine Anweisung schreiben — nicht als
 * zweite Fassung, sondern als derselbe Aufruf ({@see Console::columns()}).
 *
 * Nicht verändernd — sie liest.
 */
final class PgConsoleColumns implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.columns';
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

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => [
                'columns' => $this->console->columns($context, $as, $database, $schema, $table),
            ],
        );
    }
}
