<?php

declare(strict_types=1);

namespace App\Support\Tls;

use SrvPanel\Agent\Acme\Dns\Exchange;
use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\Ops\DnsCredentialList;

/**
 * Welche DNS-Profile hinterlegt sind — mehr fragt die Anwendung nicht.
 *
 * **Eine Naht aus demselben Grund wie {@see Lookup} und {@see Exchange}:** Die
 * Antwort kommt vom Agenten, und den gibt es in einem Testlauf nicht. Ohne
 * diese Schnittstelle hinge jede Prüfung an einem Unix-Socket — und
 * `SrvPanel\Agent\Client` ist `final`, lässt sich also auch nicht ersetzen.
 *
 * **Und sie ist absichtlich schmal.** Zurück kommen Profilnamen, sonst nichts:
 * kein Anbieter, kein Zeitpunkt und vor allem kein Geheimnis. Was die
 * Oberfläche über ein Profil sagen darf, entscheidet der Agent
 * ({@see DnsCredentialList}); diese Stelle braucht nur zu wissen, ob es eines
 * gibt.
 */
interface DnsCredentials
{
    /**
     * Die Namen der hinterlegten Profile.
     *
     * **Keine Auskunft ist eine leere Liste und keine Ausnahme.** Der Aufrufer
     * fragt, um einen Knopf anzubieten oder nicht — und die vorsichtige
     * Richtung ist „nicht": Eine Bestellung, die mangels Zugangsdaten
     * scheitert, verbrennt einen der fünf Fehlversuche je Konto und Stunde.
     *
     * @return list<string>
     */
    public function profiles(): array;
}
