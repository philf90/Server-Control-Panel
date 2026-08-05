<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Wie ein Server beweist, dass ihm eine Domain gehört.
 *
 * **Der Ablauf einer Bestellung ist für alle Arten derselbe** — bestellen,
 * Autorisierungen holen, Aufgabe erfüllen, prüfen lassen, abwarten,
 * unterschreiben lassen, abholen. Verschieden sind genau zwei Schritte:
 * hinlegen und wieder abräumen. Deshalb steht hier eine Schnittstelle und in
 * {@see Order} ein Ablauf ohne Fallunterscheidung.
 *
 * Das ist keine Vorratshaltung: HTTP-01 ist der erste Wurf, DNS-01 mit mehreren
 * Anbietern der zweite, und ohne diese Naht wäre der zweite ein Umbau an der
 * Stelle, an der ein Fehler ein Zertifikat kostet.
 *
 * **`ready()` gibt es, obwohl HTTP-01 es nicht braucht.** Bei HTTP-01 liegt die
 * Datei, sobald sie geschrieben ist. Ein TXT-Eintrag dagegen ist nicht da, weil
 * die API des Anbieters „ok" gesagt hat, sondern erst, wenn die autoritativen
 * Nameserver ihn ausliefern — und wer der Prüfstelle zu früh sagt „prüf jetzt",
 * verbrennt einen der fünf Fehlversuche, die eine Stunde halten. Diesen Schritt
 * nachträglich einzuziehen hiesse, die Form jeder Operation zu ändern, die eine
 * Bestellung fährt.
 */
interface Challenge
{
    /** So heisst die Art in der Antwort der Zertifizierungsstelle — etwa `http-01`. */
    public function type(): string;

    /**
     * Die Aufgabe hinlegen.
     *
     * @param  string  $keyAuthorization  `<token>.<Fingerabdruck des Kontoschlüssels>`
     */
    public function present(string $domain, string $token, string $keyAuthorization): void;

    /** Ist die Aufgabe von aussen sichtbar? */
    public function ready(string $domain, string $token, string $keyAuthorization): bool;

    /**
     * Wieder abräumen.
     *
     * Läuft auch, wenn die Bestellung gescheitert ist — eine liegengebliebene
     * Prüfdatei oder ein liegengebliebener TXT-Eintrag ist eine Aussage über
     * diesen Server, die niemand mehr zurücknimmt.
     */
    public function cleanup(string $domain, string $token): void;
}
