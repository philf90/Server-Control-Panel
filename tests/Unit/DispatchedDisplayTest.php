<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
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
    use WithoutPhpComments;

    private function source(string $relative): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /**
     * Der Rumpf einer Methode, über Klammern gezählt.
     *
     * **Nicht über einen Ausdruck bis zum nächsten `}`.** Genau daran ist der
     * dritte Bruch von `DispatchedRunTest` am 28. August vorbeigelaufen: Ein
     * `.*?` hinter der öffnenden Klammer läuft über das Ende der Methode hinaus
     * bis zum nächsten Treffer, und der Wächter prüfte fremden Code.
     */
    private function body(string $source, string $signature): string
    {
        $start = strpos($source, $signature);

        $this->assertNotFalse($start, sprintf('%s steht nicht im Quelltext.', $signature));

        $open = strpos($source, '{', $start);
        $this->assertNotFalse($open, sprintf('%s hat keinen Rumpf.', $signature));

        $tiefe = 0;

        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $tiefe++;
            } elseif ($source[$i] === '}') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        $this->fail(sprintf('Der Rumpf von %s endet nicht.', $signature));
    }

    /**
     * `dispatched()` setzt Meldung und Fortschritt — und lässt sie nicht stehen.
     */
    public function test_a_dispatched_run_sets_its_own_message_and_progress(): void
    {
        $rumpf = $this->body(
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
     * Der Strom trägt `result` nicht — und deshalb muss die Meldung es tragen.
     *
     * **Das ist die Begründung der Regel darüber, als Messung.** Fügte jemand
     * `result` dem Strom hinzu, wäre der Umweg über die Meldung entbehrlich;
     * solange nicht, ist er die einzige Auskunft, die ein Zusehender bekommt.
     * Der Wächter meldet dann, dass die Begründung veraltet ist — und nicht,
     * dass der Code falsch wäre.
     */
    public function test_the_stream_still_does_not_carry_the_result(): void
    {
        $strom = $this->source('resources/js/Composables/useOperationStream.ts');

        $this->assertStringContainsString('progress: payload.progress', $strom,
            'Der Strom trägt den Fortschritt nicht mehr — dieser Wächter liest die falsche Stelle.');

        $this->assertStringNotContainsString('result', $strom,
            'Der Strom trägt jetzt das Ergebnis. Dann ist die Begründung in DispatchedDisplayTest veraltet und gehört nachgezogen — der Code ist es nicht.');
    }
}
