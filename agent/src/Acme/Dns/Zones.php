<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Welche Zone zu einem Namen gehört — die einzige Stelle, die das entscheidet.
 *
 * **Warum es diese Klasse gibt.** Die Regel ist überall dieselbe: Von allen
 * Zonen, in denen ein Name liegt, gewinnt die **längste**. Führt jemand
 * `example.de` und `kunde.example.de` beim selben Anbieter, gehört
 * `_acme-challenge.kunde.example.de` in die engere. Wer die weitere nimmt, legt
 * den Eintrag in der falschen Zone an — und das ist kein Fehler, den irgendetwas
 * meldet: Der Anbieter nimmt ihn an, und die Prüfung findet ihn nur nie.
 *
 * Diese Regel stand bei RFC 2136 und bei IPv64.net wörtlich zweimal da, jedes
 * Mal als eigene Schleife. Mit Hetzner wäre sie zum dritten Mal geschrieben
 * worden. Genau das ist der Fehler, an dem dieses Projekt am häufigsten
 * verloren hat — derselbe Gedanke an mehreren Orten, und der zweite ist der,
 * der veraltet. `ZoneSourceTest` besteht darauf, dass {@see Name::within} nur
 * von hier gefragt wird.
 *
 * **Woher die Liste kommt, bleibt Sache des Anbieters.** Bei RFC 2136 steht sie
 * in den Zugangsdaten, weil ein TSIG-Schlüssel im Nameserver ohnehin auf Zonen
 * eingegrenzt ist; bei einer API-Schnittstelle wird der Anbieter gefragt. Was
 * hier steht, ist nur der Abgleich — und die Meldung an den Betreiber bleibt
 * ebenfalls draussen, weil „in den Zugangsdaten steht keine Zone dafür" und
 * „das Konto führt keine solche Zone" zwei verschiedene Sätze sind.
 */
final class Zones
{
    /**
     * Die längste Zone, in der dieser Name liegt — oder `null`.
     *
     * @param  list<string>  $known
     */
    public static function pick(string $record, array $known): ?string
    {
        $found = null;

        foreach ($known as $zone) {
            if (Name::within($record, $zone) && ($found === null || strlen($zone) > strlen($found))) {
                $found = $zone;
            }
        }

        return $found === null ? null : self::normalize($found);
    }

    /**
     * Was vor der Zone steht — leer, wenn der Name die Zone selbst ist.
     *
     * **Gerechnet wird auf der vereinheitlichten Schreibweise.** `Example.DE.`
     * und `example.de` sind derselbe Name; wer die Länge der einen von der
     * anderen abzieht, schneidet an der falschen Stelle und bekommt einen
     * Präfix, der um einen Punkt danebenliegt.
     */
    public static function prefix(string $record, string $zone): string
    {
        $name = self::normalize($record);
        $zone = self::normalize($zone);

        if ($name === $zone) {
            return '';
        }

        return substr($name, 0, -(strlen($zone) + 1));
    }

    /** Kleingeschrieben, ohne Leerraum und ohne den abschliessenden Punkt. */
    private static function normalize(string $name): string
    {
        return strtolower(trim($name, ". \t\n\r\0\x0B"));
    }
}
