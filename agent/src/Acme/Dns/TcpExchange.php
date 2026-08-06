<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * Der Weg zum Nameserver — über TCP und nicht über UDP.
 *
 * **Nicht aus Vorsicht, sondern weil es sonst nicht passt.** Eine
 * unterschriebene Aktualisierung trägt den Schlüsselnamen, den Verfahrensnamen
 * und eine Unterschrift von 32 bis 64 Bytes mit sich; zusammen mit dem Satz
 * selbst ist das regelmässig mehr als die 512 Bytes, die ein DNS-Paket über
 * UDP haben darf. Und eine abgeschnittene Aktualisierung ist keine, die man
 * noch einmal fragt — sie ist eine, die halb angekommen wäre.
 *
 * **Die Länge steht vorne, zweimal.** Über TCP führt jede DNS-Nachricht zwei
 * Bytes Länge (RFC 1035 §4.2.2) — beim Senden und beim Empfangen. Wer beim
 * Lesen `fread()` einmal aufruft und hofft, bekommt bei einer geteilten Antwort
 * die halbe; deshalb wird bis zur angekündigten Länge gelesen.
 */
final class TcpExchange implements Exchange
{
    public const TIMEOUT_SECONDS = 10;

    public function send(string $server, int $port, string $message): string
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $server, $port),
            $code,
            $error,
            self::TIMEOUT_SECONDS,
        );

        if (! is_resource($socket)) {
            throw AgentException::execFailed(
                'Der Nameserver ist nicht erreichbar.',
                ['server' => $server, 'port' => $port, 'error' => is_string($error) ? $error : ''],
            );
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            if (@fwrite($socket, pack('n', strlen($message)).$message) === false) {
                throw AgentException::execFailed('Die Aktualisierung ließ sich nicht senden.', ['server' => $server]);
            }

            $header = $this->read($socket, 2);

            /** @var array<int, int>|false $length */
            $length = unpack('n', $header);

            if ($length === false || $length[1] === 0) {
                throw AgentException::execFailed('Der Nameserver antwortet nicht.', ['server' => $server]);
            }

            return $this->read($socket, $length[1]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Genau so viele Bytes lesen — oder aufgeben.
     *
     * @param  resource  $socket
     */
    private function read($socket, int $bytes): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $piece = @fread($socket, $bytes - strlen($buffer));

            if (! is_string($piece) || $piece === '') {
                throw AgentException::execFailed('Die Antwort des Nameservers bricht ab.');
            }

            $buffer .= $piece;
        }

        return $buffer;
    }
}
