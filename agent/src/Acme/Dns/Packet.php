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
 */
final class Packet
{
    /** TXT — der einzige Satztyp, den diese Prüfung braucht. */
    public const TYPE_TXT = 16;

    public const CLASS_IN = 1;

    /**
     * Eine Frage nach den TXT-Sätzen eines Namens.
     *
     * **Ohne Rekursionswunsch.** Gefragt wird ein Server, der die Zone selbst
     * führt; er soll aus seinem eigenen Bestand antworten und nicht anderswo
     * nachsehen.
     */
    public static function query(int $id, string $name): string
    {
        return pack('n6', $id, 0, 1, 0, 0, 0)
            .Name::encode($name)
            .pack('n2', self::TYPE_TXT, self::CLASS_IN);
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
        if (strlen($answer) < 12) {
            return [];
        }

        /** @var array<string, int>|false $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $answer);

        if ($header === false) {
            return [];
        }

        // **Die Kennung wird geprüft.** Über UDP kann alles ankommen; eine
        // Antwort auf eine andere Frage ist hier keine Antwort.
        if ($header['id'] !== $id) {
            return [];
        }

        // Abgeschnitten: Die Antwort passte nicht in ein UDP-Paket. Das ist
        // hier kein Fehler, sondern ein „noch nicht" — der Aufrufer fragt
        // gleich noch einmal.
        if (($header['flags'] & 0x0200) !== 0) {
            return [];
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            Name::skip($answer, $offset);
            $offset += 4;
        }

        $values = [];

        for ($i = 0; $i < $header['an']; $i++) {
            Name::skip($answer, $offset);

            if ($offset + 10 > strlen($answer)) {
                return $values;
            }

            /** @var array<string, int>|false $meta */
            $meta = unpack('ntype/nclass/Nttl/nlength', substr($answer, $offset, 10));
            $offset += 10;

            if ($meta === false) {
                return $values;
            }

            // **Ein Satz, der über das Paket hinausragt, wird nicht halb
            // gelesen.** Sonst käme ein abgeschnittener Wert heraus, der fast
            // stimmt — und „fast" heisst hier: Die Prüfung schlägt fehl, und
            // im Protokoll steht ein Wert, der richtig aussieht.
            if ($offset + $meta['length'] > strlen($answer)) {
                return $values;
            }

            $data = substr($answer, $offset, $meta['length']);
            $offset += $meta['length'];

            if ($meta['type'] === self::TYPE_TXT) {
                $text = self::text($data);

                if ($text !== '') {
                    $values[] = $text;
                }
            }
        }

        return $values;
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
}
