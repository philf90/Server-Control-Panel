<?php

declare(strict_types=1);

namespace App\Support\Notify;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Notify\Providers;

/**
 * Das Meldeziel, wie der Agent es kennt.
 *
 * **Gefragt wird er und nicht die Datenbank**, denn dort liegt es: 0600 root in
 * einem 0700-Verzeichnis. Das Panel führt darüber keine zweite Liste — die wäre
 * die zweite Wahrheit zu derselben Frage, und das ist das Muster, an dem dieses
 * Projekt am häufigsten verloren hat.
 *
 * **Einmal je Anfrage.** Die Einstellungsseite fragt zweimal dasselbe — einmal
 * für den Zustand, einmal für die Beschriftung des Knopfes.
 *
 * **Und der Zwischenspeicher merkt sich auch das Schweigen.** Ein Agent, der
 * gerade nicht antwortet, antwortet in derselben Anfrage kein zweites Mal
 * anders; ihn dreimal zu fragen kostete drei Zeitüberschreitungen und ergäbe
 * dieselbe Antwort.
 */
final class AgentNotifyTarget implements NotifyTarget
{
    /** Der Kontext, unter dem der Agent die Aufrufe protokolliert. */
    private const CONTEXT = ['source' => 'web', 'command' => 'settings.notices'];

    /** @var array{host: string, provider: string, stored_at: int, signed: bool}|null */
    private ?array $beschrieben = null;

    private ?bool $erreichbar = null;

    public function __construct(private readonly Client $agent) {}

    /** @return array{host: string, provider: string, stored_at: int, signed: bool}|null */
    public function describe(): ?array
    {
        $this->ask();

        return $this->beschrieben;
    }

    public function reachable(): bool
    {
        $this->ask();

        return $this->erreichbar === true;
    }

    public function store(string $url, ?string $secret, string $provider): void
    {
        /*
         * **Unmittelbar und nicht eingereiht.** Adresse und Geheimnis lägen
         * sonst in `operations.payload` — im Klartext, dauerhaft, und die
         * Vorgangsseite rendert ihn als JSON. Das ist die vierte Grenze, und
         * `SecretsStayOutOfTheQueueTest` hält sie.
         */
        $this->agent->call(
            'notify.target.store',
            ['url' => $url, 'secret' => $secret, 'provider' => $provider],
            self::CONTEXT,
        );

        $this->vergessen();
    }

    public function forget(): bool
    {
        $antwort = $this->agent->call('notify.target.forget', [], self::CONTEXT);

        $this->vergessen();

        return ($antwort['removed'] ?? false) === true;
    }

    /** @param  array<string, mixed>  $event */
    public function send(array $event): void
    {
        $this->agent->call('notify.send', ['event' => $event], self::CONTEXT);
    }

    /**
     * Einmal fragen und die Antwort behalten.
     *
     * **Ein Fehlschlag heisst „nicht feststellbar" und nicht „kein Ziel".**
     * Beide auf `null` abzubilden wäre die Anzeige, die zwei Zustände gleich
     * aussehen lässt; {@see reachable()} trennt sie.
     */
    private function ask(): void
    {
        if ($this->erreichbar !== null) {
            return;
        }

        try {
            $antwort = $this->agent->call('notify.target.describe', [], self::CONTEXT);
        } catch (AgentException) {
            $this->erreichbar = false;
            $this->beschrieben = null;

            return;
        }

        $this->erreichbar = true;
        $this->beschrieben = self::shape($antwort['target'] ?? null);
    }

    /**
     * Die Antwort des Agenten auf die zugesagte Form bringen.
     *
     * **Jedes Feld einzeln und nicht die Ablage durchgereicht.** Was hier
     * herausgeht, landet in einer Antwort der Oberfläche; eine durchgereichte
     * Ablage trüge beim nächsten Feld im Agenten etwas mit, das niemand
     * entschieden hat.
     *
     * @return array{host: string, provider: string, stored_at: int, signed: bool}|null
     */
    private static function shape(mixed $target): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        $host = $target['host'] ?? null;
        $stored = $target['stored_at'] ?? 0;

        return [
            'host' => is_string($host) ? $host : '',
            'provider' => Providers::normalize($target['provider'] ?? null),
            'stored_at' => is_int($stored) ? $stored : 0,
            'signed' => ($target['signed'] ?? false) === true,
        ];
    }

    private function vergessen(): void
    {
        $this->erreichbar = null;
        $this->beschrieben = null;
    }
}
