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
     * Das Präfix, mit dem `apt-run` jede seiner Meldungen versieht.
     *
     * Es steht dort als `NAME=apt-run`, und `OutcomeTest` hält die beiden
     * aneinander — liefen sie auseinander, fände dieser Leser nichts und
     * meldete „noch kein Urteil", bis die Frist abläuft.
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
    ];

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
}
