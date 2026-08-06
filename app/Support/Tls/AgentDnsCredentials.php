<?php

declare(strict_types=1);

namespace App\Support\Tls;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Profile, wie der Agent sie kennt.
 *
 * **Gefragt wird er und nicht die Datenbank**, denn dort liegen sie: 0600 root
 * in einem 0700-Verzeichnis (`docs/34 §5`). Das Panel führt darüber keine
 * zweite Liste — die wäre die zweite Wahrheit zu derselben Frage, und das ist
 * das Muster, an dem dieses Projekt am häufigsten verloren hat.
 *
 * **Einmal je Anfrage.** Eine Domainseite fragt für jede Zeile dasselbe; der
 * Socket ist schnell, aber eine Antwort je Zeile wäre eine Frage zu viel.
 */
final class AgentDnsCredentials implements DnsCredentials
{
    /** @var list<string>|null */
    private ?array $known = null;

    public function __construct(private readonly Client $agent) {}

    /** @return list<string> */
    public function profiles(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        try {
            $answer = $this->agent->call(
                'dns.credential.list',
                [],
                ['source' => 'web', 'command' => 'domains.certificate'],
            );
        } catch (AgentException) {
            // Siehe {@see DnsCredentials::profiles()}: keine Auskunft heisst
            // „kein Profil", und das ist hier die vorsichtige Richtung.
            return $this->known = [];
        }

        $names = [];

        foreach (is_array($answer['profiles'] ?? null) ? $answer['profiles'] : [] as $profile) {
            $name = is_array($profile) ? ($profile['profile'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $this->known = $names;
    }
}
