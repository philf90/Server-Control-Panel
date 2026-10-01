<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brand\Style;
use App\Support\Settings\BrandSettings;
use Tests\Support\CssRules;
use Tests\TestCase;

/**
 * Die Markenfarbe setzt Marken und schreibt keine Regeln — B6.
 *
 * ## Die Regel, die dabei nicht fällt
 *
 * `CLAUDE.md` und `docs/20 §7.2`: **Jede Farbe kommt aus
 * `resources/css/app.css`.** Ein Betreiber, der eine Farbe vorgibt, scheint
 * dem zu widersprechen — tut es aber nicht, solange nur der **Wert** einer
 * Marke von aussen kommt und keine Regel.
 *
 * > **Eine Marke, die an einer Stelle gesetzt wird, ist das Gegenteil einer
 * > Farbe, die verstreut ist.**
 *
 * Gemessen wird deshalb die **Form** des ausgegebenen Blocks: Zwischen den
 * Klammern steht ausschliesslich `--…:…`. Eine Eigenschaft wie `color` oder
 * `background` wäre eine Regel ausserhalb von `app.css`, und genau die soll es
 * nicht geben.
 */
final class BrandStyleTest extends TestCase
{
    private function brand(string $hell = '#111827', string $dunkel = '#fde68a'): BrandSettings
    {
        return new BrandSettings(accent_light: $hell, accent_dark: $dunkel);
    }

    /**
     * Ohne Einstellung steht kein Block da.
     *
     * Die Vorgabewerte noch einmal hinzuschreiben wäre eine zweite Fassung der
     * Farben aus `app.css` — und die zweite ist die, die veraltet, sobald
     * jemand das Stylesheet anfasst.
     */
    public function test_the_shipped_colours_produce_nothing(): void
    {
        self::assertSame('', Style::css(new BrandSettings));
    }

    /** Und eine eigene Farbe produziert etwas. */
    public function test_an_own_colour_produces_a_block(): void
    {
        self::assertNotSame('', Style::css($this->brand()),
            'Ohne diese Richtung wäre die Messung darüber eine Null ohne Bedeutung.');
    }

    /**
     * Zwischen den Klammern stehen nur Marken.
     *
     * Gelesen wird Block für Block, damit ein Selektor mit einer echten
     * Eigenschaft auffällt — und nicht erst dann, wenn jemand die Seite
     * ansieht.
     */
    public function test_only_custom_properties_are_declared(): void
    {
        preg_match_all('/\{([^}]*)\}/', Style::css($this->brand()), $bloecke);

        self::assertNotSame([], $bloecke[1], 'Kein Block gefunden — dann misst dieser Fall nichts.');

        foreach ($bloecke[1] as $block) {
            foreach (array_filter(explode(';', $block)) as $zeile) {
                self::assertStringStartsWith('--', trim($zeile), sprintf(
                    'In `%s` steht eine Eigenschaft und keine Marke. Eine Regel ausserhalb von '
                    .'app.css ist genau das, was „jede Farbe kommt aus app.css" ausschliesst.',
                    trim($zeile),
                ));
            }
        }
    }

    /**
     * Der Block schreibt an genau die Selektoren, an denen `app.css` den
     * Akzent setzt — gelesen aus dem Stylesheet und nicht aus einer Liste
     * hier.
     *
     * **Bis zum 1. Oktober 2026 stand für hell nur `:root`.** `app.css` setzt
     * die helle Fassung mit `:root, :root[data-theme='light']`, und
     * `data-theme` steht immer am `<html>`: 0,1,0 gegen 0,2,0. Die helle Farbe
     * des Betreibers griff damit nie, im Browser gemessen und von keinem Fall
     * hier gesehen, weil dieser Wächter die Ausgabe las und nicht fragte, ob
     * sie ankommt (`docs/140 §0` Punkt 1).
     *
     * Gleiche Selektoren heissen gleiche Spezifität. Was dann noch
     * entscheidet, ist die Reihenfolge, und die hält
     * {@see self::test_the_block_stands_after_the_stylesheet()}.
     *
     * > **Ein Wert, der eine Marke überschreiben soll, muss mindestens so
     * > spezifisch sein wie die Regel, die sie setzt — die Reihenfolge
     * > entscheidet erst bei Gleichstand.**
     */
    public function test_the_block_writes_where_the_stylesheet_sets_the_accent(): void
    {
        $soll = array_keys($this->accentRules($this->stylesheet()));
        $ist = array_keys($this->accentRules(Style::css($this->brand())));
        sort($soll);
        sort($ist);

        self::assertGreaterThanOrEqual(6, count($soll),
            'Untergrenze: app.css setzt den Akzent an sechs Selektoren — die Wurzel zweimal, '
            .'die dunkle Wurzel, Leiste, Kopfleiste und Anmeldeseite. Weniger heisst, der Leser greift ins Leere.');

        self::assertSame($soll, $ist,
            "Der Markenblock schreibt an andere Selektoren als app.css.\n\n"
            ."Ein Selektor, der weniger spezifisch ist als der in app.css, verliert, gleich wo er steht;\n"
            .'einer, der fehlt, lässt die Fläche bei der Vorgabe. Beides sieht im Quelltext richtig aus.');
    }

    /**
     * Jede Akzentmarke, die `app.css` an einer Fläche setzt, setzt der Block
     * dort auch.
     *
     * Gefragt wird nach `--accent`, `--accent-on`, `--accent-surface` und
     * `--focus`. Der Fokusring trägt in `app.css` an jeder dieser Stellen
     * denselben Wert wie der Akzent; ohne ihn stünde neben einem grünen Knopf
     * ein indigoblauer Ring.
     */
    public function test_every_accent_token_of_the_stylesheet_comes_along(): void
    {
        $soll = $this->accentRules($this->stylesheet());
        $ist = $this->accentRules(Style::css($this->brand()));

        self::assertContains('--focus', $soll[':root'] ?? [],
            'Untergrenze: app.css setzt an der Wurzel einen Fokusring. Ohne ihn misst dieser Fall nichts.');

        $fehlt = [];

        foreach ($soll as $selektor => $marken) {
            foreach (array_diff($marken, $ist[$selektor] ?? []) as $marke) {
                $fehlt[] = $selektor.' '.$marke;
            }
        }

        self::assertSame([], $fehlt, sprintf(
            "Diese Marken setzt app.css, der Markenblock nicht:\n  %s\n\n"
            .'An diesen Stellen bleibt die Vorgabe stehen, neben der Farbe des Betreibers.',
            implode("\n  ", $fehlt),
        ));
    }

    /**
     * Hell nimmt den hellen Akzent, jede andere Fläche den dunklen.
     *
     * Leiste, Kopfleiste und Anmeldeseite sind eigene, dunkle Markenflächen
     * und in beiden Themen dieselben. Ein heller Akzent wäre dort der falsche,
     * gerechnet gegen einen weissen Grund. Die Leiste ist seit dem 1. Oktober
     * 2026 dabei, entschieden vom Betreiber (`docs/140 §6` Frage 3).
     *
     * Die beiden hellen Selektoren stehen hier als Liste: Welche Fläche hell
     * ist, sagt `app.css` nur über seine Werte, und die sind Vorgaben und kein
     * Merkmal.
     */
    public function test_the_dark_surfaces_take_the_dark_accent(): void
    {
        $regeln = CssRules::flatten(Style::css($this->brand(hell: '#111827', dunkel: '#fde68a')));

        self::assertGreaterThanOrEqual(6, count($regeln), 'Untergrenze: ohne Regeln misst dieser Fall nichts.');

        foreach ($regeln as $regel) {
            $hell = in_array($regel['selector'], [':root', ":root[data-theme='light']"], true);
            $farbe = $hell ? '#111827' : '#fde68a';

            self::assertStringContainsString('--accent:'.$farbe.';', $regel['body'], sprintf(
                '%s trägt den %s Akzent nicht.', $regel['selector'], $hell ? 'hellen' : 'dunklen'));
            self::assertStringContainsString('--focus:'.$farbe.';', $regel['body'], sprintf(
                '%s trägt den Fokusring nicht in der Farbe seines Akzents.', $regel['selector']));
        }
    }

    /**
     * Der Block steht im Kopf **nach** dem Stylesheet.
     *
     * Bei gleichen Selektoren entscheidet die Reihenfolge; stünde der Block
     * davor, gewönne die Vorgabe überall. Gelesen wird die Vorlage ohne ihre
     * Kommentare, denn der Kommentar über dem Block nennt beide.
     */
    public function test_the_block_stands_after_the_stylesheet(): void
    {
        $vorlage = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/app.blade.php'),
        );

        $stylesheet = strpos($vorlage, '@vite(');
        $block = strpos($vorlage, 'Style::css(');

        self::assertNotFalse($stylesheet, 'Kein @vite in der Vorlage — dann misst dieser Fall nichts.');
        self::assertNotFalse($block, 'Kein Markenblock in der Vorlage — dann misst dieser Fall nichts.');
        self::assertGreaterThan($stylesheet, $block,
            'Der Markenblock steht vor dem Stylesheet. Bei gleichen Selektoren gewinnt dann die Vorgabe.');
    }

    /**
     * Die Schriftfarbe auf der Akzentfläche wird mitgeliefert und gerechnet.
     *
     * Ohne sie stünde auf einem hellen Markenknopf weiterhin die eingebaute
     * Schriftfarbe — und die ist für die eingebaute Fläche gerechnet.
     */
    public function test_the_text_on_the_accent_comes_along(): void
    {
        $css = Style::css($this->brand(hell: '#fde68a'));

        self::assertStringContainsString('--accent-on:#0f1116', $css,
            'Auf einem hellen Akzent steht dunkle Schrift — gerechnet und nicht geraten.');
    }

    /** Und die Deckung der Akzentfläche ist die, die `app.css` führt. */
    public function test_the_surface_alpha_matches_the_stylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertStringContainsString(
            sprintf('/ %s)', Style::SURFACE_ALPHA_LIGHT),
            $css,
            'Die Deckung steht in app.css und wird hier nur nachgehalten.',
        );

        self::assertStringContainsString(sprintf('/ %s)', Style::SURFACE_ALPHA_DARK), $css);
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    /**
     * Die Selektoren, die `--accent` setzen, mit den Akzentmarken, die dort
     * stehen.
     *
     * @return array<string, list<string>>
     */
    private function accentRules(string $css): array
    {
        $aus = [];

        foreach (CssRules::flatten($css) as $regel) {
            if (preg_match('/(?:^|;)\s*--accent\s*:/', $regel['body']) !== 1) {
                continue;
            }

            foreach (['--accent', '--accent-on', '--accent-surface', '--focus'] as $marke) {
                if (preg_match('/(?:^|;)\s*'.preg_quote($marke, '/').'\s*:/', $regel['body']) === 1) {
                    $aus[$regel['selector']][] = $marke;
                }
            }
        }

        return $aus;
    }
}
