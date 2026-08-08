<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

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
        'systemd-run' => '/usr/bin/systemd-run',
        'journalctl' => '/usr/bin/journalctl',
        'nginx' => '/usr/sbin/nginx',
        'sshd' => '/usr/sbin/sshd',
        'php-fpm' => '/usr/sbin/php-fpm',

        // Je Version ein eigener Handler, und jeder steht einzeln hier.
        // Sie aus der Version zusammenzusetzen wäre bequemer und genau das,
        // was eine Positivliste nicht tun darf: Aus einem Wert einen Pfad zu
        // bauen ist der Vorgang, den sie verhindert. `PhpVersionCatalogTest`
        // prüft, dass zu jeder Version im Katalog eine Zeile hier gehört.
        'php-fpm8.1' => '/usr/sbin/php-fpm8.1',
        'php-fpm8.2' => '/usr/sbin/php-fpm8.2',
        'php-fpm8.3' => '/usr/sbin/php-fpm8.3',
        'php-fpm8.4' => '/usr/sbin/php-fpm8.4',
        'named-checkzone' => '/usr/bin/named-checkzone',
        'useradd' => '/usr/sbin/useradd',
        'userdel' => '/usr/sbin/userdel',
        'usermod' => '/usr/sbin/usermod',
        'groupadd' => '/usr/sbin/groupadd',
        // Dazugekommen mit subscription.remove. `userdel` nimmt die Gruppe
        // nicht mit, wenn sie nicht als „user private group" angelegt wurde —
        // und genau das ist sie hier nicht, weil useradd mit --no-user-group
        // läuft. Ohne groupdel bliebe je gelöschtem Abonnement eine Gruppe in
        // /etc/group stehen.
        'groupdel' => '/usr/sbin/groupdel',
        'setquota' => '/usr/sbin/setquota',
        'repquota' => '/usr/sbin/repquota',
        'apt-get' => '/usr/bin/apt-get',
        'mysql' => '/usr/bin/mysql',
        'mysqldump' => '/usr/bin/mysqldump',

        // **`certbot` stand hier und ist mit P4 wieder gegangen.** Er war für
        // TLS vorgesehen; gebaut wurde statt dessen ein eigener ACME-Client im
        // Agenten (`agent/src/Acme/`, Begründung in docs/32 §6). Ein Programm,
        // das der Agent als root starten darf und nie startet, ist
        // Angriffsfläche mit Erlaubnisschein — und `certbot` ist keine
        // beliebige Zeile: Seine Erneuerungsdateien dürfen Hooks nennen, die
        // bei jedem Lauf als root ausgeführt werden.
    ];

    private const ENVIRONMENT = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',

        // Ohne diese Zeile hält `apt-get` beim ersten Paket an, das eine
        // Rückfrage stellt — und wartet auf eine Eingabe, die von einem
        // Dienst ohne Terminal nie kommt. Das Zeitlimit beendet den Lauf dann
        // nach zehn Minuten mit einer Meldung, in der nichts von der
        // eigentlichen Ursache steht.
        'DEBIAN_FRONTEND' => 'noninteractive',
        'LC_ALL' => 'C',
        'LANG' => 'C',
        'HOME' => '/root',
        'TERM' => 'dumb',
    ];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Steht dieses Programm auf der Positivliste?
     *
     * Für die Prüfungen, die den Katalog gegen die Liste halten — nicht für
     * den Betrieb: Wer ein Programm starten will, ruft {@see self::run()} auf
     * und bekommt dieselbe Antwort als Ausnahme.
     */
    public static function knows(string $program): bool
    {
        return isset(self::PROGRAMS[$program]);
    }

    /**
     * Führt ein Programm aus der Positivliste aus.
     *
     * **`$input` ist eine Zeichenkette und liegt damit vollständig im
     * Speicher.** Für SQL-Anweisungen ist das richtig; für eine Sicherung von
     * zwei Gigabyte wäre es der Weg, auf dem der Agent den Arbeitsspeicher des
     * Servers füllt. Dafür gibt es `$inputFile` — der Kernel liest die Datei
     * dann selbst, und im Agenten steht davon nichts.
     *
     * Dazugekommen mit P5 (`docs/36 §10`), aus einem Fund beim Bauen: Es ist
     * dieselbe Grenze wie auf der Ausgabeseite, wo {@see self::OUTPUT_MAX} bei
     * 4 MiB deckelt. Wer einen Dump durch dieses Rohr schickt, bekommt ihn
     * abgeschnitten zurück — und eine abgeschnittene Sicherung ist schlimmer
     * als keine, weil sie aussieht wie eine.
     *
     * @param  list<string>  $args
     * @param  null|callable(string,string):void  $onOutput  Erhält Ausgabezeilen, sobald sie anfallen
     * @param  null|string  $input  Standardeingabe als Zeichenkette — nur für Kleines
     * @param  null|callable():bool  $abort  Wird in der Warteschleife befragt; `true` beendet das Programm
     * @param  null|string  $inputFile  Standardeingabe aus einer Datei — für alles Grosse
     */
    public function run(
        string $program,
        array $args,
        int $timeout = 60,
        ?callable $onOutput = null,
        ?string $input = null,
        ?callable $abort = null,
        ?string $inputFile = null,
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

        if ($input !== null && $inputFile !== null) {
            throw AgentException::badRequest('Standardeingabe entweder als Zeichenkette oder als Datei, nicht beides.');
        }

        // Der Pfad wird vom Aufrufer gebaut und nicht entgegengenommen (`Db\Dump`);
        // hier steht die Gegenprobe, dass es ihn gibt — sonst öffnete `proc_open`
        // die Standardeingabe still auf nichts, und das Programm liefe mit einer
        // leeren Eingabe erfolgreich durch.
        if ($inputFile !== null && ! is_file($inputFile)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Die Datei für die Standardeingabe gibt es nicht.',
                ['path' => $inputFile],
            );
        }

        $command = array_merge([$path], array_values($args));
        $descriptors = [
            0 => match (true) {
                $input !== null => ['pipe', 'r'],
                $inputFile !== null => ['file', $inputFile, 'r'],
                default => ['file', '/dev/null', 'r'],
            },
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
        $aborted = false;

        while (true) {
            $read = array_filter([1 => $pipes[1], 2 => $pipes[2]], static fn ($r) => is_resource($r) && ! feof($r));

            if ($read === []) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            // Der Abbruch wird in derselben Schleife geprüft wie das
            // Zeitlimit und danach genauso behandelt: erst SIGTERM, dann
            // SIGKILL. Ein Abbruch, der das Kind weiterlaufen ließe, wäre
            // keiner — er hätte nur die Ausgabe abgeschaltet.
            if ($abort !== null && $abort()) {
                $aborted = true;
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

        if ($timedOut || $aborted) {
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

        // -1 ist kein Rückgabecode, den ein Programm liefern kann: Er heißt,
        // dass jemand anderes den Kindprozess vor proc_close geerntet hat —
        // ein SIGCHLD-Handler im selben Prozess etwa. Das als „Programm
        // fehlgeschlagen" durchzureichen, hat einmal eine halbe Stunde
        // gekostet; es ist ein Fehler im Agenten und sagt das jetzt auch.
        if ($code === -1 && ! $timedOut && ! $aborted) {
            $this->journal->write('statusverlust', ['command' => $command]);

            throw AgentException::execFailed(sprintf(
                'Der Rückgabecode von %s ging verloren — ein Signalbehandler hat den Kindprozess geerntet.',
                $program,
            ));
        }

        $this->journal->command($command, $timedOut || $aborted ? null : $code, $duration);

        if ($timedOut) {
            throw new AgentException(
                AgentException::TIMEOUT,
                sprintf('%s hat das Zeitlimit von %d s überschritten.', $program, $timeout),
                ['program' => $program],
            );
        }

        if ($aborted) {
            throw new AgentException(
                AgentException::CANCELLED,
                sprintf('%s wurde abgebrochen.', $program),
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
