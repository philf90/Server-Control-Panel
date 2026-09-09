<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Eine Fläche, die ihren eigenen Grund mitbringt, rechnet ihre Schrift dagegen
 * nach.
 *
 * **Warum es diesen Wächter gibt.** `ButtonStyleTest` rechnet gegen die Marken
 * aus `:root` — er kennt zwei Gründe, `--bg` und `--surface` je Theme. Seit
 * dem 9. September 2026 gibt es zwei Flächen, die eigene Marken setzen: den
 * Navigationsstreifen samt Kopfleiste (`.rail, .topbar`) und die Anmeldeseite
 * (`.signin`). Auf ihnen gilt keine der beiden Rechnungen.
 *
 * Der naive Wurf setzt dort **nur** `--nav-bg` und lässt die Textmarken stehen:
 * `--text` erreicht auf Inkberry **1,76:1**, der Streifen ist da und die
 * Navigation fort. Das ist kein hypothetischer Fall, sondern der erste, den
 * `docs/900 §3` als Falle 2 beschreibt.
 *
 * > **Ein Streifen mit eigenem Grund braucht acht Marken und nicht eine.**
 *
 * **Gefunden werden die Blöcke und nicht aufgezählt.** Wer morgen eine dritte
 * Markenfläche baut, wird mitgeprüft, ohne dass hier jemand nachträgt — das
 * Merkmal ist, dass ein Selektor einen **Grund** setzt (`--bg`, `--surface`
 * oder `--nav-bg`) und dazu Schrift.
 */
final class SurfaceTokenTest extends TestCase
{
    /**
     * Welche Marke auf so einer Fläche der Grund ist, und welche Schrift
     * darauf steht. Der Grund steht zuerst; die erste vorhandene gewinnt.
     */
    private const GRUENDE = ['nav-bg', 'surface', 'bg'];

    /** Was 4,5:1 erreichen muss (WCAG 1.4.3) — Text in jeder Rolle. */
    private const SCHRIFT = ['text', 'text-strong', 'text-muted', 'text-faint', 'accent'];

    public function test_a_brand_surface_carries_its_own_type(): void
    {
        $bloecke = $this->markenflaechen();

        $this->assertGreaterThan(
            1,
            count($bloecke),
            'Es werden kaum Markenflächen gefunden — dann prüft dieser Test nichts. '.
            'Erwartet werden mindestens `.rail, .topbar` und `.signin`.',
        );

        foreach ($bloecke as $selektor => $marken) {
            $grund = null;

            foreach (self::GRUENDE as $name) {
                if (isset($marken[$name])) {
                    $grund = $name;

                    break;
                }
            }

            $this->assertIsString(
                $grund,
                sprintf('„%s" setzt Schriftmarken und keinen Grund — dann steht die Schrift auf dem Grund der Seite.', $selektor),
            );

            foreach (self::SCHRIFT as $rolle) {
                if (! isset($marken[$rolle])) {
                    continue;
                }

                $verhaeltnis = $this->contrast($marken[$rolle], $marken[$grund]);

                $this->assertGreaterThanOrEqual(
                    4.5,
                    $verhaeltnis,
                    sprintf(
                        '„%s": --%s (%s) erreicht auf --%s (%s) nur %.2f:1.'."\n".
                        'WCAG 1.4.3 verlangt 4,5:1 für Text. Wer den Grund einer Fläche ändert und ihre '.
                        'Schriftmarken stehen lässt, bekommt eine Fläche ohne Inhalt.',
                        $selektor,
                        $rolle,
                        $marken[$rolle],
                        $grund,
                        $marken[$grund],
                        $verhaeltnis,
                    ),
                );
            }
        }
    }

    /**
     * Die Grenze eines Bedienelements auf so einer Fläche — 3:1 nach WCAG
     * 1.4.11, dieselbe Zahl wie in `ButtonStyleTest`, nur gegen den eigenen
     * Grund gerechnet statt gegen den der Seite.
     */
    public function test_a_control_on_a_brand_surface_keeps_its_border(): void
    {
        $geprueft = 0;

        foreach ($this->markenflaechen() as $selektor => $marken) {
            if (! isset($marken['control-line'], $marken['control-bg'])) {
                continue;
            }

            $geprueft++;

            $verhaeltnis = $this->contrast($marken['control-line'], $marken['control-bg']);

            $this->assertGreaterThanOrEqual(
                3.0,
                $verhaeltnis,
                sprintf(
                    '„%s": --control-line (%s) erreicht auf --control-bg (%s) nur %.2f:1 (WCAG 1.4.11 verlangt 3:1).',
                    $selektor,
                    $marken['control-line'],
                    $marken['control-bg'],
                    $verhaeltnis,
                ),
            );
        }

        $this->assertGreaterThan(
            0,
            $geprueft,
            'Keine Markenfläche führt ein Bedienelement — dann prüft dieser Test nichts.',
        );
    }

    /**
     * Alle Blöcke in app.css, die Schriftmarken **und** einen Grund setzen und
     * dabei nicht `:root` sind.
     *
     * @return array<string, array<string, string>>
     */
    private function markenflaechen(): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        preg_match_all('/^([.][^{@}]*?)\{([^{}]*)\}/m', $css, $treffer, PREG_SET_ORDER);

        $bloecke = [];

        foreach ($treffer as $treffer_) {
            $selektor = trim((string) preg_replace('/\s+/', ' ', $treffer_[1]));

            preg_match_all('/--([a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $treffer_[2], $marken, PREG_SET_ORDER);

            if ($marken === []) {
                continue;
            }

            $gesetzt = [];

            foreach ($marken as $marke) {
                $gesetzt[$marke[1]] = $marke[2];
            }

            $traegtGrund = array_intersect(self::GRUENDE, array_keys($gesetzt)) !== [];
            $traegtSchrift = array_intersect(self::SCHRIFT, array_keys($gesetzt)) !== [];

            if ($traegtGrund && $traegtSchrift) {
                $bloecke[$selektor] = $gesetzt;
            }
        }

        return $bloecke;
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
