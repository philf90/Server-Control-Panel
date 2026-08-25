<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Net;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Pg\Hba;

/**
 * Ein Netz in CIDR-Schreibweise — prüfen, normalisieren, abgleichen.
 *
 * ## Warum diese Klasse entstanden ist, ohne dass etwas fehlte
 *
 * Die Rechnung stand seit P5b in `Pg\Hba::cidr()`, und sie ist dort gut: rohe
 * Bytes über `inet_pton` statt einer Zerlegung in Dezimalgruppen, dieselbe
 * Rechnung für IPv4 und IPv6, gesetzte Wirtsbits werden abgewiesen statt
 * stillschweigend gelesen.
 *
 * **Sie war auch längst die geteilte Stelle** — vier Klassen nennen sie im
 * Kommentar als *die* Schreibweise, und das Panel ruft sie über die
 * Namensraumgrenze hinweg direkt auf. Nur stand sie in der Klasse, die
 * `pg_hba.conf` schreibt, und trug deren Politik im Text.
 *
 * A9 Schritt 7 braucht dieselbe Rechnung für etwas, das mit PostgreSQL nichts
 * zu tun hat: die Netze, aus denen sich ein Adminkonto anmelden darf. Ein
 * zweiter Parser daneben wäre der Fehler, den dieses Repo am häufigsten macht.
 *
 * > **Eine Rechnung, die zwei Systeme brauchen, gehört keinem von beiden.**
 *
 * ## Was hier steht und was nicht
 *
 * Hier steht die **Rechnung**: Ist das eine Adresse? Passt die Präfixlänge?
 * Sind Wirtsbits gesetzt? Liegt diese Adresse in jenem Netz?
 *
 * Hier steht **keine Politik**. Ob `/0` erlaubt ist, entscheidet der Aufrufer:
 * Für einen Datenbankzugang ist „das ganze Internet" eine Ablehnung wert, für
 * eine Anmeldebeschränkung ist es bloss die Voreinstellung mit mehr Zeichen.
 * {@see Hba::cidr()} weist es ab, mit seiner eigenen
 * Begründung.
 *
 * > **Zwei Systeme mit derselben Rechnung und verschiedenen Regeln teilen die
 * > Rechnung und nicht die Regeln.**
 */
final class Cidr
{
    /**
     * Ein Netz prüfen und in seine kanonische Schreibweise bringen.
     *
     * Ohne `/…` gilt der einzelne Rechner: `192.0.2.7` wird `192.0.2.7/32`,
     * `2001:db8::1` wird `2001:db8::1/128`.
     *
     * **Gesetzte Wirtsbits sind ein Fehler und keine Nachlässigkeit.**
     * `192.0.2.7/24` liest sich wie „dieser Rechner", meint aber das ganze
     * Netz `192.0.2.0/24` — und wer das nicht wollte, hat 254 Rechner mehr
     * hereingelassen, als er dachte. Die Meldung nennt deshalb beide Lesarten.
     */
    public static function normalize(mixed $value, string $field = 'cidr'): string
    {
        $raw = trim(Guard::string($value, $field));
        $slash = strrpos($raw, '/');
        $address = $slash === false ? $raw : substr($raw, 0, $slash);

        /*
         * **`inet_pton` und nicht nur `filter_var`.** Das erste prüft, das
         * zweite rechnet: Ohne die rohen Bytes liesse sich die Netzadresse
         * unten nur über eine Zerlegung in Dezimalgruppen bestimmen — und die
         * wäre für IPv6 eine zweite, andere Fassung derselben Rechnung.
         */
        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : @inet_pton($address);

        if (! is_string($binary)) {
            throw AgentException::badRequest(sprintf(
                '%s ist keine IP-Adresse: %s', $field, $raw,
            ));
        }

        $width = strlen($binary) * 8;

        if ($slash === false) {
            return self::present($binary).'/'.$width;
        }

        $suffix = substr($raw, $slash + 1);

        if (preg_match('/\A[0-9]{1,3}\z/', $suffix) !== 1 || (int) $suffix > $width) {
            throw AgentException::badRequest(sprintf(
                '%s trägt keine gültige Präfixlänge: %s — erlaubt ist 0 bis %d.', $field, $raw, $width,
            ));
        }

        $length = (int) $suffix;
        $network = self::network($binary, $length);

        if ($network !== $binary) {
            throw AgentException::badRequest(sprintf(
                '%s hat gesetzte Wirtsbits. PostgreSQL nähme das an und läse stillschweigend %s/%d — '
                .'gemeint war vermutlich %s/%d (dieser eine Rechner) oder %s/%d (das ganze Netz).',
                $raw,
                self::present($network), $length,
                $address, $width,
                self::present($network), $length,
            ));
        }

        return self::present($binary).'/'.$length;
    }

    /**
     * Liegt diese Adresse in diesem Netz?
     *
     * **Verschiedene Familien ergeben `false` und keinen Fehler.** Eine
     * IPv4-Adresse liegt nicht in einem IPv6-Netz, und das ist eine Antwort und
     * kein Zwischenfall — eine Liste von Netzen darf beide Familien mischen,
     * und der Abgleich läuft über alle.
     *
     * > **Ein Abgleich, der bei der falschen Familie wirft, macht aus einer
     * > gemischten Liste einen Fehlerfall.**
     *
     * Eine unlesbare Adresse ist ebenfalls `false`: Der Aufrufer fragt „darf
     * dieser hier herein", und auf eine Adresse, die keine ist, lautet die
     * Antwort nein.
     */
    public static function contains(string $cidr, string $ip): bool
    {
        $slash = strrpos($cidr, '/');

        if ($slash === false) {
            return false;
        }

        $network = @inet_pton(substr($cidr, 0, $slash));
        $address = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : @inet_pton($ip);

        if (! is_string($network) || ! is_string($address)) {
            return false;
        }

        // IPv4 gegen IPv6: verschiedene Länge, also nie dasselbe Netz.
        if (strlen($network) !== strlen($address)) {
            return false;
        }

        $length = (int) substr($cidr, $slash + 1);

        if ($length < 0 || $length > strlen($network) * 8) {
            return false;
        }

        return self::network($address, $length) === self::network($network, $length);
    }

    /** Die Netzadresse: alles hinter der Präfixlänge auf null. */
    private static function network(string $binary, int $length): string
    {
        $bytes = str_split($binary);
        $full = intdiv($length, 8);
        $bits = $length % 8;

        foreach ($bytes as $index => $byte) {
            $bytes[$index] = match (true) {
                $index < $full => $byte,
                $index === $full && $bits > 0 => chr(ord($byte) & (0xFF << (8 - $bits)) & 0xFF),
                default => "\0",
            };
        }

        return implode('', $bytes);
    }

    /** Rohe Bytes zurück in die Schreibweise, die in der Datei steht. */
    private static function present(string $binary): string
    {
        $address = @inet_ntop($binary);

        if (! is_string($address)) {
            throw AgentException::badRequest('Die Adresse liess sich nicht zurückschreiben.');
        }

        return $address;
    }
}
