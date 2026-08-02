<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use Socket;

/**
 * Der Dienst: hört auf dem Socket, gabelt je Verbindung ein Kind ab.
 *
 * **Warum ein Kindprozess je Verbindung.** Ein Auftrag kann Minuten dauern
 * (Paketinstallation, Zertifikatsanforderung). Ein Agent, der der Reihe nach
 * arbeitet, würde in dieser Zeit auch die Frage „läuft der Dienst?" nicht mehr
 * beantworten. Der Fork kostet wenig, und ein abgestürztes Kind nimmt den
 * Dienst nicht mit.
 *
 * **Warum trotzdem eine Obergrenze.** Ohne sie wäre eine Schleife in der
 * Anwendung ein Weg, die Prozesstabelle des Servers zu füllen — als root.
 */
final class Daemon
{
    private ?Socket $server = null;

    private bool $running = true;

    private int $children = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Registry $registry,
        private readonly Journal $journal,
    ) {}

    public function start(): int
    {
        if (posix_getuid() !== 0 && ! $this->config->allowUnprivileged) {
            fwrite(STDERR, "srvpanel-agentd muss als root laufen.\n");

            return 1;
        }

        $appUid = $this->appUid();

        $this->openSocket();
        $this->installSignals();

        $this->journal->write('start', [
            'agent' => Version::AGENT,
            'protocol' => Version::PROTOCOL,
            'socket' => $this->config->socket,
            'app_uid' => $appUid,
            'ops' => $this->registry->names(),
        ]);

        while ($this->running) {
            // Warten mit Frist statt blockierendem accept().
            //
            // Der erste Anlauf hier war ein blockierendes socket_accept() in
            // der Erwartung, ein Signal breche es ab. Das tut es nicht:
            // pcntl_signal setzt SA_RESTART, wenn man nicht widerspricht, und
            // der Kernel nimmt den unterbrochenen accept()-Aufruf danach
            // wieder auf. Der Handler lief, das Flag stand auf „beenden" — und
            // der Prozess hing weiter im accept, bis zufällig jemand eine
            // Verbindung aufbaute. systemd hätte ihn nach der Frist mit
            // SIGKILL beendet, mitten in einem laufenden Auftrag.
            //
            // Mit select und Frist ist das Beenden nicht mehr davon abhängig,
            // wie ein Signal einen Systemaufruf trifft: Spätestens nach einer
            // Sekunde sieht die Schleife das Flag.
            $read = [$this->server];
            $write = null;
            $except = null;

            if (@socket_select($read, $write, $except, 1) < 1) {
                continue;
            }

            $connection = @socket_accept($this->server);

            if ($connection === false) {
                continue;
            }

            if ($this->children >= $this->config->maxChildren) {
                $this->reject($connection);

                continue;
            }

            $child = pcntl_fork();

            if ($child === -1) {
                $this->reject($connection);

                continue;
            }

            if ($child === 0) {
                // Im Kind: der Serversocket wird nicht gebraucht und darf beim
                // Ende des Kindes nicht mit abgeräumt werden.
                socket_close($this->server);

                // Und die Signalbehandlung des Daemons gilt hier nicht mehr.
                //
                // Der SIGCHLD-Handler erntet mit pcntl_waitpid(-1) jedes
                // beendete Kind — auch die Programme, die der Runner über
                // proc_open startet. Danach findet proc_close keinen Status
                // mehr vor und gibt -1 zurück: Der Rückgabecode jedes Aufrufs
                // ginge verloren und jede Operation sähe aus wie
                // fehlgeschlagen. Gefunden hat das kein Test, sondern die
                // erste Ersteinrichtung gegen ein echtes MariaDB — vorher
                // hatte keine Operation, die ein Programm startet, ihren
                // Rückgabecode auch ausgewertet.
                pcntl_signal(SIGCHLD, SIG_DFL);
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                $connection = new Connection($connection, $this->registry, $this->journal, $appUid);
                $connection->serve();
                exit(0);
            }

            $this->children++;
            socket_close($connection);
            $this->reapChildren();
        }

        $this->cleanUp();

        return 0;
    }

    private function openSocket(): void
    {
        // Ein Unix-Socket-Pfad ist im Kernel auf 108 Zeichen begrenzt
        // (sun_path in struct sockaddr_un). PHP wirft darüber eine
        // ValueError mitten im Start — mit dieser Prüfung steht statt dessen
        // eine Meldung da, aus der hervorgeht, was zu ändern ist.
        if (strlen($this->config->socket) >= 108) {
            fwrite(STDERR, sprintf(
                "Socket-Pfad ist %d Zeichen lang, der Kernel erlaubt höchstens 107: %s\n",
                strlen($this->config->socket),
                $this->config->socket,
            ));
            exit(1);
        }

        $directory = dirname($this->config->socket);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            fwrite(STDERR, "Verzeichnis {$directory} ließ sich nicht anlegen.\n");
            exit(1);
        }

        if (file_exists($this->config->socket)) {
            @unlink($this->config->socket);
        }

        $server = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($server === false) {
            fwrite(STDERR, "Socket ließ sich nicht anlegen.\n");
            exit(1);
        }

        // SO_PASSCRED muss VOR dem Verbinden gesetzt sein, sonst liefert der
        // Kernel die Zusatzdaten der ersten Nachricht nicht mit. Accept
        // vererbt die Einstellung an die Verbindung.
        socket_set_option($server, SOL_SOCKET, SO_PASSCRED, 1);

        if (! @socket_bind($server, $this->config->socket)) {
            fwrite(STDERR, "Socket {$this->config->socket} ließ sich nicht binden.\n");
            exit(1);
        }

        socket_listen($server, 32);

        // Erst die Rechte, dann die Gruppe: Zwischen bind und chmod steht der
        // Socket sonst kurz mit den Rechten aus der umask da.
        @chmod($this->config->socket, 0o660);
        @chgrp($this->config->socket, $this->config->group);

        $this->server = $server;
    }

    private function appUid(): int
    {
        $entry = posix_getpwnam($this->config->user);

        if ($entry === false) {
            if ($this->config->allowUnprivileged) {
                return posix_getuid();
            }

            fwrite(STDERR, "Benutzer {$this->config->user} existiert nicht.\n");
            exit(1);
        }

        return (int) $entry['uid'];
    }

    private function reject(Socket $connection): void
    {
        @socket_write($connection, json_encode([
            'type' => 'result',
            'ok' => false,
            'error' => [
                'code' => 'busy',
                'message' => 'Der Agent bedient bereits die höchstzulässige Zahl gleichzeitiger Aufträge.',
                'details' => ['max' => $this->config->maxChildren],
            ],
        ], JSON_UNESCAPED_UNICODE)."\n");
        @socket_close($connection);
        $this->journal->write('overload', ['kinder' => $this->children]);
    }

    private function installSignals(): void
    {
        pcntl_async_signals(true);

        // restart_syscalls = false: siehe die Begründung in der Hauptschleife.
        // Das select dort trägt die Beendigung; dass der Systemaufruf zusätzlich
        // abbricht, macht sie nur schneller.
        pcntl_signal(SIGTERM, function (): void {
            $this->running = false;
        }, false);
        pcntl_signal(SIGINT, function (): void {
            $this->running = false;
        }, false);
        pcntl_signal(SIGCHLD, function (): void {
            $this->reapChildren();
        });
        pcntl_signal(SIGPIPE, SIG_IGN);
    }

    private function reapChildren(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $this->children = max(0, $this->children - 1);
        }
    }

    private function cleanUp(): void
    {
        if ($this->server !== null) {
            socket_close($this->server);
        }

        @unlink($this->config->socket);
        $this->journal->write('stop', []);
    }
}
