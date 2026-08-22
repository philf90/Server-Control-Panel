<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Das Drahtformat einer DNS-Frage und ihrer Antwort (RFC 1035).
 *
 * **Getrennt vom {@see Resolver}, und zwar wegen der Prüfbarkeit.** Was hier
 * steht, sind zwei reine Umformungen: aus einem Namen wird ein Paket, aus einem
 * Paket werden Werte. Sie lassen sich mit gebauten Paketen prüfen — ohne Netz,
 * ohne Nameserver, ohne Wartezeit. Der Teil, der eine Steckdose braucht, bleibt
 * im Resolver und ist eine Handvoll Zeilen.
 *
 * **Der Name selbst steht in {@see Name}.** Dort wohnt der Fehler, den man
 * nicht sieht — der zusammengefasste Name aus §4.1.4 —, und dort wohnt er
 * einmal: Auch die TSIG-Unterschrift und die Aktualisierung schreiben Namen,
 * und drei Fassungen desselben Gedankens sind in diesem Projekt der teuerste
 * aller Fehler.
 *
 * **Seit P7 sind es vier Satztypen und nicht mehr einer.** Bis dahin stand hier
 * „TXT — der einzige Satztyp, den diese Prüfung braucht", und für ACME stimmte
 * das. Der Abgleich aus `docs/72` fragt zusätzlich nach `A`, `AAAA` und `CAA`
 * — **ausschliesslich lesend**. Geschrieben wird weiterhin nur TXT, und zwar in
 * {@see UpdateMessage}.
 *
 * **Die Satzwanderung steht einmal da** ({@see rdata}), und die vier Decoder
 * sitzen darauf. Vier Schleifen über dasselbe Paket wären vier Gelegenheiten,
 * die Prüfung auf das Paketende zu vergessen — und ein halb gelesener Satz
 * ergibt einen Wert, der fast stimmt.
 */
final class Packet
{
    public const TYPE_A = 1;

    public const TYPE_TXT = 16;

    public const TYPE_AAAA = 28;

    public const TYPE_CAA = 257;

    public const CLASS_IN = 1;

    /** Die Antwort war brauchbar und der Name existiert. */
    public const RCODE_NOERROR = 0;

    /** Den Namen gibt es nicht — für den Abgleich dasselbe wie „kein Satz". */
    public const RCODE_NXDOMAIN = 3;

    /** So viele Bytes hat eine Adresse, je nach Familie. */
    private const ADDRESS_BYTES = [self::TYPE_A => 4, self::TYPE_AAAA => 16];

    /**
     * Eine Frage nach den Sätzen eines Namens.
     *
     * **Ohne Rekursionswunsch.** Gefragt wird ein Server, der die Zone selbst
     * führt; er soll aus seinem eigenen Bestand antworten und nicht anderswo
     * nachsehen.
     *
     * **Der Typ steht als Wert da und hat keine Vorgabe.** Eine geerbte Vorgabe
     * wäre die, die beim nächsten Aufrufer falsch ist — und ein Aufruf, der
     * nicht sagt, wonach er fragt, ist bei vier Typen nicht mehr zu lesen.
     */
    public static function query(int $id, string $name, int $type): string
    {
        return pack('n6', $id, 0, 1, 0, 0, 0)
            .Name::encode($name)
            .pack('n2', $type, self::CLASS_IN);
    }

    /**
     * Der Antwortcode — oder `null`, wenn die Antwort nicht brauchbar ist.
     *
     * **Der Abgleich aus `docs/72 §2.3` braucht drei Zustände**, und ohne
     * diesen hier hätte er zwei: „kein Satz gefunden" sähe genauso aus wie
     * „nicht erreichbar". `null` heisst hier ausdrücklich das Zweite — es kam
     * keine Antwort an, die zu dieser Frage gehört.
     *
     * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
     * > beiden.**
     */
    public static function rcode(string $answer, int $id): ?int
    {
        $header = self::header($answer, $id);

        return $header === null ? null : ($header['flags'] & 0xF);
    }

    /**
     * Die TXT-Werte aus einer Antwort.
     *
     * Passt etwas nicht — zu kurz, falsche Kennung, abgeschnitten —, ist die
     * Antwort eine leere Liste und keine Ausnahme. Der Aufrufer wartet dann
     * weiter; das ist genau das Verhalten, das ein noch nicht ausgelieferter
     * Eintrag verlangt.
     *
     * @return list<string>
     */
    public static function txt(string $answer, int $id): array
    {
        $values = [];

        foreach (self::rdata($answer, $id, self::TYPE_TXT) as $data) {
            $text = self::text($data);

            if ($text !== '') {
                $values[] = $text;
            }
        }

        return $values;
    }

    /**
     * Die Adressen aus einer Antwort, in ihrer üblichen Schreibweise.
     *
     * **`inet_ntop` und keine eigene Umformung.** Für IPv4 wäre sie vier
     * Zeilen; für IPv6 ist sie die Regel, wann `::` gesetzt werden darf und
     * wann nicht (RFC 5952), und die schreibt man einmal falsch und merkt es
     * an einer Adresse, die es so nur bei einem Kunden gibt. Die Funktion ist
     * Kern-PHP und keine Abhängigkeit.
     *
     * **Der Nebeneffekt ist der eigentliche Grund:** Zwei Adressen, die beide
     * durch `inet_ntop` gegangen sind, lassen sich als Zeichenketten
     * vergleichen. `2001:db8::1` und `2001:0db8:0000::0001` sind dieselbe
     * Adresse und nicht dieselbe Zeichenkette.
     *
     * @param  int  $type  {@see TYPE_A} oder {@see TYPE_AAAA}
     * @return list<string>
     */
    public static function addresses(string $answer, int $id, int $type): array
    {
        $found = [];

        foreach (self::rdata($answer, $id, $type) as $data) {
            $address = self::address($data, $type);

            if ($address !== null && ! in_array($address, $found, true)) {
                $found[] = $address;
            }
        }

        return $found;
    }

    /**
     * Die CAA-Sätze aus einer Antwort (RFC 8659).
     *
     * **Gelesen und nie geschrieben.** Das Panel setzt kein CAA; es meldet den
     * einen Fall, der etwas kostet — ein Satz, der die eigene
     * Zertifizierungsstelle nicht nennt, lässt jede Bestellung scheitern, und
     * jeder Fehlversuch zählt bei Let's Encrypt fünf pro Konto und Stunde, für
     * jeden Kunden dieses Servers.
     *
     * @return list<array{flags: int, tag: string, value: string}>
     */
    public static function authorities(string $answer, int $id): array
    {
        $found = [];

        foreach (self::rdata($answer, $id, self::TYPE_CAA) as $data) {
            $entry = self::authority($data);

            if ($entry !== null) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * Der geprüfte Kopf einer Antwort — oder `null`.
     *
     * Zusammengezogen, weil {@see rcode} und {@see rdata} dieselben drei
     * Bedingungen prüfen und zwei Fassungen davon beim nächsten Umbau
     * auseinanderlaufen.
     *
     * @return array<string, int>|null
     */
    private static function header(string $answer, int $id): ?array
    {
        if (strlen($answer) < 12) {
            return null;
        }

        /** @var array<string, int>|false $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $answer);

        if ($header === false) {
            return null;
        }

        // **Die Kennung wird geprüft.** Über UDP kann alles ankommen; eine
        // Antwort auf eine andere Frage ist hier keine Antwort.
        if ($header['id'] !== $id) {
            return null;
        }

        // Abgeschnitten: Die Antwort passte nicht in ein UDP-Paket. Für ACME
        // ist das ein „noch nicht" — der Aufrufer fragt gleich noch einmal.
        // Für den Abgleich ist es „keine brauchbare Antwort", und beides
        // führt hierher.
        if (($header['flags'] & 0x0200) !== 0) {
            return null;
        }

        return $header;
    }

    /**
     * Die Rohdaten aller Sätze dieses Typs im **Antwortabschnitt**.
     *
     * **Nur der Antwortabschnitt, und das ist Absicht.** Ein Paket führt
     * daneben noch Autorität und Zusatzangaben, und dort stehen Sätze zu
     * anderen Namen — ein `A` des Nameservers etwa. Wer über das ganze Paket
     * liefe, bekäme dessen Adresse als Antwort auf die Frage nach der Domain.
     *
     * **Der Eigentümername wird nicht verglichen.** Das ist ebenfalls Absicht:
     * Zeigt `www` über ein `CNAME` woandershin, stehen die `A`-Sätze des Ziels
     * in derselben Antwort und unter dessen Namen. Gefragt war, wohin der Name
     * am Ende auflöst — und genau das steht dann da.
     *
     * @return list<string>
     */
    private static function rdata(string $answer, int $id, int $type): array
    {
        $header = self::header($answer, $id);

        if ($header === null) {
            return [];
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            Name::skip($answer, $offset);
            $offset += 4;
        }

        $found = [];

        for ($i = 0; $i < $header['an']; $i++) {
            Name::skip($answer, $offset);

            if ($offset + 10 > strlen($answer)) {
                return $found;
            }

            /** @var array<string, int>|false $meta */
            $meta = unpack('ntype/nclass/Nttl/nlength', substr($answer, $offset, 10));
            $offset += 10;

            if ($meta === false) {
                return $found;
            }

            // **Ein Satz, der über das Paket hinausragt, wird nicht halb
            // gelesen.** Sonst käme ein abgeschnittener Wert heraus, der fast
            // stimmt — und „fast" heisst hier: Die Prüfung schlägt fehl, und
            // im Protokoll steht ein Wert, der richtig aussieht.
            if ($offset + $meta['length'] > strlen($answer)) {
                return $found;
            }

            $data = substr($answer, $offset, $meta['length']);
            $offset += $meta['length'];

            if ($meta['type'] === $type && $meta['class'] === self::CLASS_IN) {
                $found[] = $data;
            }
        }

        return $found;
    }

    /**
     * Der Inhalt eines TXT-Satzes.
     *
     * Er besteht aus einer oder mehreren Zeichenketten mit vorangestellter
     * Länge. Für die Prüfung von ACME ist es genau eine — zusammengesetzt wird
     * trotzdem, weil ein Anbieter lange Werte aufteilen darf.
     */
    private static function text(string $data): string
    {
        $text = '';
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $piece = ord($data[$offset]);
            $text .= substr($data, $offset + 1, $piece);
            $offset += 1 + $piece;
        }

        return $text;
    }

    /**
     * Eine Adresse aus ihren Bytes — oder `null`, wenn es nicht passt.
     *
     * **Die Länge wird vorher geprüft und nicht `inet_ntop` überlassen — und
     * der Grund ist schärfer, als er zuerst aussah.** Die Funktion weist nicht
     * etwa jede falsche Länge ab: Sie entscheidet die **Adressfamilie an der
     * Länge**. Gemessen am 21. August 2026: 3, 5, 15 und 0 Bytes ergeben
     * `false` — 4 und 16 Bytes ergeben immer eine Adresse. Ein `A`-Satz mit
     * sechzehn Bytes käme damit als IPv6-Adresse zurück und ein `AAAA` mit
     * vieren als IPv4.
     *
     * Das ist kein Fehler, den man sieht, sondern ein **Wert, der falsch ist
     * und richtig aussieht** — und genau der schickt den Kunden dorthin, wo
     * nichts zu ändern ist.
     *
     * > **Eine Umformung, die aus der Länge auf die Bedeutung schliesst, hat
     * > keinen Fehlerfall für die falsche Länge — sie hat ein anderes
     * > Ergebnis.**
     */
    private static function address(string $data, int $type): ?string
    {
        $expected = self::ADDRESS_BYTES[$type] ?? null;

        if ($expected === null || strlen($data) !== $expected) {
            return null;
        }

        $address = inet_ntop($data);

        return $address === false ? null : $address;
    }

    /**
     * Ein CAA-Satz aus seinen Bytes — oder `null`, wenn es nicht passt.
     *
     * Der Aufbau ist ein Flag-Byte, eine Marke mit vorangestellter Länge und
     * der Rest als Wert (RFC 8659 §4.1). Eine Marke der Länge 0 gibt es nicht,
     * und eine, die über den Satz hinausreicht, ebenfalls nicht — beide sind
     * hier kein Ergebnis statt eines falschen.
     *
     * @return array{flags: int, tag: string, value: string}|null
     */
    private static function authority(string $data): ?array
    {
        if (strlen($data) < 2) {
            return null;
        }

        $flags = ord($data[0]);
        $tagLength = ord($data[1]);

        if ($tagLength === 0 || 2 + $tagLength > strlen($data)) {
            return null;
        }

        return [
            'flags' => $flags,
            // **Kleingeschrieben.** Die Marke ist laut RFC 8659 §4.1
            // unabhängig von der Schreibweise; wer sie so vergleicht, wie sie
            // ankommt, übersieht ein `ISSUE` und meldet „kein CAA".
            'tag' => strtolower(substr($data, 2, $tagLength)),
            'value' => substr($data, 2 + $tagLength),
        ];
    }
}
