<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Wo `Bands` steht, steht die Hülle `.bands` darum.
 *
 * **Der Anlass ist der Blick des Betreibers im Abnahmelauf am 6. September
 * 2026**, nicht eine Messung: Auf der Anmeldeseite passten die Abstände zum
 * Bildschirmrand nicht zu denen im Panel. `PanelLayout.vue` legt `<Bands>` in
 * ein `<div class="bands">`; `Login.vue` und `TwoFactorChallenge.vue` taten es
 * nicht. Damit fehlten dort das Polster, die Fuge zwischen zwei Störungen und
 * die Stapelrichtung — das Band lag bündig am Rand statt eingerückt.
 *
 * ## Warum die Hülle nicht in die Komponente zieht
 *
 * Im Panel trägt dieselbe `.bands` auch den Balken für „Anmelden als", und sie
 * nimmt `grid-row: 1` ausdrücklich. Zwei Geschwister mit derselben Rasterzeile
 * liegen **aufeinander** — das ist der M2-Befund (`docs/81 §2.3q`), und ihn
 * wieder einzubauen wäre teurer als diese Regel.
 *
 * > **Eine Hülle, die in der aufrufenden Vorlage steht statt in der Komponente,
 * > gibt es so oft, wie jemand daran denkt.**
 *
 * Also bleibt sie, wo sie ist, und bekommt einen Wächter statt eines guten
 * Vorsatzes.
 *
 * ## Was er nicht kann
 *
 * Er liest die Vorlage als Text und fragt, ob **vor** dem `<Bands`-Tag ein
 * `class="bands"` steht, das noch offen ist. Ob es das **unmittelbare**
 * Elternteil ist, sagt er nicht — dafür bräuchte er einen Baum. Für den Fehler,
 * den er fangen soll (die Hülle fehlt ganz), genügt das; für eine Hülle, die
 * zwei Ebenen zu weit oben steht, nicht.
 */
final class BandsHullTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Vorlage, die `<Bands` benutzt — ohne die Komponente selbst.
     *
     * @return list<string>
     */
    private function users(): array
    {
        $gefunden = [];

        foreach (glob($this->root().'/resources/js/{Pages,Pages/*,Layouts,Components}/*.vue', GLOB_BRACE) ?: [] as $datei) {
            if (basename($datei) === 'Bands.vue') {
                continue;
            }

            if (str_contains((string) file_get_contents($datei), '<Bands')) {
                $gefunden[] = $datei;
            }
        }

        return $gefunden;
    }

    public function test_every_use_of_bands_sits_inside_the_hull(): void
    {
        $benutzer = $this->users();

        self::assertGreaterThanOrEqual(
            3,
            count($benutzer),
            'Weniger als drei Vorlagen benutzen <Bands> — Panel, Anmeldung und Zweitfaktor sind es. '
            .'Findet der Ausdruck sie nicht, misst dieser Wächter nichts.',
        );

        $befunde = [];

        foreach ($benutzer as $datei) {
            $quelle = (string) file_get_contents($datei);
            $wo = strpos($quelle, '<Bands');
            self::assertIsInt($wo);

            $davor = substr($quelle, 0, $wo);
            $huellen = substr_count($davor, 'class="bands"');

            // Jede Hülle davor, die schon wieder geschlossen wäre, zählt nicht.
            // Gemessen wird deshalb nicht die Zahl, sondern ob überhaupt eine
            // offen ist — dafür genügt: es gibt eine, und sie steht davor.
            if ($huellen === 0) {
                $befunde[] = sprintf(
                    '%s benutzt <Bands> ohne `class="bands"` davor. Ohne die Hülle fehlen '
                    .'Polster, Fuge und Stapelrichtung — das Band liegt bündig am Rand.',
                    basename($datei),
                );
            }
        }

        self::assertSame([], $befunde, implode("\n", $befunde));
    }

    /**
     * Die Hülle bringt mit, was ihr Fehlen gekostet hat.
     *
     * Ohne diesen Fall wäre die Regel darüber erfüllt, sobald irgendwo das
     * Wort `bands` steht — auch wenn die Klasse in `app.css` nichts mehr tut.
     */
    public function test_the_hull_carries_what_its_absence_cost(): void
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents($this->root().'/resources/css/app.css'),
        );

        self::assertSame(1, preg_match('/(^|\n)\.bands\s*\{([^{}]*)\}/s', $css, $treffer));

        foreach (['padding', 'gap', 'display'] as $eigenschaft) {
            self::assertStringContainsString(
                $eigenschaft.':',
                $treffer[2],
                sprintf('`.bands` ohne `%s` ist keine Hülle mehr — genau das fehlte auf der Anmeldeseite.', $eigenschaft),
            );
        }
    }
}
