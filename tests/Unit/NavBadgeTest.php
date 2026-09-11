<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Eine Zahl im Navigationsstreifen liest die Marken des Streifens.
 *
 * **Der Anlass ist gemessen und kein Geschmack** (`docs/907 §1.2`).
 * `.rail` und `.topbar` setzen einen eigenen Markensatz — `--nav-bg` ist in
 * **beiden** Themen `#1a0b2e`, also dunkel, während die Seite daneben im
 * hellen Thema hell ist. Eine Marke, die ihre Farbe von der Seite nimmt, steht
 * dort auf einem fremden Grund:
 *
 * | Fassung | hell | dunkel |
 * |---|---|---|
 * | `.badge.warn` im Streifen | **2,67:1** | 7,04:1 |
 * | `--accent` auf `--accent-surface` | **8,42:1** | **8,42:1** |
 *
 * `app.css` sagt die Regel selbst, im Kommentar über `.rail`:
 *
 * > **Eine Markenfläche endet dort, wo eine Zustandsfarbe anfängt — sonst ist
 * > sie keine Fläche, sondern ein zweites Theme.**
 *
 * **Was dieser Wächter hält, ist die Form und nicht die Zahl.** Ein Kontrast
 * lässt sich ohne Browser nicht rechnen — die Flächen sind `rgba`, und wer die
 * Deckkraft wegwirft, misst die Farbe vor dem Überblenden (`docs/103`).
 * Gehalten wird deshalb die Eigenschaft, aus der der gemessene Wert folgt:
 * Die Marke im Streifen nennt **keine** Zustandsvariante, und die Regel, die
 * sie gestaltet, liest ausschliesslich Marken, die der Streifen selbst
 * neu setzt.
 *
 * > **Ein Wächter, der eine Zahl nicht rechnen kann, hält die Eigenschaft, aus
 * > der sie folgt — und nicht die Zahl aus dem Protokoll.**
 *
 * Gebrochen wird er mit `tests/waechter-brechen.sh`.
 */
final class NavBadgeTest extends TestCase
{
    use WithoutMarkupComments;

    /** Die fünf Varianten, die ihre Farbe von der Seite nehmen. */
    private const ZUSTANDSFARBEN = ['ok', 'warn', 'critical', 'info', 'neutral'];

    private const LAYOUT = __DIR__.'/../../resources/js/Layouts/PanelLayout.vue';

    private const CSS = __DIR__.'/../../resources/css/app.css';

    public function test_no_badge_in_the_rail_carries_a_state_colour(): void
    {
        $vorlage = $this->withoutMarkupComments((string) file_get_contents(self::LAYOUT));

        preg_match_all('/class="badge([^"]*)"/', $vorlage, $treffer);

        $this->assertNotSame(
            [],
            $treffer[1],
            'In PanelLayout steht keine Marke mehr — dann prüft dieser Wächter nichts.',
        );

        foreach ($treffer[1] as $rest) {
            $klassen = preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $this->assertSame(
                [],
                array_values(array_intersect($klassen, self::ZUSTANDSFARBEN)),
                implode("\n", [
                    'Eine Marke im Navigationsstreifen trägt eine Zustandsfarbe: class="badge'.$rest.'"',
                    'Gemessen ergibt `.badge.warn` dort 2,67:1 im hellen Thema — die Farbe ist',
                    'gegen den Seitengrund gerechnet, und der Streifen ist in beiden Themen',
                    'dunkel. `.badge.count` liest die Marken des Streifens und steht bei 8,42:1.',
                ]),
            );
        }
    }

    public function test_the_count_badge_reads_only_tokens_the_rail_redefines(): void
    {
        $css = (string) file_get_contents(self::CSS);

        // Die Marken, die der Streifen selbst neu setzt. Sie und nur sie
        // dürfen in einer Regel stehen, die auch dort gilt.
        $this->assertSame(
            1,
            preg_match('/^\.rail,\s*\n\.topbar \{(.+?)^\}/ms', $css, $streifen),
            'Der Markensatz von `.rail, .topbar` ist nicht zu finden — der Wächter greift ins Leere.',
        );

        preg_match_all('/^\s*(--[a-z-]+):/m', $streifen[1], $eigene);
        $eigene = $eigene[1];

        $this->assertGreaterThan(
            5,
            count($eigene),
            'Der Streifen setzt kaum Marken — dann ist der Vergleich unten keiner.',
        );

        $this->assertSame(
            1,
            preg_match('/^\.badge\.count \{(.+?)^\}/ms', $css, $marke),
            '`.badge.count` gibt es nicht — die Zahl im Streifen hätte dann keine Regel.',
        );

        preg_match_all('/var\((--[a-z-]+)\)/', $marke[1], $gelesen);

        $this->assertNotSame(
            [],
            $gelesen[1],
            '`.badge.count` liest keine Marke — dann steht dort ein fester Wert, und der folgt keinem Grund.',
        );

        $fremd = array_values(array_diff(array_unique($gelesen[1]), $eigene));

        $this->assertSame(
            [],
            $fremd,
            implode("\n", [
                '`.badge.count` liest Marken, die der Streifen nicht neu setzt: '.implode(', ', $fremd),
                'Dann nimmt die Zahl ihre Farbe von der Seite und steht auf dem Grund des',
                'Streifens — gemessen 2,67:1 im hellen Thema. Marken kaskadieren: Wer nur',
                'liest, was `.rail` selbst setzt, bekommt an beiden Orten die richtige Farbe.',
            ]),
        );
    }
}
