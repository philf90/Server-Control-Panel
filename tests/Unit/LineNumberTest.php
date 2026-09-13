<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Die Nummernspalte des Protokolls — Form und Verhalten.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Eine Zeilennummer in einem Rollbehälter hat zwei Arten, falsch zu sein, und
 * keine davon fällt beim Bauen auf:
 *
 * 1. **Sie rollt weg.** Ohne `position: sticky` ist sie bei einer langen Zeile
 *    ausserhalb des Sichtbaren — also genau dann fort, wenn man sie braucht.
 *    `sticky` klebt am nächsten rollenden Vorfahren; ohne `overflow` an `.log`
 *    gibt es keinen, und die Angabe tut nichts. Die beiden gehören deshalb in
 *    **einen** Fall und nicht in zwei.
 * 2. **Sie geht beim Kopieren mit.** Eine Zeile, die man aus dem Protokoll
 *    herausholt, um sie zu suchen, wäre mit vorangestellter Nummer nicht mehr
 *    die Zeile.
 *
 * ## Was er nicht halten kann
 *
 * Ob die Nummer **stimmt**, sagt er nicht — das entscheidet `offsets` aus dem
 * Agenten, und das hält {@see LogWindowTest} an echten Dateien. Und ob sie
 * nach dem waagerechten Rollen wirklich noch dasteht, sagt kein Test, sondern
 * die Bilderrunde: Es ist Punkt 6 des Abnahmekriteriums in `docs/914 §9`.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
 * > nicht als Zusage.**
 */
final class LineNumberTest extends TestCase
{
    use WithoutMarkupComments;

    private const SEITE = __DIR__.'/../../resources/js/Pages/Logs/Index.vue';

    public function test_the_gutter_stays_put_while_the_line_scrolls(): void
    {
        $stil = $this->stil();

        $this->assertMatchesRegularExpression(
            '/\.log\s*\{[^}]*overflow:\s*auto/s',
            $stil,
            '`.log` muss rollen — sonst hat `sticky` keinen Vorfahren, an dem es kleben kann.',
        );

        $regel = $this->regel($stil, '.log-number');

        $this->assertStringContainsString('position: sticky', $regel);
        $this->assertStringContainsString('left: 0', $regel);
    }

    /**
     * Die Spalte deckt bis an die Kante.
     *
     * **Zwei Angaben, eine Regel.** Eine Fläche allein genügt nicht: Ein
     * klebendes Element klebt am **Inhaltsrand**, und das waagerechte Polster
     * des Rahmens liegt davor. Beim Rollen wandert der Text sichtbar in diesen
     * Streifen — gemessen am 13. September 2026 auf `cloudsrv24`,
     * `elementFromPoint` fand dort `log-text` (`docs/916 §7`).
     *
     * > **Ein Element, das klebt, deckt seinen eigenen Kasten — nicht den
     * > Streifen, den das Polster davor freilässt.**
     *
     * Deshalb steht beides in **einem** Fall: Fällt eine der beiden Angaben
     * weg, ist die Regel gebrochen, und zwei getrennte Fälle liessen die
     * Begründung auseinanderlaufen.
     */
    public function test_the_gutter_hides_what_scrolls_under_it(): void
    {
        $stil = $this->stil();
        $spalte = $this->regel($stil, '.log-number');

        $this->assertStringContainsString(
            'background: var(--gutter-bg)',
            $spalte,
            'Ohne Fläche rollt der Text der Zeile sichtbar unter der Nummer hindurch.',
        );

        $this->assertMatchesRegularExpression(
            '/padding-inline:\s*0\s*;/',
            $this->regel($stil, '.log'),
            'Das waagerechte Polster gehört an die Kinder — vor der klebenden Spalte lässt es sonst einen Streifen frei.',
        );

        $this->assertMatchesRegularExpression(
            '/padding-left:\s*\d/',
            $spalte,
            'Die Spalte trägt das Polster, das der Rahmen abgegeben hat.',
        );
    }

    /**
     * Die Nummer vom Ende trägt einen Pfeil und kein Minus.
     *
     * **Das Zeichen unterscheidet die beiden Bedeutungen**, und das ist seine
     * eigentliche Aufgabe: Bei einer vollständig gelesenen Quelle sind `1, 2,
     * 3` die echten Zeilen der Datei; ohne Zeichen sähe die Lage vom Ende
     * genauso aus, und die Notiz darunter wäre die einzige Unterscheidung.
     *
     * **Was er nicht hält:** dass der Pfeil schöner ist als ein Minus. Das hat
     * der Betreiber am 13. September 2026 entschieden.
     */
    public function test_the_distance_from_the_end_carries_an_arrow(): void
    {
        $markup = $this->markup();

        $this->assertStringContainsString('`↑${', $markup, 'Die Lage vom Ende wird mit ↑ geschrieben.');
        $this->assertStringNotContainsString('`−${', $markup, 'Das Minus ist fort.');
        $this->assertStringContainsString('↑1 ist die neueste Zeile', $markup, 'Der Satz nennt dasselbe Zeichen.');
    }

    /**
     * Die Nummer bleibt beim Umdrehen an ihrer Zeile.
     *
     * Sie ist eine **Lage in der Quelle** und kein Anzeigeindex. Würde beim
     * Umdrehen neu gezählt, hiesse dieselbe Zeile einmal so und einmal anders
     * — genau der Grund, aus dem hier überhaupt vom Ende gezählt wird.
     */
    public function test_the_number_travels_with_its_line(): void
    {
        $markup = $this->markup();

        $this->assertMatchesRegularExpression(
            '/\{ text, nummer: nummer\(i\) \}/',
            $markup,
            'Die Nummer entsteht vor dem Umdrehen und wird an die Zeile gebunden.',
        );

        $this->assertMatchesRegularExpression(
            "/order === 'newest' \? paare\.reverse\(\) : paare/",
            $markup,
            'Umgedreht werden die fertigen Paare und nicht die Zeilen allein.',
        );
    }

    /**
     * Die Nummer ist erzeugter Inhalt und kein Text im Dokument.
     *
     * **`user-select: none` allein genügt nicht, und das ist gemessen.** Am
     * 13. September 2026 an der echten Seite, mit der Maus über drei Zeilen
     * gezogen: Die Auswahl enthielt die Nummern (`docs/914 §13`). Die Regel
     * hält den Cursor ab; eine Auswahl, die über das Element **hinweggeht**,
     * hält sie nicht ab.
     *
     * > **Eine Regel, die das Auswählen verbietet, verbietet nicht das
     * > Ausgewähltwerden.**
     */
    public function test_the_number_does_not_travel_with_the_copied_line(): void
    {
        $stil = $this->stil();

        $this->assertMatchesRegularExpression(
            '/\.log-number::before\s*\{[^}]*content:\s*attr\(data-nummer\)/s',
            $stil,
            'Die Nummer muss erzeugter Inhalt sein — sonst steht sie im Dokument und wird kopiert.',
        );

        $this->assertStringContainsString(
            'user-select: none',
            $this->regel($stil, '.log-number'),
            'Bleibt daneben stehen: Es hält die Einfügemarke aus der Spalte.',
        );
    }

    /**
     * Die Hülle spannt die volle Rollbreite auf.
     *
     * **Ohne sie klebt die Nummer nicht, obwohl `sticky` dasteht.** Ein
     * klebendes Element kann seinen eigenen Kasten nicht verlassen; ohne
     * Hülle ist jede Zeile nur so breit wie der Sichtbereich. Gemessen nach
     * `scrollLeft = 3000`: die Nummer stand bei −1908 px.
     *
     * > **Ein Wächter, der die Angabe prüft, hat über die Wirkung nichts
     * > gesagt.** `position: sticky` und `left: 0` standen die ganze Zeit da —
     * > dieser Fall gibt es, weil die Bilderrunde gemessen hat, was sie
     * > bewirken.
     */
    public function test_the_body_spans_the_whole_scroll_width(): void
    {
        $regel = $this->regel($this->stil(), '.log-body');

        $this->assertStringContainsString('width: max-content', $regel);
        $this->assertStringContainsString('min-width: 100%', $regel);
    }

    /**
     * Die Zeile bricht nicht um.
     *
     * Dieselbe Entscheidung wie seit P6 und aus demselben Grund: Eine
     * umgebrochene Zeile eines Protokolls ist unlesbar, weil man nicht mehr
     * erkennt, wo ein Eintrag anfängt. Beim Umbau vom `<pre>` auf Zeilen ist
     * der Umbruch vom Rahmen an die Zeile gewandert — hier steht, dass er
     * nicht dabei verlorengegangen ist.
     */
    public function test_a_line_still_does_not_wrap(): void
    {
        // **Mit Semikolon gesucht, und das ist tragend.** `pre-wrap` enthält
        // `pre`; ohne die Abgrenzung blieb dieser Fall grün, als der Eingriff
        // den Umbruch zurückholte — gemessen am 13. September 2026.
        //
        // > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
        // > irgendwo steht.**
        $this->assertMatchesRegularExpression(
            '/white-space:\s*pre\s*;/',
            $this->regel($this->stil(), '.log-text'),
        );
    }

    /**
     * Nummer und Text sind zwei Elemente.
     *
     * Stünde die Nummer im selben Textknoten, gäbe es weder `user-select`
     * noch `sticky` für sie — und das Kopieren nähme sie mit.
     */
    public function test_number_and_text_are_separate_elements(): void
    {
        $markup = $this->markup();

        $this->assertMatchesRegularExpression(
            '/<span class="log-number" :data-nummer="zeile\.nummer"/',
            $markup,
            'Die Nummer reist als Attribut und nicht als Textknoten.',
        );

        $this->assertMatchesRegularExpression(
            '/<span class="log-text">\{\{ zeile\.text \}\}<\/span>/',
            $markup,
        );
    }

    /**
     * Die Nummer hat zwei Bedeutungen, und die Seite entscheidet sie an
     * `complete`.
     *
     * Eine fortlaufende `1..n` über das Angezeigte wäre die dritte
     * Möglichkeit und die einzige, die lügt: Mit gesetztem Filter sind die
     * Zeilen nicht zusammenhängend.
     */
    public function test_the_number_asks_whether_the_window_reached_the_start(): void
    {
        $quelle = $this->markup();
        $von = strpos($quelle, 'function nummer(');
        $this->assertIsInt($von, 'nummer() nicht gefunden.');

        $bis = strpos($quelle, "\n}", $von);
        $this->assertIsInt($bis);

        $rumpf = substr($quelle, $von, $bis - $von);

        $this->assertStringContainsString(
            'props.result.complete',
            $rumpf,
            'Ohne diese Frage wäre jede Nummer eine Behauptung über die Datei.',
        );
        $this->assertStringContainsString('props.result.offsets', $rumpf);
    }

    private function regel(string $stil, string $selektor): string
    {
        $muster = '/'.preg_quote($selektor, '/').'\s*\{([^}]*)\}/s';

        $this->assertMatchesRegularExpression($muster, $stil, sprintf('Keine Regel für `%s`.', $selektor));
        preg_match($muster, $stil, $treffer);

        return $treffer[1];
    }

    private function stil(): string
    {
        $quelle = $this->markup();
        $von = strpos($quelle, '<style');
        $this->assertIsInt($von, 'Kein Stilblock.');

        return substr($quelle, $von);
    }

    private function markup(): string
    {
        return $this->withoutMarkupComments((string) file_get_contents(self::SEITE));
    }
}
