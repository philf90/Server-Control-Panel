<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Eine Zonenaktualisierung im Drahtformat (RFC 2136).
 *
 * **Es ist kein eigenes Protokoll, sondern eine DNS-Nachricht mit anderem
 * Bedeutungsschlüssel.** Derselbe Kopf, dieselben Abschnitte — nur heissen sie
 * anders: Aus der Frage wird die Zone, aus der Antwort werden die
 * Voraussetzungen, aus der Berechtigung die Änderungen. Wer das einmal
 * gesehen hat, liest den Rest dieser Datei in einer Minute.
 *
 * **Anlegen und nicht ersetzen.** Zwei Bestellungen für dieselbe Zone — der
 * Regelfall bei einem Platzhalter, `example.de` und `*.example.de` — ergeben
 * zwei Werte unter demselben Namen, und beide müssen dastehen. Ein Ersetzen
 * würde den Wert des jeweils anderen Vorgangs stillschweigend wegnehmen; der
 * scheitert dann an einer Ursache, die nirgends steht.
 *
 * **Und beim Abräumen wird genau ein Satz genannt.** RFC 2136 kennt dafür die
 * Klasse `NONE`: „diesen einen Satz aus dem Bestand entfernen". Die Klasse
 * `ANY` gäbe es auch — sie räumt alles unter dem Namen ab, und damit die
 * Prüfung eines fremden Vorgangs mit.
 */
final class UpdateMessage
{
    /** Der Bedeutungsschlüssel 5 — nicht Frage, sondern Aktualisierung. */
    public const OPCODE = 5 << 11;

    /** Die Zone wird über ihren SOA-Satz benannt. */
    public const TYPE_SOA = 6;

    /** „Diesen Satz entfernen" (RFC 2136 §2.5.4). */
    public const CLASS_NONE = 254;

    /**
     * So lang darf eine Zeichenkette in einem TXT-Satz sein.
     *
     * Ein ACME-Wert ist 43 Zeichen lang, das reicht also mit Abstand. Die
     * Grenze steht trotzdem hier: Sie ist die Bedingung dafür, dass das
     * Längenbyte überhaupt eine Länge sein kann.
     */
    public const MAX_TEXT = 255;

    /**
     * Einen TXT-Satz hinzufügen.
     *
     * @param  int  $ttl  Kurz gehalten: Der Satz wird Minuten später wieder abgeräumt
     */
    public static function add(int $id, string $zone, string $record, string $value, int $ttl): string
    {
        return self::message($id, $zone, self::record($record, Packet::CLASS_IN, $ttl, $value));
    }

    /** Und ihn wieder entfernen — genau diesen einen. */
    public static function remove(int $id, string $zone, string $record, string $value): string
    {
        return self::message($id, $zone, self::record($record, self::CLASS_NONE, 0, $value));
    }

    /**
     * Der Kopf und die drei Abschnitte.
     *
     * Ohne Voraussetzungen: Ob der Satz schon dasteht, ist gleichgültig — ein
     * doppeltes Anlegen bleibt nach §3.4.2.2 folgenlos, und ein Entfernen, das
     * nichts findet, ebenso. Voraussetzungen zu stellen hiesse, dafür einen
     * Fehler zu bekommen, der keiner ist.
     */
    private static function message(int $id, string $zone, string $update): string
    {
        return pack('n6', $id, self::OPCODE, 1, 0, 1, 0)
            .Name::encode($zone)
            .pack('n2', self::TYPE_SOA, Packet::CLASS_IN)
            .$update;
    }

    /** Ein TXT-Satz: der Wert als eine Zeichenkette mit vorangestellter Länge. */
    private static function record(string $name, int $class, int $ttl, string $value): string
    {
        $rdata = chr(min(strlen($value), self::MAX_TEXT)).substr($value, 0, self::MAX_TEXT);

        return Name::encode($name)
            .pack('n2N', Packet::TYPE_TXT, $class, $ttl)
            .pack('n', strlen($rdata))
            .$rdata;
    }

    /**
     * Der Rückgabewert einer Antwort — und was er auf Deutsch heisst.
     *
     * **Die Meldung ist die halbe Arbeit.** Wer eine Zone über TSIG ändern
     * lässt, bekommt bei jedem Fehler dieselbe stumme Ablehnung; ohne diese
     * Zuordnung steht im Protokoll „Rückgabewert 9" und der Betreiber sucht
     * an der falschen Stelle.
     */
    public static function explain(int $code): string
    {
        return match ($code) {
            0 => 'in Ordnung',
            1 => 'Der Nameserver hat die Nachricht nicht verstanden (FORMERR).',
            2 => 'Der Nameserver meldet einen eigenen Fehler (SERVFAIL).',
            3 => 'Diese Zone führt der Nameserver nicht (NXDOMAIN).',
            4 => 'Der Nameserver nimmt keine Aktualisierungen entgegen (NOTIMP).',
            5 => 'Der Nameserver lehnt die Aktualisierung ab — der Schlüssel darf diese Zone nicht ändern (REFUSED).',
            6, 7, 8 => 'Eine Voraussetzung der Aktualisierung traf nicht zu.',
            9 => 'Der Schlüssel wurde nicht anerkannt — Name, Verfahren, Geheimnis oder die Uhr des Servers (NOTAUTH).',
            10 => 'Die genannte Zone ist nicht die Zone des Satzes (NOTZONE).',
            default => 'Der Nameserver antwortet mit Rückgabewert '.$code.'.',
        };
    }

    /** Der Rückgabewert aus dem Kopf einer Antwort, oder `null`. */
    public static function code(string $response): ?int
    {
        if (strlen($response) < 12) {
            return null;
        }

        /** @var array<string, int>|false $header */
        $header = unpack('nid/nflags', $response);

        return $header === false ? null : ($header['flags'] & 0x000F);
    }

    /** Und ihre Kennung — sie muss die der Frage sein. */
    public static function id(string $response): ?int
    {
        if (strlen($response) < 2) {
            return null;
        }

        /** @var array<int, int>|false $parts */
        $parts = unpack('n', substr($response, 0, 2));

        return $parts === false ? null : $parts[1];
    }
}
