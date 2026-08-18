<?php

declare(strict_types=1);

namespace App\Support\Cron;

use SrvPanel\Agent\Cron\Schedule;

/**
 * Aus fünf Feldern ein Satz, den jemand lesen kann — oder keiner.
 *
 * `docs/51 §10.3` verlangt die lesbare Übersetzung unter der Eingabe („jeden Tag
 * um 03:15"). Sie ist keine Zierde: Fünf Zahlenfelder sagen niemandem, ob er
 * gerade `15 3 * * *` oder `3 15 * * *` getippt hat, und der Unterschied fällt
 * frühestens am nächsten Tag auf.
 *
 * ## Die Regel, die diese Klasse trägt: lieber nichts als ungefähr
 *
 * Cron-Ausdrücke können Dinge, für die es keinen kurzen deutschen Satz gibt: Ein
 * Schritt von sieben in der Minute läuft **nicht** alle sieben Minuten, sondern
 * zu den Minuten 0, 7, 14, 21, 28, 35, 42, 49 und 56 — und danach kommt eine
 * Lücke von elf. Wer das als „alle sieben Minuten" übersetzt, schreibt etwas
 * Falsches hin, das überzeugend aussieht.
 *
 * (Der Ausdruck dafür steht hier absichtlich nicht als Beispiel: Ein Stern mit
 * Schrägstrich beendet in einem Dokumentationsblock den Kommentar. Genau daran
 * ist die erste Fassung dieser Klasse gescheitert.)
 *
 * > **Eine Übersetzung, die nur meistens stimmt, ist schlimmer als keine — sie
 * > wird geglaubt.**
 *
 * Deshalb gibt {@see self::schedule()} für alles, was sie nicht sicher in einen
 * Satz bringt, **`null`** zurück, und die Oberfläche zeigt dann den Ausdruck
 * selbst. Was hier steht, stimmt; was nicht hier steht, behauptet nichts.
 *
 * ## Und der Satz sagt nicht, in welcher Zeit
 *
 * Die Zone gehört an die Seite und nicht in jeden Satz. cron rechnet in der Zeit
 * der Maschine (`docs/60 §11`, gemessen), das Panel zeigt sonst alles in der
 * Anzeigezone — die Beschriftung dazu steht einmal über der Liste, statt
 * fünfzehnmal in fünfzehn Zeilen.
 */
final class Spoken
{
    /** Die Wochentage, wie cron sie zählt — 0 und 7 sind beide Sonntag. */
    private const WEEKDAYS = [
        0 => 'sonntags',
        1 => 'montags',
        2 => 'dienstags',
        3 => 'mittwochs',
        4 => 'donnerstags',
        5 => 'freitags',
        6 => 'samstags',
        7 => 'sonntags',
    ];

    /**
     * Der Satz zu einem Zeitplan — oder `null`, wenn er nicht sicher ist.
     *
     * @param  array<string,string>  $schedule
     */
    public static function schedule(array $schedule): ?string
    {
        foreach (Schedule::FIELDS as $field) {
            if (! isset($schedule[$field]) || $schedule[$field] === '') {
                return null;
            }
        }

        $minute = $schedule['minute'];
        $hour = $schedule['hour'];
        $dom = $schedule['day_of_month'];
        $month = $schedule['month'];
        $dow = $schedule['day_of_week'];

        // Der Monat bleibt aussen vor: „jeden Tag im März um 03:15" ist ein
        // seltener Fall, und die Sätze dafür würden diese Klasse verdoppeln.
        if ($month !== '*') {
            return null;
        }

        /*
         * **Tag des Monats und Wochentag zusammen ergeben keinen Satz.** cron
         * verknüpft sie dann mit ODER (`docs/60`, und `Occurrence` rechnet es
         * so) — „am 13. oder freitags" ist richtig, liest sich aber wie ein
         * Tippfehler. Wer das braucht, liest den Ausdruck.
         */
        if ($dom !== '*' && $dow !== '*') {
            return null;
        }

        $wann = self::daily($minute, $hour);

        if ($wann === null) {
            return null;
        }

        if ($dom !== '*') {
            $tag = self::plainNumber($dom);

            return $tag === null ? null : sprintf('am %d. jedes Monats %s', $tag, $wann);
        }

        if ($dow !== '*') {
            $tage = self::weekdays($dow);

            return $tage === null ? null : $tage.' '.$wann;
        }

        return self::everyDay($minute, $hour, $wann);
    }

    /**
     * Die Tageszeit — „um 03:15", „zur Minute 15", „jede Minute".
     *
     * Gibt `null` für alles, was sich nicht in einen dieser drei Fälle fügt.
     */
    private static function daily(string $minute, string $hour): ?string
    {
        if ($minute === '*' && $hour === '*') {
            return 'jede Minute';
        }

        if ($hour === '*') {
            $m = self::plainNumber($minute);

            /*
             * **`%d` und nicht `%02d`.** „zur Minute 00" liest sich wie eine
             * Uhrzeit, und genau das ist es nicht — es ist eine Minute innerhalb
             * jeder Stunde. Die führende Null gehört zur Uhrzeit unten, wo sie
             * `03:15` von `3:15` unterscheidet.
             *
             * Gefunden hat das `CronScheduleFormTest` beim ersten Lauf: Auf dem
             * Knopf der Schnellwahl stand „zur Minute 0", und diese Zeile machte
             * „zur Minute 00" daraus.
             */
            return $m === null ? null : sprintf('jede Stunde zur Minute %d', $m);
        }

        $m = self::plainNumber($minute);
        $h = self::plainNumber($hour);

        if ($m === null || $h === null) {
            return null;
        }

        return sprintf('um %02d:%02d', $h, $m);
    }

    /**
     * Der Satz für „jeden Tag", mit den beiden Fällen, die keiner sind.
     *
     * „jeden Tag jede Minute" wäre albern, und „jeden Tag jede Stunde zur Minute
     * 15" auch — beide Male ist die Angabe schon vollständig.
     */
    private static function everyDay(string $minute, string $hour, string $wann): string
    {
        if ($hour === '*') {
            return $wann;
        }

        return 'jeden Tag '.$wann;
    }

    /**
     * Aus dem Wochentagsfeld die Aufzählung — „montags bis freitags", „montags
     * und donnerstags".
     */
    private static function weekdays(string $field): ?string
    {
        // Eine Spanne wie `1-5` liest sich als „montags bis freitags".
        if (preg_match('/\A([0-7])-([0-7])$/D', $field, $m) === 1 && (int) $m[1] < (int) $m[2]) {
            return self::WEEKDAYS[(int) $m[1]].' bis '.self::WEEKDAYS[(int) $m[2]];
        }

        if (preg_match('/\A[0-7](,[0-7])*$/D', $field) !== 1) {
            return null;
        }

        $tage = [];

        foreach (explode(',', $field) as $wert) {
            $name = self::WEEKDAYS[(int) $wert];

            if (! in_array($name, $tage, true)) {
                $tage[] = $name;
            }
        }

        if (count($tage) === 1) {
            return $tage[0];
        }

        $letzter = array_pop($tage);

        return implode(', ', $tage).' und '.$letzter;
    }

    /**
     * Eine schlichte Zahl — oder `null`, sobald `*`, `,`, `-` oder `/` im Spiel ist.
     *
     * Das ist die Stelle, an der die Regel dieser Klasse wirkt: Alles, was mehr
     * als eine Zahl ist, führt zu `null` und damit zum Ausdruck statt zum Satz.
     */
    private static function plainNumber(string $field): ?int
    {
        return preg_match('/\A[0-9]{1,2}$/D', $field) === 1 ? (int) $field : null;
    }
}
