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
 * **Die fünf stehen fest** (`docs/34 §6`), und sie sind eine Entscheidung des
 * Betreibers vom 6. August 2026 — keine Liste, die nebenbei wächst. Wer einen
 * sechsten braucht, ändert diese Datei; das ist eine Änderung, die jemand
 * liest, und kein Feld in einem Formular.
 *
 * **Und was noch nicht gebaut ist, lässt sich auch nicht hinterlegen.** Vier
 * der fünf stehen in {@see self::PENDING} und werden beim Ablegen der
 * Zugangsdaten abgewiesen. Das ist der Unterschied zu einer Liste, die schon
 * einmal alles anbietet: Ein Token, das im Formular angenommen wird und von
 * nichts benutzt werden kann, ist genau die Sorte Zeichenkette, die auf nichts
 * zeigt — und sie liegt hier als Geheimnis auf der Platte.
 */
final class Providers
{
    /** Der Standard: TSIG-signierte Aktualisierung, wie sie jeder Nameserver kann. */
    public const RFC2136 = 'rfc2136';

    public const HETZNER = 'hetzner';

    public const CLOUDFLARE = 'cloudflare';

    public const NETCUP = 'netcup';

    public const IPV64 = 'ipv64';

    /**
     * Die Reihenfolge ist die aus dem Plan — sie sagt, was zuerst gebaut wird.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::RFC2136 => 'RFC 2136 (TSIG)',
        self::HETZNER => 'Hetzner DNS',
        self::CLOUDFLARE => 'Cloudflare',
        self::NETCUP => 'Netcup',
        self::IPV64 => 'IPv64.net',
    ];

    /**
     * Die vier, die noch kommen — Schritt 9 des Plans.
     *
     * **Sie stehen hier und nicht in einem Kommentar**, damit
     * `Rfc2136Test::test_every_provider_key_points_at_something` beide
     * Richtungen prüfen kann: Jeder Schlüssel ist entweder gebaut oder steht
     * hier, und wer hier steht, hat keine Umsetzung. Ein Schlüssel, der aus
     * dieser Liste fällt, ohne dass es ihn gibt, fällt beim nächsten Lauf auf.
     *
     * @var list<string>
     */
    public const PENDING = [
        self::HETZNER,
        self::CLOUDFLARE,
        self::NETCUP,
        self::IPV64,
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
     * `Rfc2136Test::test_every_provider_key_points_at_something`.
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
