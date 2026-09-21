<?php

declare(strict_types=1);

namespace App\Support\Design;

/**
 * Kontrast nach WCAG 2.1 — die eine Stelle, die ihn rechnet.
 *
 * **Warum sie seit B6 in `app/` steht und nicht in einem Wächter.** Bis dahin
 * gab es die Rechnung **dreimal**, als private Methode in
 * `SurfaceTokenTest`, `ColorRoleTest` und `ButtonStyleTest`. Das ging, solange
 * nur Wächter sie brauchten: Jeder prüfte ein festes Stylesheet, und drei
 * gleiche Kopien fielen niemandem auf.
 *
 * Mit B6 gibt der **Betreiber** eine Farbe vor, und dann muss die Anwendung
 * selbst rechnen — vor dem Speichern, damit keine Farbe ins Panel kommt, unter
 * der die Schrift nicht mehr lesbar ist. Eine vierte Kopie wäre der Fehler,
 * gegen den dieses Repo seine Wächter baut.
 *
 * > **Zwei Fassungen derselben Regel laufen auseinander — und die zweite ist
 * > die, die veraltet.**
 *
 * ## Die Schwellen und woher sie kommen
 *
 * `docs/20 §7.2` und WCAG 1.4.3/1.4.11: **4,5:1 für Text**, **3:1 für die
 * Grenze eines Bedienelements**. Sie stehen hier als benannte Konstanten,
 * damit niemand die Zahl an der Stelle hinschreibt, an der er sie braucht.
 *
 * ## Was diese Klasse nicht sagt
 *
 * Sie rechnet mit **undurchsichtigen** Farben. Eine Fläche mit `rgba(…, 0.1)`
 * muss der Aufrufer vorher über ihren Grund blenden; die Messrunde zu A14 hat
 * genau daran sechsmal `1.00:1` gemeldet — „fällt durch" für einen Zustand,
 * der in Ordnung ist.
 *
 * > **Ein Prüfkörper, der die Deckkraft wegwirft, misst die Farbe vor dem
 * > Überblenden — und die sieht niemand.**
 */
final class Contrast
{
    /** Text gegen seinen Grund (WCAG 1.4.3, Stufe AA). */
    public const TEXT = 4.5;

    /** Die Grenze eines Bedienelements gegen ihren Grund (WCAG 1.4.11). */
    public const CONTROL = 3.0;

    /** Das Verhältnis zweier Farben, immer ≥ 1. */
    public static function between(string $a, string $b): float
    {
        $hoch = max(self::luminance($a), self::luminance($b));
        $tief = min(self::luminance($a), self::luminance($b));

        return ($hoch + 0.05) / ($tief + 0.05);
    }

    /**
     * Die relative Leuchtdichte einer Farbe.
     *
     * Erwartet wird `#rrggbb` oder `rrggbb`; was sich nicht lesen lässt, gilt
     * als Schwarz. **Das ist Absicht und keine Nachlässigkeit:** Schwarz ist
     * der Extremwert, und eine unlesbare Farbe fällt damit an jeder Schwelle
     * zur strengen Seite — sie wird abgewiesen statt durchgewunken.
     */
    public static function luminance(string $hex): float
    {
        $rgb = sscanf(ltrim($hex, '#'), '%2x%2x%2x') ?? [0, 0, 0];

        $kanal = static function (int|float|null $wert): float {
            $wert = ((int) $wert) / 255;

            return $wert <= 0.03928 ? $wert / 12.92 : (($wert + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $kanal($rgb[0] ?? 0)
            + 0.7152 * $kanal($rgb[1] ?? 0)
            + 0.0722 * $kanal($rgb[2] ?? 0);
    }

    /**
     * Ist eine Zeichenkette eine Farbe, mit der diese Klasse rechnen kann?
     *
     * Sechs Hexziffern mit führendem `#`. **Keine Kurzform `#abc`**: Sie wäre
     * eine zweite Schreibweise derselben Farbe, und `sscanf` läse sie falsch —
     * `#abc` ergäbe `ab`, `c` und nichts, also ein stilles Schwarz im blauen
     * Kanal.
     */
    public static function isColour(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    /**
     * Die lesbarere von zwei Schriftfarben auf einem Grund.
     *
     * **Gerechnet und nicht geraten.** „Heller Grund, dunkle Schrift" stimmt
     * meistens und bei den Farben dazwischen nicht — und genau die wählt
     * jemand, der eine Markenfarbe eingibt.
     */
    public static function readableOn(string $background, string $light, string $dark): string
    {
        return self::between($background, $light) >= self::between($background, $dark) ? $light : $dark;
    }
}
