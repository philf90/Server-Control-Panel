<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Ein Baustein wird an **einer** Stelle gestaltet.
 *
 * ## Der Fund
 *
 * Der Betreiber hat am 16. August 2026 gefragt, ob die Linien des
 * Verzeichnisbaums richtig gezeichnet werden (`docs/55`, Befund 24). Sie wurden
 * es nicht: Neben der **Wurzel** lief eine senkrechte Linie über die ganze Höhe
 * des Baums — gemessen im Browser von y=16 bis y=210, an einem Ast mit genau
 * einem Kind. Eine Linie neben einer Wurzel verbindet nichts.
 *
 * Die Ursache stand in einem anderen Baustein. `Databases/Console.vue` trägt
 * seit P5c einen Baum als `<ul class="tree" role="tree">` und rückt seine Ebenen
 * mit `.tree ul { border-left: … }` ein. `Components/FileTree.vue` nannte seinen
 * `<nav>` **ebenfalls** `tree` — und damit griff die Regel der Konsole auf den
 * Wurzelast des Dateibaums, der ein `<ul>` ist.
 *
 * > **Zwei Bausteine unter demselben Namen sind ein Baustein, sobald eine Regel
 * > über Elementnamen geht.**
 *
 * ## Warum der Wächter die Blöcke zählt und nicht die Elemente
 *
 * Der erste Wurf prüfte, ob eine Klasse in allen Vorlagen auf demselben Element
 * sitzt. Er lief sofort rot — an `.field`, das in fünfzehn Vorlagen ein
 * `<label>` ist und in zweien ein `<div>`. Das ist richtig so: Dort ist der
 * Aufbau innen derselbe (`> span`, dann das Bedienelement), und die Regeln
 * greifen genau wie gedacht. **Ein Wächter, dessen erster Fund eine Ausnahme
 * braucht, misst nicht das, wonach gefragt wurde.**
 *
 * Gefragt ist: Haben hier zwei verschiedene Dinge denselben Namen bekommen? Und
 * das steht im Stylesheet selbst — als **zwei Blöcke** unter demselben Namen,
 * 300 Zeilen auseinander, jeder mit einem eigenen Kommentar über einen eigenen
 * Gegenstand. `.field` hat einen Block, `.tree` hatte zwei.
 *
 * > **Wer eine Regel schreibt, sieht die andere nicht, wenn sie nicht danebensteht.**
 *
 * ## Was ausdrücklich erlaubt bleibt
 *
 * Eine gemeinsame Aufzählung (`.node, .leaf { … }`) neben einem eigenen Block
 * ist keine zweite Fassung, sondern ein geteilter Teil — sie zählt hier nicht
 * mit. Und die Fassung eines Bausteins in einem `@media`-Block ist derselbe
 * Baustein bei einer anderen Breite; sie zählt ebenfalls nicht.
 */
final class ClassBlockTest extends TestCase
{
    /**
     * Wie oft steht `.name {` als eigener Block auf oberster Ebene?
     *
     * @return array<string, int>
     */
    private function blocks(string $css): array
    {
        // Kommentare zuerst weg. In ihnen stehen Selektoren als Beispiel, und
        // ein Beispiel ist keine Regel — derselbe Unterschied, an dem sich
        // `BrowserDialogTest` einen Bruch eingefangen hat.
        $ohne = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $zähler = [];
        $tiefe = 0;
        $vorige = '';

        foreach (explode("\n", $ohne) as $zeile) {
            $satz = trim($zeile);
            $klammern = substr_count($zeile, '{') - substr_count($zeile, '}');

            if (str_starts_with($satz, '@')) {
                $tiefe += $klammern;
                $vorige = $satz;

                continue;
            }

            /*
             * `$vorige` endet auf ein Komma, wenn dieser Selektor die
             * Fortsetzung einer Aufzählung ist. Ohne diese Prüfung liest sich
             * die zweite Zeile von
             *
             *     .node,
             *     .leaf {
             *
             * wie ein eigener Block, und der Wächter meldet `.leaf` doppelt —
             * genau so ist er beim ersten Lauf rot geworden.
             */
            if ($tiefe === 0 && ! str_ends_with($vorige, ',')
                && preg_match('/^(\.[a-z][a-z0-9-]*)\s*\{\s*$/', $satz, $treffer) === 1) {
                $zähler[$treffer[1]] = ($zähler[$treffer[1]] ?? 0) + 1;
            }

            $tiefe = max(0, $tiefe + $klammern);

            if ($satz !== '') {
                $vorige = $satz;
            }
        }

        return $zähler;
    }

    public function test_no_class_is_styled_in_two_places(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $blöcke = $this->blocks($css);

        /*
         * **Die Untergrenze zählt mit.** Eine Null im Zähler sieht aus wie
         * „nichts zu beanstanden" — in P5c hat genau das drei Wächter
         * gekostet. Gemessen am 16. August 2026: 70 Klassen mit je einem Block.
         */
        $this->assertGreaterThan(
            40,
            count($blöcke),
            'Es werden fast keine Klassenblöcke gefunden — dann prüft dieser Wächter nichts, und '.
            'seine grüne Meldung sagt nur, dass der Aufsatz über `app.css` ins Leere läuft.',
        );

        $doppelt = array_filter($blöcke, static fn (int $anzahl): bool => $anzahl > 1);

        $this->assertSame(
            [],
            $doppelt,
            sprintf(
                "Diese Klassen werden an mehr als einer Stelle gestaltet: %s\n\n".
                'Entweder sind es zwei Bausteine unter einem Namen — dann bekommt einer von beiden '.
                'einen eigenen —, oder es ist einer, dessen Regeln auseinandergerutscht sind; dann '.
                "gehören sie zusammen.\n\n".
                'So ist die Linie neben der Wurzel des Dateibaums entstanden: `.tree ul` gehört der '.
                'Datenbankkonsole, und der Dateibaum hiess ebenfalls `tree`. Wer den einen Block '.
                'liest, sieht den anderen nicht.',
                implode(', ', array_keys($doppelt)),
            ),
        );
    }
}
