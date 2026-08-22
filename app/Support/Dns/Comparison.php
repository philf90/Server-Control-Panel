<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Enums\DnsRecordState;

/**
 * Der Sollzustand gegen das Gemessene — die eine Stelle, die urteilt.
 *
 * **Was hier entschieden wird, ist eine einzige Frage:** Wann zeigt ein Name
 * hierher? Die Antwort ist nicht „die Werte sind gleich", und der Unterschied
 * ist der ganze Sinn dieser Klasse.
 *
 * **Ein Name zeigt hierher, wenn jeder ausgelieferte Wert einer von unseren
 * ist** — nicht, wenn alle unsere ausgeliefert werden. Ein Server kann zwei
 * Adressen führen und die Website unter beiden bedienen; ein Kunde, der auf
 * eine davon zeigt, ist richtig unterwegs. Umgekehrt genügt **ein** fremder
 * Wert daneben, damit ein Teil der Anfragen woanders landet — und das ist
 * genau der Fehler, den niemand bemerkt, weil die Seite meistens funktioniert.
 *
 * > **Ein Eintrag, der überwiegend stimmt, ist ein Ausfall, den man für ein
 * > Netzproblem hält.**
 *
 * **Die Reihenfolge der Prüfungen ist Teil der Regel.** „Nicht erreichbar"
 * steht vor allem anderen, weil über eine Zone, die schweigt, nichts gesagt
 * ist — auch nicht, dass ein Eintrag fehlt.
 */
final class Comparison
{
    /**
     * Jeden erwarteten Eintrag gegen das, was gemessen wurde.
     *
     * **Ein erwarteter Eintrag ohne Messung ist `Unreachable` und nicht
     * `Missing`.** Er sagt aus, dass niemand gefragt hat — und das ist eine
     * Aussage über den Lauf, nicht über die Zone.
     *
     * @param  list<array{name: string, type: string, expected: list<string>}>  $desired
     * @param  list<array{name: string, type: string, asked: int, answered: int, values: list<string>, consistent: bool}>  $measured
     * @return list<array{name: string, type: string, state: DnsRecordState, expected: list<string>, found: list<string>}>
     */
    public static function of(array $desired, array $measured): array
    {
        $byKey = [];

        foreach ($measured as $entry) {
            $byKey[self::key($entry['name'], $entry['type'])] = $entry;
        }

        $result = [];

        foreach ($desired as $entry) {
            $found = $byKey[self::key($entry['name'], $entry['type'])] ?? null;

            $result[] = [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'state' => self::state($entry['expected'], $found),
                'expected' => $entry['expected'],
                'found' => $found === null ? [] : $found['values'],
            ];
        }

        return $result;
    }

    /**
     * Der Zustand eines einzelnen Eintrags.
     *
     * @param  list<string>  $expected
     * @param  array{asked: int, answered: int, values: list<string>, consistent: bool}|null  $found
     */
    private static function state(array $expected, ?array $found): DnsRecordState
    {
        // Niemand hat gefragt, oder niemand hat geantwortet. Über die Zone ist
        // damit nichts gesagt — und „fehlt" wäre eine Behauptung darüber.
        if ($found === null || $found['asked'] === 0 || $found['answered'] === 0) {
            return DnsRecordState::Unreachable;
        }

        // **Vor dem Vergleich der Werte.** Sagen die Nameserver Verschiedenes,
        // ist die Frage „stimmt der Eintrag" nicht beantwortbar: Er stimmt für
        // einen Teil der Welt und für den anderen nicht.
        if ($found['consistent'] === false) {
            return DnsRecordState::Inconsistent;
        }

        if ($found['values'] === []) {
            return DnsRecordState::Missing;
        }

        foreach ($found['values'] as $value) {
            if (! in_array($value, $expected, true)) {
                return DnsRecordState::Elsewhere;
            }
        }

        return DnsRecordState::Here;
    }

    private static function key(string $name, string $type): string
    {
        return strtolower(trim($name, '. ')).'/'.strtoupper($type);
    }
}
