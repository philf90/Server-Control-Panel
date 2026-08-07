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
 * **Und was noch nicht gebaut ist, lässt sich auch nicht hinterlegen.** Die
 * noch offenen stehen in {@see self::PENDING} und werden beim Ablegen der
 * Zugangsdaten abgewiesen. Das ist der Unterschied zu einer Liste, die schon
 * einmal alles anbietet: Ein Token, das im Formular angenommen wird und von
 * nichts benutzt werden kann, ist genau die Sorte Zeichenkette, die auf nichts
 * zeigt — und sie liegt hier als Geheimnis auf der Platte.
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
     * Die, die noch kommen — Schritt 9 des Plans.
     *
     * **Sie stehen hier und nicht in einem Kommentar**, damit
     * `ProvidersTest::test_every_provider_key_points_at_something` beide
     * Richtungen prüfen kann: Jeder Schlüssel ist entweder gebaut oder steht
     * hier, und wer hier steht, hat keine Umsetzung. Ein Schlüssel, der aus
     * dieser Liste fällt, ohne dass es ihn gibt, fällt beim nächsten Lauf auf.
     *
     * @var list<string>
     */
    public const PENDING = [
        self::INWX,
        self::DESEC,
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
        return array_values(array_diff(self::keys(), self::PENDING));
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
            default => throw self::missing($name),
        };
    }

    /**
     * Der Fall, den es nicht geben soll: bekannt, nicht offen, nicht gebaut.
     *
     * **Er steht hier als Zweig und nicht als Lücke.** Ein `match` ohne diesen
     * Ausgang wirft zwar auch — aber einen `UnhandledMatchError`, und der
     * landet als „interner Fehler" im Panel, ohne zu sagen, woran es liegt.
     * Erreichbar ist er nur über einen Schlüssel, der aus {@see self::PENDING}
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

    /** Ein Schlüssel, hinter dem auch etwas steht. */
    public static function usable(mixed $key): string
    {
        $name = self::normalize($key);

        if (in_array($name, self::PENDING, true)) {
            throw AgentException::badRequest(
                'Dieser DNS-Anbieter ist noch nicht umgesetzt.',
                ['provider' => $name, 'available' => self::available()],
            );
        }

        return $name;
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
