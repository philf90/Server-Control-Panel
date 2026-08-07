<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\AgentException;

/**
 * Die DNS-Anbieter, mit denen dieser Agent spricht — als Positivliste.
 *
 * **Das Panel nennt einen Schlüssel, keine Adresse.** Dieselbe Entscheidung wie
 * bei den Zertifizierungsstellen ({@see Directories}):
 * Nähme der Agent eine URL entgegen, wäre die Anwendung eine Fernsteuerung
 * dafür, wohin ein Prozess als root eine Verbindung aufbaut und wem er ein
 * Token zeigt, das eine ganze Zone öffnet.
 *
 * **Die acht stehen fest** (`docs/34 §6`): eine Entscheidung des Betreibers vom
 * 6. August 2026 über fünf, erweitert am 7. August um IONOS, INWX und deSEC —
 * nach einer Durchsicht aller 222 Anbieter, die lego mitbringt. Keine Liste,
 * die nebenbei wächst: Wer einen neunten braucht, ändert diese Datei, und das
 * ist eine Änderung, die jemand liest, kein Feld in einem Formular.
 *
 * **Strato steht nicht darauf und wird auch nicht dazukommen.** Der Anbieter
 * hat keine öffentliche Schnittstelle, über die sich ein TXT-Eintrag setzen
 * lässt — weder lego noch acme.sh können ihn. Der Ausweg für einen Kunden ist
 * die Zone und nicht das Panel: Sie lässt sich zu deSEC delegieren, ohne dass
 * die Domain umzieht, und deshalb steht deSEC überhaupt hier.
 *
 * **Und was nicht angeboten wird, lässt sich auch nicht hinterlegen.** Wer in
 * {@see self::WITHHELD} steht, wird beim Ablegen der Zugangsdaten abgewiesen —
 * mit dem Grund, der dort steht. Das ist der Unterschied zu einer Liste, die
 * schon einmal alles anbietet: Ein Geheimnis, das im Formular angenommen wird
 * und von nichts benutzt werden kann, liegt danach auf der Platte eines
 * Servers, auf dem Kunden Websites betreiben.
 */
final class Providers
{
    /** Der Standard: TSIG-signierte Aktualisierung, wie sie jeder Nameserver kann. */
    public const RFC2136 = 'rfc2136';

    public const IPV64 = 'ipv64';

    public const HETZNER = 'hetzner';

    public const CLOUDFLARE = 'cloudflare';

    public const NETCUP = 'netcup';

    public const IONOS = 'ionos';

    public const INWX = 'inwx';

    public const DESEC = 'desec';

    /**
     * Die Reihenfolge ist die aus dem Plan — sie sagt, was zuerst gebaut wird.
     *
     * IPv64.net steht vor Hetzner, weil er vorgezogen wurde: An ihm beweist
     * sich die Zonenauflösung, denn dort ist die Zone häufig selbst eine
     * Unterdomain. INWX steht zuletzt, weil er als einziger XML-RPC, eine
     * Sitzung und ein Kontopasswort statt eines Tokens mitbringt.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::RFC2136 => 'RFC 2136 (TSIG)',
        self::IPV64 => 'IPv64.net',
        self::HETZNER => 'Hetzner DNS',
        self::CLOUDFLARE => 'Cloudflare',
        self::NETCUP => 'netcup',
        self::IONOS => 'IONOS',
        self::INWX => 'INWX',
        self::DESEC => 'deSEC',
    ];

    /**
     * Die, die nicht angeboten werden — mit dem Grund, warum nicht.
     *
     * **Die Liste hiess bis zum 7. August 2026 `PENDING` und meinte „noch nicht
     * gebaut".** Mit dem achten Anbieter war sie leer, und im selben Zug fiel
     * die Entscheidung, INWX **nicht anzubieten**, obwohl er gebaut ist
     * (`docs/34 §11`). Damit sind es zwei verschiedene Gründe, und ein
     * Formular, das für beide „Noch nicht verfügbar" sagt, sagt bei einem davon
     * die Unwahrheit. Deshalb steht hier der Grund und nicht nur der Schlüssel
     * — und er wird angezeigt.
     *
     * **Sie steht hier und nicht in einem Kommentar**, damit
     * `ProvidersTest::test_every_provider_key_points_at_something` beide
     * Richtungen prüfen kann: Ein Schlüssel, der hier herausfällt, ohne dass es
     * die Umsetzung gibt, fällt beim nächsten Lauf auf.
     *
     * @var array<string, string>
     */
    public const WITHHELD = [
        self::INWX => 'Die Zugangsdaten sind dort Benutzername und Passwort des Registrarkontos '.
            'und nicht ein Token für eine Zone.',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Die Anbieter, die heute benutzbar sind.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        return array_values(array_diff(self::keys(), array_keys(self::WITHHELD)));
    }

    /**
     * Die Zugangsdaten prüfen, ohne etwas anzulegen.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> Die geprüfte Fassung — sie wird abgelegt, nicht die rohe
     */
    public static function configure(mixed $key, array $config): array
    {
        return match ($name = self::usable($key)) {
            self::RFC2136 => Rfc2136::configure($config),
            self::IPV64 => Ipv64::configure($config),
            self::HETZNER => Hetzner::configure($config),
            self::CLOUDFLARE => Cloudflare::configure($config),
            self::NETCUP => Netcup::configure($config),
            self::IONOS => Ionos::configure($config),
            self::DESEC => Desec::configure($config),
            // INWX steht hier, obwohl er nicht angeboten wird: Der Weg über
            // `usable()` kommt nie hier an. Ihn zu entfernen hiesse, ihn beim
            // nächsten Sinneswandel neu zu schreiben — und `ProvidersTest`
            // prüft weiter, dass der Schlüssel auf eine Umsetzung zeigt.
            self::INWX => Inwx::configure($config),
            default => throw self::missing($name),
        };
    }

    /**
     * Und die Werkstatt selbst.
     *
     * @param  array<string, mixed>  $config
     */
    public static function make(mixed $key, array $config): DnsProvider
    {
        return match ($name = self::usable($key)) {
            self::RFC2136 => Rfc2136::fromConfig($config),
            self::IPV64 => Ipv64::fromConfig($config),
            self::HETZNER => Hetzner::fromConfig($config),
            self::CLOUDFLARE => Cloudflare::fromConfig($config),
            self::NETCUP => Netcup::fromConfig($config),
            self::IONOS => Ionos::fromConfig($config),
            self::DESEC => Desec::fromConfig($config),
            self::INWX => Inwx::fromConfig($config),
            default => throw self::missing($name),
        };
    }

    /**
     * Der Fall, den es nicht geben soll: bekannt, nicht offen, nicht gebaut.
     *
     * **Er steht hier als Zweig und nicht als Lücke.** Ein `match` ohne diesen
     * Ausgang wirft zwar auch — aber einen `UnhandledMatchError`, und der
     * landet als „interner Fehler" im Panel, ohne zu sagen, woran es liegt.
     * Erreichbar ist er nur über einen Schlüssel, der aus {@see self::WITHHELD}
     * gefallen ist, ohne dass es die Umsetzung gibt; genau das prüft
     * `ProvidersTest::test_every_provider_key_points_at_something`.
     */
    private static function missing(string $key): AgentException
    {
        return AgentException::execFailed(
            'Für diesen DNS-Anbieter fehlt die Umsetzung.',
            ['provider' => $key, 'available' => self::available()],
        );
    }

    /**
     * Ein Schlüssel, hinter dem auch etwas steht.
     *
     * **Die Abweisung nennt den Grund**, denn es gibt zwei: „noch nicht gebaut"
     * und „gebaut, aber nicht angeboten". Wer nur „nicht verfügbar" liest,
     * wartet im zweiten Fall auf etwas, das nicht kommt.
     */
    public static function usable(mixed $key): string
    {
        $name = self::normalize($key);

        $reason = self::reason($name);

        if ($reason !== null) {
            throw AgentException::badRequest(
                'Dieser DNS-Anbieter wird nicht angeboten: '.$reason,
                ['provider' => $name, 'available' => self::available()],
            );
        }

        return $name;
    }

    /**
     * Warum ein Anbieter nicht angeboten wird — oder `null`, wenn er es wird.
     *
     * Der Grund geht bis in die Oberfläche: Er steht neben dem Namen im
     * Formular, damit niemand auf einen Anbieter wartet, der nicht kommt.
     */
    public static function reason(mixed $key): ?string
    {
        $name = self::normalize($key);

        return self::WITHHELD[$name] ?? null;
    }

    /** Der Name, wie ihn jemand liest. */
    public static function label(mixed $key): string
    {
        return is_string($key) ? (self::LABELS[$key] ?? $key) : '?';
    }

    /** Einen Schlüssel prüfen — mehr tut diese Stelle nicht. */
    public static function normalize(mixed $key): string
    {
        if (! is_string($key) || ! isset(self::LABELS[$key])) {
            throw AgentException::badRequest(
                'Unbekannter DNS-Anbieter.',
                ['provider' => is_string($key) ? $key : '?', 'known' => self::keys()],
            );
        }

        return $key;
    }
}
