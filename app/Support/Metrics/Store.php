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
 *
 * **Gerechnet wird seit B4 in {@see Points} und nicht mehr hier.** Diese
 * Klasse ist die Quelle der 24-Stunden-Reihen; die Tagesreihen aus B3 kommen
 * aus {@see Daily} und gehen durch dieselbe Geometrie. Was hier bleibt, ist
 * das, was nur für einen Ringpuffer gilt: das Eindampfen auf eine Zielzahl von
 * Stützstellen, die Spalte und die Beschriftung mit einer Uhrzeit.
 */
final class Store
{
    /**
     * Was dieser Speicher misst, ist eine **Rate** und keine Menge.
     *
     * Die Unterscheidung wandert seit B4 als Nachsilbe in die Einheit, weil
     * {@see Points} beides bedient: Der Ringpuffer schreibt Bytes je Sekunde,
     * die Tagestabelle Bytes je Tag. Ohne sie stünde auf der Abonnementseite
     * „63,4 MB/s" für ein Tagesvolumen.
     */
    private const RATE = '/s';

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
            return Points::empty($unit);
        }

        $records = $this->downsample($records, $points);
        $values = $this->column($records, $column);

        return Points::build(
            $values,
            self::labels($records),
            min($values),
            max($values),
            $bytes ? Points::bytesFormatter(max($values), self::RATE) : Points::plainFormatter($unit, $decimals),
            $bytes ? Points::bytesUnit(max($values), self::RATE)[1] : $unit,
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
        $empty = Points::empty('');

        if (count($records) < 2) {
            return ['has' => false, 'first' => $empty, 'second' => $empty];
        }

        $records = $this->downsample($records, $points);
        $a = $this->column($records, $first);
        $b = $this->column($records, $second);

        $min = min(min($a), min($b));
        $max = max(max($a), max($b));
        $labels = self::labels($records);

        return [
            'has' => true,
            'first' => Points::build($a, $labels, $min, $max, Points::bytesFormatter(max($a), self::RATE), Points::bytesUnit(max($a), self::RATE)[1], $threshold),
            'second' => Points::build($b, $labels, $min, $max, Points::bytesFormatter(max($b), self::RATE), Points::bytesUnit(max($b), self::RATE)[1], $threshold),
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
     * Die Beschriftung der Stützstellen — beim Ringpuffer eine Uhrzeit.
     *
     * **Sie steht hier und nicht in {@see Points}**, und das ist die Naht
     * zwischen den beiden Quellen: Die Geometrie ist für beide dieselbe, die
     * Beschriftung nicht. Eine Reihe über 24 Stunden liest sich an `H:i`, eine
     * über dreissig Tage an einem Datum — und ein gemeinsamer Formatierer mit
     * einer Fahne wäre die Stelle, an der später jemand die falsche setzt.
     *
     * @param  list<array{time:float,values:list<float>}>  $records
     * @return list<string>
     */
    private static function labels(array $records): array
    {
        return array_map(static fn (array $record): string => date('H:i', (int) $record['time']), $records);
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
