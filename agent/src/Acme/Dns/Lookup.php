<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Was die Prüfung von aussen sehen muss.
 *
 * **Zwei Fragen, und beide ohne Zwischenspeicher:** Wer führt diese Zone, und
 * was liefert dieser eine Server für diesen Namen aus. Die Umsetzung ist
 * {@see Resolver}; die Schnittstelle steht hier, damit ein Durchgang die
 * Antworten vorgeben kann, ohne einen Nameserver zu brauchen. Ohne sie hinge
 * jeder Test an einer Zone, die es wirklich gibt — und wäre damit langsam,
 * unzuverlässig und von aussen beeinflussbar.
 */
interface Lookup
{
    /**
     * Die Adressen der autoritativen Nameserver für diesen Namen.
     *
     * @return list<string>
     */
    public function nameservers(string $name): array;

    /**
     * Die TXT-Werte, die dieser Server für diesen Namen ausliefert.
     *
     * @return list<string>
     */
    public function txt(string $server, string $name): array;
}
