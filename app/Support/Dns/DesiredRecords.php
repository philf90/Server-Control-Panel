<?php

declare(strict_types=1);

namespace App\Support\Dns;

/**
 * Was eine Domain haben muss, damit sie hier funktioniert — die eine Stelle.
 *
 * **Warum es diese Klasse gibt.** Der Sollzustand ist die Hälfte des Abgleichs
 * (`docs/72 §2.1`), und er ist genau die Art von Regel, die dieses Projekt
 * sonst zweimal schreibt: einmal in der Ansicht, die ihn anzeigt, und einmal
 * in dem Vorgang, der ihn prüft. Die zweite ist die, die veraltet.
 *
 * **Die Regel in einem Satz:** Jede Zeile in `domains` — Haupt-, Zusatzdomain,
 * Subdomain und Alias — wird von nginx unter ihrem eigenen Namen bedient
 * (`Site::serverNames()` ist `array_merge([$domain], $aliases)`) und braucht
 * deshalb Adressen auf genau diesen Namen. Kein Sonderfall, kein `www`.
 *
 * **Ein automatisches `www` gibt es in diesem Panel nicht**, und der Plan
 * behauptete bis zum 21. August das Gegenteil. Wer es will, legt es als Alias
 * an — und dann steht es von selbst hier, weil ein Alias eine eigene Zeile ist.
 *
 * **CAA steht nicht im Sollzustand.** Es wird gelesen und nicht gefordert
 * (`docs/72 §2.4`): Kein CAA ist der richtige Zustand, und ein Sollwert dafür
 * wäre eine Forderung, die das Panel nicht stellen will.
 *
 * **Diese Klasse kennt keinen Bestand und keine Datenbank.** Sie bekommt einen
 * Namen und die Adressen dieses Servers und gibt zurück, was daraus folgt —
 * damit lässt sie sich ohne Laravel prüfen, und der Durchgang misst die Regel
 * statt eines Modells.
 */
final class DesiredRecords
{
    /**
     * Der Sollzustand für einen Namen.
     *
     * **Ohne IPv6 des Servers entsteht kein `AAAA`-Eintrag** — und nicht etwa
     * einer mit leerer Erwartung. Der Unterschied ist der zwischen „hier fehlt
     * etwas" und „danach wird nicht gefragt"; das Abnahmekriterium
     * (`docs/72 §3`, Punkt 5) hängt genau daran.
     *
     * @param  list<string>  $addresses  Die öffentlichen Adressen dieses Servers
     * @return list<array{name: string, type: string, expected: list<string>}>
     */
    public static function for(string $name, array $addresses): array
    {
        $name = self::name($name);

        if ($name === '') {
            return [];
        }

        $desired = [];

        foreach (['A' => false, 'AAAA' => true] as $type => $sechs) {
            $expected = self::canonical($addresses, $sechs);

            if ($expected !== []) {
                $desired[] = ['name' => $name, 'type' => $type, 'expected' => $expected];
            }
        }

        return $desired;
    }

    /**
     * Derselbe Sollzustand für mehrere Namen, in einem Zug.
     *
     * @param  list<string>  $names
     * @param  list<string>  $addresses
     * @return list<array{name: string, type: string, expected: list<string>}>
     */
    public static function forAll(array $names, array $addresses): array
    {
        $desired = [];
        $seen = [];

        foreach ($names as $name) {
            $normalized = self::name($name);

            // Zwei Zeilen mit demselben Namen gibt es nicht, aber eine Liste,
            // die von zwei Stellen zusammengesetzt wurde, kann es. Dieselbe
            // Frage zweimal zu stellen kostet einen fremden Nameserver.
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;

            foreach (self::for($normalized, $addresses) as $entry) {
                $desired[] = $entry;
            }
        }

        return $desired;
    }

    /**
     * Die Adressen einer Familie, in ihrer vereinheitlichten Schreibweise.
     *
     * **Das ist keine Kosmetik, sondern die Bedingung dafür, dass der Vergleich
     * überhaupt stimmt.** `2001:0db8::0001` und `2001:db8::1` sind dieselbe
     * Adresse und nicht dieselbe Zeichenkette. Die gemessene Seite geht im
     * Agenten durch `inet_ntop` (`Packet::addresses`); die erwartete geht hier
     * hindurch, und erst dann heisst „gleich" auch gleich.
     *
     * **Der Klassenname steht hier absichtlich als Text und nicht als
     * `{@see}`.** Pint macht aus einem ausgeschriebenen Verweis einen `use` —
     * und das wäre eine Abhängigkeit von `app/` auf den Agenten, die es nur im
     * Kommentar gibt.
     *
     * > **Zwei Werte, die dasselbe bedeuten und verschieden geschrieben sind,
     * > ergeben einen Befund, den es nicht gibt.**
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private static function canonical(array $addresses, bool $sechs): array
    {
        $found = [];

        foreach ($addresses as $address) {
            $packed = @inet_pton(trim($address));

            if ($packed === false) {
                continue;
            }

            // Die Familie hängt an der Länge — vier Bytes sind IPv4, sechzehn
            // IPv6. Dieselbe Eigenschaft, die in `Packet::address()` eine
            // Längenprüfung nötig macht, ist hier die Unterscheidung.
            if ((strlen($packed) === 16) !== $sechs) {
                continue;
            }

            $normalized = inet_ntop($packed);

            if ($normalized !== false && ! in_array($normalized, $found, true)) {
                $found[] = $normalized;
            }
        }

        sort($found);

        return $found;
    }

    /** Kleingeschrieben, ohne Leerraum und ohne den abschliessenden Punkt. */
    private static function name(string $name): string
    {
        return strtolower(trim($name, ". \t\n\r\0\x0B"));
    }
}
