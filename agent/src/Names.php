<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Unter welchen Namen ist dieser Server erreichbar?
 *
 * **Warum das eine eigene Datei ist.** Die Frage stellt sich an zwei Stellen
 * und muss an beiden dieselbe Antwort geben: beim Ausstellen des Zertifikats
 * (was kommt in den subjectAltName) und beim Prüfen eines vorhandenen (deckt
 * es noch ab, was gebraucht wird). Stünde sie zweimal da, entstünde
 * irgendwann ein Zertifikat, das die eine Stelle für gültig hält und die
 * andere jeden Tag neu ausstellt.
 *
 * Gelesen wird über `net_get_interfaces()` und nicht über das Programm `ip`:
 * ein Programm weniger auf der Positivliste des Agenten.
 */
final class Names
{
    /**
     * Die Namen dieses Rechners für ein Zertifikat.
     *
     * **Der Hostname steht vorn**, weil er auch der CommonName wird — nicht
     * weil ein Browser ihn noch liest, sondern weil jedes Werkzeug, das ein
     * Zertifikat anzeigt, ihn als Überschrift nimmt.
     *
     * **`localhost` und die Loopback-Adressen gehören dazu.** Die
     * Bereitschaftsprüfung des Pakets ruft `https://127.0.0.1:<port>/health`
     * auf, und sie soll das eines Tages ohne `-k` tun können.
     *
     * @return array{dns: list<string>, ip: list<string>}
     */
    public static function forThisHost(): array
    {
        $dns = [];

        $host = trim(php_uname('n'));

        if ($host !== '' && self::isHostname($host)) {
            $dns[] = $host;

            // `srv.example.com` ergibt zusätzlich `srv`. Auf einem Server im
            // eigenen Netz ist das der Name, den man tatsächlich eintippt.
            $short = strstr($host, '.', true);

            if (is_string($short) && $short !== '' && self::isHostname($short)) {
                $dns[] = $short;
            }
        }

        $dns[] = 'localhost';

        return [
            'dns' => array_values(array_unique($dns)),
            'ip' => self::addresses(),
        ];
    }

    /**
     * Die Adressen aller Schnittstellen.
     *
     * **Ohne die link-lokalen.** `169.254.0.0/16` und `fe80::/10` vergibt ein
     * Rechner sich selbst, wenn er sonst nichts bekommt; sie ändern sich, und
     * unter ihnen ruft niemand ein Panel auf. Sie stünden nur im Zertifikat
     * und wären der Grund, aus dem es jede Woche neu ausgestellt würde.
     *
     * @return list<string>
     */
    public static function addresses(): array
    {
        $interfaces = net_get_interfaces();

        if ($interfaces === false) {
            return ['127.0.0.1'];
        }

        $addresses = [];

        foreach ($interfaces as $interface) {
            foreach ($interface['unicast'] ?? [] as $entry) {
                $address = $entry['address'] ?? null;

                if (! is_string($address) || $address === '') {
                    continue;
                }

                // Eine IPv6-Adresse kommt mit Zonenkennung („%eth0"). In ein
                // Zertifikat gehört sie ohne.
                $address = strstr($address, '%', true) ?: $address;

                if (! self::usable($address)) {
                    continue;
                }

                $addresses[] = $address;
            }
        }

        sort($addresses);

        return array_values(array_unique($addresses));
    }

    /**
     * Die Namen, die ein Zertifikat abdeckt.
     *
     * Gelesen aus dem subjectAltName. Der CommonName steht ausdrücklich nicht
     * darin: Kein Browser liest ihn noch, und ihn hier mitzuzählen hiesse, ein
     * Zertifikat für ausreichend zu halten, das im Browser scheitert.
     *
     * @param  array<string, mixed>  $parsed  Ergebnis von openssl_x509_parse()
     * @return array{dns: list<string>, ip: list<string>}
     */
    public static function fromCertificate(array $parsed): array
    {
        $san = $parsed['extensions']['subjectAltName'] ?? '';

        $dns = [];
        $ip = [];

        foreach (explode(',', is_string($san) ? $san : '') as $part) {
            $part = trim($part);

            if (str_starts_with($part, 'DNS:')) {
                $dns[] = substr($part, 4);
            } elseif (str_starts_with($part, 'IP Address:')) {
                $ip[] = substr($part, 11);
            } elseif (str_starts_with($part, 'IP:')) {
                $ip[] = substr($part, 3);
            }
        }

        return ['dns' => $dns, 'ip' => $ip];
    }

    /**
     * Ein Name, der in ein Zertifikat darf.
     *
     * Eng gefasst, und zwar mit Absicht: Der Wert landet in einer
     * Konfigurationsdatei für openssl. Ein Hostname mit einem Zeilenumbruch
     * darin wäre ein Weg, dieser Datei einen weiteren Abschnitt unterzuschieben.
     */
    private static function isHostname(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.\-]*[a-zA-Z0-9])?$/', $value) === 1;
    }

    private static function usable(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Link-lokal: vergibt sich der Rechner selbst, ändert sich, und
        // niemand ruft darunter ein Panel auf.
        return ! str_starts_with($address, '169.254.')
            && preg_match('/^fe[89ab][0-9a-f]:/i', $address) !== 1;
    }
}
