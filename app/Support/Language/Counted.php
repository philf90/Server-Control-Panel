<?php

declare(strict_types=1);

namespace App\Support\Language;

use App\Support\Metrics\Store;
use App\Support\Plans\Quotas;

/**
 * Eine Menge mit dem Wort, das zu ihr passt — für PHP.
 *
 * ## Warum es das zweimal gibt
 *
 * Die Oberfläche hat diese Entscheidung seit P5c als `useCounted.ts`
 * (`docs/48 §3.3`, Anlass: „geschätzt **1 Zeilen**"). Was sie nicht hatte, ist
 * ein Gegenstück für die Meldungen, die **in PHP** entstehen — und die gibt es:
 * Eine Datei hochladen, ein Archiv entpacken, einen Kunden sperren, all das
 * antwortet mit einem Satz aus einem `sprintf`.
 *
 * **Der Abnahmelauf von A1 hat dreizehn solcher Stellen gezählt** (`docs/86`,
 * Befund 11) — und sie sind nicht deshalb liegengeblieben, weil sie schwer
 * wären, sondern weil das Werkzeug fehlte:
 *
 * > **Eine Regel ohne Werkzeug wird an jeder Stelle neu entschieden — und
 * > irgendwann an einer nicht.**
 *
 * ## Warum beide Wörter übergeben werden
 *
 * Im Deutschen gibt es keine Regel dafür: `Zeile` wird zu `Zeilen`, `Zugang` zu
 * `Zugänge`, `Treffer` bleibt. Wer die Mehrzahl rechnen wollte, bekäme
 * „1 Zugangs" statt einer Entscheidung. Dieselbe Begründung wie drüben, und
 * absichtlich dieselbe Signatur: Wer die eine kennt, kennt die andere.
 *
 * ## Warum keine `__()`-Übersetzung
 *
 * Laravels `trans_choice()` könnte das — und brächte eine Sprachdatei mit, eine
 * Pluralisierungsregel je Gebietsschema und einen zweiten Ort für Text, der in
 * diesem Projekt neben seinem Code steht. Dieses Panel ist einsprachig
 * (`docs/19`); ein Übersetzungsapparat für eine Sprache ist Verwaltung ohne
 * Gegenwert.
 */
final class Counted
{
    /**
     * Die Zahl, wie dieses Panel Zahlen schreibt.
     *
     * Punkt als Tausendertrennung, wie in {@see Quotas} und
     * {@see Store} — und wie `toLocaleString('de-DE')` es
     * in der Oberfläche tut.
     */
    public static function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /**
     * Die Menge und ihr Wort.
     *
     * Die Einzahl ist im Betrieb der Normalfall und in der Entwicklung der
     * Sonderfall — eine einzelne Datei, ein einziges Abonnement. Deshalb fällt
     * sie beim Bauen nicht auf und beim Benutzen sofort.
     *
     * **Negative Zahlen bekommen die Mehrzahl.** „−1 Datei" gibt es hier
     * nirgends; käme es je vor, wäre die Mehrzahl die harmlosere Antwort.
     */
    public static function of(int $value, string $one, string $many): string
    {
        return self::number($value).' '.($value === 1 ? $one : $many);
    }
}
