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

    /**
     * Jede Zusatzklasse an einem Knopf muss es in app.css geben.
     *
     * **Der Fehler, den dieser Test hätte melden müssen und nicht meldete.**
     * In P3 stand auf drei Seiten `class="knopf betont"` — eine Klasse, die
     * app.css nicht kennt. Der Knopf sah aus wie ein gewöhnlicher, die
     * Hervorhebung fehlte, und kein Lauf sagte etwas: Der Test darüber prüft,
     * dass keine Seite ihr *eigenes* Aussehen erfindet, nicht, dass sie ein
     * vorhandenes trifft. Gesehen hat es der Blick in den Browser — die zwei
     * Umschalter der Protokollansicht sahen gleich aus, obwohl einer der
     * ausgewählte war.
     *
     * Das ist wieder dasselbe Muster: eine Zeichenkette, die auf etwas zeigt,
     * ohne dass jemand den Bezug prüft. Ab jetzt prüft ihn dieser Test.
     */
    public function test_every_button_modifier_exists_in_the_stylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        // Die Klassen, die app.css neben `.knopf` kennt: `.knopf.wichtig` und
        // Verwandte. Gelesen statt aufgezählt — eine Aufzählung hier wäre der
        // zweite Ort für dieselbe Liste.
        preg_match_all('/\.knopf\.([a-zäöüß-]+)/', $css, $found);

        $known = array_values(array_unique($found[1]));

        $this->assertGreaterThan(2, count($known), 'In app.css stehen kaum Knopfvarianten — dann prüft dieser Test nichts.');

        $unknown = [];
        $checked = 0;

        foreach ($this->pages() as $path) {
            $template = $this->template((string) file_get_contents($path));

            // Beide Schreibweisen: `class="knopf wichtig"` und die gebundene
            // Form `:class="['knopf', { aktiv: … }]"`.
            // `\s` nach `knopf` ist der Unterschied zwischen einem Knopf und
            // `class="knopfreihe"` — der Reihe, in der Knöpfe stehen. Ohne ihn
            // meldete der Test „reihe" als unbekannte Knopfklasse.
            preg_match_all('/class="knopf(\s[^"]*)?"/', $template, $statisch);
            preg_match_all('/:class="\\[\'knopf\',\\s*\\{([^}]*)\\}/', $template, $gebunden);

            foreach ($statisch[1] as $rest) {
                foreach (preg_split('/\s+/', trim($rest)) ?: [] as $klasse) {
                    if ($klasse === '') {
                        continue;
                    }

                    $checked++;

                    if (! in_array($klasse, $known, true)) {
                        $unknown[] = $this->relative($path).': '.$klasse;
                    }
                }
            }

            foreach ($gebunden[1] as $rest) {
                preg_match_all('/([a-zäöüß-]+)\s*:/', $rest, $namen);

                foreach ($namen[1] as $klasse) {
                    $checked++;

                    if (! in_array($klasse, $known, true)) {
                        $unknown[] = $this->relative($path).': '.$klasse;
                    }
                }
            }
        }

        $this->assertGreaterThan(3, $checked, 'Es werden kaum Knopfklassen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $unknown, sprintf(
            "Diese Klassen stehen an einem Knopf und nicht in app.css:\n  %s\n\nBekannt sind: %s.",
            implode("\n  ", $unknown),
            implode(', ', $known),
        ));
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
     * Keine Seite gestaltet ein Eingabefeld selbst.
     *
     * **Dieselbe Regel wie für Knöpfe, und aus demselben Anlass — nur neun
     * Monate später bemerkt.** Elf Seiten trugen dieselbe Zeile:
     *
     *     input { padding: 6px 8px; … border: 1px solid var(--line); … }
     *
     * Elfmal abgeschrieben, und beim zwölften Mal wäre sie anders. Genau das
     * ist die Geschichte der Knöpfe, die dieser Datei ihren Namen gegeben hat:
     * keine Vorgabe, kein Werkzeug, das sie prüft, und nach einem halben Jahr
     * elf Fassungen desselben Elements.
     *
     * Der Preis stand daneben und hat neun Monate niemandem wehgetan: `--line`
     * ist eine Haarlinie zum Trennen, kein Rand für ein Bedienelement. Gegen
     * den Seitengrund erreicht sie **1,13:1** im dunklen Theme und **1,09:1**
     * im hellen — ein Eingabefeld ohne sichtbare Grenze. Gerechnet wird das im
     * Test darunter.
     */
    public function test_no_page_styles_a_field_itself(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->pages() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
                continue;
            }

            $style = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);

            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                if (! preg_match('/(^|[\s,>+~])(input|select|textarea)\b/', $selector)) {
                    continue;
                }

                $checked++;

                foreach (self::APPEARANCE as $property) {
                    if (preg_match('/(^|[;\s])'.preg_quote($property, '/').'\s*:/', $rule[2]) === 1) {
                        $found[] = sprintf('%s  „%s" setzt %s', $this->relative($path), $selector, $property);
                    }
                }
            }
        }

        $this->assertGreaterThan(4, $checked, 'Es werden kaum Feldregeln gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Regeln geben einem Eingabefeld sein eigenes Aussehen:\n  %s\n\n".
            'Das Aussehen eines Feldes steht in resources/css/app.css und sonst nirgends — '.
            "genau wie das eines Knopfes.\nEin Feld ist ein Bedienelement, und seine Grenze muss man ".
            'sehen (WCAG 1.4.11, 3:1).',
            implode("\n  ", $found),
        ));
    }

    /**
     * Die Grenze **jedes** Bedienelements muss man sehen — gerechnet.
     *
     * **Was dieser Test vorher war und warum das zu wenig war.** Er hiess
     * `test_the_button_border_stands_out_against_everything_next_to_it` und
     * las genau eine Marke: `--button-line`. Der Anlass war ein Knopf auf
     * `--surface` mit einem Rand aus `--line` — im dunklen Theme #111922 gegen
     * #141d26 und damit **1,04:1**. Auf dem Bildschirm war „Bearbeiten" kein
     * Bedienelement, sondern ein etwas hellerer Fleck, den man für Text hält.
     *
     * Die Regel dahinter steht in WCAG 1.4.11 und redet nicht von Knöpfen,
     * sondern von der Grenze eines Bedienelements. Der Test tat es doch — und
     * hat deshalb neun Monate lang nicht gemeldet, dass **jedes Eingabefeld
     * des Panels** denselben Fehler trug: `border: 1px solid var(--line)` auf
     * elf Seiten, 1,13:1 dunkel und 1,09:1 hell.
     *
     * **Er liest jetzt keine Namen mehr, sondern Regeln.** Gesucht wird jede
     * Regel — in app.css *und* in jeder Komponente —, deren Selektor ein
     * Bedienelement nennt und die ihm einen Rand aus einer Marke gibt. Diese
     * Marke wird gerechnet. Damit fällt ein künftiges `.schalter` genauso
     * darunter, ohne dass jemand diesen Test anfassen muss.
     *
     * **Wogegen gerechnet wird.** Gegen den Seitengrund und gegen jede Fläche,
     * auf der ein Bedienelement liegen kann — und gegen die eigene Fläche,
     * *falls sie eine andere ist*. Bei `.knopf.wichtig` sind Rand und Fläche
     * dieselbe Marke; dort wäre die Rechnung 1:1 und die Frage falsch gestellt:
     * Was diesen Knopf sichtbar macht, ist seine Fläche gegen die Seite.
     */
    public function test_every_control_border_stands_out(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        $sources = ['resources/css/app.css' => $css];

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) === 1) {
                $sources[$this->relative($path)] = $match[1];
            }
        }

        $borders = [];

        foreach ($sources as $name => $style) {
            foreach ($this->controlBorders($style) as $border) {
                $borders[] = $border + ['wo' => $name];
            }
        }

        $this->assertGreaterThan(2, count($borders), 'Es werden kaum Ränder von Bedienelementen gefunden — dann prüft dieser Test nichts.');

        foreach (['dark', 'light'] as $theme) {
            $tokens = $this->tokens($css, $theme);

            // Die Flächen, auf denen ein Bedienelement liegen kann. Gelesen und
            // nicht aufgezählt: Ein Entwurf, der seine Karte `--sheet` nennt,
            // wird damit mitgeprüft, ohne dass hier jemand nachträgt.
            $grounds = array_values(array_filter(
                ['bg', 'surface', 'plane', 'sheet'],
                static fn (string $name): bool => isset($tokens[$name]),
            ));

            $this->assertContains('bg', $grounds, sprintf('Im Theme „%s" fehlt --bg.', $theme));

            foreach ($borders as $border) {
                if (! isset($tokens[$border['line']])) {
                    $this->fail(sprintf(
                        '%s gibt „%s" einen Rand aus --%s, und das Theme „%s" setzt diese Marke nicht.',
                        $border['wo'],
                        $border['selector'],
                        $border['line'],
                        $theme,
                    ));
                }

                $neben = $grounds;

                // Die eigene Fläche zählt nur mit, wenn sie eine andere ist.
                if ($border['bg'] !== null && $border['bg'] !== $border['line'] && isset($tokens[$border['bg']])) {
                    $neben[] = $border['bg'];
                }

                foreach ($neben as $gegen) {
                    $ratio = $this->contrast($tokens[$border['line']], $tokens[$gegen]);

                    $this->assertGreaterThanOrEqual(
                        3.0,
                        $ratio,
                        sprintf(
                            'Theme „%s": Der Rand von „%s" (%s, --%s = %s) erreicht gegen --%s (%s) nur %.2f:1.'."\n".
                            'WCAG 1.4.11 verlangt 3:1 für die Grenze eines Bedienelements — darunter ist es keines mehr.',
                            $theme,
                            $border['selector'],
                            $border['wo'],
                            $border['line'],
                            $tokens[$border['line']],
                            $gegen,
                            $tokens[$gegen],
                            $ratio,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Die Beschriftung auf einem Knopf bleibt lesbar.
     *
     * Wer die Fläche des Knopfes anhebt, um den Rand zu retten, verliert sonst
     * den Text — die beiden Prüfungen ziehen in verschiedene Richtungen und
     * gehören deshalb beide hierher.
     */
    public function test_the_label_on_a_button_stays_readable(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        foreach (['dark', 'light'] as $theme) {
            $tokens = $this->tokens($css, $theme);

            $flaeche = $tokens['control-bg'] ?? $tokens['button-bg'] ?? null;

            $this->assertNotNull($flaeche, sprintf(
                'Im Theme „%s" gibt es weder --control-bg noch --button-bg — die Fläche eines Knopfes hat keine Marke.',
                $theme,
            ));

            $this->assertArrayHasKey('text', $tokens, sprintf('Im Theme „%s" fehlt --text.', $theme));

            $ratio = $this->contrast($tokens['text'], $flaeche);

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf(
                    'Theme „%s": --text auf der Fläche eines Knopfes (%s) erreicht nur %.2f:1 (WCAG 1.4.3 verlangt 4,5:1).',
                    $theme,
                    $flaeche,
                    $ratio,
                ),
            );
        }
    }

    /**
     * Jede Regel eines Stylesheets, die einem Bedienelement einen Rand aus
     * einer Marke gibt.
     *
     * @return list<array{selector: string, line: string, bg: string|null}>
     */
    private function controlBorders(string $style): array
    {
        $style = (string) preg_replace('#/\*.*?\*/#su', '', $style);

        preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);

        $found = [];

        foreach ($rules as $rule) {
            $selector = trim($rule[1]);

            // Was ein Bedienelement ist: der Knopf und die drei Feldarten.
            // `:disabled` und `[readonly]` fallen mit darunter und werden unten
            // über ihren Randwert ausgesortiert.
            if (! preg_match('/\.knopf|(^|[\s,>+~])(button|input|select|textarea)\b/', $selector)) {
                continue;
            }

            if (preg_match('/(^|[;\s])border(?:-color)?\s*:\s*([^;]+)/', $rule[2], $rand) !== 1) {
                continue;
            }

            // Ein Rand, der ausdrücklich keiner ist — ein Feld, das nur zum
            // Lesen dasteht, ist kein Bedienelement.
            if (preg_match('/\bvar\(\s*(--[\w-]+)\s*\)/', $rand[2], $marke) !== 1) {
                continue;
            }

            $flaeche = null;

            if (preg_match('/(^|[;\s])background(?:-color)?\s*:\s*[^;]*var\(\s*(--[\w-]+)\s*\)/', $rule[2], $grund) === 1) {
                $flaeche = ltrim($grund[2], '-');
            }

            $found[] = [
                'selector' => $selector,
                'line' => ltrim($marke[1], '-'),
                'bg' => $flaeche,
            ];
        }

        return $found;
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
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
