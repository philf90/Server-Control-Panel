<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Knöpfe kommen aus app.css — mechanisch geprüft.
 *
 * **Warum es diesen Test gibt.** Bis August 2026 brachte jede Seite ihre
 * eigenen Knöpfe mit: `padding: 8px 16px` hier, `3px 10px` dort, mal mit
 * Rahmen, mal ohne. „Kunde anlegen" war überhaupt kein Knopf, sondern ein
 * amberfarbener Link — auf dem Bildschirm eine Beschriftung, die zufällig
 * anklickbar ist. Dasselbe Muster wie bei den Schriftgrößen: keine Vorgabe,
 * kein Werkzeug, das sie prüft, und nach einem halben Jahr elf Fassungen
 * desselben Elements.
 *
 * **Was hier nicht geprüft wird:** wie ein Knopf aussieht. Geprüft wird, dass
 * keine Seite ihr eigenes Aussehen erfindet — Innenabstand, Grund, Rahmen und
 * Radius eines Knopfes stehen in app.css und sonst nirgends.
 *
 * **Warum nur `resources/js/Pages`.** Das Gerüst hat Bedienelemente, die keine
 * Knöpfe im Sinne der Gestaltung sind: der Menüknopf der Schublade, das
 * Augensymbol am Passwortfeld, das Abmelden in der Seitenleiste. Sie tragen
 * kein `.knopf` und sollen es nicht — sie sind Teil ihrer Komponente. Der
 * Unterschied ist nicht formal: Ein Knopf auf einer Seite ist eine Aktion,
 * die jemand auslöst; das Auge am Passwortfeld zeigt einen Zustand.
 */
final class ButtonStyleTest extends TestCase
{
    /** Die Eigenschaften, die das Aussehen eines Knopfes ausmachen. */
    private const APPEARANCE = ['padding', 'background', 'border', 'border-radius', 'font-weight'];

    /** @return list<string> */
    private function pages(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js/Pages', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(8, count($files), 'Es werden kaum Seiten gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    public function test_no_page_styles_a_button_itself(): void
    {
        $checked = 0;

        foreach ($this->pages() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
                continue;
            }

            // Ohne diese Zeile klebt ein Kommentar vor einer Regel am
            // Selektor — und ein Kommentar, der das Wort „Knopf" erklärt,
            // liest sich für den Ausdruck unten wie ein Knopfselektor.
            $style = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);

            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                // Ein Selektor, der einen Knopf meint: das Element selbst oder
                // eine Klasse mit „knopf" darin. Das Augensymbol (`.auge`)
                // fällt nicht darunter — es ist keines.
                if (! preg_match('/(^|[\s,>])button\b|\.knopf/', $selector)) {
                    continue;
                }

                foreach (self::APPEARANCE as $property) {
                    $this->assertSame(
                        0,
                        preg_match('/(^|[;\s])'.preg_quote($property, '/').'\s*:/', $rule[2]),
                        sprintf(
                            '%s setzt an „%s" die Eigenschaft %s. Das Aussehen eines Knopfes steht in '.
                            'app.css: .knopf, .knopf.wichtig, .knopf.gefahr, .knopf.klein.',
                            $this->relative($path),
                            $selector,
                            $property,
                        ),
                    );
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(1, $checked, 'Es werden kaum Knopfregeln gefunden — dann prüft dieser Test nichts.');
    }

    public function test_every_button_carries_the_class(): void
    {
        // Ein `<button>` ohne `.knopf` sähe aus wie das, was der Browser
        // mitbringt — grau, eckig, in einer fremden Schrift.
        $buttons = 0;

        foreach ($this->pages() as $path) {
            $template = $this->template((string) file_get_contents($path));

            preg_match_all('/<button\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $template, $matches);

            foreach ($matches[1] as $attributes) {
                $buttons++;

                $this->assertTrue(
                    str_contains($attributes, 'knopf') || str_contains($attributes, 'class="auge"'),
                    sprintf('In %s steht ein <button> ohne class="knopf" (app.css).', $this->relative($path)),
                );
            }
        }

        $this->assertGreaterThan(8, $buttons, 'Es werden kaum Knöpfe gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Ein Knopf, der die Zeilenhöhe schont, muss sie auf dem Telefon zurückbekommen.
     *
     * `.knopf.klein` setzt `min-height: 0` — eine Zusage an die Tabellenzeile,
     * die er sonst auf 30px aufblasen würde. Auf einer schmalen Fläche gibt es
     * diese Zeile nicht mehr: Die Tabelle ist dort ein Kärtchen, und übrig
     * blieben zwei 23px hohe Ziele nebeneinander. docs/24 §2 verlangt für jedes
     * Bedienelement `min-height: var(--tap)`; wer den Wert in der breiten
     * Ansicht unterschreitet, muss ihn in der schmalen wiederherstellen.
     *
     * Der Test hängt nicht an `.klein`, sondern an der Form der Ausnahme — ein
     * künftiges `.knopf.winzig` fällt genauso darunter.
     */
    public function test_a_button_that_gives_up_the_tap_target_regains_it_when_narrow(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        // app.css hat mehr als einen schmalen Block — Grössen, Tabellen,
        // Knöpfe. Wer nur den ersten liest, prüft die falsche Stelle.
        $blocks = $this->compactBlocks($css);

        $this->assertNotSame([], $blocks, 'In app.css steht kein @media (max-width: 720px) mehr.');

        $compact = implode("\n", $blocks);
        $wide = str_replace($blocks, '', $css);

        $found = 0;

        preg_match_all('/([^{}@]*)\{([^{}]*)\}/s', $wide, $rules, PREG_SET_ORDER);

        foreach ($rules as $rule) {
            $selector = trim($rule[1]);

            if (! str_contains($selector, '.knopf')) {
                continue;
            }

            if (preg_match('/(^|[;\s])min-height\s*:\s*([^;]+)/', $rule[2], $height) !== 1) {
                continue;
            }

            if (trim($height[2]) === 'var(--tap)') {
                continue;
            }

            $found++;

            $this->assertSame(
                1,
                preg_match(
                    '/'.preg_quote($selector, '/').'\s*\{[^{}]*min-height\s*:\s*var\(--tap\)/s',
                    $compact,
                ),
                sprintf(
                    '„%s" setzt min-height auf %s und bekommt sie unter 720px nicht zurück. '.
                    'Auf einem Telefon ist das ein Ziel für einen Finger (docs/24 §2).',
                    $selector,
                    trim($height[2]),
                ),
            );
        }

        $this->assertGreaterThan(0, $found, 'Kein Knopf verkleinert sich mehr — dann prüft dieser Test nichts.');
    }

    /**
     * Der Inhalt jedes `@media (max-width: 720px)`-Blocks, über Klammern gezählt.
     *
     * Ein regulärer Ausdruck kann verschachtelte Klammern nicht zählen — er
     * endet an der ersten schliessenden und schneidet den Block mitten in der
     * ersten Regel ab.
     *
     * @return list<string>
     */
    private function compactBlocks(string $css): array
    {
        preg_match_all('/@media\s*\(\s*max-width:\s*720px\s*\)\s*\{/', $css, $matches, PREG_OFFSET_CAPTURE);

        $blocks = [];

        foreach ($matches[0] as $match) {
            $start = $match[1] + strlen($match[0]);
            $depth = 1;

            for ($i = $start; $i < strlen($css); $i++) {
                $depth += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $blocks[] = substr($css, $start, $i - $start);

                    break;
                }
            }
        }

        return $blocks;
    }

    /**
     * Der Rand eines Knopfes muss man sehen — gerechnet, nicht begutachtet.
     *
     * **Warum es diesen Test gibt.** `.knopf` stand auf `--surface` mit einem
     * Rand aus `--line`. Im dunklen Theme sind das #111922 und #141d26, und
     * das ist ein Kontrast von 1,04:1 — auf dem Bildschirm kein Rand, sondern
     * gar nichts. „Bearbeiten" und „Anmelden als" wurden deshalb nicht als
     * Knöpfe wahrgenommen; aufgefallen ist es an einer Kundenliste auf einem
     * echten Monitor, denn im Entwurf hat jeder Wert einen Namen und sieht
     * damit richtig aus.
     *
     * **Die Schwelle steht in WCAG 1.4.11:** 3:1 für die Grenze eines
     * Bedienelements gegen alles, was daneben liegt. Ein Knopf liegt hier auf
     * dreierlei Grund — auf sich selbst, auf einer Karte und auf der Seite —,
     * und gegen jeden davon muss der Rand bestehen.
     *
     * **Geprüft wird die Rechnung und nicht der Wert.** Ein Test, der `#647486`
     * verlangt, hält die Farbe fest; dieser hier hält die Eigenschaft fest.
     * Wer die Reihe umstimmt, darf jede Farbe wählen, die sichtbar bleibt.
     */
    public function test_the_button_border_stands_out_against_everything_next_to_it(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        foreach (['dark', 'light'] as $theme) {
            $tokens = $this->tokens($css, $theme);

            foreach (['button-bg', 'button-line', 'surface', 'bg', 'text'] as $name) {
                $this->assertArrayHasKey($name, $tokens, sprintf('Im Theme „%s" fehlt --%s.', $theme, $name));
            }

            // Auf sich selbst, auf einer Karte, auf der Seite.
            foreach (['button-bg', 'surface', 'bg'] as $neben) {
                $ratio = $this->contrast($tokens['button-line'], $tokens[$neben]);

                $this->assertGreaterThanOrEqual(
                    3.0,
                    $ratio,
                    sprintf(
                        'Theme „%s": --button-line (%s) erreicht gegen --%s (%s) nur %.2f:1. '.
                        'WCAG 1.4.11 verlangt 3:1 — darunter ist der Knopf kein Knopf mehr.',
                        $theme,
                        $tokens['button-line'],
                        $neben,
                        $tokens[$neben],
                        $ratio,
                    ),
                );
            }

            // Und die Beschriftung darauf bleibt lesbar: Wer die Fläche des
            // Knopfes anhebt, um den Rand zu retten, verliert sonst den Text.
            $ratio = $this->contrast($tokens['text'], $tokens['button-bg']);

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf(
                    'Theme „%s": --text auf --button-bg erreicht nur %.2f:1 (WCAG 1.4.3 verlangt 4,5:1).',
                    $theme,
                    $ratio,
                ),
            );
        }
    }

    /**
     * Die Farbmarken eines Themes aus app.css.
     *
     * Über Klammern gezählt und nicht über einen regulären Ausdruck: Zwischen
     * den Marken stehen Kommentare, und in denen stehen Farbwerte als Beispiel.
     *
     * @return array<string, string>
     */
    private function tokens(string $css, string $theme): array
    {
        $start = strpos($css, ":root[data-theme='".$theme."']");

        $this->assertIsInt($start, sprintf('In app.css steht kein Block für das Theme „%s" mehr.', $theme));

        $open = strpos($css, '{', $start);
        $this->assertIsInt($open);

        $depth = 1;
        $end = $open + 1;

        for ($i = $open + 1; $i < strlen($css); $i++) {
            $depth += match ($css[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                $end = $i;

                break;
            }
        }

        $block = (string) preg_replace('#/\*.*?\*/#su', '', substr($css, $open + 1, $end - $open - 1));

        preg_match_all('/--([a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $tokens[$match[1]] = $match[2];
        }

        return $tokens;
    }

    /** Der Kontrast zweier Farben nach WCAG 2.1. */
    private function contrast(string $a, string $b): float
    {
        $high = max($this->luminance($a), $this->luminance($b));
        $low = min($this->luminance($a), $this->luminance($b));

        return ($high + 0.05) / ($low + 0.05);
    }

    private function luminance(string $hex): float
    {
        $rgb = sscanf(ltrim($hex, '#'), '%2x%2x%2x') ?? [0, 0, 0];

        $channel = static function (int|float|null $value): float {
            $value = ((int) $value) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0] ?? 0)
            + 0.7152 * $channel($rgb[1] ?? 0)
            + 0.0722 * $channel($rgb[2] ?? 0);
    }

    public function test_at_most_one_primary_button_per_form(): void
    {
        /*
         * Zwei Knöpfe, die beide die Hauptsache sind, sind keine Rangfolge,
         * sondern zwei Farbflächen.
         *
         * **Je Formular und nicht je Seite.** Hier stand zuerst „je Seite",
         * und „Mein Konto" fiel durch: Dort stehen zwei unabhängige Formulare
         * untereinander — Stammdaten und Passwortwechsel —, und jedes hat
         * seine eigene Hauptsache. Wer dort einen der beiden Knöpfe abstuft,
         * behauptet eine Rangfolge zwischen zwei Dingen, die nichts
         * miteinander zu tun haben.
         */
        foreach ($this->pages() as $path) {
            $template = $this->template((string) file_get_contents($path));

            preg_match_all('#<form\b.*?</form>#su', $template, $forms);

            $bereiche = $forms[0] === [] ? [$template] : $forms[0];

            foreach ($bereiche as $bereich) {
                $count = preg_match_all('/knopf wichtig/', $bereich);

                $this->assertLessThanOrEqual(
                    1,
                    $count,
                    sprintf(
                        '%s hat %d Knöpfe mit „wichtig" in einem Formular. Es gibt je Formular eine Hauptsache.',
                        $this->relative($path),
                        $count,
                    ),
                );
            }
        }
    }
}
