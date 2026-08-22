<?php

declare(strict_types=1);

namespace App\Support\Dns;

/**
 * Die Adressen, auf die eine Kundendomain zeigen soll.
 *
 * **Abgeleitet, mit Übersteuerung** (`docs/72 §2.1a`). Der Server kennt die
 * Adressen seiner Schnittstellen; was davon aus dem Netz erreichbar ist, weiss
 * er nicht sicher. Hinter NAT, einer Floating-IP oder einem Lastverteiler ist
 * die öffentliche Adresse von innen gar nicht zu erfahren.
 *
 * **Ohne Übersteuerung wäre das schlimmer als keine Anzeige.** Der Abgleich
 * meldete dort jede Domain als „zeigt woandershin" — mit der **privaten**
 * Adresse als Sollwert. Das sieht aus wie eine Auskunft.
 *
 * **Ohne Ableitung ginge der Abgleich vor dem ersten Eintrag gar nicht**, und
 * das träfe ausgerechnet die Ersteinrichtung: den Zeitpunkt, an dem jemand am
 * ehesten wissen will, ob seine Domain schon hierher zeigt.
 *
 * **Und der Preis der Übersteuerung gehört sichtbar:** Sie ist eine im Panel
 * gemerkte Fassung eines Serverzustands, und die kann veralten.
 *
 * > **Eine im Panel gemerkte Fassung eines Serverzustands ist die, die
 * > veraltet** — derselbe Satz, der in `Settings` schon über `bind-address`
 * > und den PostgreSQL-Schalter steht. Er verbietet die Übersteuerung nicht;
 * > er verlangt, dass man sieht, wenn sie nicht mehr stimmt.
 */
final class ServerAddresses
{
    /**
     * Was gilt: das Eingetragene, sonst das Abgeleitete.
     *
     * **Die Übersteuerung wird hier nicht gefiltert.** Sie ist beim Eintragen
     * geprüft worden ({@see rejected}), und wer sie hier noch einmal siebte,
     * hätte zwei Fassungen derselben Regel — und die zweite entschiede
     * stillschweigend anders, als die Meldung am Formular gesagt hat.
     *
     * @param  list<string>  $derived  Was der Server an seinen Schnittstellen führt
     * @param  list<string>  $override  Was der Betreiber eingetragen hat; leer heisst „nimm die abgeleiteten"
     * @return list<string>
     */
    public static function effective(array $derived, array $override): array
    {
        $chosen = self::canonical($override);

        return $chosen !== [] ? $chosen : self::routable($derived);
    }

    /**
     * Die Adressen, unter denen dieser Server aus dem Netz erreichbar sein kann.
     *
     * **Gemessen, nicht nachgelesen** (21. August 2026): `filter_var` mit
     * `NO_PRIV_RANGE|NO_RES_RANGE` wirft `10/8`, `172.16/12`, `192.168/16`,
     * `169.254/16`, `127/8`, `0.0.0.0`, `fc00::/7`, `fe80::/10` und `::1`
     * hinaus — und lässt **CGNAT (`100.64/10`) und Multicast durch.**
     *
     * Beides gehört hier heraus: Ein Server hinter CGNAT ist von aussen nicht
     * erreichbar, seine Adresse als `A`-Eintrag wäre ein Sollwert, den keine
     * Domain je erfüllen kann. Multicast steht nie auf einer Schnittstelle —
     * aber im eingetragenen Feld kann es stehen, und dort wird geprüft.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    public static function routable(array $addresses): array
    {
        $found = [];

        foreach ($addresses as $address) {
            if (self::rejected($address) !== null) {
                continue;
            }

            $normalized = self::normalize($address);

            if ($normalized !== null && ! in_array($normalized, $found, true)) {
                $found[] = $normalized;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Warum diese Adresse hier nicht taugt — oder `null`, wenn sie taugt.
     *
     * **Der Satz ist die Meldung am Formular** und deshalb hier und nicht im
     * Controller: Was die Prüfung ablehnt und was der Betreiber liest, sind
     * sonst zwei Fassungen derselben Regel.
     */
    public static function rejected(string $address): ?string
    {
        $address = trim($address);

        if ($address === '') {
            return 'Die Adresse ist leer.';
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'Das ist keine IP-Adresse.';
        }

        if (self::multicast($address)) {
            return 'Das ist eine Multicast-Adresse — unter ihr bedient kein Server eine Website.';
        }

        if (self::carrierGrade($address)) {
            return 'Diese Adresse liegt im Bereich für Anbieter-NAT (100.64.0.0/10) und ist aus dem Netz nicht erreichbar.';
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Diese Adresse ist privat oder für besondere Zwecke vergeben — aus dem Netz zeigt niemand darauf.';
        }

        return null;
    }

    /**
     * Dieselbe Schreibweise wie auf der gemessenen Seite.
     *
     * `2001:0db8::0001` und `2001:db8::1` sind dieselbe Adresse und nicht
     * dieselbe Zeichenkette. Verglichen wird später als Zeichenkette.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private static function canonical(array $addresses): array
    {
        $found = [];

        foreach ($addresses as $address) {
            $normalized = self::normalize($address);

            if ($normalized !== null && ! in_array($normalized, $found, true)) {
                $found[] = $normalized;
            }
        }

        sort($found);

        return $found;
    }

    private static function normalize(string $address): ?string
    {
        $packed = @inet_pton(trim($address));

        if ($packed === false) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }

    /** `224.0.0.0/4` und `ff00::/8`. */
    private static function multicast(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        return strlen($packed) === 4
            ? (ord($packed[0]) & 0xF0) === 0xE0
            : ord($packed[0]) === 0xFF;
    }

    /** `100.64.0.0/10` — Anbieter-NAT, von aussen nicht erreichbar. */
    private static function carrierGrade(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 4) {
            return false;
        }

        return ord($packed[0]) === 100 && (ord($packed[1]) & 0xC0) === 0x40;
    }
}
