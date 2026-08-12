<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;

/**
 * Die Tabellen einer PostgreSQL-Datenbank.
 *
 * Der erste der fünf Griffe aus `docs/46 §12`. Was er zurückgibt, ist die Liste,
 * die der Kunde als Erstes sieht: Name, Art, geschätzte Zeilenzahl, Grösse, und
 * ob es einen Schlüssel gibt.
 *
 * **Er geht nicht durch die Warteschlange** (`docs/46 §12`). Kein
 * Konsolenaufruf tut das: Ein eingereihter Vorgang legt seine Argumente in
 * `operations.payload` ab, und dort stünde bei den Geschwistern dieser
 * Operation ein Filterwert oder der Inhalt einer Kundenzeile. Das ist dieselbe
 * Regel wie für Passwörter (`docs/36 §4`), mit einem neuen Anlass — und der
 * Anlass ist weiter gefasst als der alte: **Was nicht in der Warteschlange
 * stehen darf, ist nicht nur ein Geheimnis, sondern alles, was dem Kunden
 * gehört.**
 *
 * Nicht verändernd — sie liest.
 */
final class PgConsoleTables implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.tables';
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
