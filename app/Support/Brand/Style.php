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
 * **Und auf den Markenflächen gehen Schrift und Zeichen mit**, entschieden vom
 * Betreiber am 3. Oktober 2026 im Lauf für B6 (`docs/140 §6c`). Bis dahin
 * stand auf der Anmeldeseite „Angemeldet bleiben" in Pfirsich neben einem
 * grünen Knopf, ohne Logo dazu der Name und das Zeichen; in der Leiste trug
 * der obere Balken des Zeichens weiter Pink. Gesetzt werden deshalb auf der
 * Anmeldeseite `--text-strong` und `--mark-accent`, in Leiste und Kopfleiste
 * `--mark-accent`. Dort ist `--text-strong` Weiss und keine Farbe der
 * Auslieferung.
 *
 * **Hier stand bis dahin, `--text-strong` bleibe, wie es ist: Es mitzuziehen
 * hiesse, eine gemessene Zusage durch eine ungemessene zu ersetzen.** Das
 * stimmte, und die Messung beim Bauen hat es belegt. Mit dem dunkelsten
 * Akzent, den die Prüfung damals annahm (`#02925b`), stand die Überschrift
 * einer Fehlermeldung auf der Anmeldeseite bei 3,96:1. Sie liest
 * `--text-strong` und steht auf der Tönung der Meldung, und die rechnete die
 * Prüfung nicht. Seitdem tut sie es ({@see BrandSettings::TINTS_SIGNIN}), und
 * die Zusage ist wieder gemessen: Jeder Akzent, den sie annimmt, erreicht auf
 * den drei Gründen der Anmeldeseite mindestens 6,27:1, 6,03:1 und auf der
 * Feldfläche 5,22:1. Dort stehen in dieser Farbe nur das Auge beim Überfahren
 * und die Ziffern des Codes, 34 px gross. `BrandContrastTest` rechnet das aus
 * den Werten in `app.css` nach.
 *
 * > **Zwei Marken mit demselben Wert sind nicht dieselbe Marke — wer beide
 * > umfärbt, rechnet beide auf jedem Grund, auf dem sie gelesen werden.**
 *
 * > **Was nach einem Wechsel der Marke in der Farbe der Auslieferung stehen
 * > bleibt, sieht der Besucher als Rest — gleich, wie die Marke heisst.**
 *
 * **`--mark-accent` an der Wurzel bleibt, und das ist eine Ausnahme mit
 * Grund.** Dort färbt es die Auswahl und die Suchtreffer im Datei-Editor, und
 * über dieser Fläche steht Text. Die Prüfung beim Speichern rechnet den Akzent
 * als Schrift auf einem Grund und nicht als Grund unter Schrift.
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
        $zeichen = '--mark-accent:'.$brand->accent_dark.';';
        $schrift = '--text-strong:'.$brand->accent_dark.';';

        return implode('', [
            ":root,:root[data-theme='light']{".$hell.'}',
            ":root[data-theme='dark']{".$dunkel.'}',
            '.rail,.topbar{'.$dunkel.$zeichen.'}',
            '.signin{'.$dunkel.$schrift.$zeichen.'}',
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
