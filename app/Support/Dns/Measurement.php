<?php

declare(strict_types=1);

namespace App\Support\Dns;

use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\Acme\Outbound;

/**
 * Was das Panel über eine Zone erfahren kann — und die Naht, an der die
 * Prüfung ansetzt.
 *
 * **Warum es die Schnittstelle gibt und nicht nur den Agentenaufruf.** Die
 * Fälle, um die es beim Abgleich geht, lassen sich mit einem echten Agenten
 * nicht bestellen: ein Name, dessen Zone schweigt, während die daneben
 * antwortet; ein Alias, der ausserhalb liegt; zwei Nameserver, die
 * Verschiedenes sagen. Genau denselben Dienst leisten {@see Lookup}
 * und {@see Outbound} eine Ebene tiefer.
 *
 * **`null` heisst „die Messung hat nicht stattgefunden".** Das ist etwas
 * anderes als eine Antwort ohne Sätze, und die Unterscheidung trägt den
 * ganzen Abgleich: Ohne sie meldete die Anzeige „der Eintrag fehlt", wenn in
 * Wahrheit niemand gefragt werden konnte.
 */
interface Measurement
{
    /**
     * Die autoritativen Nameserver dieser Zone fragen.
     *
     * @param  list<array{name: string, type: string}>  $queries
     * @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}|null
     */
    public function of(string $zone, array $queries): ?array;
}
