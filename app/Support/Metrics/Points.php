<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Die Stützstellen einer Kachelkurve — die eine Stelle, an der sie entstehen.
 *
 * **Warum es diese Klasse seit B4 gibt.** Bis dahin hatte das Panel genau
 * *eine* Quelle für Kurven: die Ringpuffer hinter {@see Store}, 24 Stunden
 * lang, und die Geometrie stand als private Methode darin. Mit B4 kommt eine
 * zweite — die verdichtete Tagestabelle aus B3, dreissig Tage lang. Dieselbe
 * Kachel, dieselbe Form, eine andere Herkunft.
 *
 * > **Zwei Leser derselben Marken, die verschieden zählen, sind zwei Fassungen
 * > derselben Regel — und die zweite ist die, die veraltet.**
 *
 * Der Satz steht seit `docs/81 §2.3o` im Repo und ist dort an einem verirrten
 * `END` bezahlt worden. Hier wäre der Preis eine Kurve, die auf der
 * Übersichtsseite anders liegt als auf der Abonnementseite — und zwar um
 * Beträge, die niemandem auffallen, bis jemand die beiden nebeneinanderlegt.
 *
 * **Was hier nicht steht: das Feld.** Der `viewBox` ist 100 × 32 Einheiten und
 * gehört `Tile.vue`; hier stehen nur die Zahlen, die hineinpassen müssen.
 * `SparklineShapeTest` hält die Form des Feldes, diese Klasse seine Füllung.
 */
final class Points
{
    /**
     * Die leere Reihe — und sie trägt ihre Felder trotzdem.
     *
     * **`has: false` ist eine Auskunft und kein fehlender Wert.** Eine Kachel
     * ohne Kurve sagt „noch nichts gemessen"; eine Kachel, der die Felder
     * fehlen, sagt gar nichts und bricht im Klienten an der ersten Leseprobe.
     * `SeriesThresholdTest::test_an_empty_series_carries_the_field_too` hält das
     * seit A7 für den Ringpuffer; seit B4 gilt es für beide Quellen, weil beide
     * hier durchkommen.
     *
     * @return array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>}
     */
    public static function empty(string $unit): array
    {
        return ['has' => false, 'warns' => false, 'unit' => trim($unit), 'points' => []];
    }

    /**
     * Die Stützstellen einer Reihe, gegen eine **vorgegebene** Spanne
     * gerechnet.
     *
     * Dass Spanne und Formatierung von aussen kommen, ist der ganze Zweck:
     * Nur so können sich zwei Kurven eine Achse teilen (siehe `Store::pair()`).
     *
     * **Die Beschriftung kommt ebenfalls von aussen**, und das ist der
     * Unterschied zwischen den beiden Quellen: Der Ringpuffer schreibt `H:i`,
     * die Tagestabelle einen Tag. Eine Uhrzeit an einer Reihe über dreissig
     * Tage wäre eine Ablesung, die auf jede Frage dieselbe Antwort gibt.
     *
     * **Weniger als zwei Werte ergeben keine Kurve, sondern einen Punkt.** Der
     * Ringpuffer fängt den Fall seit A7 vor dem Rechnen ab; hier steht er
     * zusätzlich, weil die Tagestabelle am zweiten Tag eines Abonnements genau
     * einen Wert hat — und `$i / $lastIndex` wäre dann eine Division durch
     * null.
     *
     * @param  list<float>  $values
     * @param  list<string>  $labels
     * @param  callable(float): string  $format
     * @return array{has:bool,warns:bool,unit:string,points:list<array{x:float,y:float,t:string,v:string}>}
     */
    public static function build(array $values, array $labels, float $min, float $max, callable $format, string $unit, ?float $threshold): array
    {
        $lastIndex = count($values) - 1;

        if ($lastIndex < 1) {
            return self::empty($unit);
        }

        $span = ($max - $min) > 0.0001 ? $max - $min : 1.0;

        $out = [];

        foreach ($values as $i => $value) {
            $out[] = [
                'x' => round($i / $lastIndex * 100, 3),
                // y wächst im SVG nach unten; die Umkehr steht hier, damit sie
                // nicht in jeder Komponente noch einmal auftaucht.
                'y' => round(28 - ($value - $min) / $span * 24, 3),
                't' => $labels[$i] ?? '',
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

    /**
     * Die grosse Zahl der Kachel — der letzte Wert der Reihe, ohne Einheit.
     *
     * **Warum sie aus der Reihe kommt und nicht aus einer zweiten Rechnung.**
     * Die Kachel zeigt oben eine Zahl und darunter eine Kurve; stammten die
     * beiden aus verschiedenen Quellen, könnten sie auseinanderlaufen — und
     * genau das ist auf der Übersichtsseite schon einmal passiert, als die
     * Zahl mit null Nachkommastellen `0 %` schrieb, während die Kurve aus den
     * Rohwerten ihre Ausschläge zeigte.
     *
     * Die Einheit steht getrennt daneben (`unit`), deshalb wird sie hier
     * abgeschnitten: Die Kachel setzt sie klein hinter die grosse Zahl.
     *
     * `$fallback` ist die Antwort auf eine leere Reihe — „noch nichts
     * gemessen" und nicht „null".
     *
     * @param  array{has:bool,points:list<array{x:float,y:float,t:string,v:string}>}  $series
     */
    public static function latest(array $series, string $fallback): string
    {
        if (! $series['has'] || $series['points'] === []) {
            return $fallback;
        }

        $letzter = $series['points'][count($series['points']) - 1];

        return trim(str_replace(['%', ' '], '', $letzter['v'])) === '' ? $fallback : trim(explode(' ', $letzter['v'])[0]);
    }

    /**
     * Die Zahl zu einem Wert — mit so vielen Stellen, dass sie etwas sagt.
     *
     * **Der Fall, aus dem das kommt.** Die CPU-Kachel stand auf einem ruhigen
     * Server dauerhaft auf `0 %` — in der Kachel und bei jeder Ablesung auf
     * der Kurve. Falsch war der Wert nicht: `cpu` wurde mit **null**
     * Nachkommastellen formatiert, und die Auslastung lag den ganzen Tag
     * zwischen 0,1 und 0,9. `number_format(0,42, 0)` ist `0`.
     *
     * Die Kurve daneben zeichnete derweil aus den **Rohwerten** und zeigte
     * deshalb ihre Ausschläge. Genau diese Mischung macht den Fehler so
     * unangenehm: Das Bild sagt „da tut sich etwas", die Zahl sagt „nichts",
     * und beide kommen aus derselben Reihe.
     *
     * > **Eine Zahl, die jeden Wert einer Reihe gleich schreibt, misst nichts
     * > mehr — sie behauptet nur noch.**
     *
     * Die Stellenzahl richtet sich deshalb nach der **Grösse des Wertes** und
     * nicht mehr allein nach dem Wunsch des Aufrufers: unter 1 zwei Stellen,
     * unter 10 eine, darüber keine. So schreibt es auch ein Mensch —
     * „0,42 %", „3,7 %", „37 %".
     *
     * **Weniger als gewünscht wird es nie.** Die Load fragt zwei Stellen an
     * und behält sie auch bei 12,00; sonst hinge ihre Genauigkeit daran, wie
     * ausgelastet der Server gerade ist.
     *
     * @return callable(float): string
     */
    public static function plainFormatter(string $unit, int $decimals): callable
    {
        return static function (float $value) use ($unit, $decimals): string {
            $betrag = abs($value);
            $noetig = $betrag >= 10.0 ? 0 : ($betrag >= 1.0 ? 1 : 2);

            return number_format($value, max($decimals, $noetig), ',', '.').$unit;
        };
    }

    /**
     * Byte in der Grössenordnung der Reihe.
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
     * @return callable(float): string
     */
    public static function bytesFormatter(float $max, string $suffix): callable
    {
        [$divisor, $unit] = self::bytesUnit($max, $suffix);

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
     * **Tausenderschritte und nicht 1024er**, und der Grund trägt beide
     * Gegenstände dieser Klasse: Eine Leitung wird in Megabit gemessen, und
     * die zählen dezimal (die Schwelle der Netzkachel rechnet mit 900 Mbit/s).
     * Ein Provider, der Übertragungsvolumen abrechnet, zählt genauso — und
     * `docs/129 §11` sagt ausdrücklich, dass unsere Zahl sich mit seiner
     * ohnehin nicht deckt, weil er TCP, TLS und Wiederholungen mitzählt.
     *
     * **`$suffix` ist der Unterschied zwischen einer Rate und einer Menge.**
     * Die Netzkachel zeigt „63,4 MB/s", die Verkehrskachel eines Abonnements
     * „63,4 GB" — dieselbe Grössenordnung, dieselben Schritte, zwei
     * verschiedene Grössen.
     *
     * > **Ein Format, das für eine Rate reicht, reicht nicht für eine Menge.**
     *
     * @return array{0: float, 1: string}
     */
    public static function bytesUnit(float $max, string $suffix): array
    {
        return match (true) {
            $max >= 1_000_000_000 => [1_000_000_000.0, ' GB'.$suffix],
            $max >= 1_000_000 => [1_000_000.0, ' MB'.$suffix],
            $max >= 1_000 => [1_000.0, ' kB'.$suffix],
            default => [1.0, ' B'.$suffix],
        };
    }
}
