<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\AwaitDispatchedRun;
use App\Jobs\RunAgentOperation;
use App\Support\Operations\OperationRecorder;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Lauf, der nur abgesetzt wurde, wird nicht als fertig gemeldet.
 *
 * ## Die Naht, die dieser Wächter hält
 *
 * Sie läuft über **drei** Dateien, und keine von ihnen kann sie allein halten:
 *
 * 1. Der Agent markiert das Ergebnis mit `dispatched` und legt `run` und
 *    `log_offset` dazu.
 * 2. {@see RunAgentOperation} liest die Marke und ruft statt
 *    `succeed()` den {@see OperationRecorder::dispatched()}.
 * 3. {@see AwaitDispatchedRun} liest dieselben drei Felder wieder.
 *
 * **Bricht ein Glied, bricht nichts sichtbar** — der Vorgang stünde einfach
 * wieder auf `fertig`, so wie bis zum 28. August 2026 (`docs/86 §5`). Das ist
 * genau die Sorte Fehler, die dieses Projekt gesammelt hat: eine Zeichenkette,
 * die auf etwas verweist, ohne dass ein Typ sie prüft.
 *
 * > **Ein Feld, das der eine schreibt und der andere liest, ist eine Naht — und
 * > eine Naht ohne Wächter ist eine Verabredung.**
 *
 * Gefahren wird das framework-frei: gelesen wird Quelltext, nicht Verhalten.
 * Die Wirkung an der Warteschlange misst der Feature-Test daneben.
 */
final class DispatchedRunTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die drei Felder, die zwischen Agent und Nachlese verabredet sind.
     *
     * @var list<string>
     */
    private const FIELDS = ['dispatched', 'run', 'log_offset'];

    private function read(string $relative): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /**
     * Wer die Marke setzt, setzt auch die Felder, die die Nachlese braucht.
     *
     * **Und die Untergrenze zählt mit.** Setzte niemand mehr `dispatched`,
     * liefe dieser Test ins Leere und bliebe grün — für einen Zustand, in dem
     * jeder abgesetzte Lauf wieder sofort „fertig" meldet.
     */
    public function test_whoever_marks_a_run_dispatched_names_it_and_its_offset(): void
    {
        $ops = glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php') ?: [];
        $setzer = [];

        foreach ($ops as $pfad) {
            $quelle = $this->withoutComments((string) file_get_contents($pfad));

            if (! str_contains($quelle, "'dispatched' => true")) {
                continue;
            }

            $setzer[] = basename($pfad);

            foreach (self::FIELDS as $feld) {
                $this->assertStringContainsString(
                    "'".$feld."' =>",
                    $quelle,
                    sprintf(
                        '%s meldet einen abgesetzten Lauf und legt `%s` nicht dazu. '
                        .'Die Nachlese liest genau dieses Feld — ohne es fragt sie den falschen Lauf '
                        .'oder gar keinen.',
                        basename($pfad),
                        $feld,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(1, count($setzer),
            'Keine Operation meldet mehr einen abgesetzten Lauf — dann steht jeder Vorgang wieder '
            .'sofort auf „fertig", und dieser Test misst nichts.');
    }

    /**
     * Und die Nachlese liest genau diese Felder.
     *
     * Die Gegenrichtung. Ohne sie bliebe der Test darüber grün, wenn die
     * Nachlese sich für andere Namen entschiede — der Agent schriebe dann
     * treu drei Felder, die niemand mehr liest.
     */
    public function test_the_follow_up_reads_the_fields_the_agent_writes(): void
    {
        $job = $this->read('app/Jobs/AwaitDispatchedRun.php');

        foreach (['run', 'log_offset', 'unit'] as $feld) {
            $this->assertStringContainsString(
                "'".$feld."'",
                $job,
                sprintf('Die Nachlese liest `%s` nicht mehr.', $feld),
            );
        }
    }

    /**
     * Der Aufruf verzweigt auf die Marke, bevor er Erfolg meldet.
     *
     * **Die Reihenfolge ist der ganze Punkt.** Stünde `succeed()` davor, wäre
     * die Marke gesetzt, die Nachlese eingereiht — und der Vorgang trotzdem
     * schon fertig.
     */
    public function test_the_job_branches_before_it_reports_success(): void
    {
        $quelle = $this->read('app/Jobs/RunAgentOperation.php');

        $marke = strpos($quelle, "\$result['dispatched']");
        $erfolg = strpos($quelle, '$recorder->succeed(');

        $this->assertNotFalse($marke, 'Der Aufruf fragt die Marke `dispatched` nicht mehr — '
            .'dann meldet jeder abgesetzte Lauf wieder sofort „fertig".');
        $this->assertNotFalse($erfolg);

        $this->assertLessThan($erfolg, $marke,
            'Die Verzweigung steht hinter `succeed()`. Dann ist der Vorgang schon fertig, '
            .'wenn die Nachlese eingereiht wird.');

        $this->assertStringContainsString(
            'return;',
            $this->body($quelle, $marke),
            'Der Zweig kehrt nicht zurück — dann läuft er weiter in `succeed()`, und die '
            .'Verzweigung ist eine Verzierung.',
        );
    }

    /**
     * Der Rumpf des Blocks, in dem diese Stelle steht — über Klammern gezählt.
     *
     * **Ein Ausdruck reicht dafür nicht, und das ist gemessen.** Der erste Wurf
     * suchte `if (…) { … return; }` mit einem `.*?` in der Mitte. Der Eingriff,
     * der das `return;` herausnahm, blieb **grün**: Der Ausdruck lief über die
     * schliessende Klammer hinaus bis zum nächsten `return;` irgendwo in der
     * Datei.
     *
     * > **Ein Wächter, der Wörter liest, sieht keine Klammern.**
     */
    private function body(string $quelle, int $von): string
    {
        $auf = strpos($quelle, '{', $von);

        if ($auf === false) {
            return '';
        }

        $tiefe = 0;

        for ($i = $auf, $laenge = strlen($quelle); $i < $laenge; $i++) {
            $tiefe += match ($quelle[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($tiefe === 0) {
                return substr($quelle, $auf, $i - $auf + 1);
            }
        }

        return '';
    }

    /**
     * Die Nachlese meldet keinen Erfolg, wenn sie den Ausgang nicht kennt.
     *
     * > **Ein Ausgang, der sich nicht feststellen liess, ist kein Erfolg.**
     *
     * Ohne diese Behauptung wäre die naheliegende „Vereinfachung", nach Ablauf
     * der Frist `succeed()` zu rufen — und der Vorgang stünde wieder auf
     * „fertig", ohne dass jemand nachgesehen hat.
     */
    public function test_an_expired_deadline_is_a_failure_and_not_a_success(): void
    {
        $job = $this->read('app/Jobs/AwaitDispatchedRun.php');

        $von = strpos($job, 'private function again(');
        $this->assertNotFalse($von, 'Der Rückweg heisst anders — dieser Test misst nichts mehr.');

        $rumpf = substr($job, $von);

        $this->assertStringContainsString('$recorder->fail(', $rumpf);
        $this->assertStringNotContainsString('$recorder->succeed(', $rumpf,
            'Nach Ablauf der Frist wird Erfolg gemeldet. Ein Ausgang, der sich nicht feststellen '
            .'liess, ist kein Erfolg.');
    }
}
