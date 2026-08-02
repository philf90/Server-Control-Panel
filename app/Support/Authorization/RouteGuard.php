<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Die Registratur der Routen, die keine Policy tragen.
 *
 * §6.2.2 verlangt: „Jede Route ist einer Policy zugeordnet; eine Route ohne
 * Policy fällt im Test durch." Nun gibt es Routen, für die eine Policy keinen
 * Sinn ergibt — die Anmeldemaske, die Bereitschaftsprüfung, die Übersicht, die
 * jedem angemeldeten Konto seine eigene Lage zeigt. Für die gilt: Wer sie
 * ungeschützt lässt, muss das **hier hinschreiben und begründen**.
 *
 * Der Unterschied zwischen dieser Datei und einer Ausnahmeliste ist die
 * zweite Richtung. Der Test prüft nicht nur, ob jede Route erklärt ist,
 * sondern auch, ob jede Erklärung noch zu einer Route gehört. Eine Ausnahme
 * für eine Route, die es nicht mehr gibt, fällt damit auf — sonst wächst so
 * eine Liste über Jahre, und irgendwann deckt sie eine Route, an die niemand
 * mehr gedacht hat.
 *
 * Und er prüft, ob die Erklärung stimmt: Was hier als „nur mit Anmeldung"
 * steht, muss die `auth`-Middleware tragen. Was als „öffentlich" steht, darf
 * sie nicht tragen. Eine Registratur, die etwas anderes behauptet als der
 * Router tut, ist schlimmer als keine.
 */
final class RouteGuard
{
    /** Ohne Konto erreichbar — mit Begründung, warum das vertretbar ist. */
    public const OPEN = 'open';

    /** Jedes angemeldete Konto, keine Prüfung an einem einzelnen Objekt. */
    public const AUTHENTICATED = 'authenticated';

    /** Über eine signierte URL geschützt statt über ein Konto. */
    public const SIGNED = 'signed';

    /**
     * Schlüssel ist `METHODE Pfad`, nicht der Routenname.
     *
     * Namen ändern sich beim Umbenennen mit, ohne dass jemand hinsieht;
     * Methode und Pfad sind das, was der Router tatsächlich vergleicht. Und
     * unbenannte Routen — die des Frameworks etwa — hätten sonst keinen
     * Schlüssel.
     *
     * @return array<string, array{kind: string, reason: string}>
     */
    public static function declarations(): array
    {
        return [
            'GET login' => [
                'kind' => self::OPEN,
                'reason' => 'Die Anmeldemaske. Sie zeigt nichts über den Server — keine Fassung, keinen Rechnernamen, keine Kundenzahl.',
            ],
            'POST login' => [
                'kind' => self::OPEN,
                'reason' => 'Die Anmeldung selbst. Geschützt durch Ratenbegrenzung je IP und je Konto statt durch ein Konto, das es an dieser Stelle noch nicht gibt.',
            ],
            'POST logout' => [
                'kind' => self::AUTHENTICATED,
                'reason' => 'Abmelden betrifft die eigene Sitzung und nichts sonst.',
            ],
            'GET /' => [
                'kind' => self::AUTHENTICATED,
                'reason' => 'Die Übersicht zeigt jedem Konto seine eigene Lage. Was darauf sichtbar ist, entscheidet die Mandantenklammer, nicht eine Policy an der Route.',
            ],
            'POST impersonation/stop' => [
                'kind' => self::AUTHENTICATED,
                'reason' => 'Der Rückweg aus „Anmelden als". Bewusst ohne Policy: Wer in fremder Sicht ist, ist in diesem Moment ein Kundenkonto und hätte die Fähigkeit impersonate nicht mehr — die Prüfung stünde ihm ausgerechnet beim Zurückkommen im Weg. Ohne laufenden Wechsel tut die Route nichts.',
            ],
            'GET health' => [
                'kind' => self::OPEN,
                'reason' => 'Die Bereitschaftsprüfung läuft, während das Paket umschaltet — da ist niemand angemeldet. Sie gibt Fassungsnummern und einen Bereitschaftszustand heraus, sonst nichts.',
            ],
            'GET up' => [
                'kind' => self::OPEN,
                'reason' => 'Die Lebensprüfung des Frameworks. Antwortet mit einer leeren Seite und sagt damit nur, dass PHP läuft.',
            ],
            'GET storage/{path}' => [
                'kind' => self::SIGNED,
                'reason' => 'Vom Framework für die private Ablage angelegt. Illuminate\\Filesystem\\ServeFile bricht ohne gültige Signatur ab; die Berechtigung steckt in der URL, nicht in der Sitzung.',
            ],
            'PUT storage/{path}' => [
                'kind' => self::SIGNED,
                'reason' => 'Gegenstück zum Herunterladen, ebenfalls signaturgebunden.',
            ],
        ];
    }

    /**
     * Der Schlüssel einer Route.
     *
     * HEAD fällt weg: Laravel legt es zu jedem GET automatisch dazu, und im
     * Schlüssel stünde es nur im Weg.
     *
     * @param  array<int, string>  $methods
     */
    public static function key(array $methods, string $uri): string
    {
        $relevant = array_values(array_filter($methods, static fn (string $m): bool => $m !== 'HEAD'));
        sort($relevant);

        return implode('|', $relevant).' '.$uri;
    }
}
