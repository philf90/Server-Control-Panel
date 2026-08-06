<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tls\DnsCredentials;

/**
 * Hinterlegte DNS-Profile, ohne dass etwas hinterlegt wäre.
 *
 * **Damit sich die drei Fälle bestellen lassen, auf die es ankommt:** Das
 * Profil des Betreibers ist da, das eines Abonnements fehlt, und der Agent
 * antwortet gar nicht. Alle drei entscheiden, ob ein Knopf erscheint — und der
 * letzte lässt sich an einem laufenden Agenten nicht herstellen, ohne ihn
 * anzuhalten.
 */
final class ScriptedDnsCredentials implements DnsCredentials
{
    /** @param list<string> $profiles */
    public function __construct(private readonly array $profiles = []) {}

    /** @return list<string> */
    public function profiles(): array
    {
        return $this->profiles;
    }
}
