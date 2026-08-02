<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

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
        private readonly string $socket = '/run/srvpanel/agent.sock',
        private readonly int $timeout = 300,
    ) {}

    /**
     * Führt eine Operation aus und gibt die Nutzdaten zurück.
     *
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>|null  $actor
     * @param  null|callable(array<string,mixed>):void  $onOutput  Fortschritt und Ausgabe, sobald sie anfallen
     * @return array<string,mixed>
     */
    public function call(string $op, array $args = [], ?array $actor = null, ?callable $onOutput = null): array
    {
        $connection = $this->connect();

        $request = [
            'v' => Version::PROTOCOL,
            'id' => bin2hex(random_bytes(8)),
            'op' => $op,
            'actor' => $actor,
            'args' => (object) $args,
        ];

        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw AgentException::badRequest('Anfrage ließ sich nicht kodieren.');
        }

        socket_write($connection, $json."\n");

        $buffer = '';
        $result = null;

        while (true) {
            $chunk = @socket_read($connection, 65536, PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 1);

                if (trim($line) === '') {
                    continue;
                }

                $frame = json_decode($line, true);

                if (! is_array($frame)) {
                    continue;
                }

                if (($frame['type'] ?? null) === 'result') {
                    $result = $frame;
                    break 2;
                }

                if ($onOutput !== null) {
                    $onOutput($frame);
                }
            }
        }

        socket_close($connection);

        if ($result === null) {
            throw AgentException::execFailed('Der Agent hat die Verbindung ohne Ergebnis geschlossen.');
        }

        if (($result['ok'] ?? false) !== true) {
            $error = $result['error'] ?? [];

            throw new AgentException(
                is_string($error['code'] ?? null) ? $error['code'] : AgentException::INTERNAL,
                is_string($error['message'] ?? null) ? $error['message'] : 'Der Agent meldete einen Fehler.',
                is_array($error['details'] ?? null) ? $error['details'] : [],
            );
        }

        $data = $result['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /** Erreichbarkeit ohne Ausnahme — für Gesundheitsendpunkt und Bereitschaftsprüfung. */
    public function reachable(): bool
    {
        try {
            $this->call('agent.ping');

            return true;
        } catch (AgentException) {
            return false;
        }
    }

    private function connect(): Socket
    {
        if (! file_exists($this->socket)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Der Agent läuft nicht: Socket ist nicht vorhanden.',
                ['socket' => $this->socket],
            );
        }

        $connection = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($connection === false) {
            throw AgentException::execFailed('Socket ließ sich nicht anlegen.');
        }

        socket_set_option($connection, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        socket_set_option($connection, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);

        if (! @socket_connect($connection, $this->socket)) {
            $reason = socket_strerror(socket_last_error($connection));

            throw AgentException::execFailed('Verbindung zum Agenten scheiterte: '.$reason);
        }

        return $connection;
    }
}
