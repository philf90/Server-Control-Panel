<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * Ein TXT-Wert, wie eine Zonendatei ihn schreibt — die einzige Stelle dafür.
 *
 * **Warum das eine eigene Klasse ist.** Ein TXT-Eintrag besteht aus
 * „character-strings" in Anführungszeichen (RFC 1035 §3.3.14), und mehrere
 * Anbieter nehmen den Wert genau so entgegen: Hetzner unter `records[].value`,
 * Cloudflare unter `content`. Das ist dieselbe Regel an zwei Stellen, und die
 * zweite ist erfahrungsgemäss die, die veraltet — bei der Zonenauflösung stand
 * sie schon zweimal da, bevor sie in {@see Zones} gezogen wurde.
 *
 * **Und ohne Fluchtfolgen.** Ein Wert mit einem Anführungszeichen oder einem
 * Rückstrich darin bräuchte eine Fluchtregel, und die wäre eine eigene kleine
 * Sprache mit eigenen Fehlern — noch dazu eine, die jeder Anbieter anders
 * auslegt. Ein ACME-Prüfwert ist Base64 ohne Polster (RFC 8555 §8.4) und
 * enthält beides nie. Was es doch enthält, ist kein Prüfwert und wird
 * abgewiesen, statt halb richtig verpackt zu werden.
 *
 * `TxtValueSourceTest` besteht darauf, dass niemand daneben selbst ein
 * Anführungszeichen an eine Zeichenkette klebt.
 */
final class TxtValue
{
    /** Länger darf eine einzelne character-string nicht sein (RFC 1035 §3.3.14). */
    public const MAX_LENGTH = 255;

    public static function quoted(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, '\\')) {
            throw AgentException::badRequest('Dieser Prüfwert lässt sich nicht als TXT-Eintrag schreiben.');
        }

        // **Die Länge gehört mit geprüft.** Anbieter teilen einen zu langen
        // Wert stillschweigend in zwei character-strings auf, und ein TXT-Satz
        // aus zwei Teilen ist für die Prüfung der Zertifizierungsstelle ein
        // anderer Wert. Ein ACME-Prüfwert ist 43 Zeichen lang; was hier
        // ankommt und länger ist, ist keiner.
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw AgentException::badRequest(
                'Ein Prüfwert für einen TXT-Eintrag ist zwischen 1 und '.self::MAX_LENGTH.' Zeichen lang.',
            );
        }

        return '"'.$value.'"';
    }

    /**
     * Und die Gegenrichtung: derselbe Wert, wie ein Anbieter ihn zurückgibt.
     *
     * **Die Anführungszeichen sind dabei nicht verlässlich.** Cloudflare legt
     * sie ab und gibt sie zurück; andere geben den nackten Wert. Wer beim
     * Abräumen nur die eine Form vergleicht, findet seinen eigenen Eintrag
     * nicht — und lässt eine Aussage über die Zone stehen, die niemand mehr
     * zurücknimmt.
     */
    public static function matches(string $stored, string $value): bool
    {
        return $stored === $value || $stored === '"'.$value.'"';
    }
}
