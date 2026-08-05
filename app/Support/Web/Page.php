<?php

declare(strict_types=1);

namespace App\Support\Web;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eine Seite eines Verzeichnisses — die Nutzlast für Inertia.
 *
 * **Warum es diesen Helfer gibt.** Vier Controller riefen `paginate()` auf, und
 * jeder schickte etwas anderes: Das Protokoll gab `data`, `current_page`,
 * `last_page` und `total`, Kunden, Domains und Vorgänge nur `data` und `total`.
 * Die Oberfläche konnte damit nirgends blättern — bei dreien fehlten die
 * Seitenzahlen überhaupt, und beim Protokoll, wo sie ankamen, warf die Seite
 * sie weg.
 *
 * **Was daraus folgte, hat monatelang niemand bemerkt:** Vom Protokoll waren 76
 * Einträge da und 50 zu sehen; von den Vorgängen — der Liste, die man ansieht,
 * wenn etwas nicht stimmt und die am schnellsten wächst — ebenso. Kein Fehler,
 * keine Meldung, nur eine Liste, die nach fünfzig Zeilen aufhört. Genau das
 * Muster, gegen das dieses Projekt seine Wächter baut: eine Zusage im Code
 * (`paginate`), der auf der Gegenseite nichts entspricht.
 *
 * Geprüft von `tests/Feature/PaginationTest.php`.
 */
final class Page
{
    /**
     * Zeilen je Seite.
     *
     * Eine Zahl für alle Verzeichnisse. Vorher stand bei Domains 100 und
     * überall sonst 50 — ohne Grund, und ein Wert ohne Grund ist einer, den
     * beim nächsten Verzeichnis jemand anders setzt. 100 Zeilen sind ausserdem
     * auf der schmalen Fläche 100 Kärtchen untereinander (docs/24 §5).
     */
    public const SIZE = 50;

    /**
     * @template TModel
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string, mixed>  $row
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public static function from(LengthAwarePaginator $paginator, callable $row): array
    {
        return [
            'data' => array_values(array_map($row, $paginator->items())),

            /*
             * `last_page` und nicht `has_more`: Die Oberfläche schreibt „Seite
             * 2 von 5". Eine Fahne „es geht noch weiter" könnte das nicht, und
             * ohne diese Angabe weiss niemand, ob hinter „Weiter" eine Seite
             * liegt oder vierzig.
             */
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
