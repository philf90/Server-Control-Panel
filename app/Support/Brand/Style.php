<?php

declare(strict_types=1);

namespace App\Support\Brand;

use App\Support\Settings\BrandSettings;

/**
 * Die Markenfarbe als Marken und nicht als Regeln (B6).
 *
 * ## „Jede Farbe kommt aus `app.css`" — und das gilt weiter
 *
 * Das Abnahmekriterium von B6 verlangt eine Farbe des Betreibers **und** hält
 * an der Regel fest, dass jede Farbe aus `resources/css/app.css` kommt. Das
 * ist kein Widerspruch, sobald man liest, wogegen die Regel geschrieben ist:
 * gegen Hexwerte, die in dreissig Komponenten liegen und sich nicht umstellen
 * lassen.
 *
 * > **Eine Marke, die an einer Stelle gesetzt wird, ist das Gegenteil einer
 * > Farbe, die verstreut ist.**
 *
 * Hier entsteht deshalb **kein einziger Selektor mit einer Eigenschaft** —
 * nur Werte für Marken, die `app.css` schon führt und deren Regeln dort
 * stehen. `BrandStyleTest` hält genau das: Was diese Klasse ausgibt, enthält
 * ausschliesslich `--`-Zuweisungen.
 *
 * ## Dieselben Selektoren wie in `app.css`, sonst greift die Farbe nicht
 *
 * Geschrieben wird an genau die Selektoren, an denen `app.css` den Akzent
 * setzt: hell `:root, :root[data-theme='light']`, dunkel
 * `:root[data-theme='dark']`, dazu Leiste und Kopfleiste und die
 * Anmeldeseite. Gleich spezifisch, und dieser Block steht im Kopf **nach** dem
 * Stylesheet; bei Gleichstand gewinnt die spätere Regel.
 *
 * **Bis zum 1. Oktober 2026 stand hier für hell nur `:root`.** Das ist 0,1,0
 * gegen 0,2,0, und `data-theme` steht immer am `<html>`. Die helle Farbe des
 * Betreibers hat damit nie gegriffen; im dunklen Thema schrieben beide Seiten
 * denselben Selektor, und dort fiel es nicht auf (`docs/140 §0` Punkt 1).
 * `BrandStyleTest` liest die Selektoren seitdem aus `app.css`.
 *
 * > **Ein Wert, der eine Marke überschreiben soll, muss mindestens so
 * > spezifisch sein wie die Regel, die sie setzt — die Reihenfolge entscheidet
 * > erst bei Gleichstand.**
 *
 * **Die Reihenfolge hält nur im gebauten Stand.** Unter `npm run dev` setzt
 * Vite das Stylesheet erst zur Laufzeit ins Dokument, also hinter diesen Block,
 * und die Vorgabe gewinnt wieder. Gemessen am 1. Oktober 2026: Der Block stand
 * vor den Stilblöcken der Komponenten und vor `app.css`, und hell galt an der
 * Wurzel `#3730a3`.
 *
 * **Leiste, Kopfleiste und Anmeldeseite bekommen den dunklen Akzent.** Alle
 * drei sind eigene, dunkle Markenflächen und in beiden Themen gleich; ihr
 * Grund `#1a0b2e` steht in {@see BrandSettings::SURFACES_DARK}, gegen die jede
 * dunkle Farbe gerechnet wird. Die Leiste ist seit dem 1. Oktober dabei,
 * entschieden vom Betreiber (`docs/140 §6` Frage 3); vorher stand dort
 * Pfirsich neben Knöpfen in der Farbe des Betreibers.
 *
 * **Der Fokusring geht mit.** `--focus` trägt in `app.css` an jeder dieser
 * Stellen denselben Wert wie `--accent`; ohne ihn stünde neben grünen Knöpfen
 * ein indigoblauer Ring.
 *
 * **`--text-strong` bleibt dort, wie es ist.** In `.signin` trägt es heute
 * denselben Wert wie `--accent`, ist aber eine andere Marke: die Schriftfarbe
 * der Überschrift auf der pflaumenfarbenen Fläche, gegen sie gerechnet
 * (11,11:1, gehalten von `SurfaceTokenTest`). Sie mitzuziehen hiesse, eine
 * gemessene Zusage durch eine ungemessene zu ersetzen.
 *
 * > **Zwei Marken mit demselben Wert sind nicht dieselbe Marke.**
 */
final class Style
{
    /**
     * Die Deckung der Akzentfläche — dieselben Zahlen, die `app.css` führt.
     *
     * Sie stehen hier, weil diese Klasse kein CSS liest; dass sie
     * übereinstimmen, hält `BrandStyleTest`.
     */
    public const SURFACE_ALPHA_LIGHT = 0.09;

    public const SURFACE_ALPHA_DARK = 0.14;

    /**
     * Der Block für den Kopf des Dokuments — oder eine leere Zeichenkette.
     *
     * **Leer, solange nichts eingestellt ist**, und das ist mehr als
     * Sparsamkeit: Ein Block, der die Vorgabewerte noch einmal hinschreibt,
     * wäre eine zweite Fassung der Farben aus `app.css` — und die zweite ist
     * die, die veraltet, sobald jemand das Stylesheet anfasst.
     */
    public static function css(BrandSettings $brand): string
    {
        if ($brand->accent_light === BrandSettings::DEFAULT_ACCENT_LIGHT
            && $brand->accent_dark === BrandSettings::DEFAULT_ACCENT_DARK) {
            return '';
        }

        $hell = self::tokens($brand, $brand->accent_light, self::SURFACE_ALPHA_LIGHT);
        $dunkel = self::tokens($brand, $brand->accent_dark, self::SURFACE_ALPHA_DARK);

        return implode('', [
            ":root,:root[data-theme='light']{".$hell.'}',
            ":root[data-theme='dark']{".$dunkel.'}',
            '.rail,.topbar{'.$dunkel.'}',
            '.signin{'.$dunkel.'}',
        ]);
    }

    /** Die vier Marken eines Akzents. */
    private static function tokens(BrandSettings $brand, string $accent, float $alpha): string
    {
        return sprintf(
            '--accent:%s;--accent-on:%s;--accent-surface:rgb(%s / %s);--focus:%s;',
            $accent,
            $brand->accentOn($accent),
            self::channels($accent),
            rtrim(rtrim(number_format($alpha, 2, '.', ''), '0'), '.'),
            $accent,
        );
    }

    /**
     * `#3730a3` zu `55 48 163`.
     *
     * Die Schreibweise mit Leerzeichen ist die, die `app.css` benutzt; eine
     * zweite (`rgba(55,48,163,0.09)`) wäre dasselbe in einer anderen Sprache,
     * und ein Leser, der beide kennen muss, ist eine Gelegenheit mehr.
     */
    private static function channels(string $hex): string
    {
        $rgb = sscanf(ltrim($hex, '#'), '%2x%2x%2x') ?? [0, 0, 0];

        return sprintf('%d %d %d', (int) ($rgb[0] ?? 0), (int) ($rgb[1] ?? 0), (int) ($rgb[2] ?? 0));
    }
}
