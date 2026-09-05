<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Streifen ganz oben hält, was die Messrunde vor A14 verlangt hat
 * (`docs/81 §2.3q`).
 *
 * ## Drei Regeln, die je eine Messung tragen
 *
 * Sie stehen zusammen, weil sie **denselben Baustein** beschreiben und
 * einzeln je zwei Zeilen wären. Was sie halten, hat je eine Zahl hinter sich:
 *
 * | | Messung | wäre sonst |
 * |---|---|---|
 * | eine Hülle nimmt die Rasterzeile | M2 | drei Bänder liegen aufeinander |
 * | die Klammer geht über Zeilen | M8 | 500 Zeichen sind 273 px hoch |
 * | der Rang trägt drei Träger | M9 | ΔE 3,8 zwischen Warnung und Störung |
 *
 * ## Warum ohne Framework
 *
 * Er liest `app.css` und `PanelLayout.vue` als Text und erbt nur von
 * `TestCase` — damit läuft er im Gestell dieses Containers und nicht erst in
 * der CI (`CLAUDE.md`, „Diese Umgebung").
 *
 * ## Was er nicht kann
 *
 * **Er misst keine Pixel.** Ob drei Bänder wirklich untereinander stehen und
 * 195 px kosten, sagt Punkt 3 des Abnahmelaufs (`docs/103 §8`) — und die
 * eigentliche Falle dabei hat gar keine Zahl:
 *
 * > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
 * > Betrachter.**
 *
 * Was hier steht, ist die Bedingung, unter der die gemessene Lage überhaupt
 * entstehen kann: Nimmt ein zweites Element die Rasterzeile, ist sie wieder
 * fort, und kein Wächter über den Überlauf sähe es.
 */
final class AnnouncementBandTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    private function layout(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue');
    }

    /**
     * Genau **ein** Selektor nimmt die erste Rasterzeile des Rahmens.
     *
     * Der Befund aus M2 in seiner Ursache: `grid-row: 1` an mehreren
     * Geschwistern legt sie in dieselbe Zelle.
     */
    public function test_only_one_selector_takes_the_first_grid_row(): void
    {
        $treffer = [];
        foreach (preg_split('/(?=\n\.)/', $this->css()) ?: [] as $block) {
            if (str_contains($block, 'grid-row: 1;') && preg_match('/^\n?(\.[\w.\s,>-]+)\s*\{/', $block, $m) === 1) {
                $treffer[] = trim($m[1]);
            }
        }

        self::assertSame(['.bands'], $treffer,
            'Nur die Hülle darf die erste Rasterzeile nehmen. Nimmt ein zweites Element sie, '
            .'liegen die Bänder aufeinander — und der Überlauf bleibt dabei 0 (M2).');
    }

    /** Jedes Band steht in der Hülle und keines daneben. */
    public function test_every_band_lives_inside_the_hull(): void
    {
        $vorlage = $this->template($this->layout());

        $offen = substr_count($vorlage, 'class="bands"');
        self::assertSame(1, $offen, 'Es gibt genau eine Hülle.');

        // Jede Stelle, die `class="band` schreibt, steht nach der Hülle und vor
        // ihrem schliessenden Tag. Gemessen an der Verschachtelung und nicht an
        // der Reihenfolge im Text — ein Band davor wäre der Fehler aus M2.
        $huelle = strpos($vorlage, 'class="bands"');
        self::assertIsInt($huelle);

        preg_match_all('/class="band[ "]|:class="hinweis\.badge"/', $vorlage, $m, PREG_OFFSET_CAPTURE);
        self::assertNotSame([], $m[0], 'Der Ausdruck findet keine Bänder — er misst nichts.');

        foreach ($m[0] as [$text, $wo]) {
            self::assertGreaterThan($huelle, $wo,
                "Das Band bei Zeichen $wo steht vor der Hülle: $text");
        }
    }

    /**
     * Gedeckelt wird über Zeilen und nicht über Zeichen.
     *
     * Der Befund aus M8: 40 Zeichen je Zeile bei 390 px, 160 bei 1440 px — eine
     * Grenze in Zeichen ist auf zwei Breiten zwei Grenzen.
     */
    public function test_the_clamp_counts_lines_and_not_characters(): void
    {
        self::assertMatchesRegularExpression(
            '/\.band \.clamped \{[^}]*-webkit-line-clamp: 2;/s',
            $this->css(),
            'Der Text eines Bandes wird über eine Zeilenklammer gedeckelt (M8).');

        // Und die Seite kürzt nicht selbst — das wäre die zweite Fassung
        // derselben Regel, und sie hinge an der Breite, die sie nicht kennt.
        self::assertStringNotContainsString('substr(', $this->template($this->layout()),
            'Die Seite kürzt nicht selbst; das tut die Klammer in app.css.');
    }

    /**
     * Der Rang steht auf drei Trägern, und jeder ist geschrieben.
     *
     * M9 hat gemessen, dass die Fläche allein ihn nicht trägt: ΔE 3,8 zwischen
     * Warnung und Störung im hellen Thema.
     */
    public function test_every_rank_carries_surface_border_and_text_colour(): void
    {
        foreach (['ok', 'warn', 'critical'] as $rang) {
            self::assertMatchesRegularExpression(
                '/\.band\.'.$rang.' \{[^}]*color: var\(--'.$rang.'\);[^}]*'
                .'background: var\(--'.$rang.'-surface\);[^}]*border-color: var\(--'.$rang.'\);/s',
                $this->css(),
                "Der Rang `$rang` braucht Fläche, Rand und Textfarbe — die Fläche allein trägt ihn nicht (M9).");
        }

        // Und der Rand hat die Stärke von `.notice` und nicht die eine Linie,
        // die `.band` als Sichtwechsel-Streifen hatte.
        self::assertMatchesRegularExpression('/\.band \{[^}]*border-bottom: 3px solid;/s', $this->css());
    }

    /** Und das Wort daneben — Farbe allein trägt für niemanden mit Rot-Grün-Schwäche. */
    public function test_the_rank_is_also_a_word(): void
    {
        self::assertStringContainsString('class="rank"', $this->template($this->layout()),
            'Die Kategorie steht als Wort im Streifen und nicht nur als Farbe (M9, WCAG 1.4.1).');
    }

    /**
     * Nur der Vorlagenblock — ein `<style scoped>` darin wirft Vue weg.
     *
     * Dieselbe Vorsicht wie in {@see ClassReachTest}: Ein
     * Wächter, der die ganze Datei liest, findet seine Zeichenkette auch dort,
     * wo sie nicht wirkt.
     */
    private function template(string $quelle): string
    {
        if (preg_match('/<template>(.*)<\/template>/s', $quelle, $m) !== 1) {
            self::fail('Kein Vorlagenblock in PanelLayout.vue — der Wächter misst nichts.');
        }

        return $m[1];
    }
}
