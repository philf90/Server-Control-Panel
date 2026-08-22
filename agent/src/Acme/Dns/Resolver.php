<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Fragt die autoritativen Nameserver — und nicht den Anbieter.
 *
 * **Das ist der Grund, aus dem `ready()` überhaupt existiert.** Die API eines
 * DNS-Anbieters sagt „ok", sobald sie den Eintrag entgegengenommen hat;
 * ausgeliefert wird er Sekunden bis Minuten später. Wer der
 * Zertifizierungsstelle zu früh sagt „prüf jetzt", verbrennt einen der fünf
 * Fehlversuche, die eine Stunde halten — und die gelten für das ganze Konto,
 * also für jeden Kunden dieses Servers.
 *
 * **Und nicht der Systemauflöser.** Der antwortet aus seinem Zwischenspeicher,
 * und der kann den Namen von vorhin noch als „gibt es nicht" führen. Gefragt
 * werden deshalb die Nameserver der Zone selbst, jeder einzeln: Erst wenn alle
 * den Wert ausliefern, sieht ihn auch die Zertifizierungsstelle — egal, welchen
 * sie erwischt.
 *
 * **Ein eigener Auflöser und kein `dig`.** Das Programm gehörte auf die
 * Positivliste des Agenten und als Abhängigkeit ins Paket, für eine Frage, die
 * in hundert Zeilen beantwortet ist. Unterhalb von `agent/` gibt es keine
 * Abhängigkeiten, und das bleibt so. Das Drahtformat selbst steht in
 * {@see Packet} — dort ohne Steckdose und damit prüfbar.
 */
final class Resolver implements Lookup
{
    public const PORT = 53;

    /** Wie lange auf eine Antwort gewartet wird. */
    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly int $port = self::PORT) {}

    /**
     * Die Adressen der autoritativen Nameserver für diesen Namen.
     *
     * **Gesucht wird von unten nach oben.** `_acme-challenge.example.de` hat
     * selbst keinen NS-Satz; er steht an der Zone darüber. Gefragt wird
     * deshalb der Reihe nach mit einer Beschriftung weniger, bis eine Antwort
     * kommt — und nicht weiter als bis zu zwei Beschriftungen, damit die Suche
     * nicht bei der öffentlichen Endung landet und deren Server zurückgibt.
     *
     * Für diese eine Frage ist der Systemauflöser richtig: Wo eine Zone liegt,
     * ändert sich nicht im Minutentakt, und die Antwort ist keine, die ein
     * Zwischenspeicher verfälschen kann.
     *
     * @return list<string>
     */
    public function nameservers(string $name): array
    {
        $labels = explode('.', trim($name, '.'));

        while (count($labels) >= 2) {
            $records = @dns_get_record(implode('.', $labels), DNS_NS);

            if (is_array($records) && $records !== []) {
                return $this->addresses($records);
            }

            array_shift($labels);
        }

        return [];
    }

    /**
     * Die Werte, die dieser Server für diesen Namen und Typ ausliefert.
     *
     * Kommt keine Antwort oder keine, die zu dieser Frage gehört, ist das
     * Ergebnis `null` und keine Ausnahme. Wer wie ACME nicht unterscheiden
     * will, schreibt `?? []` daneben — wer wie der Abgleich unterscheiden
     * muss, kann es.
     *
     * **Die Typweiche steht in {@see Packet::values} und nicht hier.** Hier
     * hinge sie an einer Steckdose und wäre ohne Nameserver nicht zu prüfen.
     *
     * @return list<string>|null
     */
    public function records(string $server, string $name, int $type): ?array
    {
        $id = random_int(0, 0xFFFF);
        $answer = $this->ask($server, Packet::query($id, $name, $type));

        return $answer === null ? null : Packet::values($answer, $id, $type);
    }

    /**
     * Die CAA-Sätze, die dieser Server für diesen Namen ausliefert.
     *
     * @return list<array{flags: int, tag: string, value: string}>|null
     */
    public function authorities(string $server, string $name): ?array
    {
        $id = random_int(0, 0xFFFF);
        $answer = $this->ask($server, Packet::query($id, $name, Packet::TYPE_CAA));

        if ($answer === null || Packet::rcode($answer, $id) === null) {
            return null;
        }

        return Packet::authorities($answer, $id);
    }

    /**
     * Die Adressen hinter den NS-Namen.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return list<string>
     */
    private function addresses(array $records): array
    {
        $addresses = [];

        foreach ($records as $record) {
            $target = $record['target'] ?? null;

            if (! is_string($target) || $target === '') {
                continue;
            }

            foreach (@dns_get_record($target, DNS_A) ?: [] as $a) {
                $ip = $a['ip'] ?? null;

                if (is_string($ip) && $ip !== '' && ! in_array($ip, $addresses, true)) {
                    $addresses[] = $ip;
                }
            }
        }

        return $addresses;
    }

    /** Die Frage stellen — über UDP, mit Zeitlimit. */
    private function ask(string $server, string $query): ?string
    {
        $socket = @stream_socket_client(
            sprintf('udp://%s:%d', $server, $this->port),
            $error,
            $message,
            self::TIMEOUT_SECONDS,
        );

        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        $written = @fwrite($socket, $query);
        $answer = $written === false ? false : @fread($socket, 4096);

        fclose($socket);

        return is_string($answer) && $answer !== '' ? $answer : null;
    }
}
