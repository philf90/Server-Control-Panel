<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Taktprobe misst das, was die Übersicht wirklich tut.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `tests/takt-messen.js` erkennt eine Nachladung an der Kopfzeile
 * `X-Inertia-Partial-Data` — die schickt Inertia nur bei einer **Teil**ladung,
 * also bei `router.reload({ only: [...] })`. Wird der Selbstlauf der Übersicht
 * eines Tages auf eine volle Navigation umgestellt, meldet die Probe **nichts
 * mehr**, und das sieht aus wie „der Takt ist aus".
 *
 * > **Ein Messmittel, das an einer Eigenschaft des Prüflings hängt, misst
 * > nichts mehr, sobald der Prüfling sie ablegt — und meldet dabei dasselbe wie
 * > ein Prüfling, der stillsteht.**
 *
 * Das ist die Falle, gegen die die Gegenprobe im Kopf der Probe steht: Ein Klick
 * auf „Aktualisieren" muss sofort eine Zeile erzeugen. Sie fängt den Fall zur
 * Laufzeit; dieser Wächter fängt ihn beim Bauen.
 *
 * ## Was er nicht prüft
 *
 * Ob die gemessenen Abstände stimmen. Das entsteht erst aus einem laufenden
 * Browser über Minuten und steht als Messung in `docs/76` — hier wäre es eine
 * zweite Fassung dessen, was `TeardownTest` und der Aufsatz schon halten.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class TickProbeTest extends TestCase
{
    /** Die Kopfzeile, an der die Probe eine Nachladung erkennt. */
    private const HEADER = 'x-inertia-partial-data';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function probe(): string
    {
        return (string) file_get_contents($this->root().'/tests/takt-messen.js');
    }

    /**
     * Ein Kommentar ist kein Code.
     *
     * **Und das ist hier keine Kosmetik, sondern der Grund, warum der Wächter
     * überhaupt zubeisst.** Der Kopf der Probe erklärt, worauf sie hört, und
     * schreibt `X-Inertia-Partial-Data` in den Fliesstext. Ein Vergleich über
     * die ganze Datei findet die Kopfzeile dort — und ist grün, auch wenn der
     * Code auf etwas ganz anderes hört. Genau so ist der erste Wurf dieses
     * Wächters durchgefallen: Der Eingriff tauschte die Zeichenkette aus, die
     * Erklärung blieb stehen, und der Wächter merkte nichts.
     *
     * > **Ein Wächter, der Kommentare mitliest, verlangt, dass niemand
     * > aufschreibt, was er prüfen soll.**
     *
     * Derselbe Satz wie im Kopf von `FormLabelTest`, dort an einem `<label>`
     * im Kommentar.
     */
    private function withoutComments(string $quelle): string
    {
        $ohneBlock = (string) preg_replace('#/\*.*?\*/#s', '', $quelle);

        return (string) preg_replace('#//[^\n]*#', '', $ohneBlock);
    }

    /**
     * **Die Gegenprobe zum Abzug der Kommentare.**
     *
     * Sie braucht keine Datei im Baum, nur die Regel: Der Name in der Erklärung
     * darf den im Code nicht ersetzen.
     */
    public function test_a_header_named_in_a_comment_does_not_count(): void
    {
        $quelle = "/* Sie hört auf X-Inertia-Partial-Data. */\nxhr.setRequestHeader('x-etwas-anderes', wert)\n";

        $this->assertStringNotContainsString(
            self::HEADER,
            strtolower($this->withoutComments($quelle)),
            'Der Name aus der Erklärung wird für den im Code gehalten — dann ist dieser Wächter grün, egal worauf die Probe hört.',
        );

        $this->assertStringContainsString(
            'x-etwas-anderes',
            $this->withoutComments($quelle),
            'Der Abzug nimmt auch den Code weg.',
        );
    }

    private function overview(): string
    {
        return (string) file_get_contents($this->root().'/resources/js/Pages/Overview.vue');
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Fehlte die Datei oder wäre sie leer, verglichen die Fälle darunter zwei
     * leere Zeichenketten und wären grün, ohne etwas gelesen zu haben.
     */
    public function test_the_probe_is_there_and_has_content(): void
    {
        $this->assertGreaterThan(1000, strlen($this->probe()), 'tests/takt-messen.js ist weg oder leer — dann prüfen die Fälle darunter nichts.');
        $this->assertGreaterThan(1000, strlen($this->overview()), 'Overview.vue ist weg oder leer — dann prüft der Fall darunter nichts.');
    }

    /** Die Probe hört auf die Kopfzeile einer Teilladung. */
    public function test_the_probe_listens_for_the_partial_header(): void
    {
        $this->assertStringContainsString(self::HEADER, strtolower($this->withoutComments($this->probe())), implode("\n", [
            'tests/takt-messen.js erkennt eine Nachladung nicht mehr an',
            '`'.self::HEADER.'`. Dann meldet die Probe nichts — und das sieht',
            'genauso aus wie ein Takt, der steht.',
        ]));
    }

    /**
     * Und die Übersicht lädt wirklich als Teilladung nach.
     *
     * **Das ist die eigentliche Kopplung.** Nur ein `router.reload` mit `only`
     * erzeugt die Kopfzeile, auf die die Probe hört. Eine volle Navigation
     * täte dasselbe für den Betrachter und wäre für die Probe unsichtbar.
     */
    public function test_the_overview_reloads_partially(): void
    {
        $quelle = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $this->overview());

        $this->assertMatchesRegularExpression(
            '/router\.reload\(\s*\{[^}]*\bonly\s*:/s',
            $quelle,
            implode("\n", [
                'Die Übersicht lädt nicht mehr als Teilladung nach — kein',
                '`router.reload({ only: [...] })`.',
                '',
                'Damit schickt Inertia kein `'.self::HEADER.'` mehr, und',
                'tests/takt-messen.js meldet nichts. Wer das aendert, aendert die',
                'Probe mit; sonst misst beim naechsten Nachlauf niemand mehr den',
                'Takt und haelt die Stille fuer ein Ergebnis.',
            ]),
        );
    }
}
