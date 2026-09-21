<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brand\Style;
use App\Support\Settings\BrandSettings;
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
     * Drei Selektoren, und der dritte ist der, den man vergisst.
     *
     * Die Anmeldeseite trägt einen eigenen Markensatz und ist die Seite, die
     * das Abnahmekriterium nennt.
     */
    public function test_the_sign_in_surface_gets_the_colour_too(): void
    {
        $css = Style::css($this->brand());

        self::assertStringContainsString(':root{', $css);
        self::assertStringContainsString(":root[data-theme='dark']{", $css);
        self::assertStringContainsString('.signin{', $css);
    }

    /**
     * Die Anmeldeseite bekommt den **dunklen** Akzent.
     *
     * Sie ist in beiden Themes dunkel — ein heller Akzent von dort wäre auf
     * ihrer pflaumenfarbenen Fläche der falsche.
     */
    public function test_the_sign_in_surface_takes_the_dark_accent(): void
    {
        $css = Style::css($this->brand(dunkel: '#fde68a'));
        $anmeldung = substr($css, (int) strpos($css, '.signin{'));

        self::assertStringContainsString('--accent:#fde68a', $anmeldung);
        self::assertStringContainsString('--focus:#fde68a', $anmeldung);
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
}
