<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Der Weg zur Zertifizierungsstelle — in der Form, die ACME braucht.
 *
 * **Die Grenze nach draussen steht in {@see Curl}**, samt ihrer vier Zusagen:
 * nur https, keine Umleitungen, gedeckelte Antwort, Zeitlimit. Hier steht nur
 * noch, was an ACME eigen ist — und das sind zwei Dinge.
 *
 * **Nur zwei Verben, weil ACME nur zwei kennt:** `GET` für das Verzeichnis und
 * den Nonce, `POST` für alles andere. Was aussieht wie ein Lesezugriff auf eine
 * geschützte Ressource, ist in ACME ein signiertes POST mit leerem Rumpf
 * (POST-as-GET) — deshalb gibt es hier kein drittes Verb.
 *
 * **Und `application/jose+json` beim Schreiben.** Der Rumpf ist ein fertig
 * signiertes JWS; eine Zertifizierungsstelle, die etwas anderes angekündigt
 * bekommt, weist es ab.
 */
final class CurlTransport implements Transport
{
    public function __construct(private readonly Outbound $curl = new Curl) {}

    public function get(string $url): Response
    {
        return $this->curl->send('GET', $url, ['Accept: application/json']);
    }

    /**
     * @param  string  $body  Das fertig signierte JWS
     */
    public function post(string $url, string $body): Response
    {
        return $this->curl->send(
            'POST',
            $url,
            ['Accept: application/json', 'Content-Type: application/jose+json'],
            $body,
        );
    }
}
