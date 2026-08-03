<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunAgentOperation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Stimmt das Paket noch mit der Anwendung überein?
 *
 * **Warum das ein Test sein muss.** Die systemd-Units rufen die Anwendung über
 * Zeichenketten auf: einen Kommandonamen, einen Warteschlangennamen. Ändert
 * sich der Name in der Anwendung, merkt es hier niemand — kein Typ, kein
 * Aufruf, keine Referenz. Es fällt erst auf dem Server auf, und dort als
 * Dienst, der nicht startet, oder als Vorgang, der ewig „wartet".
 *
 * Genau das war zweimal der Fall, beide Male als Rest der Umbenennung auf
 * englische Bezeichner:
 *
 * - `srvpanel-metrics.service` rief `artisan srvpanel:kennzahlen` auf. Das
 *   Kommando heisst `srvpanel:metrics`. Der Dienst wäre auf jedem Server in
 *   eine Neustartschleife gelaufen — und mit ihm wären alle Verlaufskacheln
 *   leer geblieben.
 * - `srvpanel-worker.service` horchte auf `vorgaenge,standard`. Aufträge gehen
 *   in `operations`. Kein einziger Vorgang wäre je ausgeführt worden.
 */
final class PackagingTest extends TestCase
{
    private function unit(string $name): string
    {
        $path = dirname(__DIR__, 2).'/packaging/systemd/'.$name;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> */
    private function artisanCommands(): array
    {
        $commands = [];

        /** @var Command $command */
        foreach ($this->app->make(Kernel::class)->all() as $command) {
            $commands[] = $command->getName() ?? '';
        }

        return array_values(array_filter($commands));
    }

    public function test_every_unit_calls_an_artisan_command_that_exists(): void
    {
        $known = $this->artisanCommands();
        $unknown = [];

        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.service') ?: [] as $path) {
            $content = (string) file_get_contents($path);

            if (preg_match_all('/artisan\s+([a-z][a-z0-9:_\-]*)/', $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $name) {
                if (! in_array($name, $known, true)) {
                    $unknown[] = basename($path).' → '.$name;
                }
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "Diese Units rufen ein Kommando auf, das es nicht gibt:\n  %s",
            implode("\n  ", $unknown),
        ));
    }

    public function test_the_worker_listens_on_the_queue_the_operations_go_to(): void
    {
        $unit = $this->unit('srvpanel-worker.service');

        $found = preg_match('/--queue=([a-zA-Z0-9,_\-]+)/', $unit, $matches);
        $this->assertSame(1, $found, 'Die Unit des Arbeiters nennt keine Warteschlange.');

        $queues = explode(',', $matches[1]);

        $this->assertContains(RunAgentOperation::QUEUE, $queues, sprintf(
            'Der Arbeiter horcht auf %s, Vorgänge gehen aber nach %s. '.
            'Sie blieben dann für immer auf „wartet" stehen.',
            $matches[1],
            RunAgentOperation::QUEUE,
        ));

        // Die Standardwarteschlange muss mit dabei sein: Was Laravel selbst
        // einreiht — Mails etwa — trägt keinen eigenen Namen.
        $this->assertContains((string) config('queue.connections.database.queue'), $queues);
    }

    public function test_a_dispatched_operation_carries_that_queue(): void
    {
        // Die Gegenprobe zur Unit: Der Name steht nicht nur als Konstante da,
        // der Auftrag trägt ihn auch.
        $job = new RunAgentOperation(1);

        $this->assertSame(RunAgentOperation::QUEUE, $job->queue);
    }
}
