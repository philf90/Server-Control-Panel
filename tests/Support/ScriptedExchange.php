<?php

declare(strict_types=1);

namespace Tests\Support;

use SrvPanel\Agent\Acme\Dns\Exchange;
use SrvPanel\Agent\Acme\Dns\Tsig;
use SrvPanel\Agent\Acme\Dns\UpdateMessage;

/**
 * Ein Nameserver, der genau das antwortet, was der Durchgang braucht.
 *
 * **Damit sich die Fälle bestellen lassen, die es sonst nicht gibt.** Eine
 * Antwort mit `REFUSED`, eine mit falscher Kennung, eine ohne gültige
 * Unterschrift — an einem echten Nameserver wäre jeder davon ein eigener
 * Aufbau, und die meisten liessen sich gar nicht herstellen.
 *
 * **Er unterschreibt richtig, solange nichts anderes gesagt ist.** Sonst
 * bestünde jeder Durchgang schon daran, dass die Antwort verworfen wird — und
 * die Prüfung des eigentlichen Falls käme nie dran.
 */
final class ScriptedExchange implements Exchange
{
    /** @var list<string> Was gesendet wurde — in der Reihenfolge. */
    public array $sent = [];

    /** @var list<array{server: string, port: int}> */
    public array $to = [];

    /**
     * @param  string  $secret  In Rohbytes, wie {@see Tsig} sie hält
     */
    public function __construct(
        private readonly string $keyName,
        private readonly string $secret,
        private readonly int $code = 0,
        private readonly bool $wrongId = false,
        private readonly bool $sign = true,
    ) {}

    public function send(string $server, int $port, string $message): string
    {
        $this->sent[] = $message;
        $this->to[] = ['server' => $server, 'port' => $port];

        // Nicht eine feste falsche Kennung, sondern die verschobene: Eine
        // feste träfe die zufällig gewählte irgendwann und der Durchgang wäre
        // einmal unter 65536 Läufen grün, ohne dass jemand wüsste warum.
        $id = ((UpdateMessage::id($message) ?? 0) + ($this->wrongId ? 1 : 0)) & 0xFFFF;
        $body = pack('n6', $id, 0xA800 | $this->code, 0, 0, 0, 0);

        if (! $this->sign) {
            return $body;
        }

        // Der Zähler steigt erst, nachdem über die Nachricht gerechnet wurde —
        // dieselbe Reihenfolge wie beim Unterschreiben.
        return substr_replace($body, pack('n', 1), 10, 2)
            .$this->signature($id, $body, $this->requestMac($message));
    }

    /**
     * Die Unterschrift der Frage — sie geht in die der Antwort ein.
     *
     * Sie steht ganz hinten: Nach ihr kommen nur noch die ursprüngliche
     * Kennung, das Fehlerfeld und die Länge des Zusatzfeldes, zusammen sechs
     * Bytes. Ihre Länge ist die des Verfahrens, und dieser Doppelgänger
     * unterschreibt ausschliesslich mit SHA-256.
     */
    private function requestMac(string $message): string
    {
        return substr($message, -6 - 32, 32);
    }

    private function signature(int $id, string $body, string $requestMac): string
    {
        $time = time();

        $variables = $this->wire($this->keyName)
            .pack('nN', Tsig::CLASS_ANY, 0)
            .$this->wire(Tsig::DEFAULT_ALGORITHM)
            .substr(pack('J', $time), 2, 6)
            .pack('n3', Tsig::FUDGE, 0, 0);

        $mac = hash_hmac(
            'sha256',
            pack('n', strlen($requestMac)).$requestMac.$body.$variables,
            $this->secret,
            true,
        );

        $rdata = $this->wire(Tsig::DEFAULT_ALGORITHM)
            .substr(pack('J', $time), 2, 6)
            .pack('n2', Tsig::FUDGE, strlen($mac))
            .$mac
            .pack('n3', $id, 0, 0);

        return $this->wire($this->keyName)
            .pack('n2N', Tsig::TYPE, Tsig::CLASS_ANY, 0)
            .pack('n', strlen($rdata))
            .$rdata;
    }

    private function wire(string $name): string
    {
        $encoded = '';

        foreach (explode('.', strtolower($name)) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }
}
