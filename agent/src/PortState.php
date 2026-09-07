<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Antwort von `ss -H -ltnp` in einen Zustand, den eine Seite zeigen kann.
 *
 * Ein reiner Leser und deshalb eine eigene Klasse — dieselbe Bauart wie der
 * Leser für `timedatectl show`. Die Prüfkörper stammen aus den **gemessenen**
 * Ausgaben von `docs/81 §2.3s` und nicht aus erfundenen.
 *
 * ## Warum `-H` und nicht die volle Ausgabe
 *
 * Gemessen (M1): Die Kopfzeile klebt. Dort steht wörtlich
 * `Peer Address:PortProcess` — ohne Leerzeichen zwischen der fünften und der
 * sechsten Spalte. Ein Leser, der die Kopfzeile mitliest und an Leerzeichen
 * trennt, bekommt fünf Spalten, wo sechs stehen. `-H` lässt sie weg.
 *
 * ## Warum die Spaltenbreite nichts zusagt
 *
 * Die Ausgabe ist auf die längste Zeile ausgerichtet, und die ändert sich mit
 * dem Bestand. Getrennt wird deshalb an Leerraum und nicht an Position — und
 * die sechste Spalte wird **nicht** mitgetrennt, sondern ab `users:(` als Rest
 * genommen: Ein Prozessname darf ein Leerzeichen enthalten.
 *
 * ## `privileged` ist ein Argument und kein Schluss aus der Ausgabe
 *
 * Das ist der teuerste Fund der Messrunde (M4). Ohne root gibt `ss -ltnp`
 * **dieselben Zeilen**, `rc=0` und eine wortlos leere Prozessspalte — keine
 * Meldung, kein anderer Rückgabewert.
 *
 * > **Eine leere Spalte, die „nicht nachgesehen" bedeutet, sieht aus wie
 * > „niemand".**
 *
 * Wer aus der leeren Spalte „kein Eigentümer" schlösse, meldete für jeden Port
 * eines unprivilegierten Laufs eine Aussage über den Server statt über den
 * Aufruf. Der Aufrufer weiss, unter welchen Rechten er gefragt hat; er sagt es
 * hier, statt dass es hier geraten wird.
 *
 * ## Welche Adressformen gemessen sind — und welche nicht
 *
 * **Gemessen** (M3, IPv4): `0.0.0.0:19001` und `127.0.0.1:19002`.
 *
 * **Nicht gemessen: jede IPv6-Form.** Der Prüfstand hat kein IPv6 —
 * `/proc/net/if_inet6` gibt es nicht, `AF_INET6` gibt `Errno 97`, und eine
 * eigene Netz-Namespace hilft nicht (beides gemessen, `docs/81 §2.3s`). Die
 * Klammerform `[::]:80` steht hier nach der Dokumentation von iproute2 und
 * **nicht** nach einer Messung; `docs/109 §7` Punkt 5 ist genau dafür da.
 *
 * > **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
 * > Fussnote.**
 */
final class PortState
{
    /** Die Zustände, die dieser Leser aussprechen kann, wenn er nichts lesen konnte. */
    public const REASONS = ['unreadable'];

    /** Adressen, die für „überall" stehen. */
    private const ANY = ['0.0.0.0', '::', '*'];

    /**
     * Der Zustand der lauschenden TCP-Sockel.
     *
     * @param  bool  $privileged  Ob der Aufruf die Prozessspalte überhaupt sehen durfte
     * @return array{
     *     readable: bool,
     *     reason?: string,
     *     privileged?: bool,
     *     listeners?: list<array{address: string, port: int, family: string, scope: string, process: ?string, pid: ?int}>
     * }
     */
    public static function read(Result $result, bool $privileged): array
    {
        if (! $result->successful()) {
            return ['readable' => false, 'reason' => 'unreadable'];
        }

        $lauscher = [];

        foreach ($result->lines() as $zeile) {
            $eintrag = self::line($zeile, $privileged);

            if ($eintrag !== null) {
                $lauscher[] = $eintrag;
            }
        }

        return [
            'readable' => true,
            'privileged' => $privileged,
            'listeners' => $lauscher,
        ];
    }

    /**
     * Eine Zeile — oder `null`, wenn sie keine ist.
     *
     * **Eine unlesbare Zeile wird übersprungen und wirft nicht.** Der Zustand
     * dieser Abfrage ist eine Liste; eine einzelne Zeile, die nicht passt,
     * darf die übrigen zwanzig nicht mitnehmen. Dass sie übersprungen wurde,
     * fällt beim Zählen auf — und das ist die Aufgabe des Wächters, nicht die
     * einer Ausnahme zur Laufzeit.
     *
     * @return array{address: string, port: int, family: string, scope: string, process: ?string, pid: ?int}|null
     */
    private static function line(string $zeile, bool $privileged): ?array
    {
        $felder = preg_split('/\s+/', trim($zeile), 6) ?: [];

        // Zustand, Recv-Q, Send-Q, lokale Adresse, Gegenstelle — fünf sind das
        // Mindeste. Die sechste ist die Prozessspalte und darf fehlen.
        if (count($felder) < 5 || $felder[0] !== 'LISTEN') {
            return null;
        }

        $adresse = self::address($felder[3]);

        if ($adresse === null) {
            return null;
        }

        [$wo, $port, $familie] = $adresse;

        return [
            'address' => $wo,
            'port' => $port,
            'family' => $familie,
            'scope' => self::scope($wo),
            'process' => $privileged ? self::process($felder[5] ?? '') : null,
            'pid' => $privileged ? self::pid($felder[5] ?? '') : null,
        ];
    }

    /**
     * `0.0.0.0:19001` und `[::]:80` in Adresse, Port und Familie.
     *
     * **Der Port steht hinter dem letzten Doppelpunkt**, und deshalb wird von
     * rechts getrennt: Eine IPv6-Adresse trägt selbst welche. Die Klammern
     * unterscheiden die beiden Familien, ohne dass jemand die Doppelpunkte
     * zählen muss.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private static function address(string $feld): ?array
    {
        $trenner = strrpos($feld, ':');

        if ($trenner === false) {
            return null;
        }

        $wo = substr($feld, 0, $trenner);
        $port = substr($feld, $trenner + 1);

        if ($port === '' || ! ctype_digit($port)) {
            return null;
        }

        $nummer = (int) $port;

        // Ein Port ist eine 16-Bit-Zahl. Steht dort etwas anderes, ist die
        // Zeile keine, die dieser Leser versteht — und eine Null, die als
        // Portnummer durchginge, wäre schlimmer als eine übersprungene Zeile.
        if ($nummer < 1 || $nummer > 65535) {
            return null;
        }

        if (str_starts_with($wo, '[') && str_ends_with($wo, ']')) {
            return [substr($wo, 1, -1), $nummer, 'inet6'];
        }

        return [$wo, $nummer, 'inet'];
    }

    /**
     * Wie weit reicht diese Bindung?
     *
     * Drei Werte und keine Wahrheitswerte: „überall" und „nur lokal" sind
     * verschiedene Aussagen, und „auf genau dieser Adresse" ist eine dritte.
     */
    private static function scope(string $adresse): string
    {
        if (in_array($adresse, self::ANY, true)) {
            return 'any';
        }

        if ($adresse === '::1' || str_starts_with($adresse, '127.')) {
            return 'loopback';
        }

        return 'specific';
    }

    /**
     * Der Prozessname aus `users:(("mariadbd",pid=1234,fd=21))`.
     *
     * Genommen wird der **erste** Eintrag: Mehrere Prozesse an einem
     * lauschenden Sockel sind Kinder desselben Dienstes (php-fpm, nginx), und
     * eine Liste von zwölf gleichen Namen ist keine bessere Auskunft als einer.
     */
    private static function process(string $spalte): ?string
    {
        return preg_match('/users:\(\("([^"]*)"/', $spalte, $treffer) === 1 && $treffer[1] !== ''
            ? $treffer[1]
            : null;
    }

    /** Die Prozessnummer aus derselben Spalte. */
    private static function pid(string $spalte): ?int
    {
        return preg_match('/pid=(\d+)/', $spalte, $treffer) === 1 ? (int) $treffer[1] : null;
    }
}
