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
 * - `install.sh` holt `php-source.sh` von der Seite, der Freigabelauf hat es
 *   dorthin nie kopiert. Ohne PHP-Quelle kein PHP 8.4, und die Installation
 *   endete mit einer apt-Meldung über `php8.4-cli`, die die Ursache nicht
 *   nennt. Gefunden hat das kein Test, sondern der erste Mensch, der es
 *   benutzen wollte.
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

    public function test_the_release_publishes_every_file_the_installer_fetches(): void
    {
        $root = dirname(__DIR__, 2);
        $installer = (string) file_get_contents($root.'/packaging/install.sh');
        $release = (string) file_get_contents($root.'/.github/workflows/release.yml');

        // Alles, was install.sh unterhalb der Seitenwurzel holt. `${REPO_URL%/apt}`
        // ist genau diese Wurzel — die Schreibweise steht so im Skript.
        preg_match_all('#\$\{REPO_URL%/apt\}/([A-Za-z0-9._\-]+)#', $installer, $matches);

        $this->assertNotSame([], $matches[1], 'install.sh holt nichts von der Seite — dann stimmt dieser Test nicht mehr.');

        $missing = [];

        foreach (array_unique($matches[1]) as $file) {
            // Der Freigabelauf kopiert die Datei aus packaging/ in den
            // Pages-Branch. Beides muss stimmen: Sie muss im Repository
            // liegen, und sie muss veröffentlicht werden.
            if (! is_file($root.'/packaging/'.$file)) {
                $missing[] = $file.' (fehlt in packaging/)';

                continue;
            }

            if (! str_contains($release, 'packaging/'.$file.' '.$file)) {
                $missing[] = $file.' (wird vom Freigabelauf nicht veröffentlicht)';
            }
        }

        $this->assertSame([], $missing, sprintf(
            "install.sh holt diese Dateien von der Seite, aber sie kommen dort nicht an:\n  %s\n\n".
            'Ein `curl -f` ins Leere gibt nichts aus, und ein leeres `sh` endet mit 0 — '.
            'der Fehlschlag ist also unsichtbar, bis Schritte später etwas anderes scheitert.',
            implode("\n  ", $missing),
        ));
    }

    public function test_the_php_source_package_ships_the_script_it_runs(): void
    {
        $root = dirname(__DIR__, 2);
        $nfpm = (string) file_get_contents($root.'/packaging/nfpm-php-source.yaml');
        $postinstall = (string) file_get_contents($root.'/packaging/scripts/php-source-postinstall.sh');

        // Der Pfad steht in zwei Dateien: einmal als Ziel im Paket, einmal als
        // Aufruf im postinst. Genau die Verbindung, die sonst niemand prüft —
        // und ein postinst, das ins Leere greift, scheitert erst auf dem
        // Server des Kunden.
        $found = preg_match('#dst:\s*(/usr/share/[A-Za-z0-9./_\-]+)#', $nfpm, $matches);

        $this->assertSame(1, $found, 'nfpm-php-source.yaml legt kein Skript unter /usr/share ab.');
        $this->assertStringContainsString(
            $matches[1],
            $postinstall,
            sprintf('Das postinst ruft nicht %s auf, wohin das Paket das Skript legt.', $matches[1]),
        );

        // Und es muss das gemeinsame Skript sein, keine Kopie: Drei Wege
        // (install.sh, CI, Paket) auf drei Fassungen laufen irgendwann
        // auseinander.
        $this->assertStringContainsString('./packaging/php-source.sh', $nfpm);
    }

    public function test_neither_package_depends_on_the_other(): void
    {
        $root = dirname(__DIR__, 2);
        $panel = (string) file_get_contents($root.'/packaging/nfpm.yaml');
        $helper = (string) file_get_contents($root.'/packaging/nfpm-php-source.yaml');

        // Ein `Depends: srvpanel-php-source` am Panel sähe hilfreich aus und
        // wäre wirkungslos: apt löst die Abhängigkeiten auf, bevor das erste
        // Paketskript läuft — die Quelle käme also immer zu spät. Eine
        // Beziehung, die nur Absicht ausdrückt und nichts bewirkt, ist beim
        // Lesen der Paketbeziehungen schlimmer als keine.
        $this->assertStringNotContainsString('srvpanel-php-source', $panel);
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*srvpanel\s*$/m', $helper);
    }

    public function test_the_build_produces_both_packages(): void
    {
        $build = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/build.sh');

        foreach (['packaging/nfpm.yaml', 'packaging/nfpm-php-source.yaml'] as $config) {
            $this->assertStringContainsString($config, $build, sprintf(
                '%s wird von build.sh nicht gebaut — dann liegt das Paket in keinem Freigabelauf.',
                $config,
            ));
        }
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
