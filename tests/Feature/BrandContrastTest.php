<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Design\Contrast;
use App\Support\Settings\BrandSettings;
use Tests\TestCase;

/**
 * Die Markenfarbe des Betreibers bleibt lesbar — B6, `docs/129 §9`.
 *
 * ## Warum die Zahlen aus `app.css` kommen und nicht aus diesem Test
 *
 * {@see BrandSettings} führt die Flächen und die Vorgabefarben als Konstanten,
 * weil sie kein CSS liest. Zwei Fassungen derselben Farbe sind der Fehler,
 * gegen den dieses Repo seine Wächter baut — dieser hält sie deshalb
 * aneinander und schreibt keine Zahl ab.
 *
 * > **Eine Zahl, die an zwei Stellen steht, ist an einer von beiden falsch,
 * > sobald jemand die andere anfasst.**
 *
 * ## Und was er nicht kann
 *
 * Er sagt nicht, ob eine Farbe **gefällt**. Er sagt, ob die Schrift darauf
 * lesbar ist — 4,5:1 nach WCAG 1.4.3, weil `--accent` in `app.css` sechsmal
 * als Schriftfarbe vorkommt.
 */
final class BrandContrastTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    /**
     * Die Marken eines Blocks aus `app.css`.
     *
     * @return array<string, string>
     */
    private function tokens(string $selector): array
    {
        $css = $this->css();

        /*
         * **Der ganze Selektor und nicht sein Anfang.**
         *
         * Zwei Wege dahin, und beide hat der erste Wurf genommen. `:root`
         * allein trifft den zweiteiligen hellen Block nicht — dort steht
         * `:root,` und darunter `:root[data-theme='light']` —, sondern einen
         * gleichnamigen weiter unten. Und `.signin` allein trifft seit
         * **dieser** Änderung `.signin .brand-logo`, weil die Regel für das
         * Logo im Stylesheet vor ihm steht.
         *
         * > **Ein Anker, der ein Präfix ist, trifft die längere Zeile, die
         * > jemand später darüber schreibt — und „später" kann derselbe
         * > Commit sein.**
         */
        $anfang = strpos($css, "\n".$selector.' {');

        self::assertNotFalse($anfang, sprintf(
            'Den Block `%s` gibt es in app.css nicht mehr. Ohne ihn misst dieser Wächter nichts — '
            .'und eine leere Liste sähe aus wie ein Stylesheet ohne Fehler.',
            $selector,
        ));

        $klammer = strpos($css, '{', $anfang);
        $ende = strpos($css, "\n}", (int) $klammer);
        $block = substr($css, (int) $klammer, (int) $ende - (int) $klammer);

        preg_match_all('/^\s+(--[a-z-]+):\s*([^;]+);/m', $block, $treffer, PREG_SET_ORDER);

        $out = [];

        foreach ($treffer as $t) {
            $out[$t[1]] = trim($t[2]);
        }

        return $out;
    }

    /**
     * Die Flächen, gegen die gerechnet wird, sind die des Stylesheets.
     *
     * **Drei Blöcke und nicht zwei.** `.signin` trägt seit „Kontor" einen
     * eigenen Markensatz und ist die Seite, die das Abnahmekriterium nennt.
     * Wer nur `:root` liest, lässt sie ungemessen.
     */
    public function test_the_surfaces_are_the_ones_the_stylesheet_has(): void
    {
        $hell = $this->tokens(":root[data-theme='light']");
        $dunkel = $this->tokens(":root[data-theme='dark']");
        $anmeldung = $this->tokens('.signin');

        self::assertSame(
            [$hell['--bg'], $hell['--surface']],
            BrandSettings::SURFACES_LIGHT,
            'Die hellen Flächen stehen in app.css und werden hier nur nachgehalten.',
        );

        self::assertSame(
            [$dunkel['--bg'], $dunkel['--surface'], $anmeldung['--surface']],
            BrandSettings::SURFACES_DARK,
            'Die dunklen Flächen sind die beiden des Themes **und** die der Anmeldeseite.',
        );
    }

    /** Und die Vorgabefarben sind die, die das Stylesheet ausliefert. */
    public function test_the_defaults_are_the_shipped_accents(): void
    {
        self::assertSame(
            $this->tokens(":root[data-theme='light']")['--accent'],
            BrandSettings::DEFAULT_ACCENT_LIGHT,
        );

        self::assertSame(
            $this->tokens(":root[data-theme='dark']")['--accent'],
            BrandSettings::DEFAULT_ACCENT_DARK,
        );
    }

    /**
     * Die ausgelieferten Farben bestehen ihre eigene Prüfung.
     *
     * Ohne diese Richtung wäre die Messung daneben eine Null ohne Bedeutung:
     * Eine Schwelle, die schon die eingebauten Farben nicht schaffen, hätte
     * jede Eingabe abgewiesen und niemandem etwas gesagt.
     */
    public function test_the_shipped_accents_pass_their_own_rule(): void
    {
        $hell = BrandSettings::verdict(BrandSettings::DEFAULT_ACCENT_LIGHT, BrandSettings::SURFACES_LIGHT);
        $dunkel = BrandSettings::verdict(BrandSettings::DEFAULT_ACCENT_DARK, BrandSettings::SURFACES_DARK);

        self::assertTrue($hell['passes'], sprintf('hell: %s:1', $hell['ratio']));
        self::assertTrue($dunkel['passes'], sprintf('dunkel: %s:1', $dunkel['ratio']));
    }

    /** Und eine Farbe, unter der niemand mehr liest, fällt durch. */
    public function test_a_washed_out_colour_is_refused(): void
    {
        $urteil = BrandSettings::verdict('#cccccc', BrandSettings::SURFACES_LIGHT);

        self::assertFalse($urteil['passes']);
        self::assertLessThan(Contrast::TEXT, $urteil['ratio']);
    }

    /**
     * Gemessen wird gegen die **ungünstigste** Fläche und nicht die erste.
     *
     * Gegen die freundlichste zu rechnen hiesse, eine Farbe zuzulassen, die
     * auf der Hälfte der Flächen durchfällt — und die Hälfte sähe man erst,
     * wenn jemand das Theme umschaltet.
     */
    public function test_the_worst_surface_decides(): void
    {
        $urteil = BrandSettings::verdict(BrandSettings::DEFAULT_ACCENT_DARK, BrandSettings::SURFACES_DARK);

        $einzeln = [];

        foreach (BrandSettings::SURFACES_DARK as $flaeche) {
            $einzeln[$flaeche] = Contrast::between(BrandSettings::DEFAULT_ACCENT_DARK, $flaeche);
        }

        self::assertSame(
            array_search(min($einzeln), $einzeln, true),
            $urteil['surface'],
            'Genannt wird die Fläche, an der das schlechteste Verhältnis entsteht — eine Zahl ohne '
            .'ihren Gegenstand sagt dem Betreiber nicht, wo er nachsehen soll.',
        );

        self::assertSame(round(min($einzeln), 2), $urteil['ratio']);
    }

    /**
     * Die Schriftfarbe auf der Akzentfläche wird gerechnet und nicht geraten.
     *
     * Gemessen an zwei Farben, bei denen die Faustregel „heller Grund, dunkle
     * Schrift" in verschiedene Richtungen zeigt.
     */
    public function test_the_text_on_the_accent_is_computed(): void
    {
        $marke = new BrandSettings;

        self::assertSame('#ffffff', $marke->accentOn('#111827'), 'Auf einem dunklen Akzent steht Weiss.');
        self::assertSame('#0f1116', $marke->accentOn('#fde68a'), 'Auf einem hellen Akzent steht Dunkel.');
    }

    /** Eine unlesbare Ablage fällt auf die Vorgabe zurück und nicht auf Schwarz. */
    public function test_a_broken_store_falls_back_to_the_shipped_colour(): void
    {
        $marke = BrandSettings::fromArray(['accent_light' => 'blau', 'accent_dark' => '#abc']);

        self::assertSame(BrandSettings::DEFAULT_ACCENT_LIGHT, $marke->accent_light);
        self::assertSame(BrandSettings::DEFAULT_ACCENT_DARK, $marke->accent_dark,
            'Auch die Kurzform `#abc` ist keine Farbe für diesen Leser — `sscanf` läse sie falsch, '
            .'und heraus käme ein stilles Schwarz im blauen Kanal.');
    }
}
