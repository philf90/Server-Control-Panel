<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Der Weg nach draussen — und die Naht, an der die Prüfung ansetzt.
 *
 * **Warum das eine Schnittstelle ist.** Der ACME-Ablauf besteht aus einem
 * Dutzend Anfragen, die in einer bestimmten Reihenfolge stehen müssen, und aus
 * Antworten, auf die er unterschiedlich reagiert — ein verbrauchter Nonce,
 * eine Bestellung, die noch nicht fertig ist, eine Prüfung, die scheitert. Das
 * gegen einen echten Server zu prüfen hiesse: Netz in der CI, eine
 * Ratenbegrenzung, die den Lauf sperrt, und kein Weg, den seltenen Fall
 * absichtlich herzustellen. Gegen ein Drehbuch geprüft läuft dasselbe hier im
 * Container, offline, in Millisekunden — und der seltene Fall ist eine Zeile.
 *
 * Nur zwei Verben, weil ACME nur zwei kennt: `GET` für das Verzeichnis und den
 * Nonce, `POST` für alles andere. Was aussieht wie ein Lesezugriff auf eine
 * geschützte Ressource, ist in ACME ein signiertes POST mit leerem Rumpf
 * (POST-as-GET) — deshalb steht hier kein drittes Verb.
 */
interface Transport
{
    public function get(string $url): Response;

    /**
     * @param  string  $body  Das fertig signierte JWS
     */
    public function post(string $url, string $body): Response;
}
