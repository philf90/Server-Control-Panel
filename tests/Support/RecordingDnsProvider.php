<?php

declare(strict_types=1);

namespace Tests\Support;

use SrvPanel\Agent\Acme\Dns\DnsProvider;
use SrvPanel\Agent\Acme\Patience;

/**
 * Ein DNS-Anbieter, der nichts tut und alles behält.
 *
 * **Warum kein echter.** Ein Durchgang gegen eine fremde API wäre einer, der
 * Netz braucht, ein Konto, eine Zone — und der beim dritten roten Lauf aus
 * einem Grund abgeschaltet wird, der mit diesem Panel nichts zu tun hat. Was
 * hier geprüft wird, ist ohnehin nicht der Anbieter, sondern was ihm gesagt
 * wird: der richtige Name, der richtige Wert, und beim Abräumen genau der
 * Eintrag, der vorher angelegt wurde.
 */
final class RecordingDnsProvider implements DnsProvider
{
    /** @var list<array{0: string, 1: string}> */
    public array $added = [];

    /** @var list<array{0: string, 1: string}> */
    public array $removed = [];

    public function add(string $record, string $value): void
    {
        $this->added[] = [$record, $value];
    }

    public function remove(string $record, string $value): void
    {
        $this->removed[] = [$record, $value];
    }

    /**
     * Eine Sekunde, damit ein Test, der doch einmal wartet, nicht wartet.
     *
     * **Der Wert ist beliebig, die Methode nicht.** `DnsProvider::patience()`
     * steht ohne Vorgabe in der Schnittstelle — genau deshalb muss auch das
     * Testdoppel eine Zahl nennen. Am 7. August hat das eine CI-Runde gekostet:
     * Die Schnittstelle bekam die Methode, dieses Doppel nicht, und die Tests
     * starben mit „Premature end of PHP process", weil eine nicht ladbare
     * Klasse kein Fehlschlag ist, sondern ein Abbruch.
     */
    public function patience(): Patience
    {
        return new Patience(1, 1);
    }
}
