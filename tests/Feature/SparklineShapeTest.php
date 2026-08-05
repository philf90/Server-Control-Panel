<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * In einem ungleich gezogenen Feld ist ein Kreis kein Kreis.
 *
 * **Der Befund kam vom Betreiber, und es war schon der zweite dieser Art.** Die
 * Verlaufskachel zeichnet in einem viewBox von 100 × 32 Einheiten, das mit
 * `preserveAspectRatio="none"` auf rund 204 × 46 Bildpunkte gezogen wird —
 * waagerecht gut zweieinhalbmal so stark wie senkrecht. Alles, was in
 * Nutzerkoordinaten rund oder gleichmässig sein soll, wird dabei verzerrt:
 *
 *   1. Das Strichmuster der zweiten Kurve. `stroke-dasharray: 2 1.6` ergab auf
 *      flachen Stücken lange und auf steilen gestauchte Striche — im Bild sah
 *      das nach einer unsauber gezeichneten Linie aus.
 *   2. Der Punkt am Ende jeder Kurve. `<circle r="2">` wurde zu 4,6px
 *      waagerecht und 2,9px senkrecht: eine liegende Ellipse, und weil sie an
 *      der rechten Kante zur Hälfte abgeschnitten war, ein Klotz.
 *
 * Beide Male dieselbe Ursache, beide Male erst auf dem Bildschirm des
 * Betreibers aufgefallen. Die Antwort ist beide Male dieselbe: **im
 * Bildschirmraum zeichnen.** `vector-effect="non-scaling-stroke"` nimmt die
 * Strichstärke — und damit auch die runde Kappe und das Strichmuster — aus dem
 * Bildschirmraum statt aus den Nutzerkoordinaten.
 *
 * Dieser Test hält die Regel fest, damit es kein drittes Mal gibt: Wer ein Feld
 * ungleich zieht, zeichnet darin nichts Rundes in Nutzerkoordinaten.
 */
final class SparklineShapeTest extends TestCase
{
    private function component(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/Tile.vue');
    }

    /** Der `<template>`-Block, ohne Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            $this->fail('In Tile.vue gibt es keinen <template>-Block mehr.');
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    public function test_the_field_is_stretched_unevenly(): void
    {
        /*
         * Die Voraussetzung der ganzen Regel — und sie ist gewollt: Die Kurve
         * soll die Kachel füllen, egal wie breit sie ist. Fiele
         * `preserveAspectRatio="none"` weg, wäre alles darunter gegenstandslos,
         * und dieser Test stünde als Vorschrift für einen Zustand da, den es
         * nicht mehr gibt.
         */
        $this->assertStringContainsString(
            'preserveAspectRatio="none"',
            $this->template($this->component()),
            'Ohne das ungleiche Ziehen gilt dieser Test nicht mehr — dann gehört er weg und nicht angepasst.',
        );
    }

    public function test_nothing_round_is_drawn_in_user_coordinates(): void
    {
        $template = $this->template($this->component());

        foreach (['<circle', '<ellipse', 'r="', 'rx="', 'ry="'] as $form) {
            $this->assertStringNotContainsString(
                $form,
                $template,
                sprintf(
                    "In Tile.vue steht `%s`.\n\n".
                    "Das Feld wird waagerecht gut zweieinhalbmal so stark gezogen wie senkrecht — ein\n".
                    "Kreis mit `r=\"2\"` wird darin 4,6px breit und 2,9px hoch, also eine liegende\n".
                    "Ellipse. Ein runder Punkt entsteht hier aus der **Kappe eines Strichs**: ein kurzer\n".
                    'Pfad mit `stroke-linecap: round` und `vector-effect="non-scaling-stroke"`.',
                    $form,
                ),
            );
        }
    }

    public function test_every_shape_that_must_keep_its_size_says_so(): void
    {
        $template = $this->template($this->component());

        /*
         * Gezählt: Linie, zweite Linie, Endpunkt, zweiter Endpunkt, Zeiger,
         * zweiter Zeiger und das Gitter — sieben Zeichnungen, deren Strich in
         * Bildpunkten gemessen wird. Nur die Fläche unter der Kurve nicht; sie
         * hat keinen Strich.
         */
        $pfade = preg_match_all('/<(path|line)\b/', $template);
        $fest = preg_match_all('/vector-effect="non-scaling-stroke"/', $template);

        $this->assertGreaterThan(5, $pfade, 'Es werden kaum Zeichnungen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame(
            $pfade - 1,
            $fest,
            sprintf(
                "%d Zeichnungen, aber nur %d mit `non-scaling-stroke` (die Fläche braucht keins).\n\n".
                "Ohne die Angabe wird die Strichstärke mitgezogen: waagerecht anders als senkrecht.\n".
                'Aus einer 1,8px-Linie wird dann eine, die je nach Steigung unterschiedlich dick ist.',
                $pfade,
                $fest,
            ),
        );
    }

    /**
     * Und der Punkt am Ende darf über die Kante hinausragen.
     *
     * Die letzte Stützstelle liegt bei x = 100, also genau auf dem rechten Rand.
     * Ein `<svg>` schneidet dort ab — vom Punkt bliebe die linke Hälfte, und die
     * liest sich als Klotz. Beim vorigen Zustand fiel das nicht auf, weil der
     * Punkt ohnehin eine breitgezogene Ellipse war.
     */
    public function test_the_last_dot_is_not_cut_in_half(): void
    {
        if (preg_match('/<style scoped>(.*)<\/style>/su', $this->component(), $match) !== 1) {
            $this->fail('In Tile.vue gibt es keinen <style scoped>-Block mehr.');
        }

        $css = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);

        if (preg_match('/\.trend\s+svg\s*\{([^}]*)\}/s', $css, $regel) !== 1) {
            $this->fail('Es gibt keine Regel für `.trend svg` mehr.');
        }

        $this->assertMatchesRegularExpression(
            '/overflow\s*:\s*visible/',
            $regel[1],
            'Ohne `overflow: visible` schneidet das Feld den Punkt am Ende der Kurve zur Hälfte ab.',
        );
    }
}
