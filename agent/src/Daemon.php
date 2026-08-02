<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

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

    private bool $laeuft = true;

    private int $kinder = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Registry $registry,
        private readonly Journal $journal,
    ) {}

    public function starte(): int
    {
        if (posix_getuid() !== 0 && ! $this->config->erlaubeUnprivilegiert) {
            fwrite(STDERR, "cloudsrv-agentd muss als root laufen.\n");

            return 1;
        }

        $anwendungsUid = $this->anwendungsUid();

        $this->oeffneSocket();
        $this->signale();

        $this->journal->schreibe('start', [
            'agent' => Version::AGENT,
            'protokoll' => Version::PROTOKOLL,
            'socket' => $this->config->socket,
            'anwendung_uid' => $anwendungsUid,
            'ops' => $this->registry->namen(),
        ]);

        while ($this->laeuft) {
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
            $lesen = [$this->server];
            $schreiben = null;
            $sonst = null;

            if (@socket_select($lesen, $schreiben, $sonst, 1) < 1) {
                continue;
            }

            $verbindung = @socket_accept($this->server);

            if ($verbindung === false) {
                continue;
            }

            if ($this->kinder >= $this->config->maxKinder) {
                $this->weiseAb($verbindung);

                continue;
            }

            $kind = pcntl_fork();

            if ($kind === -1) {
                $this->weiseAb($verbindung);

                continue;
            }

            if ($kind === 0) {
                // Im Kind: der Serversocket wird nicht gebraucht und darf beim
                // Ende des Kindes nicht mit abgeräumt werden.
                socket_close($this->server);
                $bediener = new Verbindung($verbindung, $this->registry, $this->journal, $this->config, $anwendungsUid);
                $bediener->bediene();
                exit(0);
            }

            $this->kinder++;
            socket_close($verbindung);
            $this->ernteKinder();
        }

        $this->raeumeAuf();

        return 0;
    }

    private function oeffneSocket(): void
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

        $verzeichnis = dirname($this->config->socket);

        if (! is_dir($verzeichnis) && ! @mkdir($verzeichnis, 0o755, true) && ! is_dir($verzeichnis)) {
            fwrite(STDERR, "Verzeichnis {$verzeichnis} ließ sich nicht anlegen.\n");
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
        @chgrp($this->config->socket, $this->config->gruppe);

        $this->server = $server;
    }

    private function anwendungsUid(): int
    {
        $eintrag = posix_getpwnam($this->config->benutzer);

        if ($eintrag === false) {
            if ($this->config->erlaubeUnprivilegiert) {
                return posix_getuid();
            }

            fwrite(STDERR, "Benutzer {$this->config->benutzer} existiert nicht.\n");
            exit(1);
        }

        return (int) $eintrag['uid'];
    }

    private function weiseAb(Socket $verbindung): void
    {
        @socket_write($verbindung, json_encode([
            'type' => 'result',
            'ok' => false,
            'error' => [
                'code' => 'busy',
                'message' => 'Der Agent bedient bereits die höchstzulässige Zahl gleichzeitiger Aufträge.',
                'details' => ['max' => $this->config->maxKinder],
            ],
        ], JSON_UNESCAPED_UNICODE)."\n");
        @socket_close($verbindung);
        $this->journal->schreibe('ueberlast', ['kinder' => $this->kinder]);
    }

    private function signale(): void
    {
        pcntl_async_signals(true);

        // restart_syscalls = false: siehe die Begründung in der Hauptschleife.
        // Das select dort trägt die Beendigung; dass der Systemaufruf zusätzlich
        // abbricht, macht sie nur schneller.
        pcntl_signal(SIGTERM, function (): void {
            $this->laeuft = false;
        }, false);
        pcntl_signal(SIGINT, function (): void {
            $this->laeuft = false;
        }, false);
        pcntl_signal(SIGCHLD, function (): void {
            $this->ernteKinder();
        });
        pcntl_signal(SIGPIPE, SIG_IGN);
    }

    private function ernteKinder(): void
    {
        while (($pid = pcntl_waitpid(-1, $stand, WNOHANG)) > 0) {
            $this->kinder = max(0, $this->kinder - 1);
        }
    }

    private function raeumeAuf(): void
    {
        if ($this->server !== null) {
            socket_close($this->server);
        }

        @unlink($this->config->socket);
        $this->journal->schreibe('ende', []);
    }
}
