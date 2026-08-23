<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kein Selektor in `app.css` enthält eine Stelle, die ihn ganz wegfallen lässt.
 *
 * ## Der Anlass
 *
 * Am 23. August 2026 sollte eine Regel den Seitenkopf der Übersicht bei 390 px
 * in eine Zeile bringen — aber nur dort, wo das Feld seine Beschriftung nicht
 * braucht. Geschrieben stand:
 *
 *     .page-head .button-row:has(.field:not(:has(> span))) { … }
 *
 * Das ist ein `:has()` **in** einem `:has()`, und das verbietet die Spezifikation
 * ausdrücklich. Ein Selektor mit einer ungültigen Stelle wird nicht etwa
 * ungenauer — der Browser wirft ihn **ganz** weg, samt allem, was in seinem Block
 * steht.
 *
 * > **Eine ungültige Stelle in einem Selektor macht ihn nicht ungenauer, sondern
 * > wirkungslos.**
 *
 * **Und das sieht aus wie ein richtiges Ergebnis.** Die Messung daneben meldete
 * unverändert 58 px — genau die Zahl, die auch eine Regel liefert, die aus gutem
 * Grund nicht greift. Drei Werkzeuge waren dabei grün: `npm run build` übersetzt
 * die Datei, ohne den Selektor zu prüfen, `ClassReachTest` findet die Klassen,
 * und `SpecificityTest` vergleicht Regeln, die es gibt. Gefunden hat es das
 * Bild.
 *
 * > **Ein Werkzeug, das eine Datei übersetzt, sagt nicht, dass der Browser
 * > alles darin behält.**
 *
 * ## Was er prüft
 *
 * Genau diesen einen Fall, und nicht „ist CSS gültig". Ein vollständiger Parser
 * wäre eine zweite Fassung dessen, was der Browser tut, und die zweite veraltet.
 * Geprüft wird die Verschachtelung von `:has()`, weil sie hier bezahlt worden
 * ist und weil sie stumm ist.
 */
final class SelectorValidityTest extends TestCase
{
    private function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    }

    /**
     * Die Selektoren der Datei — ohne Kommentare und ohne At-Regeln.
     *
     * **Ohne Kommentare, und das ist keine Kosmetik.** Der Kopf der Regel, die
     * diesen Wächter ausgelöst hat, führt den falschen Selektor als Beispiel
     * an. Wer den Text mitliest, meldet die Erklärung als Verstoss — und die
     * Behebung wäre, die Erklärung zu löschen.
     *
     * > **Ein Wächter, der Kommentare mitliest, verlangt, dass niemand
     * > aufschreibt, was er verhindern soll.**
     *
     * @return list<string>
     */
    private function selectors(string $css): array
    {
        $ohneKommentar = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        /*
         * **Gescannt und nicht gesucht.** Der erste Wurf war
         * `/(^|[};])([^{};]*?)\{/` — und der hat die **erste** Regel jedes
         * `@media`-Blocks verloren: `preg_match_all` setzt hinter dem Ende des
         * vorigen Treffers auf, das Trennzeichen ist damit schon verbraucht,
         * und die Alternative findet weder `^` noch ein `}`. Ein `{` in die
         * Zeichenklasse zu nehmen half nicht — aus demselben Grund. Gemessen an
         * einem Prüfkörper mit drei Regeln: `.erste-im-block` fehlte in beiden
         * Fassungen, und die Zahl am Bestand blieb bei beiden gleich.
         *
         * > **Zwei Fassungen eines Ausdrucks, die dieselbe Zahl liefern, können
         * > beide denselben Fall verlieren.**
         */
        $selektoren = [];
        $kopf = '';
        $laenge = strlen($ohneKommentar);

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $ohneKommentar[$i];

            if ($zeichen === '{') {
                $kopf = trim($kopf);

                // `@media`, `@supports`, `@font-face` — ihre Bedingung ist kein
                // Selektor, und die Regeln darin fängt derselbe Durchgang.
                if ($kopf !== '' && ! str_starts_with($kopf, '@')) {
                    $selektoren[] = $kopf;
                }

                $kopf = '';

                continue;
            }

            if ($zeichen === '}' || $zeichen === ';') {
                $kopf = '';

                continue;
            }

            $kopf .= $zeichen;
        }

        return $selektoren;
    }

    /**
     * Steht in diesem Selektor ein `:has()` innerhalb eines `:has()`?
     *
     * **Gezählt und nicht gesucht.** Ein Ausdruck über „`:has(` … `:has(`"
     * fände auch zwei Selektoren nebeneinander (`a:has(b), c:has(d)`) und jedes
     * Paar, zwischen dem die erste Klammer längst zu ist. Gezählt wird deshalb
     * die Klammertiefe — derselbe Grund wie bei den `<label>` in
     * `FormLabelTest`.
     */
    private function nestsHas(string $selector): bool
    {
        $tiefe = 0;
        $inHas = 0;
        $laenge = strlen($selector);

        for ($i = 0; $i < $laenge; $i++) {
            if ($selector[$i] === '(') {
                $tiefe++;

                // Vier Zeichen zurück: `:has` steht unmittelbar vor der Klammer.
                if ($i >= 4 && strtolower(substr($selector, $i - 4, 4)) === ':has') {
                    if ($inHas > 0) {
                        return true;
                    }

                    $inHas = $tiefe;
                }

                continue;
            }

            if ($selector[$i] === ')') {
                if ($inHas === $tiefe) {
                    $inHas = 0;
                }

                $tiefe--;
            }
        }

        return false;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Ausdruck keine Selektoren mehr — ein Umbau der Datei, ein
     * Fehler im Muster —, vergleicht der Fall darunter eine leere Liste mit
     * einer leeren und ist grün, ohne eine Zeile gelesen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     *
     * Und die zweite Untergrenze zählt die `:has()` selbst: Ohne sie prüft der
     * Fall darunter eine Regel, die in dieser Datei gar nicht vorkommt.
     */
    public function test_there_are_selectors_and_some_of_them_use_has(): void
    {
        $selektoren = $this->selectors($this->stylesheet());

        $mitHas = array_filter($selektoren, static fn (string $s): bool => str_contains($s, ':has('));

        // 250 gegen 286 gemessene: Die Untergrenze zählt, wo Selektoren stehen
        // *dürfen*, damit ein Aufräumen sie nicht auslöst.
        $this->assertGreaterThanOrEqual(250, count($selektoren), 'Es werden kaum Selektoren gelesen — dann prüft dieser Wächter nichts.');
        $this->assertGreaterThanOrEqual(3, count($mitHas), 'Es gibt kaum noch `:has()` — dann prüft die Regel darunter nichts.');
    }

    /**
     * **Und die Gegenprobe zum Ausschnitt.**
     *
     * Sie steht hier und nicht in `waechter-brechen.sh`, weil sie keine Datei
     * im Baum braucht — nur die Regel. Der Prüfkörper hat die Stelle, die den
     * ersten Wurf gekostet hat: eine Regel unmittelbar hinter der öffnenden
     * Klammer eines `@media`-Blocks.
     */
    public function test_the_scan_finds_the_first_rule_inside_a_block(): void
    {
        $probe = implode("\n", [
            '@media (max-width: 720px) {',
            '  .erste-im-block { color: red; }',
            '  .zweite { color: blue; }',
            '}',
            '.danach { color: green; }',
        ]);

        $this->assertSame(
            ['.erste-im-block', '.zweite', '.danach'],
            $this->selectors($probe),
            'Der Ausschnitt verliert die erste Regel eines Blocks — und die Zahl am Bestand merkt es nicht.',
        );
    }

    /**
     * **Und die Gegenprobe zum Ausdruck selbst.**
     *
     * Der Fall darüber zählt am Bestand. Er merkt nicht, dass die Zählung der
     * Klammern verlorengegangen ist — und ein Ausdruck, der nichts mehr
     * unterscheidet, ist auf einer heilen Datei von einem richtigen nicht zu
     * trennen. Der Prüfkörper hat deshalb beide Richtungen: die verschachtelte
     * Form, die dieses Projekt bezahlt hat, und die drei Formen, die gültig
     * sind und wie sie aussehen.
     */
    public function test_the_expression_tells_nested_from_neighbouring(): void
    {
        $this->assertTrue(
            $this->nestsHas('.button-row:has(.field:not(:has(> span)))'),
            'Die verschachtelte Form wird nicht erkannt.',
        );

        $this->assertTrue(
            $this->nestsHas('.a:has(.b:has(.c))'),
            'Die unmittelbare Verschachtelung wird nicht erkannt.',
        );

        foreach ([
            '.a:has(.b), .c:has(.d)' => 'zwei Selektoren nebeneinander',
            '.a:has(.b) .c:has(.d)' => 'zwei Stufen nacheinander',
            '.page-head .button-row:has(.field > select:only-child)' => 'die Form, die es geworden ist',
            '.toggle:has(input:disabled)' => 'ein einfaches :has()',
        ] as $selektor => $was) {
            $this->assertFalse($this->nestsHas($selektor), sprintf('`%s` (%s) wird fälschlich gemeldet.', $selektor, $was));
        }
    }

    /** Und kein Selektor der Datei nistet. */
    public function test_no_selector_nests_has_inside_has(): void
    {
        $gefunden = [];

        foreach ($this->selectors($this->stylesheet()) as $selektor) {
            if ($this->nestsHas($selektor)) {
                $gefunden[] = $selektor;
            }
        }

        $this->assertSame([], $gefunden, implode("\n", [
            'Diese Selektoren enthalten ein :has() in einem :has():',
            ...$gefunden,
            '',
            'Die Spezifikation verbietet das. Der Browser wirft den Selektor',
            'nicht etwa halb weg, sondern GANZ — samt allem, was in seinem Block',
            'steht. npm run build meldet davon nichts.',
            '',
            'Der Weg: die Bedingung ohne zweites :has() ausdruecken, zum Beispiel',
            'ueber :only-child, :first-child oder einen Typselektor.',
        ]));
    }
}
