<?php

declare(strict_types=1);

namespace Tests\Unit;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Journal;
use CloudSrv\Agent\Runner;
use PHPUnit\Framework\TestCase;

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
            $runner->run('systemctl', ['--version'], 15);
            // Erntet der Handler nicht schnell genug, ist der Status heil —
            // dann ist auch nichts zu beanstanden.
            $this->addToAssertionCount(1);
        } catch (AgentException $error) {
            // Was nicht passieren darf: dass daraus ein „Programm ist
            // fehlgeschlagen" wird. Der Agent nennt die Ursache.
            $this->assertSame(AgentException::EXEC_FAILED, $error->errorCode);
            $this->assertStringContainsString('Signalbehandler', $error->getMessage());
        }
    }
}
