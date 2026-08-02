<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Die einzige Stelle, an der der Agent ein Programm startet.
 *
 * Vier Zusagen, die hier eingelöst werden und nirgends sonst:
 *
 * 1. **Positivliste mit absoluten Pfaden.** Aufrufer nennen einen logischen
 *    Namen („systemctl"), nie einen Pfad. Der Pfad wird nicht über $PATH
 *    gesucht — ein manipuliertes PATH-Element wäre bei einem Prozess als root
 *    der direkte Weg zu fremdem Code.
 * 2. **Keine Shell.** Argumente gehen als Array an proc_open. Es gibt keinen
 *    Punkt in diesem Programm, an dem aus Benutzereingaben eine
 *    Kommandozeile zusammengesetzt wird.
 * 3. **Feste Umgebung.** LC_ALL=C hält die Ausgabe in dem Format, das die
 *    Parser kennen. Auf einem deutsch eingestellten Server scheiterte das
 *    Auswerten sonst — und zwar still.
 * 4. **Gedeckelte Ausgabe und Zeitlimit.** Ein Programm mit endloser Ausgabe
 *    kann den Speicher des Agenten nicht füllen, ein hängendes nicht seine
 *    Prozesstabelle.
 */
final class Runner
{
    public const OUTPUT_MAX = 4 * 1024 * 1024;

    /** @var array<string,string> logischer Name => absoluter Pfad */
    private const PROGRAMS = [
        'systemctl' => '/usr/bin/systemctl',
        'journalctl' => '/usr/bin/journalctl',
        'nginx' => '/usr/sbin/nginx',
        'sshd' => '/usr/sbin/sshd',
        'php-fpm' => '/usr/sbin/php-fpm',
        'named-checkzone' => '/usr/bin/named-checkzone',
        'useradd' => '/usr/sbin/useradd',
        'userdel' => '/usr/sbin/userdel',
        'usermod' => '/usr/sbin/usermod',
        'groupadd' => '/usr/sbin/groupadd',
        'setquota' => '/usr/sbin/setquota',
        'repquota' => '/usr/sbin/repquota',
        'apt-get' => '/usr/bin/apt-get',
        'mysql' => '/usr/bin/mysql',
        'mysqldump' => '/usr/bin/mysqldump',
        'certbot' => '/usr/bin/certbot',
    ];

    private const ENVIRONMENT = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
        'LC_ALL' => 'C',
        'LANG' => 'C',
        'HOME' => '/root',
        'TERM' => 'dumb',
    ];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Führt ein Programm aus der Positivliste aus.
     *
     * @param  list<string>  $args
     * @param  null|callable(string,string):void  $onOutput  Erhält Ausgabezeilen, sobald sie anfallen
     */
    public function run(
        string $program,
        array $args,
        int $timeout = 60,
        ?callable $onOutput = null,
        ?string $input = null,
    ): Result {
        $path = self::PROGRAMS[$program] ?? null;

        if ($path === null) {
            throw AgentException::denied(sprintf('Programm %s steht nicht auf der Positivliste.', $program));
        }

        if (! is_executable($path)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('%s ist auf diesem System nicht installiert.', $program),
                ['path' => $path],
            );
        }

        foreach ($args as $i => $arg) {
            if (! is_string($arg) || str_contains($arg, "\0")) {
                throw AgentException::badRequest(sprintf('Argument %d ist keine gültige Zeichenkette.', $i));
            }
        }

        $command = array_merge([$path], array_values($args));
        $descriptors = [
            0 => $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $startedAt = microtime(true);
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, '/', self::ENVIRONMENT);

        if (! is_resource($process)) {
            throw AgentException::execFailed(sprintf('%s ließ sich nicht starten.', $program));
        }

        if ($input !== null) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = ['stdout' => '', 'stderr' => ''];
        $truncated = false;
        $deadline = $startedAt + $timeout;
        $timedOut = false;

        while (true) {
            $read = array_filter([1 => $pipes[1], 2 => $pipes[2]], static fn ($r) => is_resource($r) && ! feof($r));

            if ($read === []) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);

            if ($ready === false) {
                break;
            }

            foreach ($read as $number => $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $channel = $number === 1 ? 'stdout' : 'stderr';

                if (strlen($output[$channel]) < self::OUTPUT_MAX) {
                    $output[$channel] .= $chunk;
                    if (strlen($output[$channel]) > self::OUTPUT_MAX) {
                        $output[$channel] = substr($output[$channel], 0, self::OUTPUT_MAX);
                        $truncated = true;
                    }
                } else {
                    $truncated = true;
                }

                if ($onOutput !== null) {
                    foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
                        $onOutput($channel, $line);
                    }
                }
            }
        }

        if ($timedOut) {
            proc_terminate($process, SIGTERM);
            usleep(300000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }
        }

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $code = proc_close($process);
        $duration = microtime(true) - $startedAt;

        $this->journal->command($command, $timedOut ? null : $code, $duration);

        if ($timedOut) {
            throw new AgentException(
                AgentException::TIMEOUT,
                sprintf('%s hat das Zeitlimit von %d s überschritten.', $program, $timeout),
                ['program' => $program],
            );
        }

        return new Result($code, $output['stdout'], $output['stderr'], $truncated, $duration);
    }

    /** @return array<string,string> */
    public static function programs(): array
    {
        return self::PROGRAMS;
    }
}
