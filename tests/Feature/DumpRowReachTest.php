<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Was der Controller über eine Sicherung schickt, steht auch auf der Seite.
 *
 * **Der Anlass ist ein Zeitstempel, den es gab und den niemand sah.**
 * `DatabaseController::dumpRows()` legte `created_at` seit jeher in die Ablage;
 * die Tabelle „Sicherungen" zeigte Name, Grösse, Zustand und Aktion. Wer zwei
 * Sicherungen desselben Tages unterscheiden wollte, musste den Zeitstempel aus
 * dem Dateinamen lesen — `…-20260811-093136-15abd902` —, und der ist eine
 * Kennung und kein Datum. Gemeldet vom Betreiber am 11. August 2026.
 *
 * > **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**
 *
 * Derselbe Satz wie bei {@see AgentAnswerReachTest}, nur eine Grenze weiter: Der
 * dort betrifft die Antworten des Agenten an das Panel, dieser die Ablage des
 * Panels an den Browser. Beide Male ist der Wert da, beide Male sieht ihn
 * niemand, und beide Male fällt es erst jemandem auf, der die Seite benutzt.
 *
 * ## Warum alle Felder und keine Liste
 *
 * Eine Liste einzelner Felder wäre gepflegt worden, bis das erste vergessen
 * wird — und genau das ist ja passiert. Geprüft wird deshalb die Ablage selbst:
 * Was hineingeht, kommt an. Wer ein Feld schickt, das die Seite nicht braucht,
 * merkt es hier und nicht in einem Jahr.
 */
final class DumpRowReachTest extends TestCase
{
    use WithoutPhpComments;

    public function test_every_field_of_a_dump_row_is_read_by_the_page(): void
    {
        $root = dirname(__DIR__, 2);

        $controller = $this->withoutComments(
            (string) file_get_contents($root.'/app/Http/Controllers/DatabaseController.php'),
        );

        $start = strpos($controller, 'private function dumpRows(');

        $this->assertNotFalse($start, 'dumpRows() gibt es nicht mehr — dann zeigt dieser Test auf nichts.');

        /*
         * **Bis zur nächsten Methode und nicht auf gut Glück.** Ein fester
         * Ausschnitt von 2000 Zeichen reichte in `storeUser()` hinein und
         * meldete deren `label` und `host` als ungelesene Felder einer
         * Sicherung — ein Wächter, der die falsche Fläche liest, findet dort
         * auch etwas.
         */
        $rest = substr($controller, $start + 1);
        $end = preg_match('~\n    (?:private|public|protected) function ~', $rest, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : strlen($rest);

        $body = substr($rest, 0, $end);

        preg_match_all("/'([a-z_]+)' =>/", $body, $matches);

        $fields = array_values(array_unique($matches[1]));

        // Die Untergrenze zählt, wo die Felder stehen dürfen: Ein Ausdruck, der
        // nichts mehr findet, meldete „alles in Ordnung".
        $this->assertGreaterThan(5, count($fields), 'Der Ausdruck findet keine Felder mehr.');

        $page = (string) file_get_contents($root.'/resources/js/Pages/Databases/Show.vue');

        // Kommentare fallen weg — über der Zeile steht ein Absatz, der das Feld
        // beim Namen nennt, und ein Wächter, der ihn mitliest, prüft die
        // Erklärung statt des Codes. Diese Woche viermal passiert.
        $markup = preg_replace('~<!--.*?-->~s', '', $page);

        $unread = [];

        foreach ($fields as $field) {
            if (! str_contains((string) $markup, 'dump.'.$field)) {
                $unread[] = $field;
            }
        }

        $this->assertSame([], $unread, sprintf(
            "Diese Felder einer Sicherung schickt der Controller, und die Seite liest sie nicht: %s\n\n"
            .'Entweder gehören sie auf die Seite — dann fehlt dort eine Spalte — oder sie gehören '
            .'nicht in die Ablage. Beides ist eine Entscheidung; keines von beidem zu treffen '
            .'heisst, den Wert zu berechnen und wegzuwerfen.',
            implode(', ', $unread),
        ));
    }
}
