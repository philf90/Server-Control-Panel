<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ein Wahrheitswert, der durch eine Adresse reist, ist ein Wort.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * `docs/66`, Befund 5. Die Suche des Dateimanagers schickte
 * `content: inContent.value` durch `router.get` — und `router.get` legt seine
 * Werte in die **Adresse**. Dort gibt es keine Wahrheitswerte: Aus `false`
 * wird das Wort `"false"`. Laravels Regel `boolean` nimmt
 * `true, false, 1, 0, "1", "0"` und **kein Wort**, also wies sie ab, was der
 * Browser schickte — in beiden Zuständen des Kästchens.
 *
 * Die Folge war nicht ein halbes Merkmal, sondern gar keines: Die Suche im
 * Dateimanager ist seit P6 Schritt 5 an keinem Tag durchgekommen. Aufgefallen
 * ist es erst, als Wunsch 3 die Leiste dorthin stellte, wo jemand sie drückt.
 *
 * > **Dieselbe Regel über einem Wert, der einmal als JSON und einmal als
 * > Zeichenkette reist, gilt nur einmal.**
 *
 * Der Gegenbeleg stand in derselben Datei: `recursive` trägt dieselbe Regel und
 * funktioniert — es reist im Rumpf eines `DELETE` und bleibt dort ein echter
 * Wahrheitswert.
 *
 * ## Warum dieser Wächter zwei Richtungen prüft
 *
 * `FileSearchTest::test_both_inputs_send_the_same_values` war grün, während der
 * Fehler stand: Er vergleicht die **Schlüssel**, die beide Seiten schicken, und
 * beide schickten denselben kaputten Wert.
 *
 * > **Zwei Eingaben, die dasselbe schicken, schicken auch denselben Fehler.**
 *
 * Deshalb steht hier beides: die Regel am Empfänger (eine GET-Route benutzt
 * `boolean` nicht) und die Form am Absender (die Suche schickt `1` und `0`).
 * Eine allein liesse die andere Seite frei.
 */
final class QueryBooleanTest extends TestCase
{
    /** Die Regel, die in einer Adresse nicht trägt. */
    private const FALLE = "'boolean'";

    /**
     * Die beiden Eingaben der Suche.
     *
     * @var list<string>
     */
    private const SUCHENDE_SEITEN = [
        'resources/js/Pages/Files/Index.vue',
        'resources/js/Pages/Files/Search.vue',
    ];

    /**
     * Keine GET-Route prüft mit `boolean`.
     *
     * **Die Regel ist stumpf, und das ist Absicht.** `boolean` *kann* an einer
     * GET-Route funktionieren — nämlich dann, wenn jeder Absender `1` oder `0`
     * schickt. Nur ist das eine Zusage über alle künftigen Absender, und die
     * hält niemand. `Rule::in(['0', '1'])` oder eine Umwandlung sagt dasselbe
     * und kann nicht auf diese Weise brechen.
     */
    public function test_no_get_route_validates_with_the_boolean_rule(): void
    {
        $gesehen = 0;
        $mitRegeln = 0;
        $schuldig = [];

        foreach ($this->getRoutes() as [$datei, $methode]) {
            $rumpf = $this->methodBody($datei, $methode);

            if ($rumpf === null) {
                continue;
            }

            $gesehen++;

            if (str_contains($rumpf, 'validate') || str_contains($rumpf, 'Validator::')) {
                $mitRegeln++;
            }

            if (str_contains($rumpf, self::FALLE)) {
                $schuldig[] = $datei.'::'.$methode;
            }
        }

        /*
         * **Zwei Untergrenzen, nicht eine.** Die erste sagt, dass überhaupt
         * Methoden gefunden wurden; die zweite, dass unter ihnen welche mit
         * Regeln sind. Ohne die zweite wäre der Wächter auch dann grün, wenn
         * der Ausdruck zwar Methoden findet, aber nie eine, die etwas prüft —
         * und dann hätte er an der Stelle gar nicht gemessen.
         *
         * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes
         * > als Null steht.**
         */
        $this->assertGreaterThanOrEqual(30, $gesehen, sprintf(
            'Nur %d Methoden hinter GET-Routen gefunden — dann prüft dieser Wächter nichts.',
            $gesehen,
        ));

        /*
         * **Eins ist hier der wahre Wert, kein bequemer.** Gemessen am
         * 21. August: Von 43 Methoden hinter GET-Routen prüft genau **eine**
         * Eingaben — `FileController::search`. Die übrigen lesen ihre Filter
         * durch Hilfsmethoden und nicht durch `validate()`. Steht hier je eine
         * Null, ist nicht die Falle verschwunden, sondern die Auslese kaputt.
         */
        $this->assertGreaterThanOrEqual(1, $mitRegeln, sprintf(
            'Keine der %d Methoden hinter GET-Routen prüft Eingaben. Dann kann dieser Wächter die '.
            'Falle nicht sehen, die er sucht — er liest die Rümpfe nicht mehr richtig.',
            $gesehen,
        ));

        $this->assertSame([], $schuldig, sprintf(
            "Diese Methoden hinter einer GET-Route prüfen mit `boolean`:\n\n  %s\n\n".
            'Eine GET-Route bekommt ihre Werte aus der Adresse, und dort ist jeder Wert eine '.
            'Zeichenkette: Aus `false` wird `"false"`, und `boolean` weist das ab (docs/66, '.
            "Befund 5). Statt dessen `Rule::in(['0', '1'])` oder eine Umwandlung.",
            implode("\n  ", $schuldig),
        ));
    }

    /**
     * Die Suche schickt `1` und `0`.
     *
     * Geprüft wird der Wert und nicht sein Name: Ob die Fahne `inContent` oder
     * `imInhalt` heisst, ist gleich — was in der Adresse landet, zählt.
     */
    public function test_the_search_sends_one_and_zero(): void
    {
        foreach (self::SUCHENDE_SEITEN as $seite) {
            $aufruf = $this->searchCall($seite);

            $this->assertNotSame('', $aufruf, sprintf(
                'In %s ist der Aufruf der Suche nicht mehr zu finden — dann prüft dieser Wächter '.
                'an dieser Stelle nichts.',
                $seite,
            ));

            $this->assertMatchesRegularExpression(
                '/content:\s*[^,\n]*\?\s*1\s*:\s*0/',
                $aufruf,
                sprintf(
                    "%s schickt `content` nicht als `1`/`0`.\n\n".
                    'Der Wert reist in der Adresse und wird dort zu einem Wort; `true` und '.
                    '`false` weist der Server ab, und zwar beide (docs/66, Befund 5).',
                    $seite,
                ),
            );
        }
    }

    /**
     * Die GET-Routen als Paare aus Datei und Methodenname.
     *
     * @return list<array{string, string}>
     */
    private function getRoutes(): array
    {
        $routen = $this->read('routes/web.php');
        $pfade = $this->controllerPaths($routen);

        preg_match_all(
            '/Route::get\(\s*[\'"][^\'"]*[\'"]\s*,\s*\[\s*(\w+)::class\s*,\s*[\'"](\w+)[\'"]/',
            $routen,
            $treffer,
            PREG_SET_ORDER,
        );

        $paare = [];

        foreach ($treffer as $t) {
            if (isset($pfade[$t[1]])) {
                $paare[] = [$pfade[$t[1]], $t[2]];
            }
        }

        return $paare;
    }

    /**
     * Kurzname einer Steuerung auf ihre Datei.
     *
     * @return array<string, string>
     */
    private function controllerPaths(string $routen): array
    {
        preg_match_all('/^use (App\\\\Http\\\\Controllers\\\\[\w\\\\]+);/m', $routen, $treffer);

        $pfade = [];

        foreach ($treffer[1] as $klasse) {
            $relativ = str_replace('\\', '/', substr($klasse, strlen('App\\'))).'.php';
            $kurz = substr($klasse, strrpos($klasse, '\\') + 1);
            $pfade[$kurz] = 'app/'.$relativ;
        }

        return $pfade;
    }

    /**
     * Der Rumpf einer Methode, von `{` bis zur passenden `}`.
     *
     * **Klammern zählen und nicht bis zur nächsten `}` lesen.** Ein Rumpf, der
     * an der ersten geschlossenen Klammer endet, hört bei der ersten `if`-
     * Anweisung auf — und was danach steht, sieht dieser Wächter dann nie.
     */
    private function methodBody(string $datei, string $methode): ?string
    {
        $voll = dirname(__DIR__, 2).'/'.$datei;

        if (! is_file($voll)) {
            return null;
        }

        $quelle = (string) file_get_contents($voll);
        $muster = '/function\s+'.preg_quote($methode, '/').'\s*\(/';

        if (preg_match($muster, $quelle, $t, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $auf = strpos($quelle, '{', (int) $t[0][1]);

        if ($auf === false) {
            return null;
        }

        $tiefe = 0;

        for ($i = $auf, $n = strlen($quelle); $i < $n; $i++) {
            if ($quelle[$i] === '{') {
                $tiefe++;
            } elseif ($quelle[$i] === '}') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($quelle, $auf, $i - $auf + 1);
                }
            }
        }

        return null;
    }

    /** Der Aufruf der Suche in einer Seite, von `files/search` bis `})`. */
    private function searchCall(string $pfad): string
    {
        if (preg_match('/files\/search`,\s*\{(.+?)\n\s*\}\)/s', $this->read($pfad), $t) !== 1) {
            return '';
        }

        return $t[1];
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
