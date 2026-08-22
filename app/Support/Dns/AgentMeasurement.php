<?php

declare(strict_types=1);

namespace App\Support\Dns;

use SrvPanel\Agent\Acme\Dns\Packet;
use SrvPanel\Agent\Acme\Dns\Resolver;
use SrvPanel\Agent\Client;
use Throwable;

/**
 * Die Messung über den Agenten — die eine Stelle mit einer Steckdose.
 *
 * **Sie enthält keine Entscheidung.** Was gefragt wird und was die Antwort
 * bedeutet, steht in {@see Survey}; hier steht nur, wie man fragt. Der Schnitt
 * ist derselbe wie zwischen {@see Packet} und {@see Resolver}: Die Umformung ist prüfbar, der
 * Socket ist eine Handvoll Zeilen.
 *
 * **Ein Fehlschlag ist `null` und keine Ausnahme.** Ein Alias, der ausserhalb
 * seiner Zone liegt, ist für `dns.check` zu Recht ein Fehler — für den
 * Abgleich ist er ein Name, über den nichts gesagt werden kann. Wer die
 * Ausnahme durchreichte, machte daraus den Zustand der ganzen Domain.
 */
final class AgentMeasurement implements Measurement
{
    public function __construct(private readonly Client $agent) {}

    /**
     * @param  list<array{name: string, type: string}>  $queries
     * @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}|null
     */
    public function of(string $zone, array $queries): ?array
    {
        try {
            $answer = $this->agent->call('dns.check', ['zone' => $zone, 'queries' => $queries]);
        } catch (Throwable) {
            return null;
        }

        return [
            'nameservers' => self::strings($answer['nameservers'] ?? null),
            'records' => self::rows($answer['records'] ?? null),
            'authorities' => self::rows($answer['authorities'] ?? null),
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }
}
