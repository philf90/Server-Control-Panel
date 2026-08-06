<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * Die Unterschrift unter eine Aktualisierung (RFC 8945, früher RFC 2845).
 *
 * **Sie ist der ganze Zugangsschutz.** Ein Nameserver, der `nsupdate`
 * entgegennimmt, entscheidet allein an ihr, ob er eine Änderung annimmt — es
 * gibt kein Passwort und keine Sitzung. Wer den Schlüssel hat, ändert die Zone;
 * wer ihn falsch anwendet, bekommt `NOTAUTH` und keinen Hinweis darauf, an
 * welcher der acht Grössen es lag.
 *
 * **Rein und ohne Steckdose, wie {@see Packet}.** Der Rechenweg lässt sich
 * damit gegen gebaute Nachrichten prüfen — und das ist nötig, denn die drei
 * Stellen, an denen es hier üblicherweise schiefgeht, sieht man einer laufenden
 * Verbindung nicht an:
 *
 * 1. **Die Zählung im Kopf.** Gerechnet wird über die Nachricht, *bevor* der
 *    TSIG-Satz dazukommt — also mit dem alten `ARCOUNT`. Erhöht wird erst
 *    danach.
 * 2. **Die kanonische Form der Namen.** Schlüsselname und Verfahrensname gehen
 *    kleingeschrieben und ausgeschrieben in die Rechnung, nie zusammengefasst
 *    ({@see Name::canonical()}).
 * 3. **Die Kennung in den Daten.** Im TSIG-Satz steht die *ursprüngliche*
 *    Kennung der Nachricht, nicht die des Kopfes — bei uns dieselbe, aber wer
 *    das Feld weglässt, unterschreibt etwas anderes als er sendet.
 *
 * **Und die Antwort wird nachgerechnet.** Über TCP ist eine untergeschobene
 * Antwort nicht leicht, aber „nicht leicht" ist bei einem Prozess mit
 * Systemrechten kein Argument: Ein gefälschtes `NOERROR` hiesse, wir sagen der
 * Zertifizierungsstelle „prüf jetzt", während nichts in der Zone steht — und
 * verbrennen damit einen der fünf Fehlversuche je Konto und Stunde.
 */
final class Tsig
{
    public const TYPE = 250;

    /** TSIG steht in der Klasse ANY und ist nie zwischengespeichert. */
    public const CLASS_ANY = 255;

    /** Wie weit die Uhren auseinandergehen dürfen — die übliche Wahl. */
    public const FUDGE = 300;

    /**
     * Die Verfahren, die dieser Agent unterschreibt.
     *
     * **Ohne `hmac-md5` und ohne `hmac-sha1`.** Beide stehen in RFC 8945 noch
     * drin, und `hmac-md5` ist dort sogar der alte Standardwert; für einen
     * Schlüssel, der heute neu eingerichtet wird, gibt es keinen Grund dazu.
     * Wer einen alten Schlüssel hat, richtet einen neuen ein — das ist eine
     * Zeile in der Zonenkonfiguration.
     *
     * @var array<string, string>
     */
    private const ALGORITHMS = [
        'hmac-sha256' => 'sha256',
        'hmac-sha384' => 'sha384',
        'hmac-sha512' => 'sha512',
    ];

    public const DEFAULT_ALGORITHM = 'hmac-sha256';

    /**
     * @param  string  $keyName  Der Name des Schlüssels, wie er im Nameserver steht
     * @param  string  $algorithm  Einer aus {@see self::ALGORITHMS}
     * @param  string  $secret  Das Geheimnis in Rohbytes, nicht als Base64
     */
    public function __construct(
        private readonly string $keyName,
        private readonly string $algorithm,
        private readonly string $secret,
    ) {}

    /** @return list<string> */
    public static function algorithms(): array
    {
        return array_keys(self::ALGORITHMS);
    }

    public static function normalizeAlgorithm(mixed $algorithm): string
    {
        $name = is_string($algorithm) ? strtolower(trim($algorithm)) : '';

        if ($name === '') {
            $name = self::DEFAULT_ALGORITHM;
        }

        if (! isset(self::ALGORITHMS[$name])) {
            throw AgentException::badRequest(
                'Unbekanntes TSIG-Verfahren.',
                ['algorithm' => is_string($algorithm) ? $algorithm : '?', 'known' => self::algorithms()],
            );
        }

        return $name;
    }

    /**
     * Eine Nachricht unterschreiben.
     *
     * @return array{message: string, mac: string} Die fertige Nachricht und die
     *                                             Unterschrift, die die Antwort belegen muss
     */
    public function sign(string $message, int $timeSigned): array
    {
        $variables = $this->variables($timeSigned, self::FUDGE, 0, '');
        $mac = hash_hmac(self::ALGORITHMS[$this->algorithm], $message.$variables, $this->secret, true);

        $rdata = Name::canonical($this->algorithm)
            .self::time($timeSigned)
            .pack('n2', self::FUDGE, strlen($mac))
            .$mac
            .pack('n3', self::id($message), 0, 0);

        $record = Name::canonical($this->keyName)
            .pack('n2N', self::TYPE, self::CLASS_ANY, 0)
            .pack('n', strlen($rdata))
            .$rdata;

        return ['message' => self::withOneMoreAdditional($message).$record, 'mac' => $mac];
    }

    /**
     * Trägt die Antwort eine Unterschrift, die zu unserer Frage passt?
     *
     * **`$now` kommt von aussen**, damit ein Durchgang die Uhr vorgeben kann;
     * ohne das wäre die Prüfung des Zeitfensters nicht prüfbar.
     */
    public function verify(string $response, string $requestMac, int $now): bool
    {
        $found = $this->locate($response);

        if ($found === null) {
            return false;
        }

        [$start, $name, $rdata] = $found;

        if (! self::sameName($name, $this->keyName)) {
            return false;
        }

        $parsed = self::parseRdata($rdata);

        if ($parsed === null || ! self::sameName($parsed['algorithm'], $this->algorithm)) {
            return false;
        }

        // Ein Fehlerfeld ungleich null heisst: Der Server hat unterschrieben,
        // aber inhaltlich abgelehnt. Die Unterschrift stimmt dann zwar, die
        // Aktualisierung ist trotzdem keine.
        if ($parsed['error'] !== 0) {
            return false;
        }

        if (abs($now - $parsed['time']) > $parsed['fudge']) {
            return false;
        }

        // Gerechnet wird über die Antwort ohne ihren TSIG-Satz und mit dem
        // Zähler, den sie davor hatte — dieselbe Reihenfolge wie beim
        // Unterschreiben, nur mit der Unterschrift der Frage davor.
        $withoutRecord = self::withOneLessAdditional(substr($response, 0, $start));

        $expected = hash_hmac(
            self::ALGORITHMS[$this->algorithm],
            pack('n', strlen($requestMac)).$requestMac
                .$withoutRecord
                .$this->variables($parsed['time'], $parsed['fudge'], $parsed['error'], $parsed['other']),
            $this->secret,
            true,
        );

        return hash_equals($expected, $parsed['mac']);
    }

    /**
     * Die acht Grössen, die neben der Nachricht in die Rechnung gehen.
     *
     * Reihenfolge und Breite stehen in RFC 8945 §4.3.3 und sind nicht
     * verhandelbar: Schlüsselname, Klasse, Haltbarkeit, Verfahren, Zeitpunkt,
     * Spielraum, Fehler, Länge und Inhalt des Zusatzfeldes.
     */
    private function variables(int $timeSigned, int $fudge, int $error, string $other): string
    {
        return Name::canonical($this->keyName)
            .pack('nN', self::CLASS_ANY, 0)
            .Name::canonical($this->algorithm)
            .self::time($timeSigned)
            .pack('n3', $fudge, $error, strlen($other))
            .$other;
    }

    /**
     * Der TSIG-Satz einer Antwort — er ist immer der letzte.
     *
     * @return array{0: int, 1: string, 2: string}|null Anfang des Satzes, sein
     *                                                  Name und seine Daten
     */
    private function locate(string $response): ?array
    {
        if (strlen($response) < 12) {
            return null;
        }

        /** @var array<string, int>|false $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $response);

        if ($header === false || $header['ar'] < 1) {
            return null;
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            Name::skip($response, $offset);
            $offset += 4;
        }

        $records = $header['an'] + $header['ns'] + $header['ar'];
        $last = null;

        for ($i = 0; $i < $records; $i++) {
            $start = $offset;
            $nameStart = $offset;
            Name::skip($response, $offset);
            $name = substr($response, $nameStart, $offset - $nameStart);

            if ($offset + 10 > strlen($response)) {
                return null;
            }

            /** @var array<string, int>|false $meta */
            $meta = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            $offset += 10;

            if ($meta === false || $offset + $meta['length'] > strlen($response)) {
                return null;
            }

            $rdata = substr($response, $offset, $meta['length']);
            $offset += $meta['length'];

            $last = $meta['type'] === self::TYPE ? [$start, $name, $rdata] : null;
        }

        // **Nur der letzte zählt.** Ein TSIG-Satz irgendwo mittendrin wäre kein
        // gültiger, und ihn trotzdem zu prüfen hiesse, sich die Stelle
        // aussuchen zu lassen, über die gerechnet wird.
        return $last;
    }

    /**
     * Die Felder eines TSIG-Satzes.
     *
     * @return array{algorithm: string, time: int, fudge: int, mac: string, error: int, other: string}|null
     */
    private static function parseRdata(string $rdata): ?array
    {
        $offset = 0;
        $nameStart = $offset;
        Name::skip($rdata, $offset);
        $algorithm = substr($rdata, $nameStart, $offset - $nameStart);

        if ($offset + 10 > strlen($rdata)) {
            return null;
        }

        $time = self::readTime(substr($rdata, $offset, 6));
        $offset += 6;

        /** @var array<string, int>|false $head */
        $head = unpack('nfudge/nmac', substr($rdata, $offset, 4));
        $offset += 4;

        if ($head === false || $offset + $head['mac'] + 6 > strlen($rdata)) {
            return null;
        }

        $mac = substr($rdata, $offset, $head['mac']);
        $offset += $head['mac'];

        /** @var array<string, int>|false $tail */
        $tail = unpack('nid/nerror/nother', substr($rdata, $offset, 6));
        $offset += 6;

        if ($tail === false || $offset + $tail['other'] > strlen($rdata)) {
            return null;
        }

        return [
            'algorithm' => $algorithm,
            'time' => $time,
            'fudge' => $head['fudge'],
            'mac' => $mac,
            'error' => $tail['error'],
            'other' => substr($rdata, $offset, $tail['other']),
        ];
    }

    /** Der Zeitpunkt ist 48 Bit breit — sechs Bytes, nicht vier. */
    private static function time(int $seconds): string
    {
        return substr(pack('J', $seconds), 2, 6);
    }

    private static function readTime(string $bytes): int
    {
        /** @var array<int, int>|false $parts */
        $parts = unpack('J', "\0\0".$bytes);

        return $parts === false ? 0 : $parts[1];
    }

    /** Ein Name im Drahtformat gegen einen geschriebenen. */
    private static function sameName(string $wire, string $name): bool
    {
        return $wire === Name::canonical($name);
    }

    private static function id(string $message): int
    {
        /** @var array<int, int>|false $parts */
        $parts = unpack('n', substr($message, 0, 2));

        return $parts === false ? 0 : $parts[1];
    }

    private static function withOneMoreAdditional(string $message): string
    {
        return self::countAdditional($message, 1);
    }

    private static function withOneLessAdditional(string $message): string
    {
        return self::countAdditional($message, -1);
    }

    private static function countAdditional(string $message, int $by): string
    {
        /** @var array<int, int>|false $count */
        $count = unpack('n', substr($message, 10, 2));

        return $count === false
            ? $message
            : substr_replace($message, pack('n', max(0, $count[1] + $by)), 10, 2);
    }
}
