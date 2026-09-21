<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Eine Anfrage nach draussen — und die Naht, an der die Prüfung ansetzt.
 *
 * **Warum es die Schnittstelle gibt und nicht nur {@see Curl}.** Was der Agent
 * nach draussen tut, lässt sich nur gegen ein Drehbuch prüfen: Ein echter
 * Gegenüber in der CI hiesse Netz, Zugangsdaten und eine Ratenbegrenzung, die
 * den Lauf irgendwann sperrt — und keinen Weg, den seltenen Fall absichtlich
 * herzustellen. Genau denselben Dienst leistet {@see Transport} für den
 * ACME-Ablauf; hier ist er eine Ebene tiefer gezogen, damit auch die
 * DNS-Anbieter ihn haben.
 *
 * **Drei Verben, weil die Anbieter drei brauchen.** ACME kommt mit `GET` und
 * `POST` aus; IPv64.net löscht mit `DELETE`, und andere werden `PUT` oder
 * `PATCH` verlangen. Die Methode steht deshalb als Wert da und nicht im Namen
 * der Funktion.
 */
interface Outbound
{
    /**
     * Darf der Agent diese Adresse überhaupt wählen?
     *
     * **Sie steht hier und nicht nur an {@see Curl}, seit B1.** Das Meldeziel
     * wird beim Hinterlegen gegen dieselbe Schranke geprüft, gegen die eine
     * Bestellung geprüft wird — und wer eine eigene Bedingung dafür schriebe,
     * hätte eine zweite Fassung von Zusage 1. Die zweite ist die, die
     * veraltet.
     *
     * > **Grenze 1 lässt nichts anderes zu: Wer eine Adresse nach draussen
     * > wählen darf, wählt sonst auch `http://127.0.0.1:…`.**
     */
    public function permitted(string $url): bool;

    /**
     * @param  list<string>  $headers  Fertige Kopfzeilen, `Name: Wert`
     * @param  string|null  $body  `null` heisst: kein Rumpf
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): Response;
}
