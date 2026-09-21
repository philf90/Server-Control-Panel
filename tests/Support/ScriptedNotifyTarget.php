<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Notify\NotifyTarget;
use SrvPanel\Agent\AgentException;

/**
 * Ein Meldeziel aus Papier.
 *
 * **Warum gegen ein Drehbuch und nicht gegen den Agenten.** Dieselben drei
 * Zustände wie bei {@see ScriptedDnsCredentials} — es ist eines hinterlegt, es
 * ist keines hinterlegt, der Agent antwortet gar nicht —, und der dritte lässt
 * sich an einem laufenden Agenten nicht herstellen, ohne ihn anzuhalten. Dazu
 * ein vierter, den es nur hier gibt: Das Ziel steht, und die **Zustellung**
 * scheitert. Genau der trennt die Buchung der beiden Kanäle.
 *
 * **Mitgeschrieben wird jede Zustellung.** Ohne das könnte ein Test nur prüfen,
 * *dass* etwas hinausging — nicht, dass die Meldung die richtige Form hatte.
 * Dieselbe Machart wie {@see ScriptedOutbound} eine Ebene tiefer.
 */
final class ScriptedNotifyTarget implements NotifyTarget
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @param array{host: string, stored_at: int, signed: bool}|null $target */
    public function __construct(
        private ?array $target = ['host' => 'hooks.example.org', 'stored_at' => 1_789_000_000, 'signed' => true],
        private readonly bool $reachable = true,
        private readonly bool $delivers = true,
    ) {}

    /** Kein Ziel hinterlegt — der Agent antwortet. */
    public static function empty(): self
    {
        return new self(null);
    }

    /** Der Agent antwortet gar nicht. */
    public static function silent(): self
    {
        return new self(null, reachable: false);
    }

    /** Das Ziel steht, und die Zustellung kommt nicht an. */
    public static function broken(): self
    {
        return new self(delivers: false);
    }

    /** @return array{host: string, stored_at: int, signed: bool}|null */
    public function describe(): ?array
    {
        return $this->target;
    }

    public function reachable(): bool
    {
        return $this->reachable;
    }

    public function store(string $url, ?string $secret): void
    {
        $this->target = [
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'stored_at' => 1_789_000_000,
            'signed' => $secret !== null,
        ];
    }

    public function forget(): bool
    {
        $stand = $this->target !== null;
        $this->target = null;

        return $stand;
    }

    /** @param  array<string, mixed>  $event */
    public function send(array $event): void
    {
        if (! $this->delivers) {
            throw AgentException::execFailed('Das Meldeziel hat die Meldung abgewiesen (HTTP 500).');
        }

        $this->sent[] = $event;
    }
}
