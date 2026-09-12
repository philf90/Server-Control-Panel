<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FindingCheck;
use App\Support\Diagnose\PendingFindings;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Das Abzeichen am Menüpunkt „Diagnose" — und die Voraussetzung, unter der es
 * zählen darf.
 *
 * **Warum es diesen Wächter gibt.** `PendingFindings::count()` filtert über
 * `reason` **allein**. Der Zustand eines Befundes hängt aber am Paar
 * `(check, reason)` — es gibt keine Zustandsspalte, `FindingCheck::state()`
 * ist die Abbildung. Ein Filter über den halben Schlüssel ist nur so lange
 * richtig, wie **kein Grundname zugleich auffällig und nicht auffällig ist**.
 *
 * Gemessen am 11. September 2026 (`docs/910 §2` M6): 28 auffällige Namen gegen
 * einen einzigen nicht auffälligen (`unreachable`), Überschneidung leer. Der
 * kurze Weg kostet 0,073 ms statt 1,06 — aber er ist eine Abkürzung, und eine
 * Abkürzung ohne ihre Voraussetzung ist eine Vermutung.
 *
 * > **Eine Abkürzung, deren Voraussetzung ein Wächter hält, ist keine Abkürzung
 * > mehr — sie ist ein Sonderfall mit Beleg.**
 *
 * Trägt eine Prüfung eines Tages einen Grund, der anderswo schon mit einem
 * anderen Zustand vorkommt, wird dieser Fall rot — und dann baut jemand die
 * Paarform, die dann nötig ist.
 *
 * **Was er nicht kann:** Er sagt nicht, ob die gezählten Befunde die
 * *richtigen* sind. Dass `unknown` nicht mitzählt, ist eine Entscheidung des
 * Betreibers (`docs/910 §6`) und keine Eigenschaft des Quelltextes.
 */
final class DiagnoseBadgeTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const LAYOUT = __DIR__.'/../../resources/js/Layouts/PanelLayout.vue';

    private const MIDDLEWARE = __DIR__.'/../../app/Http/Middleware/HandleInertiaRequests.php';

    private const QUELLE = __DIR__.'/../../app/Support/Diagnose/PendingFindings.php';

    /**
     * Die Voraussetzung des kurzen Weges — der tragende Fall.
     *
     * Ohne sie zählt `whereNotIn('reason', …)` Befunde nicht mit, die auffällig
     * sind, oder zählt ruhige mit. Beides wäre still: Die Zahl sähe aus wie
     * eine Zahl.
     */
    public function test_no_reason_name_is_both_loud_and_quiet(): void
    {
        $laut = PendingFindings::loudReasons();
        $ruhig = PendingFindings::quietReasons();

        $this->assertNotEmpty($laut, implode("\n", [
            'Es wurde kein einziger auffälliger Grund gefunden.',
            '',
            'Dann misst dieser Fall nichts — entweder heisst die Abbildung nicht mehr',
            '`reasons()`, oder es gibt keine Prüfung mehr, die etwas melden kann.',
        ]));

        $this->assertNotEmpty($ruhig, implode("\n", [
            'Es wurde kein einziger ruhiger Grund gefunden.',
            '',
            'Gemessen sind es elf, alle `unreachable`. Sind sie fort, ist die Abkürzung',
            'zwar unschädlich — aber dieser Fall belegt sie dann nicht mehr, und der',
            'Filter in `count()` gehört dann weg statt ungeprüft zu bleiben.',
        ]));

        $this->assertSame([], array_values(array_intersect($laut, $ruhig)), sprintf(
            "Diese Grundnamen sind zugleich auffällig und ruhig:\n  %s\n\n".
            "`PendingFindings::count()` filtert über `reason` allein und zählt damit falsch.\n".
            'Entweder bekommt der neue Grund einen eigenen Namen, oder die Zählung geht '.
            'über das Paar `(check, reason)` — gemessen kostet das 1,06 ms statt 0,073.',
            implode(', ', array_intersect($laut, $ruhig)),
        ));
    }

    /**
     * Die Gründe kommen aus dem Enum und nicht aus einer Liste.
     *
     * Eine Liste hier oder dort wäre die zweite Fassung dessen, was
     * `FindingCheck` schon weiss — und die zweite ist die, die veraltet.
     */
    public function test_the_reasons_are_derived_and_not_written_down(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(self::QUELLE));

        $this->assertStringContainsString('FindingCheck::cases()', $quelle, implode("\n", [
            '`PendingFindings` leitet die Gründe nicht mehr aus `FindingCheck` ab.',
            '',
            'Steht dort eine Liste, trägt ein neuer Grund sich nicht mehr selbst ein —',
            'und ein `unknown`, das niemand nachträgt, erscheint als Befund im Abzeichen.',
        ]));

        $this->assertStringNotContainsString("'unreachable'", $quelle, implode("\n", [
            '`PendingFindings` nennt einen Grund beim Namen.',
            '',
            'Gemessen sind heute alle elf ruhigen Gründe `unreachable` — das ist ein',
            'Befund der Messrunde und keine Zusage. Wer ihn hier hinschreibt, macht aus',
            'der Ableitung eine Abschrift.',
        ]));

        // Die Untergrenze: Der Ausdruck greift wirklich in die Abbildung.
        $this->assertGreaterThanOrEqual(10, count(FindingCheck::cases()), implode("\n", [
            'Es gibt weniger als zehn Prüfungen.',
            '',
            'Gemessen sind es fünfzehn. Findet der Ausdruck weniger, misst dieser',
            'Wächter an einer Abbildung, die es so nicht mehr gibt.',
        ]));
    }

    /**
     * Die Zahl reist als Verschluss und kommt am Menüpunkt an.
     *
     * Die Naht, und sie bricht still: Ein fertiger Wert in `share()` liefe auch
     * bei einem partiellen Nachladen, das ihn gar nicht mitschickt, und ein
     * Menüpunkt ohne `badge` zeigt nichts, während der Wert daneben berechnet
     * wird.
     */
    public function test_the_count_travels_as_a_closure_and_reaches_the_item(): void
    {
        $mittelschicht = $this->withoutComments((string) file_get_contents(self::MIDDLEWARE));

        $this->assertMatchesRegularExpression(
            "/'pendingFindings' => fn \(\): \?int =>/",
            $mittelschicht,
            implode("\n", [
                '`pendingFindings` wird nicht als Verschluss geteilt.',
                '',
                'Ein fertiger Wert läuft bei jeder Anfrage — auch bei einem partiellen',
                'Nachladen, das ihn gar nicht mitschickt (`docs/103`).',
            ]),
        );

        $vorlage = $this->withoutMarkupComments((string) file_get_contents(self::LAYOUT));
        $bei = strpos($vorlage, "name: 'Diagnose'");
        $this->assertNotFalse($bei, 'Den Menüpunkt „Diagnose" gibt es nicht mehr.');

        $eintrag = substr($vorlage, $bei, 220);

        foreach (['badge: pendingFindings.value' => 'die Zahl', "badgeNoun: ['Befund'" => 'sein Wort'] as $teil => $was) {
            $this->assertStringContainsString($teil, $eintrag, sprintf(
                "Der Menüpunkt „Diagnose\" trägt %s nicht (`%s`).\n\n".
                'Ohne die Zahl zeigt er kein Abzeichen; ohne das Wort liest ein '.
                'Screenreader „Hinweise" statt „Befunde".',
                $was,
                $teil,
            ));
        }
    }
}
