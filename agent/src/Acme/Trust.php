<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Kann ein Browser diesem Zertifikat trauen?
 *
 * **Die Frage steht hier, weil sie zwei Vorlagen stellen.** Sie stand in
 * `panel.vhost.apply`, solange nur die Oberfläche ein Zertifikat hatte; mit
 * P4 fragt die Kundenvorlage dasselbe. Zwei Formulierungen derselben Regel
 * sind der Fehler, an dem dieses Projekt sechsmal verloren hat — und hier
 * wäre die zweite die, die HSTS auf ein selbstsigniertes Zertifikat schreibt.
 *
 * **Unlesbar zählt als selbstsigniert.** Wer aus einem Zertifikat, das er
 * nicht lesen kann, auf eine Zertifizierungsstelle schliesst, verspricht ein
 * Jahr erzwungenes HTTPS auf Verdacht — und das ist die Richtung, in der ein
 * Irrtum aussperrt (`docs/27 §7`).
 */
final class Trust
{
    /**
     * Hat sich dieses Zertifikat selbst ausgestellt?
     *
     * Aussteller gleich Inhaber. Das ist dieselbe Frage, die `/settings/tls`
     * als `self_signed` beantwortet.
     */
    public static function selfSigned(string $pem): bool
    {
        $parsed = @openssl_x509_parse($pem);

        if (! is_array($parsed)) {
            return true;
        }

        $issuer = $parsed['issuer'] ?? null;
        $subject = $parsed['subject'] ?? null;

        return ! is_array($issuer) || ! is_array($subject) || $issuer === $subject;
    }

    /**
     * Darf dieser Server-Block HSTS versprechen?
     *
     * **Zwei Bedingungen, und jede steht auf der Seite, die sie beantworten
     * kann.** Ob ein Jahr erzwungenes HTTPS überhaupt gewollt ist, weiss das
     * Panel — es kennt die Zertifizierungsstelle und weiss, ob gerade der
     * Testbetrieb läuft, dessen Wurzel kein Browser kennt. Ob das Zertifikat
     * es hergibt, weiss nur der Agent, denn nur er liest die Datei.
     *
     * Ein `true` aus dem Panel ist damit eine Erlaubnis und keine Anweisung.
     */
    public static function hsts(bool $wanted, ?string $certificate): bool
    {
        if (! $wanted || $certificate === null || ! is_file($certificate)) {
            return false;
        }

        return ! self::selfSigned((string) file_get_contents($certificate));
    }
}
