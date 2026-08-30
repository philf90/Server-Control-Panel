<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Was ein abgesetzter Vorgang über seinen Ausgang sagt — und wann.
 *
 * ## Der Zustand, den dieser Wächter ablöst
 *
 * Der Nachlauf zu `0.7.2-rc.5` hat am 28. August 2026 drei Befunde auf **einer**
 * Seite gefunden, und sie sind eine Familie (`docs/88 §15` bis `§17`). Der
 * Zustand eines abgesetzten Laufs stimmte seit demselben Tag; alles daneben
 * sprach noch von vorhin:
 *
 * | | zeigte | weil |
 * |---|---|---|
 * | Meldung | `läuft`, auch nach dem Ende | `succeed()` reichte `null`, und `finish()` liest das als „lass die alte stehen" |
 * | Balken | `100 %`, während der Lauf lief | der Agent ruft `progress(100, 'läuft')` als letzte Handlung |
 * | Urteil | gar nicht, wer zusah | der Strom trägt `result` nicht |
 *
 * > **Eine Behebung, die den Zustand richtig macht, hat über die Anzeige
 * > daneben nichts gesagt.**
 *
 * ## Was hier gehalten wird
 *
 * 1. **`dispatched()` setzt Meldung und Fortschritt selbst.** Nicht der Agent —
 *    der hat mit seinen 100 % aus seiner Sicht recht, er ist fertig. Nur diese
 *    Stelle weiss, dass der Lauf weiterläuft.
 * 2. **Der Balken behauptet kein Ende.** Ein abgesetzter Lauf meldet nichts
 *    zurück; die Zahl ist der Verzicht auf eine Behauptung und muss unter 100
 *    liegen.
 * 3. **Die Nachlese trägt ihr Urteil als Meldung**, in **beiden** Richtungen.
 *    Im Fehlerfall stand es längst dort; die Asymmetrie war der Befund.
 *
 * **Warum die Meldung und nicht das Ergebnis.** Beides steht am Vorgang, aber
 * nur eines reist über den Strom eines offenen Vorgangs. Wer einem Lauf beim
 * Enden zusieht, bekommt `result` erst beim Neuladen.
 *
 * > **Ein Strom, der den Zustand nachführt und das Ergebnis nicht, zeigt ein
 * > Ende ohne seinen Ausgang.**
 */
final class DispatchedDisplayTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    private function source(string $relative): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /**
     * `dispatched()` setzt Meldung und Fortschritt — und lässt sie nicht stehen.
     */
    public function test_a_dispatched_run_sets_its_own_message_and_progress(): void
    {
        $rumpf = $this->methodBody(
            $this->source('app/Support/Operations/OperationRecorder.php'),
            'public function dispatched(',
        );

        $this->assertStringContainsString("'message' =>", $rumpf,
            'Ein abgesetzter Lauf lässt die Meldung des Agenten stehen — die sagt „läuft" und bleibt es auch nach dem Ende.');

        $this->assertStringContainsString("'progress' =>", $rumpf,
            'Ein abgesetzter Lauf lässt den Fortschritt des Agenten stehen — der steht auf 100, während der Lauf noch läuft.');

        /*
         * **Und kein `finished_at`.** Die alte Regel gilt weiter; sie stand
         * bisher nur im Kommentar.
         */
        $this->assertStringNotContainsString('finished_at', $rumpf,
            'Ein abgesetzter Lauf setzt einen Endzeitpunkt — damit behauptet er ein Ende.');
    }

    /**
     * Der Balken eines abgesetzten Laufs behauptet kein Ende.
     *
     * Gelesen wird der Wert und nicht sein Name: Eine Konstante, die auf 100
     * gesetzt wird, erzeugt denselben Befund wie eine wörtliche 100.
     */
    public function test_the_dispatched_progress_claims_no_end(): void
    {
        $quelle = $this->source('app/Support/Operations/OperationRecorder.php');

        $this->assertSame(1, preg_match(
            '/private const DISPATCHED_PROGRESS\s*=\s*(\d+)\s*;/',
            $quelle,
            $treffer,
        ), 'DISPATCHED_PROGRESS steht nicht als Zahl da — dann prüft dieser Wächter nichts.');

        $this->assertLessThan(100, (int) $treffer[1],
            'Der Balken eines abgesetzten Laufs steht auf 100 — genau der Befund, den diese Regel abschafft.');

        $this->assertGreaterThan(0, (int) $treffer[1],
            'Eine Null nähme das Absetzen zurück, für das der Agent gearbeitet hat.');
    }

    /**
     * Die Nachlese trägt ihr Urteil in beide Richtungen als Meldung.
     */
    public function test_the_follow_up_carries_its_verdict_both_ways(): void
    {
        $quelle = $this->source('app/Jobs/AwaitDispatchedRun.php');

        $this->assertSame(1, preg_match('/->fail\(\s*\$urteil\b/', $quelle),
            'Der Fehlschlag reicht sein Urteil nicht als Begründung durch.');

        $this->assertSame(1, preg_match('/->succeed\([^;]*,\s*\$urteil\s*\)/', $quelle),
            'Der Erfolg reicht sein Urteil nicht als Meldung durch — dann sieht es nur, wer die Seite neu lädt.');
    }

    /**
     * `succeed()` kann überhaupt eine Meldung tragen.
     *
     * **Die Gegenrichtung zum Test darüber.** Ohne diesen Parameter wäre der
     * Aufruf dort ein Fehler beim Laden, und zwar erst zur Laufzeit — die
     * Signatur ist die Stelle, an der die Möglichkeit entsteht.
     */
    public function test_success_can_carry_a_message_at_all(): void
    {
        $this->assertSame(1, preg_match(
            '/public function succeed\(\s*array \$result = \[\],\s*\?string \$message = null\s*\)/',
            $this->source('app/Support/Operations/OperationRecorder.php'),
        ), 'succeed() nimmt keine Meldung entgegen — dann kann das Urteil dort nicht ankommen.');
    }

    /**
     * Der Klient wirft das Ergebnis des Schlussereignisses nicht mehr weg.
     *
     * ## Diese Prüfung hat ihre eigene Begründung überführt
     *
     * Sie hiess bis zum 30. August `test_the_stream_still_does_not_carry_the_result`
     * und behauptete, der Strom trage `result` nicht — deshalb müsse die
     * Meldung das Urteil tragen. **Beides war falsch gemessen.**
     * `OperationStreamController` schickt beim Schliessen ein `done` mit
     * `status` **und** `result`; der Klient las daraus
     * `as { status: string }` und liess den Rest fallen.
     *
     * > **Ein Feld, das gesendet und nicht gelesen wird, ist von einem, das
     * > niemand sendet, nicht zu unterscheiden.**
     *
     * Der Wächter hat trotzdem geleistet, wofür er gebaut wurde: Als der
     * Klient `result` behielt, meldete er **„die Begründung ist veraltet"** und
     * nicht „der Code ist falsch". Genau das war der Fall.
     *
     * ## Was jetzt gehalten wird
     *
     * Der Klient **behält** `result` aus dem Schlussereignis. Daran hängt der
     * Vorbehalt auf der Detailseite: Ohne ihn sähe ein Zuschauer ihn erst beim
     * Neuladen, weil die Seite geladen wurde, als es ihn noch nicht gab.
     */
    public function test_the_client_keeps_the_result_of_the_closing_event(): void
    {
        $strom = $this->source('resources/js/Composables/useOperationStream.ts');

        $this->assertStringContainsString('progress: payload.progress', $strom,
            'Der Strom trägt den Fortschritt nicht mehr — dieser Wächter liest die falsche Stelle.');

        $this->assertSame(1, preg_match('/result\.value\s*=\s*payload\.result/', $strom),
            'Der Klient behält das Ergebnis des Schlussereignisses nicht — dann sieht ein Zuschauer den Vorbehalt erst beim Neuladen.');

        /*
         * **Und der Server schickt es auch weiterhin.** Die Zeile darüber
         * prüfte lange den Klienten und nannte den Server als Grund; hier steht
         * der Server selbst. Fiele `result` aus dem `done`, läse der Klient
         * `undefined` — und zwar wortlos.
         */
        $this->assertSame(1, preg_match(
            '/\x27done\x27.*?\x27result\x27 => \$operation->result/s',
            $this->source('app/Http/Controllers/OperationStreamController.php'),
        ), 'Das Schlussereignis trägt das Ergebnis nicht mehr — dann liest der Klient undefined.');
    }

    /**
     * Die Meldung wird nach ihrem Inhalt gefärbt und nicht nach dem Zustand.
     *
     * **Der Anlass ist Befund 8** (`docs/88 §24`): Die Vorgangsliste zeigte
     * „Nicht erreicht: …" bernsteinfarben, die Detailseite dieselbe Auskunft
     * **grün** — weil ein gelungener Lauf `ok` ist und die Meldung dem Zustand
     * folgte.
     *
     * > **Dieselbe Auskunft in zwei Farben sagt zweimal etwas anderes — und die
     * > grüne gewinnt, weil sie oben steht.**
     *
     * Das nahm die Entscheidung des Betreibers vom 28. August zurück: Der
     * Zustand bleibt, der **Vorbehalt wird sichtbar**. Grün ist die Farbe, die
     * sagt, es sei nichts zu sehen.
     */
    public function test_a_reservation_is_not_painted_in_the_colour_of_success(): void
    {
        $seite = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Operations/Show.vue');

        /*
         * **Gefragt wird, woran die Meldung ihre Farbe bindet — nicht, welcher
         * Ausdruck fehlt.**
         *
         * Der erste Wurf suchte den alten Vergleich als Zeichenkette und war
         * rot, weil der Kommentar in `Show.vue`, der diese Regel **erklärt**,
         * sie wörtlich zitiert. Der zweite suchte `:class="rang ===` — und
         * liess damit `:class="rang"` durch, also genau die Form, in die
         * jemand beim Vereinfachen zurückfällt. Der Lauf des Bruchskripts hat
         * das am 30. August gemeldet.
         *
         * > **Ein Wächter, der die Abwesenheit eines Wortlauts prüft, deckt nur
         * > die eine Rückfallform, an die sein Verfasser gedacht hat.**
         *
         * Die Bindung positiv zu nennen deckt beide und jede weitere: Steht
         * dort etwas anderes als `notizart`, folgt die Farbe nicht mehr dem
         * Inhalt.
         */
        $this->assertSame(1, preg_match(
            '/<p v-if="message" class="notice" :class="notizart">/',
            $seite,
        ), 'Die Meldung bindet ihre Farbe nicht mehr an notizart — dann folgt sie wieder etwas anderem als ihrem Inhalt.');

        $this->assertSame(1, preg_match("/warnung\\.value === null \\? 'ok' : 'warn'/", $seite),
            'Die Meldung unterscheidet nicht mehr, ob ein Vorbehalt vorliegt.');
    }

    /**
     * Der Vorbehalt steht einmal im Quelltext und nicht zweimal.
     *
     * **Zweiter Teil von Befund 8.** `SystemPackagesRefresh` schrieb den Satz
     * sechsundzwanzig Zeilen auseinander zweimal — einmal klein als
     * Fortschrittsmeldung, einmal gross als `warning`. Die Oberfläche zeigte
     * beide, und sie unterschieden sich im ersten Buchstaben.
     */
    public function test_the_reservation_is_written_once(): void
    {
        $quelle = $this->source('agent/src/Ops/SystemPackagesRefresh.php');

        $this->assertSame(1, preg_match_all("/'Nicht erreicht: '/", $quelle),
            'Der Vorbehalt steht mehr als einmal im Quelltext — zwei Fassungen desselben Satzes laufen auseinander.');

        $this->assertSame(0, preg_match("/'nicht erreicht: '/", $quelle),
            'Die kleingeschriebene Fassung ist zurück — dann zeigen Liste und Detailseite wieder zwei Texte.');
    }
}
