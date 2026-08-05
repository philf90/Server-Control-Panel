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
     * @return array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>}
     */
    public function series(
        string $name,
        int $columns,
        int $column = 0,
        int $points = 60,
        string $unit = '',
        int $decimals = 0,
        ?float $threshold = null,
        bool $bytes = false,
    ): array {
        $records = $this->buffer($name, $columns)->read();

        if (count($records) < 2) {
            return ['has' => false, 'warns' => false, 'unit' => trim($unit), 'points' => []];
        }

        $records = $this->downsample($records, $points);
        $values = $this->column($records, $column);

        return $this->build(
            $records,
            $values,
            min($values),
            max($values),
            $bytes ? $this->bytesFormatter(max($values)) : $this->plainFormatter($unit, $decimals),
            $bytes ? self::bytesUnit(max($values))[1] : $unit,
            $threshold,
        );
    }

    /**
     * Zwei Spalten derselben Kennzahl — für eine Kachel mit zwei Kurven.
     *
     * **Warum das nicht zweimal `series()` ist, und das ist der wichtige
     * Satz.** `series()` normiert jede Reihe auf ihr eigenes Kleinstes und
     * Grösstes: Eine Kurve füllt die 24 Einheiten der Kachel immer aus, ganz
     * gleich, ob sie zwischen 4 und 13 Kilobyte schwankt oder zwischen 38 und
     * 90 Megabyte. Für **eine** Kurve ist das richtig — man liest den Verlauf,
     * und die Zahl steht daneben.
     *
     * Zwei so gerechnete Kurven in **einem** Feld sind dagegen eine Lüge: Der
     * eingehende Verkehr, tausendfach kleiner, läge gleich hoch wie der
     * ausgehende und schlüge genauso weit aus. Wer das Bild ansieht, liest
     * „beide etwa gleich", und das Gegenteil ist der Fall. Deshalb eine
     * gemeinsame Spanne über beide Spalten — die kleinere Richtung liegt dann
     * flach unten, und genau das ist die Auskunft.
     *
     * **Die Zahlen bekommen trotzdem jede ihre eigene Einheit.** Die
     * gemeinsame Spanne ist eine Aussage über die Geometrie; „0,0 MB/s" wäre
     * eine über den Messwert, und sie wäre falsch. Also teilen sich die Kurven
     * die Achse und nicht die Vorsilbe.
     *
     * @return array{
     *     has: bool,
     *     first: array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>},
     *     second: array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>},
     * }
     */
    public function pair(
        string $name,
        int $columns,
        int $first,
        int $second,
        int $points = 60,
        ?float $threshold = null,
    ): array {
        $records = $this->buffer($name, $columns)->read();
        $empty = ['has' => false, 'warns' => false, 'unit' => '', 'points' => []];

        if (count($records) < 2) {
            return ['has' => false, 'first' => $empty, 'second' => $empty];
        }

        $records = $this->downsample($records, $points);
        $a = $this->column($records, $first);
        $b = $this->column($records, $second);

        $min = min(min($a), min($b));
        $max = max(max($a), max($b));

        return [
            'has' => true,
            'first' => $this->build($records, $a, $min, $max, $this->bytesFormatter(max($a)), self::bytesUnit(max($a))[1], $threshold),
            'second' => $this->build($records, $b, $min, $max, $this->bytesFormatter(max($b)), self::bytesUnit(max($b))[1], $threshold),
        ];
    }

    /**
     * @param  list<array{time:float,values:list<float>}>  $records
     * @return list<float>
     */
    private function column(array $records, int $column): array
    {
        return array_map(static fn (array $s): float => $s['values'][$column] ?? 0.0, $records);
    }

    /**
     * Die Stützstellen einer Reihe, gegen eine **vorgegebene** Spanne
     * gerechnet.
     *
     * Dass Spanne und Formatierung von aussen kommen, ist der ganze Zweck:
     * Nur so können sich zwei Kurven eine Achse teilen (siehe `pair()`).
     *
     * @param  list<array{time:float,values:list<float>}>  $records
     * @param  list<float>  $values
     * @param  callable(float): string  $format
     * @return array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>}
     */
    private function build(array $records, array $values, float $min, float $max, callable $format, string $unit, ?float $threshold): array
    {
        $span = ($max - $min) > 0.0001 ? $max - $min : 1.0;
        $lastIndex = count($records) - 1;

        $out = [];

        foreach ($records as $i => $record) {
            $value = $values[$i] ?? 0.0;

            $out[] = [
                'x' => round($i / $lastIndex * 100, 3),
                // y wächst im SVG nach unten; die Umkehr steht hier, damit sie
                // nicht in jeder Komponente noch einmal auftaucht.
                'y' => round(28 - ($value - $min) / $span * 24, 3),
                't' => date('H:i', (int) $record['time']),
                'v' => $format($value),
            ];
        }

        return [
            'has' => true,
            'warns' => $threshold !== null && ($values[$lastIndex] ?? 0.0) >= $threshold,

            // Die Einheit einmal je Reihe, damit die Kachel sie klein neben
            // die grosse Zahl setzen kann, ohne sie aus `v` zurückzuschneiden.
            'unit' => trim($unit),
            'points' => $out,
        ];
    }

    /** @return callable(float): string */
    private function plainFormatter(string $unit, int $decimals): callable
    {
        return static fn (float $value): string => number_format($value, $decimals, ',', '.').$unit;
    }

    /**
     * Byte je Sekunde, in der Grössenordnung der Reihe.
     *
     * **Warum nicht einfach die rohe Zahl.** Sie stand hier bis August 2026,
     * und sie passt nicht: Eine Kachel ist auf einem 1440px-Bildschirm 228px
     * breit, ihre Beizeile 179px — gemessen, nicht geschätzt. „65.981.645 B/s"
     * sind vierzehn Zeichen, und mit einem Wort davor bricht die Zeile um.
     * Lesbar war die Zahl ohnehin nie: Wer sieht einem neunstelligen
     * Bytewert an, dass er 63 Megabyte bedeutet?
     *
     * **Eine Grössenordnung für die ganze Reihe**, gewählt nach ihrem
     * höchsten Wert. Je Stützstelle zu skalieren ergäbe eine Ablesung, die
     * beim Wandern über die Kurve zwischen kB/s und MB/s springt — und zwei
     * Zahlen, die man nicht vergleichen kann, ohne die Einheit mitzulesen.
     *
     * Tausenderschritte und nicht 1024er: Eine Leitung wird in Megabit
     * gemessen, und die zählen dezimal. Die Schwelle der Kachel rechnet
     * genauso (900 Mbit/s).
     *
     * @return callable(float): string
     */
    private function bytesFormatter(float $max): callable
    {
        [$divisor, $unit] = self::bytesUnit($max);

        // Eine Nachkommastelle, sobald geteilt wird: „63 MB/s" verschweigt den
        // Unterschied zwischen 62,5 und 63,4 — bei einer Leitung ist das
        // knapp ein Megabyte je Sekunde.
        $decimals = $divisor > 1.0 ? 1 : 0;

        return static fn (float $value): string => number_format($value / $divisor, $decimals, ',', '.').$unit;
    }

    /**
     * Teiler und Einheit — auch für die Kachel, die ihre Einheit klein neben
     * die grosse Zahl setzt und sie deshalb getrennt braucht.
     *
     * Die Einheit aus einer fertigen Zeichenkette zurückzuschneiden wäre der
     * Fehler, gegen den dieses Projekt seine Wächter baut: eine Zeichenkette,
     * aus der jemand etwas herausliest, ohne dass der Bezug geprüft wird.
     *
     * @return array{0: float, 1: string}
     */
    public static function bytesUnit(float $max): array
    {
        return match (true) {
            $max >= 1_000_000_000 => [1_000_000_000.0, ' GB/s'],
            $max >= 1_000_000 => [1_000_000.0, ' MB/s'],
            $max >= 1_000 => [1_000.0, ' kB/s'],
            default => [1.0, ' B/s'],
        };
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
