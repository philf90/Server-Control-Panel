<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Vorbehalt, den eine Operation meldet, kommt in der Liste an.
 *
 * ## Form B aus `docs/86 §5`
 *
 * Ein `apt-get update`, bei dem eine von fünf Quellen ausfiel, hat vier Listen
 * frisch geholt und sagt, welche fehlt. Der Vorgang steht auf `fertig` — und
 * das ist richtig.
 *
 * > **Ein Lauf, der getan hat, worum man ihn bat, ist gelungen — auch wenn er
 * > dabei etwas zu melden hat.**
 *
 * Was fehlte, war nicht der Zustand, sondern die Sicht: Die Vorgangsliste zeigt
 * Nummer, Aufgabe, Zustand und drei Zeiten. Die tote Quelle stand allein im
 * Ergebnis, das man aufschlagen muss.
 *
 * **Das ist beim Bauen gemessen worden und war vorher falsch angenommen.** Ich
 * hatte dem Betreiber geschrieben, `message` stehe „schon in der Zeile" — es
 * steht im Payload und wird von keiner Spalte gezeigt.
 *
 * > **Ein Feld im Payload ist noch keine Spalte.**
 *
 * ## Die Naht, die dieser Wächter hält
 *
 * Agent → `OperationController::row()` → `Operations/Index.vue`. Bricht ein
 * Glied, bricht nichts sichtbar: Die Warnung verschwindet einfach wieder, und
 * die Zeile sieht aus wie vorher.
 */
final class OperationWarningTest extends TestCase
{
    use WithoutPhpComments;

    private function read(string $relative): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /**
     * Mindestens eine Operation meldet einen Vorbehalt.
     *
     * **Die Untergrenze ist hier der eigentliche Wächter.** Meldete niemand
     * mehr einen, liefen die beiden Tests darunter ins Leere und blieben grün
     * — für eine Zeile, die nie etwas zeigt.
     */
    public function test_at_least_one_operation_reports_a_caveat(): void
    {
        $ops = glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php') ?: [];
        $melder = [];

        foreach ($ops as $pfad) {
            if (str_contains($this->withoutComments((string) file_get_contents($pfad)), "'warning' =>")) {
                $melder[] = basename($pfad, '.php');
            }
        }

        $this->assertNotSame([], $melder,
            'Keine Operation meldet mehr einen Vorbehalt. Dann zeigt die Vorgangsliste nie einen, '
            .'und ein gelungener Lauf mit einer toten Quelle sieht wieder aus wie einer ohne.');
    }

    /**
     * Der Controller reicht ihn durch — und nicht über `message`.
     *
     * In `message` steht, **was** der Vorgang ist. Wer die Warnung dorthin
     * schriebe, nähme der Zeile ihre Auskunft, um eine zweite hineinzulegen.
     */
    public function test_the_row_carries_the_caveat_in_its_own_field(): void
    {
        $controller = $this->read('app/Http/Controllers/OperationController.php');

        $this->assertStringContainsString("'warning' =>", $controller,
            'Die Zeile trägt den Vorbehalt nicht mehr — dann bleibt er im Ergebnis liegen, '
            .'und man muss den Vorgang aufschlagen, um ihn zu sehen.');

        $this->assertStringContainsString("'message' => \$operation->message", $controller,
            '`message` wird nicht mehr unverändert durchgereicht. Dort steht, was der Vorgang '
            .'ist; der Vorbehalt gehört in sein eigenes Feld.');
    }

    /**
     * Und die Seite zeigt ihn.
     *
     * **Das ist die Hälfte, die still bricht.** Ein Feld, das der Controller
     * schickt und keine Spalte rendert, ist von einem, das es nicht gibt, in
     * der Liste nicht zu unterscheiden — genau der Irrtum, mit dem dieser
     * Schritt begonnen hat.
     */
    public function test_the_list_renders_the_caveat(): void
    {
        $seite = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Operations/Index.vue',
        );

        $von = strpos($seite, '<template>');
        $this->assertNotFalse($von, 'Die Seite hat keinen Vorlagenblock mehr.');

        $this->assertStringContainsString('row.warning', substr($seite, $von),
            'Die Vorlage liest `warning` nicht. Der Controller schickt es dann an eine Spalte, '
            .'die es nicht gibt — und in der Liste sieht ein Lauf mit toter Quelle wieder aus '
            .'wie einer ohne.');
    }
}
