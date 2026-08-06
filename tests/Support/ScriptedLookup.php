<?php

declare(strict_types=1);

namespace Tests\Support;

use SrvPanel\Agent\Acme\Dns\Lookup;

/**
 * Ein DNS, das antwortet, was der Durchgang bestimmt.
 *
 * **Der Fall, um den es geht, lässt sich anders nicht herstellen:** Ein Wert
 * steht auf dem einen Nameserver und auf dem anderen noch nicht. Genau dann
 * darf die Prüfung nicht losgeschickt werden — und genau das ist im echten
 * Netz ein Zufall von Sekunden, den man nicht bestellen kann.
 */
final class ScriptedLookup implements Lookup
{
    /**
     * @param  list<string>  $servers
     * @param  array<string, list<string>>  $values  Werte je Server
     */
    public function __construct(
        private readonly array $servers,
        private readonly array $values = [],
    ) {}

    /** @return list<string> */
    public function nameservers(string $name): array
    {
        return $this->servers;
    }

    /** @return list<string> */
    public function txt(string $server, string $name): array
    {
        return $this->values[$server] ?? [];
    }
}
