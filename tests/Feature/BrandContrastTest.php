<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brand\Style;
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
     * Die Zustandstönungen eines Blocks: Name des Zustands => Farbe und Deckung.
     * `--accent-surface` gehört nicht dazu — das ist die eigene Tönung des
     * Akzents, und die mischt die Prüfung aus dem Akzent selbst.
     *
     * @return array<string, array{string, float}>
     */
    private function tints(string $selector): array
    {
        $aus = [];

        foreach ($this->tokens($selector) as $marke => $wert) {
            if (preg_match('/^--([a-z]+)-surface$/D', $marke, $name) !== 1 || $name[1] === 'accent') {
                continue;
            }

            self::assertSame(1, preg_match('/^rgb\((\d+) (\d+) (\d+) \/ ([\d.]+)\)$/D', $wert, $t), sprintf(
                '%s in `%s` ist keine Tönung in der Schreibweise von app.css: %s', $marke, $selector, $wert));

            $aus[$name[1]] = [sprintf('#%02x%02x%02x', (int) $t[1], (int) $t[2], (int) $t[3]), (float) $t[4]];
        }

        return $aus;
    }

    /** Die Deckung aus `rgb(r g b / a)`. */
    private function alpha(string $wert): float
    {
        self::assertSame(1, preg_match('/\/\s*([\d.]+)\)$/D', $wert, $t), sprintf('Keine Deckung in %s.', $wert));

        return (float) $t[1];
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

    /**
     * Die getönten Flächen, gegen die gerechnet wird, sind die des Stylesheets
     * — in beide Richtungen.
     *
     * Eine Tönung, die `app.css` dazubekommt und diese Liste nicht, ist ein
     * Grund, auf dem der Akzent ungeprüft Schrift tragen kann. Eine, die es
     * nicht mehr führt, weist Farben für eine Fläche ab, die es nicht gibt.
     * Beides sieht im Formular gleich aus: Die Farbe wird angenommen
     * beziehungsweise abgewiesen, und niemand fragt warum.
     *
     * Die Leiste führt keine Zustandstönung, und das ist der Grund, dass die
     * Prüfung sie dort nicht rechnet: Auf ihr steht keine Meldung.
     */
    public function test_the_tints_are_the_ones_the_stylesheet_has(): void
    {
        self::assertSame($this->tints(":root[data-theme='light']"), BrandSettings::TINTS_LIGHT,
            'Die Tönungen des hellen Themas stehen in app.css und werden hier nur nachgehalten.');
        self::assertSame($this->tints(":root[data-theme='dark']"), BrandSettings::TINTS_DARK,
            'Die Tönungen des dunklen Themas stehen in app.css und werden hier nur nachgehalten.');
        self::assertSame($this->tints('.signin'), BrandSettings::TINTS_SIGNIN,
            'Die Anmeldeseite setzt ihre Zustände selbst; die Prüfung rechnet genau diese.');
        self::assertSame([], $this->tints('.topbar'),
            'Leiste und Kopfleiste setzen eine Zustandstönung — dann steht dort eine Meldung, und die Prüfung kennt ihren Grund nicht.');

        $anmeldung = $this->tokens('.signin');

        self::assertSame([$anmeldung['--bg'], $anmeldung['--surface']], BrandSettings::SIGNIN_GROUNDS,
            'Die Gründe der Anmeldeseite stehen in app.css: um die Maske und in ihr.');

        // Die eigene Tönung des Akzents: dieselbe Deckung, mit der der
        // Markenblock --accent-surface schreibt.
        self::assertSame(Style::SURFACE_ALPHA_LIGHT, $this->alpha($this->tokens(":root[data-theme='light']")['--accent-surface']));
        self::assertSame(Style::SURFACE_ALPHA_DARK, $this->alpha($this->tokens(":root[data-theme='dark']")['--accent-surface']));
    }

    /**
     * Eine Farbe, die auf jeder Fläche besteht und auf einer Tönung nicht, wird
     * abgewiesen — und die Meldung nennt den Ort.
     *
     * Die beiden Prüfkörper hat die alte Prüfung angenommen: `#02925b` war der
     * dunkelste Akzent, den sie zuliess, und mit ihm stand am 3. Oktober 2026
     * die Überschrift einer Fehlermeldung auf der Anmeldeseite bei 3,96:1 und
     * der aktive Menüpunkt der Leiste bei 4,14:1 (`docs/140 §6c`).
     */
    public function test_a_colour_that_fails_only_on_a_tint_is_refused(): void
    {
        foreach ([
            'hell' => ['#737373', BrandSettings::SURFACES_LIGHT, BrandSettings::verdictLight('#737373')],
            'dunkel' => ['#02925b', BrandSettings::SURFACES_DARK, BrandSettings::verdictDark('#02925b')],
        ] as $thema => [$farbe, $flaechen, $urteil]) {
            self::assertTrue(BrandSettings::verdict($farbe, $flaechen)['passes'],
                sprintf('%s: %s besteht auf den Flächen nicht mehr — dann misst dieser Fall nichts über die Tönungen.', $thema, $farbe));

            self::assertFalse($urteil['passes'], sprintf(
                '%s: %s wird angenommen, obwohl sie auf %s nur %s:1 erreicht.', $thema, $farbe, $urteil['surface'], $urteil['ratio']));
            self::assertNotContains($urteil['surface'], $flaechen, 'Entschieden hat eine Tönung und keine Fläche.');
            self::assertStringContainsString('Tönung', (string) $urteil['place'],
                'Der Hexwert einer Tönung steht in keinem Stylesheet; ohne den Ort sucht der Betreiber eine Farbe, die es nur auf dem Bildschirm gibt.');
        }
    }

    /**
     * Die eigene Tönung des Akzents hängt am Akzent und wird mit ihm gemischt.
     *
     * Gemessen an `#6ee7b7`: Ihr schlechtester Grund ist ihre eigene Tönung
     * über `#14171d`. Ohne die Mischung stünde dort eine Fläche, und der
     * aktive Knopf bliebe ungeprüft.
     */
    public function test_the_accent_is_reckoned_on_its_own_tint(): void
    {
        $urteil = BrandSettings::verdictDark('#6ee7b7');

        self::assertSame(Contrast::over('#6ee7b7', Style::SURFACE_ALPHA_DARK, '#14171d'), $urteil['surface']);
        self::assertStringContainsString('eigenen Tönung', (string) $urteil['place']);
        self::assertTrue($urteil['passes']);

        self::assertSame('#171e34', Contrast::over('#02925b', 0.14, '#1a0b2e'),
            'Die Mischung ist die des Browsers: Chromium zeichnet diese Tönung am 3. Oktober 2026 als #171e34.');
    }

    /**
     * Was die Prüfung annimmt, ist auf jedem Grund der Anmeldeseite lesbar —
     * auch auf den beiden, die sie nicht ausdrücklich rechnet.
     *
     * Seit dem 3. Oktober 2026 trägt dort auch `--text-strong` die Farbe des
     * Betreibers: die Überschrift, „Angemeldet bleiben", die Ziffern im Feld
     * des zweiten Faktors. `--bg` liegt um die Maske, `--control-bg` unter den
     * Feldern. Auf der Feldfläche stehen in dieser Farbe nur das Auge beim
     * Überfahren und die Ziffern des Codes, 34 px gross — beides verlangt
     * 3:1 (`docs/20 §7.2`: grosse Schrift).
     *
     * **Gerechnet wird mit dem dunkelsten Akzent, den die Prüfung annimmt.**
     * Er folgt aus dem hellsten Grund, der nicht vom Akzent abhängt — den
     * Flächen und den Tönungen der Zustände; die eigene Tönung kann nur mehr
     * abweisen. Dass das die Prüfung selbst ist und kein Modell daneben, hält
     * die Gegenprobe an jedem Grauton.
     */
    public function test_what_the_check_accepts_holds_on_every_ground_of_the_signin_page(): void
    {
        $fest = array_intersect_key(BrandSettings::groundsDark('#ffffff'), BrandSettings::groundsDark('#000000'));

        self::assertGreaterThan(count(BrandSettings::SURFACES_DARK), count($fest),
            'Untergrenze: Ohne getönte Gründe rechnete dieser Fall die alte Prüfung nach.');

        $hellste = max(array_map(static fn (int|string $grund): float => Contrast::luminance((string) $grund), array_keys($fest)));
        $schwelle = Contrast::TEXT * ($hellste + 0.05) - 0.05;

        for ($k = 0; $k < 256; $k++) {
            $grau = sprintf('#%02x%02x%02x', $k, $k, $k);

            self::assertSame(Contrast::luminance($grau) >= $schwelle, BrandSettings::verdictDark($grau)['passes'], sprintf(
                'Bei %s gehen die Rechnung hier und die Prüfung auseinander — dann gilt die Grenze unten nicht für das, was die Prüfung annimmt.',
                $grau,
            ));
        }

        $anmeldung = $this->tokens('.signin');

        foreach (['--bg' => Contrast::TEXT, '--surface' => Contrast::TEXT, '--control-bg' => Contrast::CONTROL] as $marke => $verlangt) {
            $verhaeltnis = ($schwelle + 0.05) / (Contrast::luminance($anmeldung[$marke]) + 0.05);

            self::assertGreaterThanOrEqual($verlangt, $verhaeltnis, sprintf(
                "Der dunkelste Akzent, den die Prüfung annimmt, erreicht auf %s (%s) der Anmeldeseite nur %.2f:1; verlangt sind %s:1.\n\n"
                .'Dort steht seit dem 3. Oktober 2026 auch --text-strong in der Farbe des Betreibers (docs/140 §6c).',
                $marke,
                $anmeldung[$marke],
                $verhaeltnis,
                $verlangt,
            ));
        }
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
