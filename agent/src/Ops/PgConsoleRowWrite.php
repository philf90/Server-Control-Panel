<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Console;
use SrvPanel\Agent\Pg\Credentials;

/**
 * Eine Zeile anlegen, ändern oder löschen.
 *
 * Der einzige verändernde Griff der Konsole — und der einzige, dessen
 * Fehlschlag man an der Zeile hinterher **nicht** sieht (`docs/46 §10.1`).
 *
 * ## Die drei Regeln des Schreibwegs
 *
 * **1. `null` ist ein eigener Zustand und keine leere Eingabe.** Ein Formular,
 * das eine leere Eingabe als `''` schreibt, macht aus jedem `NULL` einer
 * nullbaren Spalte lautlos eine leere Zeichenkette — und ein
 * `WHERE spalte IS NULL` der Kundenanwendung findet die Zeile danach nicht mehr.
 * Deshalb trägt `values` **`null` als JSON-`null`** und nicht als leere
 * Zeichenkette; {@see Console::literal()} ist die Stelle, an der es zählt.
 *
 * **2. Nur die geänderten Spalten.** Was `values` nicht nennt, kommt nicht in
 * die Anweisung. Ein `UPDATE` über alle Spalten schriebe auch die zurück, die
 * nur angezeigt wurden — und damit jede Kürzung und jedes `''` aus einem `NULL`.
 * Diese Regel schützt auch vor den Fällen, die hier niemand aufgezählt hat.
 *
 * **3. Genau eine Zeile.** {@see Console::writeStatement()} zählt selbst nach
 * und nimmt den Vorgang zurück, wenn es nicht genau eine war.
 *
 * ## Warum kein `Lifecycle`
 *
 * Sie verändert den Zustand — aber nicht den des **Panels**. Ein Vorgang mit
 * Lebenslauf legte seine Argumente in `operations.payload` ab, und dort stünde
 * der Inhalt einer Kundenzeile (`docs/46 §12`). Sie läuft deshalb als
 * unmittelbarer Aufruf, wie `db.user.create`, und das Protokoll schreibt die
 * Anwendung — **ohne die Werte** (`docs/46 §3`, Entscheidung 4).
 */
final class PgConsoleRowWrite implements Op
{
    public function __construct(private readonly Console $console = new Console) {}

    public static function name(): string
    {
        return 'pg.console.row.write';
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
        $schema = Guard::string($args['schema'] ?? null, 'schema');
        $table = Guard::string($args['table'] ?? null, 'table');
        $mode = Guard::enum($args['mode'] ?? null, Console::MODES, 'mode');
        $key = $mode === 'insert' ? [] : self::key($args);
        $values = $mode === 'delete' ? [] : self::values($args);

        return $this->console->within(
            $context,
            $args,
            fn (Credentials $as, string $database): array => $this->console->write(
                $context,
                $as,
                $database,
                $schema,
                $table,
                $mode,
                $key,
                $values,
            ),
        );
    }

    /**
     * Der Schlüssel der Zeile.
     *
     * **Er trägt nur Zeichenketten.** Was aus der Anzeige zurückkommt, ist der
     * Text, den die Datenbank geliefert hat; ihn hier in eine Zahl zu wandeln
     * hiesse, dass zwei Stellen entscheiden, wie ein Wert aussieht — und die
     * zweite kennt den Typ der Spalte nicht.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public static function key(array $args): array
    {
        $key = $args['key'] ?? null;

        if (! is_array($key) || $key === []) {
            throw AgentException::badRequest(
                'Ohne Primärschlüssel lässt sich eine einzelne Zeile nicht eindeutig ansprechen.',
            );
        }

        $geprueft = [];

        foreach ($key as $name => $value) {
            $geprueft[Guard::string($name, 'key')] = Guard::string($value, 'key.'.$name);
        }

        return $geprueft;
    }

    /**
     * Die zu schreibenden Spalten.
     *
     * **`null` bleibt `null`** — siehe Regel 1 im Klassenkopf. Das ist der
     * einzige Ort dieser Operation, an dem ein Wert **nicht** durch
     * {@see Guard::string()} geht, und der Grund ist genau der Unterschied, den
     * diese Stufe zu wahren hat.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, string|null>
     */
    public static function values(array $args): array
    {
        $values = $args['values'] ?? null;

        if (! is_array($values) || $values === []) {
            throw AgentException::badRequest('Ein Schreibvorgang ohne eine einzige Spalte ändert nichts.');
        }

        $geprueft = [];

        foreach ($values as $name => $value) {
            if ($value !== null && ! is_string($value)) {
                throw AgentException::badRequest(
                    'Ein Wert ist weder Text noch NULL.',
                    ['column' => (string) $name],
                );
            }

            $geprueft[Guard::string($name, 'values')] = $value;
        }

        return $geprueft;
    }
}
