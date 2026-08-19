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
}
