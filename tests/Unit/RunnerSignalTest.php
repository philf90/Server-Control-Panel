<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Runner;
use Tests\Support\WithoutPhpComments;

/**
 * Der Regressionstest zu einem Fehler, der jede Operation des Agenten betraf
 * und trotzdem monatelang hätte unentdeckt bleiben können.
 *
 * Der Daemon behandelt SIGCHLD, um seine Verbindungskinder zu ernten. Beim
 * Fork erbt das Kind diesen Handler — und der erntet mit `pcntl_waitpid(-1)`
 * dann auch die Programme, die der Runner über `proc_open` startet. Danach
 * findet `proc_close` keinen Status mehr vor und gibt -1 zurück: Der
 * Rückgabecode geht verloren, und jeder Programmaufruf sieht aus wie
 * fehlgeschlagen.
 *
 * Gefunden hat das kein Test, sondern die erste Ersteinrichtung gegen ein
 * echtes MariaDB. Vorher hatte keine der gebauten Operationen ihren
 * Rückgabecode ausgewertet — `service.status` ignoriert ihn sogar mit
 * Begründung.
 */
final class RunnerSignalTest extends TestCase
{
    use WithoutPhpComments;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_signal') || ! is_executable('/usr/bin/systemctl')) {
            $this->markTestSkipped('Der Test braucht pcntl und systemctl.');
        }
    }

    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGCHLD, SIG_DFL);
        }
    }

    public function test_the_exit_code_survives_without_a_signal_handler(): void
    {
        $runner = new Runner(new Journal('/dev/null'));

        $this->assertSame(0, $runner->run('systemctl', ['--version'], 15)->code);
    }

    public function test_a_stolen_exit_code_is_reported_as_an_agent_error(): void
    {
        $runner = new Runner(new Journal('/dev/null'));

        // Genau die Einstellung, die der Daemon setzt und die ein Kind erbt.
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, static function (): void {
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            }
        });

        try {
            $ergebnis = $runner->run('systemctl', ['--version'], 15);

            // Erntet der Handler nicht schnell genug, ist der Status heil —
            // und dann muss er auch so aussehen wie ohne Handler.
            //
            // **Zwei Prüfungen, so viele wie im anderen Zweig.** Hier stand
            // `addToAssertionCount(1)`, und die Summe der Suite schwankte mit
            // dem Wettlauf um eins: 1 oder 2, je nachdem, wer erntet. Am
            // 24. September 2026 hat genau diese Schwankung einen Vergleich
            // zweier Läufe in die Irre geführt (#266). Welcher Zweig läuft,
            // entscheidet der Scheduler; was er zählt, darf er nicht.
            $this->assertSame(0, $ergebnis->code, 'Der Status blieb heil, aber der Rückgabecode ist nicht der von systemctl.');
            $this->assertStringStartsWith('systemd ', $ergebnis->stdout, 'Der Status blieb heil, aber die Ausgabe fehlt.');
        } catch (AgentException $error) {
            // Was nicht passieren darf: dass daraus ein „Programm ist
            // fehlgeschlagen" wird. Der Agent nennt die Ursache.
            $this->assertSame(AgentException::EXEC_FAILED, $error->errorCode);
            $this->assertStringContainsString('Signalbehandler', $error->getMessage());
        }
    }

    /**
     * Beide Ausgänge des Wettlaufs prüfen gleich viel.
     *
     * **Gelesen wird der Bau und nicht ein Lauf.** Ein Lauf sieht nur den Zweig,
     * den der Wettlauf gerade wählt — am 24. September 2026 allein gestartet
     * in 39 von 40 Läufen den Fang-Zweig. Eine Prüfung am Ende des Tests hätte
     * einen Eingriff in den seltenen Zweig also fast nie gesehen: ein Wächter,
     * der vom Scheduler abhängt. Die Zahl der Prüfungen je Zweig steht dagegen
     * fest im Quelltext.
     *
     * Kommentare werden vorher entfernt; sie zitieren die alte Zählzeile.
     */
    public function test_both_outcomes_of_the_race_count_the_same(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(__FILE__));

        $treffer = preg_match(
            '/function test_a_stolen_exit_code_is_reported_as_an_agent_error\(\).*?\n    \}\n/s',
            $quelle,
            $rumpf,
        );
        $this->assertSame(1, $treffer, 'Der Wettlauf-Test ist nicht mehr zu finden — dann prüft dieser Test nichts.');

        $teile = explode('} catch (AgentException', $rumpf[0], 2);
        $this->assertCount(2, $teile, 'Der Wettlauf-Test fängt keine AgentException mehr.');

        $pruefungen = static fn (string $teil): int => (int) preg_match_all('/\$this->assert\w+\(/', $teil);

        $this->assertGreaterThan(0, $pruefungen($teile[1]), 'Der Fang-Zweig prüft nichts — dann zählt dieser Test nichts.');
        $this->assertSame(
            $pruefungen($teile[1]),
            $pruefungen($teile[0]),
            'Die beiden Ausgänge des Wettlaufs prüfen verschieden viel — dann schwankt die Summe der Suite mit dem Scheduler.',
        );
        $this->assertStringNotContainsString(
            'addToAssertionCount',
            $rumpf[0],
            'Eine gezählte statt einer geprüften Zusicherung — genau die Zeile, mit der die Schwankung begann.',
        );
    }
}
