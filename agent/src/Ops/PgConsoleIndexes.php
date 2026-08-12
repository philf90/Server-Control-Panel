<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;

/**
 * Die Indexe einer Tabelle.
 *
 * **Eine eigene Operation und keine Erweiterung von `pg.console.columns`.**
 * Die Spaltenliste ist die Prüfliste, gegen die jeder Bezeichner geht, bevor er
 * in eine Anweisung kommt ({@see Console::columns()}) — sie wird bei **jedem**
 * Blättern, Filtern und Schreiben geholt. Die Indexe braucht ausschliesslich die
 * Strukturansicht. Sie dort anzuhängen hiesse, bei jeder Seite Zeilen eine
 * zweite Katalogabfrage zu fahren, die niemand liest.
 *
 * > **Was eine Ansicht braucht, gehört nicht in die Abfrage, die alle brauchen.**
 *
 * Nicht verändernd — sie liest.
 */
final class PgConsoleIndexes implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.indexes';
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
                'indexes' => $this->console->indexes($context, $as, $database, $schema, $table),
            ],
        );
    }
}
