<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Zeitbasierte Einmalkennwörter nach RFC 6238.
 *
 * **Warum ohne Bibliothek.** Sonst gilt in diesem Projekt: Krypto nimmt man
 * fertig. Hier ist die Abwägung eine andere, und zwar aus einem prüfbaren
 * Grund: Das Verfahren ist HMAC-SHA1 plus eine Abschneideregel — die
 * eigentliche Kryptographie macht `hash_hmac` —, und der RFC liefert
 * Testvektoren. Die Umsetzung lässt sich damit gegen den Standard belegen und
 * nicht nur gegen sich selbst. Genau das tut tests/Unit/TotpTest.php —
 * als Verweis im Text und nicht als {@see}, denn daraus macht der Formatierer
 * einen Import, und dann hinge eine Produktionsklasse an einer Testklasse.
 *
 * Eine Bibliothek wäre eine weitere Abhängigkeit im Paket, das als root auf
 * fremden Servern läuft. Für achtzig Zeilen mit amtlichen Testvektoren ist
 * das der schlechtere Tausch.
 *
 * **Sechs Stellen, dreißig Sekunden, SHA1.** Nicht weil es das Beste wäre,
 * sondern weil jede Authenticator-App genau das erwartet. Ein Panel, dessen
 * zweiter Faktor nur mit einer bestimmten App funktioniert, hat keinen.
 *
 * **Warum diese Klasse im Agenten liegt und nicht in `app/`.** Sie hat dort
 * angefangen, für den zweiten Faktor der Anmeldung. Mit INWX braucht sie ein
 * zweiter Aufrufer: Dessen Schnittstelle verlangt bei einem gesicherten Konto
 * einen TAN, und der entsteht aus einem Geheimnis, das der Agent hält und die
 * Anwendung nach dem Speichern nie wiedersieht. Der Agent kann nicht auf `app/`
 * zugreifen — die andere Richtung geht, `SrvPanel\Agent\` ist von dort
 * autoladbar. Es hier zu haben ist deshalb der einzige Weg, es **einmal** zu
 * haben; eine zweite Umsetzung im Agenten wäre genau das Muster, an dem dieses
 * Projekt am häufigsten verloren hat. `TotpSourceTest` besteht darauf.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Ein neues Geheimnis: 160 Bit, wie es der RFC empfiehlt. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Prüft einen Code und gibt den Zeitschritt zurück, der gepasst hat.
     *
     * **Der Rückgabewert ist nicht kosmetisch.** Der Aufrufer muss sich
     * merken, welcher Zeitschritt verbraucht ist — sonst lässt sich derselbe
     * Code innerhalb seines Fensters ein zweites Mal verwenden. Wer einen Code
     * über die Schulter mitliest, hat sonst bis zu neunzig Sekunden Zeit.
     *
     * Das Fenster von einem Schritt in jede Richtung fängt Uhren ab, die
     * auseinanderlaufen. Größer wäre bequemer und schwächer.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $now = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;

            // Zeitgleicher Vergleich: Ein Vergleich, der beim ersten
            // abweichenden Zeichen abbricht, verrät über die Laufzeit, wie
            // viele Stellen stimmen.
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Der Code zu einem Zeitschritt. */
    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $binary = hash_hmac('sha1', pack('J', $step), $key, true);

        // Dynamic Truncation, RFC 4226 §5.3: Das untere Halbbyte des letzten
        // Bytes sagt, wo die vier Bytes beginnen, die den Code ergeben.
        $offset = ord($binary[strlen($binary) - 1]) & 0x0F;

        $value = ((ord($binary[$offset]) & 0x7F) << 24)
            | ((ord($binary[$offset + 1]) & 0xFF) << 16)
            | ((ord($binary[$offset + 2]) & 0xFF) << 8)
            | (ord($binary[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Die Adresse für den QR-Code der Authenticator-App.
     *
     * Aussteller und Konto stehen doppelt darin — einmal im Pfad, einmal als
     * Parameter. Das ist keine Redundanz aus Nachlässigkeit, sondern die
     * Empfehlung der Key-URI-Spezifikation: Ältere Apps lesen nur das eine,
     * neuere das andere.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '=', '-'], '', $secret));
        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                // Ein unbekanntes Zeichen wird übersprungen statt zu werfen:
                // Menschen tippen Geheimnisse ab, und ein „0" statt „O" soll
                // zu einem falschen Code führen, nicht zu einem Absturz.
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
