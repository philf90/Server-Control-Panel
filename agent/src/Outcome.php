<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Das Urteil eines abgesetzten Laufs — gelesen aus seinem Log.
 *
 * ## Warum es diese Klasse gibt
 *
 * Drei Operationen setzen ihren Lauf über `systemd-run` ab und kehren zurück,
 * bevor er fertig ist. Das ist keine Nachlässigkeit, sondern die tragende
 * Eigenschaft: Punkt 5 des Abnahmelaufs von A1 belegt, dass die transiente
 * Unit den Neustart von `srvpanel-worker` überlebt, wenn `srvpanel` selbst im
 * Lauf steckt. Ein `--wait` nähme genau das zurück.
 *
 * > **Eine Behebung, die das Merkmal zurücknimmt, für das der Lauf gefahren
 * > wurde, ist keine.**
 *
 * Was fehlte, war die Rückmeldung **danach**: Der Vorgang stand auf `fertig`,
 * während der Lauf noch acht Sekunden lief (`docs/86 §5`).
 *
 * ## Warum nicht der Zustand der Unit
 *
 * **`--collect` räumt die Unit auch dann ab, wenn sie gescheitert ist.** Sie ist
 * fort, sobald sie fertig ist — ihr Zustand kann also „fertig" von „gescheitert"
 * nicht unterscheiden. Er beantwortet genau **eine** Frage, und nur die wird
 * ihm hier gestellt: läuft noch etwas?
 *
 * > **Ein Zustand, der nach dem Ende verschwindet, ist kein Urteil über das
 * > Ende.**
 *
 * Das Urteil ist die Zeile, die `apt-run` selbst schreibt — vier Formen, alle
 * mit demselben Präfix, und alle vier sind im Abnahmelauf gemessen worden.
 *
 * ## Warum ein Versatz und nicht die letzte Zeile
 *
 * `upgrade.log` sammelt Läufe: `systemd-run` hängt mit
 * `StandardOutput=append:` an. Die letzte Zeile der Datei kann also vom
 * **vorigen** Lauf stammen — und das ist genau die Falle, die im Abnahmelauf
 * eine Beobachtung gekostet hat (`docs/86`, Beobachtung 17): Ein Griff, der
 * etwas findet, wird gelesen; ein leerer fällt auf.
 *
 * > **Ein Urteil in einer Datei, die mehrere Läufe sammelt, gehört an die
 * > Stelle gebunden, an der der eigene Lauf begonnen hat.**
 *
 * Der Versatz wird beim Absetzen genommen und mit dem Vorgang gespeichert.
 */
final class Outcome
{
    /**
     * Das Präfix, mit dem `apt-run` **sein Urteil** versieht — und nichts sonst.
     *
     * Es steht dort als `NAME=apt-run`, und `OutcomeTest` hält die beiden
     * aneinander — liefen sie auseinander, fände dieser Leser nichts und
     * meldete „noch kein Urteil", bis die Frist abläuft.
     *
     * ## „Jede Meldung" stand hier, und es war falsch
     *
     * **Gemessen auf `cloudsrv24` am 1. September 2026** (`docs/94 §8`): Der
     * Fassungsmodus schrieb zwei Fortschrittszeilen mit demselben Präfix —
     * `apt-run: Paketlisten werden aufgefrischt.` als erstes, lange vor dem
     * Ende. {@see self::verdict()} nimmt die **letzte** solche Zeile, und
     * während des Laufs ist die letzte auch die erste.
     *
     * > **Ein Leser, der „die letzte Zeile" nimmt, liest während des Laufs die
     * > erste.**
     *
     * `srvpanel update` meldete damit nach zwei Sekunden `Paketlisten werden
     * aufgefrischt.` als Urteil, grün, mit Rückgabewert 0. Der einzige Modus
     * mit solchen Zeilen war `panel` — also genau der, für den die
     * Warteschleife gebaut wurde; `system.packages.upgrade` schreibt keine, und
     * `AwaitDispatchedRun` konnte es deshalb nie sehen.
     *
     * **Behoben ist es im Skript**: Die beiden Zeilen tragen den Präfix nicht
     * mehr. Seitdem gilt ohne Ausnahme — auf eine Zeile mit `apt-run: ` folgt
     * ein `exit`, und `OutcomeTest` hält das.
     */
    public const PREFIX = 'apt-run: ';

    /**
     * Die Urteile, die einen Fehlschlag bedeuten.
     *
     * **Gelesen wird der Anfang und nicht das ganze Wort.** Die beiden Sätze
     * tragen Zahlen, die zur Laufzeit entstehen; ein Vergleich auf Gleichheit
     * fände sie nie.
     *
     * @var list<string>
     */
    public const BAD = [
        'apt-get endete mit ',
        'Der Lauf hat nichts verändert',

        /*
         * **Ein falscher Aufruf ist ein Fehlschlag und kein Erfolg mit
         * Meldung.** `apt-run`s `fehler()` schreibt auf stderr, und stderr
         * landet im selben Log; ohne diesen Eintrag käme ein vertippter Aufruf
         * als grünes Urteil zurück.
         */
        'Aufruf falsch — ',
    ];

    /*
     * **Und der vierte Fall steht bewusst nicht hier: „Es stand nichts an".**
     *
     * Bis zum 31. August 2026 gab es ihn nicht — `apt-run` schrieb auch für
     * einen Lauf ohne Anlass „Der Lauf hat nichts verändert" und endete mit 3.
     * Auf `cloudsrv24` gemessen (`docs/91 §17`): Der zweite Druck auf denselben
     * Knopf meldete `fehlgeschlagen`, mit der Zahl `0` im eigenen Satz.
     *
     * > **Ein Urteil, das seine Zahl mitbringt und nur an seinem Anfang gelesen
     * > wird, wirft die Unterscheidung weg, die es trägt.**
     *
     * **Behoben wurde es im Skript und nicht hier**, und dieser Leser brauchte
     * dafür keine Zeile: Sein Kopf sah den Fall vorher — ein Urteil, das hier
     * nicht steht, ist ein Erfolg mit einer Meldung. Der neue Satz kommt also
     * als grüner Vorbehalt an, ohne dass jemand ihn eintragen musste.
     *
     * > **Eine Voreinstellung, die zur sicheren Seite fällt, trägt den Fall,
     * > den niemand vorhergesehen hat — und den, den jemand vorhergesehen und
     * > nicht gebaut hat, ebenso.**
     */

    /**
     * Der Anfang des Urteils, das einen Lauf ohne Anlass meldet.
     *
     * **Er steht bewusst nicht in {@see self::BAD}** — ein Lauf, dem nichts zu
     * tun blieb, ist kein Fehlschlag; genau das war Befund 6 aus `docs/91 §20`.
     * Hier steht er, weil ein *Erfolg* nicht immer dasselbe bedeutet: Wo nichts
     * eingespielt wurde, ist auch nichts zurückzunehmen.
     *
     * Die Zeichenkette ist die Naht zu `apt-run`. Läuft sie auseinander,
     * antwortet {@see self::unchanged()} mit `false` — und das ist die richtige
     * Richtung: Ein Vorbehalt zuviel ist harmloser als einer, der fehlt.
     */
    public const UNCHANGED = 'Es stand nichts an';

    /**
     * Was nach dem Versatz noch im Log steht — Zeile für Zeile.
     *
     * **Der Pfad kommt nicht von aussen.** Er wird vom Aufrufer aus einer
     * Konstanten gereicht, und der Aufrufer ist eine Operation mit einer
     * Positivliste. Dieselbe Regel wie bei {@see Logs}: übergeben wird ein
     * Schlüssel und kein Pfad.
     *
     * @return list<string>
     */
    public static function lines(string $path, int $offset): array
    {
        if ($offset < 0 || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $groesse = filesize($path);

        /*
         * **Eine Datei, die kürzer geworden ist, hat neu angefangen.**
         * `PanelUpdate` leert sein Log zu Beginn jedes Laufs. Von einem
         * Versatz zu lesen, der grösser ist als die Datei, gäbe leer zurück —
         * und leer heisst hier „noch kein Urteil", also das Gegenteil von dem,
         * was der Fall ist.
         */
        if ($groesse === false) {
            return [];
        }

        $von = $offset > $groesse ? 0 : $offset;

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (fseek($handle, $von) !== 0) {
                return [];
            }

            $inhalt = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($inhalt === false || $inhalt === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('rtrim', explode("\n", $inhalt)),
            static fn (string $zeile): bool => $zeile !== '',
        ));
    }

    /**
     * Die Urteilszeile aus diesen Zeilen — oder `null`, solange keine dasteht.
     *
     * **Die letzte und nicht die erste.** `apt-run` schreibt sein Urteil als
     * letzte Zeile; stünden zwei da, gehörte die frühere zu einem Lauf, den
     * der Versatz nicht ganz abgeschnitten hat.
     *
     * @param  list<string>  $lines
     */
    public static function verdict(array $lines): ?string
    {
        $treffer = null;

        foreach ($lines as $zeile) {
            if (str_starts_with($zeile, self::PREFIX)) {
                $treffer = substr($zeile, strlen(self::PREFIX));
            }
        }

        return $treffer;
    }

    /**
     * Ist dieses Urteil ein Fehlschlag?
     *
     * **Ein unbekanntes Urteil gilt nicht als Fehlschlag**, und das ist die
     * bewusste Richtung: Was hier ankommt, hat `apt-run` geschrieben, also ist
     * es eines seiner vier. Käme ein fünftes dazu, wäre es ein Erfolg mit
     * einer Meldung — und nicht ein Fehlschlag ohne Grund.
     *
     * Der Fall „gar kein Urteil" wird hier nicht entschieden, sondern beim
     * Aufrufer: Er allein weiss, ob noch etwas läuft.
     */
    public static function failed(string $verdict): bool
    {
        foreach (self::BAD as $anfang) {
            if (str_starts_with($verdict, $anfang)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hat dieser Lauf gar nichts eingespielt?
     *
     * **Gefragt wird das, wo eine Meldung von einer Installation ausgeht.** Der
     * Hinweis auf die Bereitschaftsprüfung und den Rückweg gilt nur für einen
     * Lauf, der ein Paket entpackt hat — sonst wurde weder eine Kopie gelegt
     * noch etwas geprüft, und der Satz verspricht ein Netz, das gar nicht
     * gespannt ist.
     *
     * Gemessen am 1. September 2026 auf `cloudsrv24` (`docs/96 §2`): Der Satz
     * stand unter „Es stand nichts an — Fassung unverändert: 0.7.3~rc.9."
     *
     * > **Zwei Sätze über denselben Lauf, von denen einer eine Installation
     * > voraussetzt, die der andere ausschliesst.**
     */
    public static function unchanged(string $verdict): bool
    {
        return str_starts_with($verdict, self::UNCHANGED);
    }
}
