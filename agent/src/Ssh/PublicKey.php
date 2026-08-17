<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ssh;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Pg\Hba;

/**
 * Ein öffentlicher Schlüssel, bevor er in `authorized_keys` kommt.
 *
 * ## Warum hier geprüft wird und nicht im Formular
 *
 * Diese Zeichenkette wird zu einer Zeile in einer Datei, die OpenSSH als root
 * liest — es ist genau die Sorte Eingabe, für die es die erste Grenze aus
 * `CLAUDE.md` gibt. Eine Prüfung im Panel wäre die zweite Fassung, und die
 * zweite ist die, die veraltet; dieselbe Entscheidung wie bei
 * {@see Hba::cidr()}.
 *
 * ## Was eine Zeile in `authorized_keys` alles sein kann
 *
 * **Vor dem Schlüssel dürfen Optionen stehen**, und eine davon ist
 * `command="…"`. Gemessen am 16. August 2026 (`docs/57 §11`): Ohne ein
 * `ForceCommand` in der Konfiguration wird sie ausgeführt —
 * `forced-command (key-option) '/usr/bin/id'`. Mit ihm gewinnt die
 * Konfiguration.
 *
 * Die zweite Wand steht also. Sie ist trotzdem kein Grund für ein Loch in der
 * ersten: Was hier hereinkommt, fängt mit einem Schlüsseltyp an oder gar nicht.
 *
 * **Und ein Zeilenumbruch macht aus einer Zeile zwei.** Der Kunde bekäme damit
 * einen Zugang, den das Panel nicht anzeigt — dieselbe Einschleusung wie in
 * `docs/51 §10.1` für `/etc/cron.d`, nur mit einem anderen Ziel. Deshalb fliegt
 * jedes Steuerzeichen raus, und nicht nur `\n`.
 *
 * ## Der Fingerabdruck wird gerechnet und nicht erfragt
 *
 * `SHA256:` plus Base64 des SHA-256 über das rohe Schlüsselmaterial, ohne
 * Auffüllzeichen. Gegen `ssh-keygen -lf` gehalten und zeichengleich
 * (`docs/57 §12`) — damit bleibt `§13` des Plans eingehalten: **kein neues
 * Programm auf der Positivliste.**
 *
 * > **Ein Prüfwert über nichts sieht aus wie ein Prüfwert.** `hash('sha256',
 * > '')` liefert `SHA256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU`, und
 * > genau so sähe die Anzeige eines Parsers aus, der bei einem kaputten Blob
 * > still weitermacht. Hier macht keiner still weiter.
 *
 * ## Was nicht angenommen wird, und warum
 *
 * - **`ssh-dss`.** OpenSSH hat es 7.0 abgeschaltet; ein Zugang damit wäre einer,
 *   der nicht funktioniert, mit einer Fehlersuche beim Kunden.
 * - **RSA unter 2048 Bit.** Gemessen an den Prüfdaten: `ssh-keygen -t rsa -b
 *   1024` legt so einen Schlüssel anstandslos an, und OpenSSH nimmt ihn.
 * - **`sk-*` (Hardware-Token).** Knowingly draussen: Sie sind gut, und sie sind
 *   hier nie gemessen worden. Wer sie braucht, bekommt sie, wenn jemand sie an
 *   einem echten Server ausprobiert hat — nicht vorher.
 */
final class PublicKey
{
    /**
     * Die Typen, die hereindürfen — und wie lang ihr Schlüssel ist.
     *
     * `null` heisst „steht nicht im Typ, wird gerechnet" (RSA).
     *
     * @var array<string,int|null>
     */
    public const TYPES = [
        'ssh-ed25519' => 256,
        'ecdsa-sha2-nistp256' => 256,
        'ecdsa-sha2-nistp384' => 384,
        'ecdsa-sha2-nistp521' => 521,
        'ssh-rsa' => null,
    ];

    /** Kürzer nimmt dieses Panel nicht an. */
    public const RSA_MINIMUM = 2048;

    /**
     * Die Obergrenze für die ganze Zeile.
     *
     * Dieselbe, die OpenSSH selbst für eine Zeile in `authorized_keys` hat. Ein
     * RSA-8192 liegt bei rund 1,5 KB, es ist also keine enge Grenze — sondern
     * ein Riegel gegen eine Eingabe, die als Datei endet.
     */
    public const MAX_LENGTH = 8192;

    /** Wie viel Beschriftung mitgeht. */
    public const MAX_COMMENT = 100;

    /**
     * Einen Schlüssel lesen — oder mit einem Satz abweisen, der sagt, was fehlt.
     *
     * @return array{type: string, material: string, fingerprint: string, bits: int, comment: string}
     */
    public static function parse(mixed $value, string $field = 'key'): array
    {
        $raw = trim(Guard::string($value, $field));

        if ($raw === '') {
            throw AgentException::badRequest('Es ist kein Schlüssel eingegeben worden.');
        }

        if (strlen($raw) > self::MAX_LENGTH) {
            throw AgentException::badRequest(sprintf(
                'Der Schlüssel ist mit %d Zeichen zu lang — mehr als %d nimmt auch OpenSSH nicht.',
                strlen($raw),
                self::MAX_LENGTH,
            ));
        }

        /*
         * **Steuerzeichen fliegen raus, bevor irgendetwas zerlegt wird.** Ein
         * `\n` machte aus einer Zeile zwei, und die zweite wäre ein Zugang, den
         * das Panel nicht anzeigt. Geprüft wird auf *jedes* Steuerzeichen und
         * nicht auf `\n`: Wer nur den einen Fall abfängt, hat den Fall
         * abgefangen, an den er gedacht hat.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw AgentException::badRequest(
                'In dem Schlüssel steht ein Steuerzeichen. Kopieren Sie die Datei `.pub` als eine '
                .'einzige Zeile — ein Zeilenumbruch darin würde aus einem Zugang zwei machen.',
            );
        }

        $fields = preg_split('/\s+/', $raw) ?: [];
        $type = $fields[0] ?? '';

        /*
         * **`array_key_exists` und nicht `isset`.** `ssh-rsa` steht in der
         * Liste mit dem Wert `null` — „die Länge steht nicht im Typ" —, und
         * `isset` ist für einen Schlüssel mit dem Wert `null` **falsch**. Der
         * Wächter hat es im ersten Lauf gefunden: Jeder RSA-Schlüssel wurde mit
         * dem Satz abgewiesen, die Zeile fange nicht mit einem Schlüsseltyp an
         * — und nannte `ssh-rsa` im selben Satz als erlaubt.
         *
         * > **Eine Prüfung, die „kenne ich nicht" und „weiss ich nicht" nicht
         * > unterscheidet, weist das ab, was sie erlaubt.**
         */
        if (! array_key_exists($type, self::TYPES)) {
            throw AgentException::badRequest(self::whyNot($type, $raw));
        }

        $material = $fields[1] ?? '';
        $blob = base64_decode($material, true);

        if (! is_string($blob) || $blob === '' || base64_encode($blob) !== $material) {
            throw AgentException::badRequest(
                'Nach dem Typ steht kein lesbarer Schlüssel. Erwartet wird die Zeile aus der Datei '
                .'`.pub`, also `'.$type.' AAAA…` — nicht der private Schlüssel und keine Datei mit '
                .'mehreren Zeilen.',
            );
        }

        /*
         * **Der Typ steht zweimal da: als Wort und im Schlüsselmaterial.**
         * Gemessen (`docs/57 §12`): Das erste längenpräfixierte Feld des Blobs
         * ist der Typ. Ein Schlüssel, dessen Aufschrift nicht zu seinem Inhalt
         * passt, ist entweder falsch zusammenkopiert oder absichtlich falsch
         * beschriftet — beides will man nicht in der Datei haben.
         */
        $inside = self::field($blob, 0);

        if ($inside !== $type) {
            throw AgentException::badRequest(sprintf(
                'Die Aufschrift des Schlüssels („%s") passt nicht zu seinem Inhalt („%s"). '
                .'Vermutlich sind zwei Zeilen durcheinandergeraten.',
                $type,
                $inside ?? 'unlesbar',
            ));
        }

        $bits = self::bits($type, $blob);
        $comment = implode(' ', array_slice($fields, 2));

        return [
            'type' => $type,
            'material' => $material,
            'fingerprint' => self::fingerprint($blob),
            'bits' => $bits,
            'comment' => self::comment($comment),
        ];
    }

    /**
     * Der Fingerabdruck, wie ihn `ssh-keygen -lf` schreibt.
     *
     * Ohne Auffüllzeichen — die schreibt OpenSSH auch nicht, und ein `=` am Ende
     * machte aus einem Vergleich mit dem, was der Kunde vor sich sieht, einen
     * Fehlschlag.
     */
    public static function fingerprint(string $blob): string
    {
        if ($blob === '') {
            throw AgentException::badRequest('Zu einem leeren Schlüssel gibt es keinen Fingerabdruck.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Die Zeile, wie sie in `authorized_keys` steht.
     *
     * **`restrict` steht davor**, und das ist gemessen und nicht gehofft
     * (`docs/57 §13`): SFTP läuft danach weiter. Es schaltet
     * Port-Weiterleitung, Agent-Weiterleitung, Terminal, X11 und `user-rc` ab —
     * alles Dinge, die ein Zugang zum Dateiaustausch nicht braucht.
     *
     * Es ist die **dritte** Wand hinter `ForceCommand` und `PermitTTY no` aus
     * dem verwalteten Block. Drei Wände sind hier nicht zu viel: Die beiden
     * anderen stehen in einer Datei, die der Betreiber selbst überschreiben
     * kann (`docs/57 §7` — der erste passende `Match`-Block gewinnt).
     *
     * @param  array{type: string, material: string, ...}  $key
     */
    public static function line(array $key, string $comment): string
    {
        return sprintf('restrict %s %s %s', $key['type'], $key['material'], self::comment($comment));
    }

    /**
     * Eine Beschriftung, die in einer Zeile bleibt.
     *
     * **Der Kommentar wird ersetzt und nicht übernommen.** Was in der `.pub`
     * steht, ist der Rechnername dessen, der sie erzeugt hat; was in der Datei
     * stehen soll, ist die Bezeichnung aus dem Panel. Zwei Auskünfte über
     * dasselbe wären eine zu viel.
     */
    public static function comment(string $value): string
    {
        $sauber = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $sauber = trim((string) preg_replace('/\s+/u', ' ', $sauber));

        if ($sauber === '') {
            return 'srvpanel';
        }

        return mb_substr($sauber, 0, self::MAX_COMMENT);
    }

    /**
     * Warum dieser Typ nicht hereinkommt — mit dem Grund und nicht nur mit dem Nein.
     *
     * Die Fälle sehen für den Kunden völlig verschieden aus: Er hat den privaten
     * Schlüssel erwischt, er hat den Fingerabdruck erwischt, er hat einen alten
     * Typ, oder er hat eine Zeile mit Optionen davor. Ein einziger Satz für alle
     * schickte ihn in die falsche Richtung.
     */
    private static function whyNot(string $type, string $raw = ''): string
    {
        /*
         * **Der Fingerabdruck sieht aus wie ein Schlüssel und ist keiner.** Im
         * Abnahmelauf hat der Betreiber die Ausgabe von `ssh-keygen -lf`
         * eingetragen (`docs/59`, Befund 7) — `256 SHA256:… (ED25519)`. Der
         * allgemeine Satz nannte daraufhin „256" als das, womit die Zeile
         * anfängt, und zählte die erlaubten Typen auf: richtig, und für diesen
         * Fall unbrauchbar. Es sind zwei Zeilen, die im Terminal direkt
         * untereinander stehen, und die falsche ist die kürzere.
         *
         * > **Ein Satz, der beschreibt, was dasteht, hilft dem nicht, der die
         * > falsche von zwei ähnlichen Zeilen kopiert hat.**
         */
        if (ctype_digit($type) && str_contains($raw, 'SHA256:')) {
            return 'Das ist der **Fingerabdruck** eines Schlüssels, wie ihn `ssh-keygen -l` ausgibt — '
                .'nicht der Schlüssel selbst. Gebraucht wird der Inhalt der Datei `.pub`; er fängt mit '
                .'`ssh-ed25519` an und ist deutlich länger.';
        }

        if (str_starts_with($type, '-----BEGIN')) {
            return 'Das ist ein **privater** Schlüssel. Hierher gehört die Datei mit der Endung '
                .'`.pub` — der private bleibt auf Ihrem Rechner und wird nirgends hochgeladen.';
        }

        if ($type === 'ssh-dss') {
            return 'DSA-Schlüssel (`ssh-dss`) hat OpenSSH mit Fassung 7.0 abgeschaltet; ein Zugang '
                .'damit käme nicht zustande. Erzeugen Sie einen neuen: `ssh-keygen -t ed25519`.';
        }

        if (str_starts_with($type, 'sk-')) {
            return 'Schlüssel von Hardware-Token (`sk-…`) nimmt dieses Panel noch nicht an.';
        }

        return sprintf(
            'Die Zeile fängt nicht mit einem Schlüsseltyp an, sondern mit „%s". Erwartet wird die '
            .'Zeile aus Ihrer `.pub`-Datei, unverändert und ohne etwas davor — angenommen werden %s.',
            mb_substr($type, 0, 30),
            implode(', ', array_keys(self::TYPES)),
        );
    }

    /**
     * Die Länge des Schlüssels — aus dem Typ oder aus dem Modul.
     *
     * Bei RSA steht sie nirgends geschrieben; sie ist die Bitlänge von `n`.
     * Das führende Nullbyte, das die Kodierung vor ein gesetztes höchstes Bit
     * setzt, zählt dabei nicht mit — sonst käme für jeden zweiten Schlüssel
     * 3080 statt 3072 heraus.
     */
    private static function bits(string $type, string $blob): int
    {
        $bekannt = self::TYPES[$type] ?? null;

        if ($bekannt !== null) {
            return $bekannt;
        }

        // ssh-rsa: <typ> <e> <n>
        $modulus = self::field($blob, 2);

        if ($modulus === null || $modulus === '') {
            throw AgentException::badRequest('Der RSA-Schlüssel ist unvollständig.');
        }

        $modulus = ltrim($modulus, "\x00");
        $bits = strlen($modulus) * 8;

        if ($modulus !== '') {
            for ($maske = 0x80; $maske > 0 && (ord($modulus[0]) & $maske) === 0; $maske >>= 1) {
                $bits--;
            }
        }

        if ($bits < self::RSA_MINIMUM) {
            throw AgentException::badRequest(sprintf(
                'Der RSA-Schlüssel hat %d Bit; angenommen werden ab %d. Erzeugen Sie einen neuen: '
                .'`ssh-keygen -t ed25519` — der ist kürzer und stärker.',
                $bits,
                self::RSA_MINIMUM,
            ));
        }

        return $bits;
    }

    /**
     * Das n-te längenpräfixierte Feld des Blobs — oder `null`, wenn es das nicht gibt.
     *
     * **Jede Länge wird gegen den Rest geprüft, bevor gelesen wird.** Ein
     * Schlüssel, den jemand von Hand zusammengesetzt hat, kann `0xFFFFFFFF` als
     * Länge nennen; ein `substr` darauf gäbe stillschweigend zu wenig zurück,
     * und der Vergleich weiter oben ginge gegen eine abgeschnittene
     * Zeichenkette.
     */
    private static function field(string $blob, int $index): ?string
    {
        $offset = 0;

        for ($i = 0; $i <= $index; $i++) {
            if ($offset + 4 > strlen($blob)) {
                return null;
            }

            /** @var array{1: int} $kopf */
            $kopf = unpack('N', substr($blob, $offset, 4));
            $length = $kopf[1];
            $offset += 4;

            if ($length < 0 || $offset + $length > strlen($blob)) {
                return null;
            }

            if ($i === $index) {
                return substr($blob, $offset, $length);
            }

            $offset += $length;
        }

        return null;
    }
}
