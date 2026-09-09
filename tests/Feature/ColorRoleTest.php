<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Farben, die keine Bedienelemente sind, stehen trotzdem auf einem Grund.
 *
 * **Warum es diesen Wächter gibt: eine gerechnete Zahl im Kommentar ist keine
 * Prüfung.** `ButtonStyleTest` rechnet Ränder und Beschriftungen von
 * Bedienelementen nach — eine Kurve ist keines, und ein Verweis auch nicht.
 * `--accent-second` stand seit seiner Einführung mit „6,18:1 gegen den hellen
 * Grund" im Kommentar und ohne einen Test daneben; `--accent` wurde nur dort
 * gemessen, wo er als Knopffläche landet.
 *
 * Beide Lücken sind am 9. September 2026 aufgefallen, als die Farben getauscht
 * wurden (`docs/900 §5` und `§6`). Die zweite Kurve hat auf `--surface` im
 * hellen Theme seitdem **3,06:1** — sechs Hundertstel über der Grenze.
 *
 * > **Ein Wert, der die Grenze um sechs Hundertstel überschreitet, ist
 * > zulässig und nicht robust — und was ihn hält, muss man dazuschreiben.**
 *
 * Wer `--surface` je um einen Schritt abdunkelt, nimmt der Kurve ihre
 * Zulässigkeit. Bis zu diesem Wächter hätte das niemand erfahren.
 */
final class ColorRoleTest extends TestCase
{
    /**
     * Der Akzent trägt in **beiden** Themes Text — einen Verweis, einen
     * aktiven Menüpunkt, eine Beizeile. Er braucht deshalb 4,5:1 und nicht
     * 3:1, und zwar gegen beide Gründe seines Themes.
     *
     * **Der Fall, für den es ihn gibt:** Electric Pink steht im dunklen Theme
     * bei 8,54:1 und im hellen bei **2,21:1**. Wer den Wert in den falschen
     * Block trägt, bekommt einen Akzent, den bis dahin nur ein Knopf gemeldet
     * hätte — als Textfarbe eines Verweises nichts.
     */
    public function test_the_accent_carries_its_own_theme(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $marken = $this->tokens($theme);

            foreach (['bg', 'surface'] as $grund) {
                $verhaeltnis = $this->contrast($marken['accent'], $marken[$grund]);

                $this->assertGreaterThanOrEqual(
                    4.5,
                    $verhaeltnis,
                    sprintf(
                        'Theme „%s": --accent (%s) erreicht gegen --%s (%s) nur %.2f:1.'."\n".
                        'Der Akzent trägt Text — einen Verweis, einen aktiven Menüpunkt —, und WCAG 1.4.3 '.
                        'verlangt dafür 4,5:1. Eine Farbe, die im einen Theme trägt, trägt im anderen nicht.',
                        $theme,
                        $marken['accent'],
                        $grund,
                        $marken[$grund],
                        $verhaeltnis,
                    ),
                );
            }
        }
    }

    /**
     * Die zweite Kurve ist ein grafisches Objekt: 3:1 nach WCAG 1.4.11, gegen
     * **beide** Gründe, in beiden Themes.
     *
     * `--surface` ist dabei die engere der beiden und nicht `--bg` — die
     * Kacheln liegen auf der getönten Fläche. Wer nur gegen `--bg` rechnete,
     * hätte im hellen Theme 3,20:1 gemessen und die 3,06:1 nie gesehen.
     */
    public function test_a_second_curve_stays_visible(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $marken = $this->tokens($theme);

            foreach (['bg', 'surface'] as $grund) {
                $verhaeltnis = $this->contrast($marken['accent-second'], $marken[$grund]);

                $this->assertGreaterThanOrEqual(
                    3.0,
                    $verhaeltnis,
                    sprintf(
                        'Theme „%s": --accent-second (%s) erreicht gegen --%s (%s) nur %.2f:1.'."\n".
                        'WCAG 1.4.11 verlangt 3:1 für ein grafisches Objekt. Diese Kurve hat im hellen Theme '.
                        'sechs Hundertstel Luft — ein Schritt an --surface nimmt sie ihr.',
                        $theme,
                        $marken['accent-second'],
                        $grund,
                        $marken[$grund],
                        $verhaeltnis,
                    ),
                );
            }
        }
    }

    /**
     * Die Marken eines Themes. Gelesen wie in `ButtonStyleTest`: der Block zu
     * `:root[data-theme='…']`, Kommentare heraus, nur volle Hexwerte.
     *
     * @return array<string, string>
     */
    private function tokens(string $theme): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        $start = strpos($css, ":root[data-theme='".$theme."']");

        $this->assertIsInt($start, sprintf('In app.css steht kein Block für das Theme „%s" mehr.', $theme));

        $auf = strpos($css, '{', $start);
        $this->assertIsInt($auf);

        $tiefe = 1;
        $ende = $auf + 1;

        for ($i = $auf + 1; $i < strlen($css); $i++) {
            $tiefe += match ($css[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($tiefe === 0) {
                $ende = $i;

                break;
            }
        }

        $block = (string) preg_replace('#/\*.*?\*/#su', '', substr($css, $auf + 1, $ende - $auf - 1));

        preg_match_all('/--([a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block, $treffer, PREG_SET_ORDER);

        $marken = [];

        foreach ($treffer as $treffer_) {
            $marken[$treffer_[1]] = $treffer_[2];
        }

        foreach (['accent', 'accent-second', 'bg', 'surface'] as $noetig) {
            $this->assertArrayHasKey(
                $noetig,
                $marken,
                sprintf('Theme „%s" setzt --%s nicht — dann prüft dieser Test die Farbe nicht.', $theme, $noetig),
            );
        }

        return $marken;
    }

    private function contrast(string $a, string $b): float
    {
        $hoch = max($this->luminance($a), $this->luminance($b));
        $tief = min($this->luminance($a), $this->luminance($b));

        return ($hoch + 0.05) / ($tief + 0.05);
    }

    private function luminance(string $hex): float
    {
        $rgb = sscanf(ltrim($hex, '#'), '%2x%2x%2x') ?? [0, 0, 0];

        $kanal = static function (int|float|null $wert): float {
            $wert = ((int) $wert) / 255;

            return $wert <= 0.03928 ? $wert / 12.92 : (($wert + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $kanal($rgb[0] ?? 0)
            + 0.7152 * $kanal($rgb[1] ?? 0)
            + 0.0722 * $kanal($rgb[2] ?? 0);
    }
}
