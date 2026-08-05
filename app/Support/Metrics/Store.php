<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Hält die RingBuffer und macht aus ihnen Stützstellen für die Oberfläche.
 *
 * Die Umrechnung passiert hier und nicht im Browser: Was der Server schickt,
 * ist fertig — Wert, Beschriftung, Einheit, Position. Das ist Regel 2 des
 * Gestaltungssystems (§7.2) und der Grund, warum die Kachel im Browser mit
 * dreißig Zeilen auskommt.
 */
final class Store
{
    /** @var array<string,RingBuffer> */
    private array $buffers = [];

    public function __construct(
        private readonly string $directory,
        private readonly int $capacity = 8640,
    ) {}

    public function buffer(string $name, int $columns): RingBuffer
    {
        $schluessel = $name.':'.$columns;

        return $this->buffers[$schluessel] ??= new RingBuffer(
            sprintf('%s/%s.ring', rtrim($this->directory, '/'), $name),
            $columns,
            $this->capacity,
        );
    }

    /**
     * Ein Verlauf für die Kachel: höchstens `$points` Stützstellen, auf 0…100
     * in x normiert, mit fertiger Beschriftung.
     *
     * **`$threshold` ist die Zahl, ab der die Kurve warnt** — und sie kommt von
     * aussen. Ab wann eine Auslastung eng ist, ist eine Aussage über den
     * Betrieb und keine über die Darstellung: Der Controller kennt sie, dieser
     * Speicher nicht. `null` heisst „für diese Kennzahl gibt es keine
     * allgemeingültige Schwelle" und ist keine Nachlässigkeit, sondern eine
     * Angabe.
     *
     * Verglichen wird der **letzte** Wert und nicht der höchste. Eine Kurve,
     * die vor einer Stunde einmal ausgeschlagen ist und seitdem ruhig läuft,
     * warnt sonst für immer — und eine Warnung, die nicht mehr weggeht, liest
     * nach dem dritten Mal niemand.
     *
     * @return array{has:bool,warns:bool,points:list<array{x:float,y:float,t:string,v:string}>}
     */
    public function series(
        string $name,
        int $columns,
        int $column = 0,
        int $points = 60,
        string $unit = '',
        int $decimals = 0,
        ?float $threshold = null,
    ): array {
        $records = $this->buffer($name, $columns)->read();

        if (count($records) < 2) {
            return ['has' => false, 'warns' => false, 'points' => []];
        }

        $records = $this->downsample($records, $points);

        $values = array_map(static fn (array $s): float => $s['values'][$column] ?? 0.0, $records);
        $min = min($values);
        $max = max($values);
        $span = ($max - $min) > 0.0001 ? $max - $min : 1.0;
        $lastIndex = count($records) - 1;

        $out = [];

        foreach ($records as $i => $record) {
            $value = $record['values'][$column] ?? 0.0;

            $out[] = [
                'x' => round($i / $lastIndex * 100, 3),
                // y wächst im SVG nach unten; die Umkehr steht hier, damit sie
                // nicht in jeder Komponente noch einmal auftaucht.
                'y' => round(28 - ($value - $min) / $span * 24, 3),
                't' => date('H:i', (int) $record['time']),
                'v' => number_format($value, $decimals, ',', '.').$unit,
            ];
        }

        $last = $values[$lastIndex] ?? 0.0;

        return [
            'has' => true,
            'warns' => $threshold !== null && $last >= $threshold,
            'points' => $out,
        ];
    }

    /**
     * Auf höchstens `ziel` Stützstellen eindampfen, mit Mittelwert je Fenster.
     *
     * Jede n-te Stützstelle zu nehmen wäre billiger und würde Spitzen
     * verschlucken — genau die, wegen derer jemand auf die Kurve schaut.
     *
     * @param  list<array{time:float,values:list<float>}>  $records
     * @return list<array{time:float,values:list<float>}>
     */
    private function downsample(array $records, int $target): array
    {
        $count = count($records);

        if ($count <= $target) {
            return $records;
        }

        $width = $count / $target;
        $result = [];

        for ($i = 0; $i < $target; $i++) {
            $from = (int) floor($i * $width);
            $to = min($count, (int) floor(($i + 1) * $width));
            $window = array_slice($records, $from, max(1, $to - $from));

            $sums = [];

            foreach ($window as $record) {
                foreach ($record['values'] as $column => $value) {
                    $sums[$column] = ($sums[$column] ?? 0.0) + $value;
                }
            }

            $result[] = [
                'time' => $window[count($window) - 1]['time'],
                'values' => array_map(static fn (float $s): float => $s / count($window), $sums),
            ];
        }

        return $result;
    }
}
