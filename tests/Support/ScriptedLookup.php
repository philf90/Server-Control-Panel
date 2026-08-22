<?php

declare(strict_types=1);

namespace Tests\Support;

use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\Acme\Dns\Packet;

/**
 * Ein DNS, das antwortet, was der Durchgang bestimmt.
 *
 * **Der Fall, um den es geht, lässt sich anders nicht herstellen:** Ein Wert
 * steht auf dem einen Nameserver und auf dem anderen noch nicht. Genau dann
 * darf die Prüfung nicht losgeschickt werden — und genau das ist im echten
 * Netz ein Zufall von Sekunden, den man nicht bestellen kann.
 *
 * **Seit P7 kann dieses Doppel auch schweigen.** `null` unter einem Server
 * heisst „nicht erreichbar" und ist etwas anderes als eine leere Liste; ohne
 * diesen Unterschied liesse sich der Zustand aus `docs/72 §2.3` gar nicht
 * prüfen.
 */
final class ScriptedLookup implements Lookup
{
    /**
     * @param  list<string>  $servers
     * @param  array<string, list<string>|null>  $values  TXT-Werte je Server
     * @param  array<string, array<string, list<string>|null>>  $records  Werte je Server, Name und Typ
     * @param  array<string, list<array{flags: int, tag: string, value: string}>|null>  $caa
     */
    public function __construct(
        private readonly array $servers,
        private readonly array $values = [],
        private readonly array $records = [],
        private readonly array $caa = [],
    ) {}

    /** @return list<string> */
    public function nameservers(string $name): array
    {
        return $this->servers;
    }

    /** @return list<string>|null */
    public function records(string $server, string $name, int $type): ?array
    {
        /*
         * **Zwei Wege in dasselbe Doppel, und das ist Absicht.** Die älteren
         * Durchgänge zu ACME geben nur TXT-Werte je Server vor; die von P7
         * brauchen sie je Server, Name und Typ. Beides nebeneinander spart
         * ein zweites Doppel, das mit dem ersten auseinanderliefe.
         */
        if ($this->records !== []) {
            $key = $name.'/'.$type;

            return array_key_exists($server, $this->records) && array_key_exists($key, $this->records[$server])
                ? $this->records[$server][$key]
                : [];
        }

        return $type === Packet::TYPE_TXT ? ($this->values[$server] ?? []) : [];
    }

    /** @return list<array{flags: int, tag: string, value: string}>|null */
    public function authorities(string $server, string $name): ?array
    {
        $key = $server.'/'.$name;

        return array_key_exists($key, $this->caa) ? $this->caa[$key] : [];
    }
}
