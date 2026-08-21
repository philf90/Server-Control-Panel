<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Prüfkörper der Überlaufmessung hängt am Fenster und nicht an einer Zahl.
 *
 * **Das ist Befund 22 aus `docs/59`, und er hatte bis zum 19. August 2026
 * keinen Wächter** — die Messvorschrift stand in einem Dokument, und kein Test
 * liest ein Dokument. Genau das ist der Fehler, der in diesem Projekt am
 * häufigsten wiederkehrt: eine Regel, die auf etwas verweist, ohne dass ein
 * Typ, ein Test oder ein Werkzeug den Bezug prüft.
 *
 * **Was schiefging.** Der Prüfkörper war ein fester Block von 900 px. Bei
 * 390 px erzeugte er die erwarteten 510 px Überlauf; bei 1440 px passte er
 * hinein, und die Gegenprobe meldete `0` — also denselben Wert, den auch eine
 * kaputte Messung liefert. Die Bilderrunde von `docs/59` war damit nur an der
 * schmalen Breite als arbeitsfähig belegt.
 *
 * > **Eine Gegenprobe, deren Ausschlag von der Breite abhängt, ist bei der
 * > grösseren Breite keine.**
 *
 * **Und die berichtigte Vorschrift aus `docs/58 §12` reichte auch noch nicht.**
 * Sie band den Prüfkörper an `clientWidth + 200`. Am 19. August 2026 im echten
 * Chromium gemessen: Auf einer Seite, die *schon* schiebt, ist er damit nicht
 * mehr das Breiteste, und der Ausschlag fällt wieder auf `0` — ausgerechnet auf
 * der kaputten Seite, wo die Messung ihre Arbeitsfähigkeit am nötigsten belegen
 * müsste. Gebunden wird deshalb an `scrollWidth`.
 *
 * > **Ein Prüfkörper, der nur auf der heilen Seite ausschlägt, belegt die
 * > Messung dort, wo sie niemand braucht.**
 *
 * **Und die Erwartung gehört daneben.** Ein Prüfkörper von `clientWidth + 200`
 * muss genau 200 ergeben. Steht die Zahl nicht dabei, ist jedes Ergebnis
 * plausibel — und eine Gegenprobe, deren Ergebnis niemand vorher kennt,
 * schliesst nichts aus.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 */
final class OverflowProbeTest extends TestCase
{
    /** Die Messvorschrift, die dieser Wächter liest. */
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/tests/bilder-messen.js');
    }

    /**
     * Der Prüfkörper wird aus der Seite gerechnet und nicht hingeschrieben.
     */
    public function test_the_probe_is_bound_to_the_page(): void
    {
        $this->assertMatchesRegularExpression(
            '/scrollWidth \+ \d+/',
            $this->source(),
            implode("\n", [
                'Der Pruefkoerper haengt nicht mehr an der Breite der Seite.',
                'Ein fester Block schlaegt unterhalb seiner Groesse aus und darueber',
                'nicht — und liefert dort dieselbe Null wie eine kaputte Messung.',
            ]),
        );
    }

    /**
     * Und er ist keine feste Breite, die zufällig einmal passt.
     *
     * **Der Bruch, den dieser Fall fangen soll, ist der Rückweg:** Jemand
     * schreibt `width:900px` hin, weil es bei 390 px funktioniert. Deshalb
     * steht hier nicht „irgendwo kommt `clientWidth` vor", sondern: In der
     * Zeile, die die Breite setzt, steht keine feste Zahl.
     */
    public function test_the_probe_has_no_fixed_width(): void
    {
        preg_match('/\.style\.cssText = `([^`]*)`/', $this->source(), $treffer);

        $this->assertCount(2, $treffer, 'Die Zeile, die den Pruefkoerper breit macht, gibt es nicht mehr.');

        $this->assertStringContainsString(
            'scrollWidth',
            $treffer[1],
            'Die Breite des Pruefkoerpers ist eine feste Zahl — dann gilt die Gegenprobe nur unterhalb dieser Zahl.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/width:\s*\d+px/',
            $treffer[1],
            'Die Breite des Pruefkoerpers steht als feste Zahl da (Befund 22 aus docs/59).',
        );
    }

    /**
     * Die Gegenprobe ist Teil des Ergebnisses und kein eigener Aufruf.
     *
     * **Sonst wird sie vergessen.** Eine Messung, die ohne sie ein Ergebnis
     * liefert, wird irgendwann ohne sie gefahren — und `dokument: 0` ohne
     * Gegenprobe ist keine Aussage, sondern zwei mögliche.
     */
    public function test_the_counter_check_is_part_of_the_result(): void
    {
        $quelltext = $this->source();

        $ergebnis = (string) strstr($quelltext, '  return {');

        $this->assertNotSame('', $ergebnis, 'Die Messung gibt nichts mehr zurueck.');

        $this->assertStringContainsString(
            'gegenprobe: gegenprobe()',
            $ergebnis,
            'Die Gegenprobe steht nicht im Ergebnis — dann laeuft die Messung irgendwann ohne sie.',
        );

        $this->assertStringContainsString(
            'erwartet: 200',
            $quelltext,
            'Der erwartete Ausschlag steht nicht neben dem gemessenen — dann ist jedes Ergebnis plausibel.',
        );
    }

    /**
     * Gemessen wird jedes Element und keine Liste von Selektoren.
     *
     * Eine Liste nennt, woran man beim Schreiben gerade dachte. Der Fund von
     * P5c Schritt 5 steckte in einer Textzelle, der von Schritt 4 in einem
     * Bereichstitel — beides Stellen, die in keiner Liste gestanden hätten.
     */
    public function test_every_element_is_measured(): void
    {
        $this->assertStringContainsString(
            "querySelectorAll('*')",
            $this->source(),
            'Die Messung sieht nur an genannten Stellen nach — dann misst sie das Erinnerungsvermoegen.',
        );
    }

    /**
     * Ein Fund nennt seinen Ort und nicht bloss seine Bauart.
     *
     * **Was schiefging.** Bis zum 19. August 2026 stand als Kennzeichen nur
     * `Marke.Klassen` da. Für einen Baustein mit Klasse genügt das — für ein
     * `div` ohne jede Klasse heisst die Zeile dann `div`, und genau diese Zeile
     * stand in der Bilderrunde viermal in vier Ansichten, ohne irgendwohin zu
     * zeigen. Vier Messungen, und keine sagte, welches Element gemeint war.
     *
     * > **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**
     *
     * Geprüft wird deshalb beides: der Weg von `body` herab und die ersten
     * Zeichen des Markups. Der Weg allein reicht nicht — `div > div > div`
     * zeigt zwar auf ein Element, sagt aber nicht, was drinsteht.
     */
    public function test_a_finding_names_where_it_is(): void
    {
        $ergebnis = (string) strstr($this->source(), 'roller.push({');

        $this->assertNotSame('', $ergebnis, 'Die Messung sammelt keine Funde mehr ein.');

        $this->assertStringContainsString(
            'pfad: pfad(element)',
            $ergebnis,
            'Ein Fund nennt seinen Weg nicht — ein Element ohne Klasse heisst dann nur „div".',
        );

        $this->assertStringContainsString(
            'anfang: element.outerHTML',
            $ergebnis,
            'Ein Fund zeigt sein Markup nicht — dann sagt der Weg zwar wo, aber nicht was.',
        );
    }

    /**
     * Jede Messung nennt den Stand des Messmittels, das sie erzeugt hat.
     *
     * **Was schiefging.** Dieses Skript lebt in der Konsole und verschwindet bei
     * jedem Neuladen — es kommt also aus der Zwischenablage zurück. Am
     * 19. August 2026 kam es mit den Feldern von vorgestern wieder, während die
     * Frage, die es beantworten sollte, gerade an den neuen hing. Der Ausdruck
     * sah dabei aus wie ein Ergebnis: eine Zahl, ein Fund, keine Fehlermeldung.
     *
     * > **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt,
     * > ist so alt wie die Zwischenablage und sagt es nicht.**
     *
     * Geprüft wird deshalb dasselbe wie bei `breite` und `thema`: dass die
     * Herkunft im Ergebnis steht. Ob der Stand gepflegt ist, kann kein Test
     * sagen — dass er dasteht, schon.
     */
    public function test_a_reading_names_the_instrument(): void
    {
        $quelltext = $this->source();

        $this->assertMatchesRegularExpression(
            "/const STAND = '\\d{4}-\\d{2}-\\d{2}'/",
            $quelltext,
            'Das Messmittel fuehrt keinen Stand — dann traegt keine Zeile ihre Herkunft.',
        );

        $ergebnis = (string) strstr($quelltext, '  return {');

        $this->assertStringContainsString(
            'stand: STAND',
            $ergebnis,
            'Der Stand steht nicht im Ergebnis — dann sieht eine alte Messung aus wie eine neue.',
        );
    }

    /**
     * Eine Beschriftung nur für die Vorlesesoftware wird gezählt, nicht gelistet.
     *
     * **Befund 2 aus `docs/66`.** Die übliche Technik dafür ist
     * `width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)`; ein
     * solcher Kasten hat **immer** `scrollWidth > clientWidth`, und `hidden`
     * steht nicht in der Liste der erlaubten Roller. Gemessen im echten
     * Chromium gegen das gebaute Stylesheet, vorher und nachher:
     *
     *     vorher:   schiebt: [span.sr 42, span.sr 42, thead 379, tr 351, div 287]
     *     nachher:  schiebt: [div 287]   versteckt: 4
     *
     * > **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein
     * > Urteil.**
     *
     * ## Drei Eigenschaften, ohne die der Filter falsch wäre
     *
     * **Beide Merkmale zusammen.** Ein Filter über `overflow: hidden` allein
     * nähme die halbe Messung mit — jeder Rollbehälter fiele darunter.
     *
     * **Die Vorfahren gehören dazu.** Bei `.stacks thead` trägt der Kopf die
     * Klippung; das `tr` darin ist 1 px breit, weil sein Behälter es ist, und
     * klippt selbst nicht. Ohne den Weg nach oben bliebe die halbe Geisterzeile
     * stehen.
     *
     * **Und die Zahl steht daneben.** Eine Messung, die etwas weglässt, sagt
     * wie viel — sonst liest sich eine kurze Liste wie eine heile Seite.
     *
     * > **Kein stiller Deckel: Wer die Sicht begrenzt, nennt die Zahl dazu.**
     */
    public function test_a_screen_reader_label_is_counted_and_not_listed(): void
    {
        $quelltext = $this->source();

        /*
         * **Gelesen wird der Filter selbst und nicht die ganze Datei.** Stünde
         * `clipPath` irgendwo sonst — in einer Erklärung zum Beispiel —, wäre
         * dieser Wächter grün für einen Filter, der es gar nicht prüft. Das ist
         * derselbe Fehler wie bei `docs/62` Punkt 12b, wo ein Wächter einen
         * Satz suchte statt seiner Erreichbarkeit.
         */
        $filter = $this->between($quelltext, 'const nurFuerVorlesen', 'const roller');

        $this->assertNotSame('', $filter, 'Der Filter fuer versteckte Beschriftungen ist fort — dann prueft dieser Waechter nichts.');

        foreach ([
            "clipPath !== 'none'" => 'Der Filter fragt nicht mehr nach der Klippung.',
            'clientWidth <= 1' => 'Der Filter fragt nicht mehr nach der Breite.',
            'clientHeight <= 1' => 'Der Filter fragt nicht mehr nach der Hoehe.',
        ] as $merkmal => $satz) {
            $this->assertStringContainsString($merkmal, $filter, $satz.
                ' Verlangt sind beide Merkmale zusammen — geklippt **und** auf einen Punkt '.
                'zusammengezogen. Ueber `overflow: hidden` allein naehme er die halbe Messung mit.');
        }

        $this->assertStringContainsString(
            'parentElement',
            $filter,
            'Der Filter sieht nicht mehr bei den Vorfahren nach. Bei `.stacks thead` traegt nur der '.
            'Kopf die Klippung, und das `tr` darin bliebe als Geisterzeile stehen.',
        );

        $this->assertStringContainsString(
            'versteckt,',
            (string) strstr($quelltext, '  return {'),
            'Die Zahl der uebersprungenen Kaesten steht nicht mehr im Ergebnis. Dann liest sich '.
            'eine kurze Liste wie eine heile Seite.',
        );
    }

    /** Das Stück zwischen zwei Marken — leer, wenn eine davon fehlt. */
    private function between(string $quelle, string $von, string $bis): string
    {
        $anfang = strpos($quelle, $von);
        $ende = $anfang === false ? false : strpos($quelle, $bis, $anfang);

        if ($anfang === false || $ende === false) {
            return '';
        }

        return substr($quelle, $anfang, $ende - $anfang);
    }
}
