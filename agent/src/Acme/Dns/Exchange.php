<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * Eine Nachricht hin, eine Antwort zurück — mehr braucht eine Aktualisierung.
 *
 * **Die Schnittstelle gibt es aus demselben Grund wie {@see Lookup}:** damit
 * ein Durchgang die Antwort vorgeben kann. Ohne sie hinge jeder Test an einem
 * Nameserver, der Aktualisierungen entgegennimmt — und die Fälle, um die es
 * geht, liessen sich dort gar nicht bestellen: eine Antwort mit falscher
 * Kennung, eine mit `REFUSED`, eine mit einer Unterschrift, die nicht passt.
 */
interface Exchange
{
    /**
     * @throws AgentException wenn die Verbindung nicht zustande kommt
     */
    public function send(string $server, int $port, string $message): string;
}
