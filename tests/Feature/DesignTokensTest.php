<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Die Marken des Gestaltungssystems — mechanisch geprüft.
 *
 * **Warum das ein Test ist.** Die CI prüft seit dem ersten Tag, dass außerhalb
 * von `app.css` kein Farbwert steht (§7.2). Für Schriftgrößen gab es diese
 * Prüfung nicht, und das Ergebnis waren zehn `rem`-Werte für fünf Rollen —
 * `.7rem`, `.72rem`, `.75rem`, `.78rem`, `.8rem`, `.82rem`, `.85rem`, `.9rem`,
 * `.95rem`, `1.15rem` — dazu neun Literale in px. Keiner davon war eine
 * Entscheidung; sie sind beim Schreiben entstanden.
 *
 * Dazu kam ein systematischer Fehler: `rem` rechnet gegen das Wurzelelement
 * und damit gegen die Browservorgabe von 16px, während die Grundgröße des
 * Panels 13px am `body` ist. Jeder rem-Wert war 23 % größer als gemeint.
 * `.85rem` für Tabellentext ergab 13,6px — größer als der Fließtext, den er
 * unterschreiten sollte.
 *
 * Der Fehler ist behoben. Dieser Test hält ihn draußen.
 */
final class DesignTokensTest extends TestCase
{
    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js')
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(8, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    /** Der `<style>`-Block einer Vue-Datei. */
    private function style(string $source): string
    {
        if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    public function test_no_component_measures_in_rem(): void
    {
        $found = [];

        foreach ($this->vueFiles() as $path) {
            foreach (explode("\n", $this->style((string) file_get_contents($path))) as $number => $line) {
                if (preg_match('/(?<![\w.])\d*\.?\d+rem\b/', $line) === 1) {
                    $found[] = sprintf('%s:%d  %s', $this->relative($path), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Zeilen messen in rem:\n  %s\n\n".
            '`rem` rechnet gegen das Wurzelelement (16px), nicht gegen die Grundgröße des '.
            "Panels (13px). Jeder Wert ist damit 23 %% grösser als gemeint.\n".
            'Schriftgrößen kommen aus den Marken `--text-…`, alles andere steht in px.',
            implode("\n  ", $found),
        ));
    }

    public function test_every_font_size_comes_from_the_scale(): void
    {
        $found = [];

        foreach ($this->vueFiles() as $path) {
            $style = $this->style((string) file_get_contents($path));

            // Erlaubt sind ausschliesslich die Marken der Skala. Kein Literal,
            // auch kein „nur dieses eine Mal" — genau so sind die zehn
            // rem-Werte entstanden.
            if (preg_match_all('/font-size:\s*([^;]+);/', $style, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $value) {
                $value = trim($value);

                /*
                 * **Hier stand `|block-heading-size`, und das war eine Ausnahme
                 * für eine Regelverletzung.**
                 *
                 * §7.2 sagt zwei Absätze über der Dichtetabelle: „Nicht nach
                 * Dichte gestaffelt. Die Dichtetabelle unten staffelt
                 * Zeilenhöhe, Abstände und Kacheln je Reihe. Schriftgrößen
                 * nicht." `--block-heading-size` ist genau das — 13px auf der
                 * Adminfläche, 15px auf der Kundenfläche —, und statt die Regel
                 * zu klären, hat der Ausdruck hier die Ausnahme eingebaut.
                 *
                 * Damit hielt dieser Test die Regel nicht mehr fest, sondern
                 * ihre Verletzung. Die Bereichsüberschrift bekommt eine eigene
                 * Rolle in der Skala und wird nicht gestaffelt.
                 */
                if (preg_match('/^var\(--text-[a-z-]+\)$/', $value) !== 1) {
                    $found[] = sprintf('%s  font-size: %s', $this->relative($path), $value);
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Schriftgrößen stehen nicht in der Skala:\n  %s\n\n".
            'Die Stufen stehen in resources/css/app.css als `--text-…` und sonst nirgends. '.
            'Wer eine weitere braucht, trägt sie dort ein — und muss dabei begründen, welche Rolle '.
            'sie hat. Eine Marke, die nach Dichte staffelt, ist keine Rolle, sondern zwei.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Jede Stufe der Skala wird auch benutzt.
     *
     * **Warum das die Gegenrichtung derselben Regel ist.** Der Test darüber
     * verhindert Größen ohne Marke. Dieser verhindert Marken ohne Rolle: eine
     * Stufe, die in app.css steht und die keine Komponente liest, ist keine
     * Entscheidung über Typografie, sondern ein Rest. Beim nächsten Umbau
     * hält sich jemand daran fest, weil sie dasteht.
     *
     * Es ist dieselbe Sorte Fund wie `class="value num"` in `Tile.vue` — eine
     * Zeichenkette, deren Bezug niemand prüft —, nur in der anderen Richtung:
     * Dort zeigte die Klasse auf keine Regel, hier zeigte keine Regel auf die
     * Marke.
     */
    public function test_every_step_of_the_scale_is_used(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        // Die Skala und nicht die Farben: `--text-strong` und Verwandte tragen
        // Hexwerte, die Stufen tragen eine Länge in px.
        preg_match_all('/(--text-[a-z-]+)\s*:\s*[\d.]+px/', $css, $matches);

        $scale = array_values(array_unique($matches[1]));

        $this->assertGreaterThan(4, count($scale), 'In app.css stehen kaum Schriftstufen — dann prüft dieser Test nichts.');

        $used = '';

        foreach ($this->vueFiles() as $path) {
            $used .= $this->style((string) file_get_contents($path));
        }

        $unused = [];

        foreach ($scale as $token) {
            if (! str_contains($used, 'var('.$token.')')) {
                $unused[] = $token;
            }
        }

        $this->assertSame([], $unused, sprintf(
            "Diese Stufen der Skala benutzt keine Komponente:\n  %s\n\n".
            'Eine Rolle ohne Nutzer ist keine Rolle. Entweder fehlt die Verwendung — oder die '.
            'Stufe gehört aus app.css entfernt, bevor sich jemand daran festhält.',
            implode("\n  ", $unused),
        ));
    }

    public function test_every_scale_token_a_component_uses_exists(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $missing = [];

        foreach ($this->vueFiles() as $path) {
            preg_match_all('/var\((--(?:text|block)-[a-z-]+)\)/', $this->style((string) file_get_contents($path)), $matches);

            foreach (array_unique($matches[1]) as $token) {
                // Die Marke muss in app.css *gesetzt* werden, nicht bloss
                // vorkommen. Ein Tippfehler im Namen ergäbe sonst eine
                // Eigenschaft ohne Wert — und der Browser fällt still auf die
                // geerbte Größe zurück, was niemandem auffällt.
                if (preg_match('/^\s*'.preg_quote($token, '/').':/m', $css) !== 1) {
                    $missing[] = sprintf('%s  %s', $this->relative($path), $token);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), sprintf(
            "Diese Marken benutzt eine Komponente, app.css setzt sie nicht:\n  %s\n\n".
            'Der Browser fällt dann still auf die geerbte Größe zurück.',
            implode("\n  ", array_unique($missing)),
        ));
    }
}
