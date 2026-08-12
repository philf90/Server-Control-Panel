<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;

/**
 * Der ganze Wert einer einzelnen Zelle.
 *
 * **Sie ist der Ausweg aus der Kürzung.** In der Tabellenansicht steht ein Wert
 * bei {@see Console::CELL_LIMIT} Zeichen abgeschnitten und ist damit auch nicht
 * änderbar (`docs/46 §10.1`); ohne diesen Griff käme niemand mehr an den Rest.
 *
 * **Sie nimmt den Schlüssel und nicht die Zeilennummer.** Eine Nummer wäre eine
 * Aussage über eine *Seite*: Zwischen ihrem Abruf und dem Öffnen der Zelle kann
 * jemand eine Zeile einfügen, und dann zeigte die Ansicht den Wert einer anderen
 * Zeile, ohne dass es jemand sähe. Damit gilt hier dieselbe Voraussetzung wie
 * fürs Ändern — **ohne Schlüssel gibt es diese Ansicht nicht**, und in einer
 * Tabelle ohne Schlüssel ist die Kürzung endgültig (`docs/46 §12`).
 *
 * Nicht verändernd — sie liest.
 */
final class PgConsoleCell implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.cell';
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
        $column = Guard::string($args['column'] ?? null, 'column');
        $key = PgConsoleRowWrite::key($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->cell(
                $context,
                $as,
                $database,
                $schema,
                $table,
                $key,
                $column,
            ),
        );
    }
}
