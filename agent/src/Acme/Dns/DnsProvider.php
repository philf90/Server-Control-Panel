<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Wer für die Prüfung einen TXT-Eintrag anlegt und wieder abräumt.
 *
 * **Zwei Handlungen und keine dritte.** Alles, was ein Anbieter sonst noch
 * kann — Zonen auflisten, Einträge suchen, Fehler mit eigenen Nummern melden —,
 * bleibt in seiner Umsetzung. Was hier steht, ist das, was die Prüfung
 * braucht.
 *
 * **Der volle Name, nicht Zone und Präfix getrennt.** Wie ein Anbieter den
 * Namen zerlegt, ist seine Sache und nicht die des Aufrufers: Bei IPv64.net
 * ist die Zone häufig selbst eine Unterdomain (`meinname.ipv64.net`), und wer
 * die Zone aus dem Namen errechnet, liegt dort falsch (`docs/34 §6`). Die API
 * kennt ihre Zonen — sie wird gefragt.
 *
 * **`remove()` bekommt den Wert mit.** Mehrere Zertifikate für dieselbe Zone
 * können gleichzeitig laufen, und dann stehen zwei `_acme-challenge`-Einträge
 * nebeneinander. Wer nur nach dem Namen löscht, räumt die Prüfung eines
 * anderen Vorgangs mit ab — und der scheitert dann an einer Ursache, die
 * nirgends steht.
 */
interface DnsProvider
{
    /**
     * Den Eintrag anlegen.
     *
     * @param  string  $record  Der volle Name, etwa `_acme-challenge.example.de`
     * @param  string  $value  Der Wert, den die Zertifizierungsstelle sehen will
     */
    public function add(string $record, string $value): void;

    /**
     * Und wieder abräumen.
     *
     * **Ein liegengebliebener Eintrag ist eine Aussage über diese Zone, die
     * niemand mehr zurücknimmt.** Deshalb läuft dieser Aufruf auch, wenn die
     * Bestellung gescheitert ist — er darf einen Fehlschlag nicht in einen
     * zweiten verwandeln.
     */
    public function remove(string $record, string $value): void;
}
