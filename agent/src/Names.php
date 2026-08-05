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

        /*
         * **Der vollständige Name zuerst — und er steht nicht im Kernel.**
         *
         * `php_uname('n')` liefert den Knotennamen, und der ist auf den
         * meisten Servern der kurze: „cloudsrv24" statt „cloudsrv24.de".
         * Hier stand deshalb genau das Falsche — der Knotenname, und aus ihm
         * *abgeleitet* noch eine Kurzform. Auf einem Server, dessen Knotenname
         * schon kurz ist, kam damit ausschliesslich „cloudsrv24" ins
         * Zertifikat, und wer „cloudsrv24.de" aufruft, bekommt eine Warnung
         * über einen Namen, der nicht passt.
         *
         * **Dasselbe war in `srvpanel setup` schon einmal behoben** — dort
         * steht seit dem ersten Einrichtungslauf ein Kommentar mit genau
         * diesem Beispiel. Eine Regel, die an einer Stelle gelernt und an der
         * nächsten neu erfunden wird, ist keine Regel; deshalb steht sie jetzt
         * hier, und die Einrichtung fragt dieselbe Funktion.
         */
        $node = trim(php_uname('n'));
        $fqdn = self::fqdn($node);

        if ($fqdn !== null) {
            $dns[] = $fqdn;
        }

        if ($node !== '' && self::isHostname($node)) {
            $dns[] = $node;
        }

        // `srv.example.com` ergibt zusätzlich `srv`. Auf einem Server im
        // eigenen Netz ist das der Name, den man tatsächlich eintippt.
        $short = strstr($fqdn ?? $node, '.', true);

        if (is_string($short) && $short !== '' && self::isHostname($short)) {
            $dns[] = $short;
        }

        $dns[] = 'localhost';

        return [
            'dns' => array_values(array_unique($dns)),
            'ip' => self::addresses(),
        ];
    }

    /**
     * Der vollständige Name dieses Rechners — oder `null`.
     *
     * **Drei Quellen, von der billigsten zur teuersten.** Trägt der
     * Knotenname schon einen Punkt, ist er es. Sonst `/etc/hosts`, wo Debian
     * die Zeile `127.0.1.1 cloudsrv24.de cloudsrv24` anlegt. Erst danach eine
     * Rückwärtsauflösung über die Adresse, mit der der Rechner nach aussen
     * spricht — die kostet einen Namensdienst, der auch schweigen kann.
     *
     * **Ein gefundener Name muss den Knotennamen fortsetzen.** Er zählt nur,
     * wenn er mit „<knotenname>." beginnt. Ohne diese Bedingung könnte eine
     * fremde Zeile in `/etc/hosts` oder ein Namensdienst einen beliebigen
     * Namen in das Zertifikat dieses Servers schreiben — und ein Zertifikat
     * ist eine Behauptung darüber, wer man ist.
     */
    public static function fqdn(?string $node = null): ?string
    {
        $node = trim($node ?? php_uname('n'));

        if ($node === '' || ! self::isHostname($node)) {
            return null;
        }

        if (str_contains($node, '.')) {
            return $node;
        }

        $lines = @file('/etc/hosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $found = self::fromHosts(is_array($lines) ? $lines : [], $node);

        if ($found !== null) {
            return $found;
        }

        $address = self::primaryAddress();

        if ($address === null) {
            return null;
        }

        $reverse = gethostbyaddr($address);

        return is_string($reverse) && self::extends($reverse, $node) ? $reverse : null;
    }

    /**
     * Der beste Name, den dieser Rechner für sich hat.
     *
     * Der vollständige, wenn es einen gibt — sonst der Knotenname. Anders als
     * {@see fqdn()} gibt diese Funktion nie `null` zurück: Wer eine Adresse
     * zusammensetzt, braucht **einen** Namen und kann mit „keiner" nichts
     * anfangen.
     *
     * **Warum es sie gibt.** `APP_URL` schrieb `php_uname('n')` — den kurzen
     * Knotennamen — und damit war derselbe Fehler zum dritten Mal im Repo. Die
     * beiden ersten Male ist er einzeln behoben worden; beim dritten stand
     * daneben `fqdn() ?? php_uname('n')`, und das ist wieder eine Stelle, die
     * die Frage selbst beantwortet. Jetzt gibt es dafür eine Funktion, und
     * `HostnameSourceTest` lässt `php_uname('n')` sonst nirgends mehr zu.
     */
    public static function host(): string
    {
        return self::fqdn() ?? trim(php_uname('n'));
    }

    /**
     * Den vollständigen Namen aus `/etc/hosts` lesen.
     *
     * Getrennt, damit die Regel prüfbar ist, ohne die Datei des Systems zu
     * fälschen — sie ist der Teil, der schiefgehen kann.
     *
     * @param  list<string>  $lines
     */
    public static function fromHosts(array $lines, string $node): ?string
    {
        foreach ($lines as $line) {
            /*
             * Hier stand `trim(strstr($line, '#', true) ?: $line)`, und eine
             * vollständig auskommentierte Zeile kam damit trotzdem durch:
             * `strstr` liefert für „# 127.0.1.1 …" die leere Zeichenkette, und
             * die ist unwahr — `?:` griff und nahm die ganze Zeile samt Raute.
             * Wer eine Zeile auskommentiert, hat einen Grund.
             */
            $hash = strpos($line, '#');
            $line = trim($hash === false ? $line : substr($line, 0, $hash));

            if ($line === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $line) ?: [];

            // Feld eins ist die Adresse, alles Weitere sind Namen. Eine Zeile
            // zählt nur, wenn der Knotenname darauf steht — sonst wäre jeder
            // fremde Eintrag ein Name für diesen Rechner.
            $names = array_slice($fields, 1);

            if (! in_array($node, $names, true)) {
                continue;
            }

            foreach ($names as $name) {
                if (self::extends($name, $node)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** Setzt `$name` den Knotennamen fort — `cloudsrv24.de` zu `cloudsrv24`? */
    private static function extends(string $name, string $node): bool
    {
        return $name !== $node
            && str_starts_with($name, $node.'.')
            && self::isHostname($name);
    }

    /**
     * Die Adresse, über die dieser Rechner nach aussen spricht.
     *
     * Über einen verbundenen UDP-Socket: Das schickt kein einziges Paket — der
     * Kernel wählt beim `connect` nur die Route aus und trägt die passende
     * Quelladresse ein, und die lesen wir zurück. Der Weg über
     * `gethostbyname(gethostname())` liefert dagegen auf vielen Servern
     * 127.0.1.1, weil genau das in /etc/hosts steht.
     */
    public static function primaryAddress(): ?string
    {
        if (! function_exists('socket_create')) {
            return null;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            return null;
        }

        // Dokumentationsadresse nach RFC 5737 — sie wird nie erreicht und soll
        // es auch nicht.
        $connected = @socket_connect($socket, '203.0.113.1', 53);
        $address = null;

        if ($connected && @socket_getsockname($socket, $local)) {
            $address = $local;
        }

        socket_close($socket);

        return is_string($address) && $address !== '' && ! str_starts_with($address, '127.')
            ? $address
            : null;
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
            && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.\-]*[a-zA-Z0-9])?$/D', $value) === 1;
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
