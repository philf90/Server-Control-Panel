<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Was das Regelwerk dieser Maschine sagt — aus **beiden** Familien gelesen.
 *
 * Ein reiner Leser wie der für die lauschenden Sockel. Die Prüfkörper stammen
 * aus den gemessenen Ausgaben von `docs/81 §2.3s`.
 *
 * ## Warum zwei Familien und nicht eine
 *
 * Gemessen (M10): Eine Regel über `iptables-legacy` ist für `nft list ruleset`
 * unsichtbar — `rc=0`, stdout 0 Bytes, stderr 0 Bytes. Das ist zeichengleich
 * mit „es gibt keine Regeln".
 *
 * > **Ein leeres Regelwerk und ein Regelwerk, das man mit dem falschen Werkzeug
 * > abfragt, sehen gleich aus — und beide sagen `rc=0`.**
 *
 * Ein Server mit vollständiger Legacy-Firewall sähe für einen Leser, der nur
 * `nft` fragt, aus wie einer ohne jede Regel. Das ist die Schwester des Fundes
 * aus A1 Schritt 2: *Eine Sperre, die man mit dem falschen Werkzeug abfragt,
 * meldet immer frei* — dort `flock(2)` gegen `fcntl`, hier nftables gegen
 * iptables-legacy.
 *
 * ## Und es sind zwei Fragen, nicht eine
 *
 * *Was steht im Regelwerk* beantwortet `nft`. *Wer verwaltet es* beantwortet es
 * nicht: Eine `table ip filter` sagt nicht, ob ufw sie geschrieben hat oder
 * jemand von Hand (M18/M19). Dafür gibt es `ufw status` und
 * `firewall-cmd --state`.
 *
 * ## Warum „konfiguriert" nicht an der Zeilenzahl hängt
 *
 * Der erste Entwurf (`docs/109 §3.1`) sagte `lines > 3`, weil beide
 * iptables-Bauarten im unberührten Zustand genau drei Zeilen ausgeben (M11).
 * **Gemessen am 7. September, beim Bauen, ist das falsch** (M11b): Ein
 * `iptables-legacy -P INPUT DROP` ohne eine einzige Regel gibt ebenfalls drei
 * Zeilen — und das ist eine Firewall, die alles sperrt.
 *
 * > **Eine Zahl, die „unberührt" bedeuten soll, zählt eine geänderte
 * > Standardrichtlinie mit — und die ist genau der Fall, den man sehen will.**
 *
 * **Und das Muster endet auf `$/D`.** Ohne den Modifikator passt `$` auch vor
 * einem abschliessenden Zeilenumbruch. `AnchoredPatternTest` hat es beim
 * ersten vollen Lauf gemeldet — der Fehler steht seit P3 im Repo und ist
 * dort neunmal aufgetreten.
 *
 * Gefragt wird deshalb nach dem **Inhalt**: Alles, was nicht
 * `-P <Kette> ACCEPT` ist, ist eine Konfiguration. Das trägt beide Fälle, die
 * zusätzliche Regel und die verschärfte Richtlinie, und es zählt nichts.
 *
 * ## Warum der Verwalter eine geschlossene Grundmenge ist
 *
 * `firewall-cmd --state` hat vier gemessene Ausgänge, und **drei beantworten
 * die Frage nicht** (M15): `rc=1`, wenn der Interpreter nicht passt; `rc=36`
 * ohne D-Bus; `rc=252`, wenn der Dienst nicht läuft — nur das ist die Antwort;
 * `rc=0` mit `running`.
 *
 * > **Ein Rückgabewert, der aus einem Fehlschlag vor der Frage entsteht, sieht
 * > aus wie eine Antwort auf die Frage.**
 *
 * Gewertet wird deshalb der **genannte Zustand** und nicht „ungleich null".
 * Alles, was dieser Leser nicht kennt, ist `unknown` und nicht „aus" — derselbe
 * Schnitt, aus dem A11 den Wortlaut „Operation not possible due to RF-kill"
 * nicht auf die Seite lässt.
 */
final class FilterState
{
    /** Die Verwalter, die dieser Leser aussprechen kann. Mehr gibt es nicht. */
    public const MANAGERS = ['nftables', 'iptables', 'ufw', 'firewalld', 'none', 'unknown'];

    /**
     * Der Zustand des Regelwerks aus vier Antworten.
     *
     * `null` heisst „das Programm ist auf dieser Maschine nicht installiert" —
     * der Runner wirft dafür, und der Aufrufer fängt es. Das ist etwas anderes
     * als „es hat geantwortet und nichts gefunden", und beide Fälle haben
     * deshalb verschiedene Felder.
     *
     * @param  Result|null  $nft  `nft list ruleset`
     * @param  Result|null  $legacy  `iptables-legacy -S`
     * @param  Result|null  $ufw  `ufw status`
     * @param  Result|null  $firewalld  `firewall-cmd --state`
     * @return array{
     *     readable: bool,
     *     nft: array{installed: bool, readable: bool, configured: bool, tables: list<string>},
     *     legacy: array{installed: bool, readable: bool, configured: bool},
     *     legacy_only: bool,
     *     manager: string
     * }
     */
    public static function read(?Result $nft, ?Result $legacy, ?Result $ufw, ?Result $firewalld): array
    {
        $n = self::nft($nft);
        $l = self::legacy($legacy);

        return [
            // Lesbar ist der Zustand, wenn **mindestens eine** Familie
            // geantwortet hat. Beide stumm heisst „nicht feststellbar" — und
            // nicht „keine Regeln", was der gefährlichere der beiden Sätze
            // wäre.
            'readable' => $n['readable'] || $l['readable'],
            'nft' => $n,
            'legacy' => $l,

            // **Das Feld, das es ohne M10 nicht gäbe.** Es trägt genau den
            // Fall, in dem die verbreitete Frage die falsche Antwort gibt.
            'legacy_only' => $l['configured'] && $n['readable'] && ! $n['configured'],

            'manager' => self::manager($n, $l, $ufw, $firewalld),
        ];
    }

    /**
     * `nft list ruleset` — leer bei `rc=0` heisst wirklich leer.
     *
     * Anders als bei `ss` ist der Fehlerfall hier sauber unterscheidbar (M8):
     * ohne CAP_NET_ADMIN `rc=1` und `Operation not permitted (you must be
     * root)` auf stderr. `readable` trägt diesen Unterschied.
     *
     * @return array{installed: bool, readable: bool, configured: bool, tables: list<string>}
     */
    private static function nft(?Result $result): array
    {
        if ($result === null) {
            return ['installed' => false, 'readable' => false, 'configured' => false, 'tables' => []];
        }

        if (! $result->successful()) {
            return ['installed' => true, 'readable' => false, 'configured' => false, 'tables' => []];
        }

        $tabellen = [];

        foreach ($result->lines() as $zeile) {
            if (preg_match('/^table\s+(\S+)\s+(\S+)\s*\{/', trim($zeile), $treffer) === 1) {
                $tabellen[] = $treffer[1].' '.$treffer[2];
            }
        }

        return [
            'installed' => true,
            'readable' => true,
            'configured' => $tabellen !== [],
            'tables' => $tabellen,
        ];
    }

    /**
     * `iptables-legacy -S` — konfiguriert ist alles ausser `-P <Kette> ACCEPT`.
     *
     * @return array{installed: bool, readable: bool, configured: bool}
     */
    private static function legacy(?Result $result): array
    {
        if ($result === null) {
            return ['installed' => false, 'readable' => false, 'configured' => false];
        }

        if (! $result->successful()) {
            return ['installed' => true, 'readable' => false, 'configured' => false];
        }

        foreach ($result->lines() as $zeile) {
            $zeile = trim($zeile);

            if ($zeile === '') {
                continue;
            }

            if (preg_match('/^-P\s+\S+\s+ACCEPT$/D', $zeile) !== 1) {
                return ['installed' => true, 'readable' => true, 'configured' => true];
            }
        }

        return ['installed' => true, 'readable' => true, 'configured' => false];
    }

    /**
     * Wer das Regelwerk verwaltet.
     *
     * **Die Reihenfolge ist die der Zuständigkeit und nicht die der
     * Sichtbarkeit.** firewalld und ufw sagen selbst, dass sie zuständig sind;
     * `nft` und `iptables` sagen nur, dass etwas dasteht. Wer zuerst nach der
     * Sichtbarkeit fragte, nennte bei laufendem ufw „iptables" — richtig
     * beobachtet und falsch beantwortet.
     *
     * @param  array{installed: bool, readable: bool, configured: bool, tables: list<string>}  $nft
     * @param  array{installed: bool, readable: bool, configured: bool}  $legacy
     */
    private static function manager(array $nft, array $legacy, ?Result $ufw, ?Result $firewalld): string
    {
        // `rc=0` **und** das Wort — der Rückgabewert allein trägt hier nicht,
        // weil drei der vier gemessenen Ausgänge die Frage nicht beantworten.
        if ($firewalld !== null && $firewalld->successful() && str_contains($firewalld->stdout, 'running')) {
            return 'firewalld';
        }

        if ($ufw !== null && $ufw->successful() && str_contains($ufw->stdout, 'Status: active')) {
            return 'ufw';
        }

        if ($legacy['configured']) {
            return 'iptables';
        }

        if ($nft['configured']) {
            return 'nftables';
        }

        // „Keiner" darf nur sagen, wer beide Familien wirklich gelesen hat.
        return $nft['readable'] && $legacy['readable'] ? 'none' : 'unknown';
    }
}
