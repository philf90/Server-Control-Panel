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
 * **Umgesetzt ist noch keiner, und hier steht deshalb auch keine Werkstatt.**
 * Die Reihenfolge des Plans baut zuerst die Strecke — Prüfung, Ablage der
 * Zugangsdaten — und dann die Anbieter. Eine Fabrikmethode, die für jeden
 * Schlüssel eine Ausnahme wirft, wäre genau die Sorte Zeichenkette, die auf
 * nichts zeigt; sie kommt mit der ersten Umsetzung. Geprüft wird hier nur, ob
 * ein Schlüssel überhaupt einer ist.
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

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
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
