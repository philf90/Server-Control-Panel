<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

use Socket;

/**
 * Die Gegenseite: spricht vom unprivilegierten Prozess aus mit dem Agenten.
 *
 * Sie liegt bewusst im selben Verzeichnis wie der Agent, obwohl sie nie als
 * root läuft. Der Grund ist das Protokoll: Anfrage- und Antwortform stehen
 * damit an einer Stelle statt an zweien, und eine Änderung, die nur eine Seite
 * mitmacht, fällt beim Lesen auf statt im Betrieb.
 */
final class Client
{
    public function __construct(
        private readonly string $socket = '/run/cloudsrv/agent.sock',
        private readonly int $zeitlimit = 300,
    ) {}

    /**
     * Führt eine Operation aus und gibt die Nutzdaten zurück.
     *
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>|null  $actor
     * @param  null|callable(array<string,mixed>):void  $mitlesen  Fortschritt und Ausgabe, sobald sie anfallen
     * @return array<string,mixed>
     */
    public function ruf(string $op, array $args = [], ?array $actor = null, ?callable $mitlesen = null): array
    {
        $verbindung = $this->verbinde();

        $anfrage = [
            'v' => Version::PROTOKOLL,
            'id' => bin2hex(random_bytes(8)),
            'op' => $op,
            'actor' => $actor,
            'args' => (object) $args,
        ];

        $json = json_encode($anfrage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw AgentException::badRequest('Anfrage ließ sich nicht kodieren.');
        }

        socket_write($verbindung, $json."\n");

        $puffer = '';
        $ergebnis = null;

        while (true) {
            $stueck = @socket_read($verbindung, 65536, PHP_BINARY_READ);

            if ($stueck === false || $stueck === '') {
                break;
            }

            $puffer .= $stueck;

            while (($bruch = strpos($puffer, "\n")) !== false) {
                $zeile = substr($puffer, 0, $bruch);
                $puffer = substr($puffer, $bruch + 1);

                if (trim($zeile) === '') {
                    continue;
                }

                $satz = json_decode($zeile, true);

                if (! is_array($satz)) {
                    continue;
                }

                if (($satz['type'] ?? null) === 'result') {
                    $ergebnis = $satz;
                    break 2;
                }

                if ($mitlesen !== null) {
                    $mitlesen($satz);
                }
            }
        }

        socket_close($verbindung);

        if ($ergebnis === null) {
            throw AgentException::execFailed('Der Agent hat die Verbindung ohne Ergebnis geschlossen.');
        }

        if (($ergebnis['ok'] ?? false) !== true) {
            $fehler = $ergebnis['error'] ?? [];

            throw new AgentException(
                is_string($fehler['code'] ?? null) ? $fehler['code'] : AgentException::INTERNAL,
                is_string($fehler['message'] ?? null) ? $fehler['message'] : 'Der Agent meldete einen Fehler.',
                is_array($fehler['details'] ?? null) ? $fehler['details'] : [],
            );
        }

        $daten = $ergebnis['data'] ?? [];

        return is_array($daten) ? $daten : [];
    }

    /** Erreichbarkeit ohne Ausnahme — für Gesundheitsendpunkt und Bereitschaftsprüfung. */
    public function erreichbar(): bool
    {
        try {
            $this->ruf('agent.ping');

            return true;
        } catch (AgentException) {
            return false;
        }
    }

    private function verbinde(): Socket
    {
        if (! file_exists($this->socket)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Der Agent läuft nicht: Socket ist nicht vorhanden.',
                ['socket' => $this->socket],
            );
        }

        $verbindung = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($verbindung === false) {
            throw AgentException::execFailed('Socket ließ sich nicht anlegen.');
        }

        socket_set_option($verbindung, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->zeitlimit, 'usec' => 0]);
        socket_set_option($verbindung, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);

        if (! @socket_connect($verbindung, $this->socket)) {
            $grund = socket_strerror(socket_last_error($verbindung));

            throw AgentException::execFailed('Verbindung zum Agenten scheiterte: '.$grund);
        }

        return $verbindung;
    }
}
