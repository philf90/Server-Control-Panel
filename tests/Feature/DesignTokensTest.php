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

                if (preg_match('/^var\(--(text-[a-z-]+|block-heading-size)\)$/', $value) !== 1) {
                    $found[] = sprintf('%s  font-size: %s', $this->relative($path), $value);
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Schriftgrößen stehen nicht in der Skala:\n  %s\n\n".
            'Die fünf Stufen stehen in resources/css/app.css. Wer eine sechste braucht, '.
            'trägt sie dort ein — und muss dabei begründen, welche Rolle sie hat.',
            implode("\n  ", $found),
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
