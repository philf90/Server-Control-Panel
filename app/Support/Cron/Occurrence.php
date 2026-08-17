<?php

declare(strict_types=1);

namespace App\Support\Cron;

use DateTimeImmutable;
use DateTimeZone;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Wann läuft dieser Zeitplan das nächste Mal?
 *
 * **Gerechnet wird in der Zone der Maschine** ({@see ServerZone}) und
 * zurückgegeben wird UTC — so, wie jeder Zeitstempel dieses Panels gespeichert
 * wird. Die Anzeige besorgt danach `Clock` wie bei jedem anderen auch.
 *
 * ## Die Falle, an der solche Rechnungen scheitern
 *
 * **Tag des Monats und Wochentag sind mit ODER verknüpft, sobald beide gesetzt
 * sind** — nicht mit UND. `0 0 13 * 5` heisst „jeden Dreizehnten **und** jeden
 * Freitag", nicht „jeden Freitag, den Dreizehnten". Das steht so in
 * `crontab(5)`, es ist die einzige Stelle der ganzen Syntax, an der die
 * Verknüpfung wechselt, und eine Rechnung, die überall UND nimmt, ist an
 * elf Zwölfteln aller Zeitpläne richtig und an diesem einen still falsch.
 *
 * > **Eine Sonderregel, die nur bei einer von zwölf Kombinationen greift, wird
 * > von jedem Test gefunden, der sie prüft — und von keinem anderen.**
 *
 * Ist nur eines von beiden gesetzt, gilt schlicht dieses; `*` heisst dann nicht
 * „alle Tage", sondern „schweigt zu dieser Frage".
 *
 * ## Was diese Rechnung nicht ist
 *
 * Sie ist eine **Bequemlichkeit für die Liste** und keine Quelle. Was wirklich
 * läuft, entscheidet cron aus der Datei. Insbesondere bildet sie die
 * Zeitumstellung nicht nach: `docs/60 §11` hat gemessen, dass cron einen Job aus
 * der ausgefallenen Spanne im Augenblick des Sprungs nachholt und einen aus der
 * doppelten Spanne genau einmal läuft. Beides ist richtig, und beides ist
 * seltener als einmal im Jahr — eine Nachbildung hier wäre eine zweite Fassung
 * von cron, und die zweite ist die, die abweicht.
 */
final class Occurrence
{
    /**
     * Wie weit gesucht wird.
     *
     * Vier Jahre, weil `0 0 29 2 *` — der 29. Februar — bis zu vier Jahre
     * entfernt sein kann. Ein Zeitplan, der auch dann nichts trifft, trifft
     * nie: `0 0 30 2 *` gibt es, und die richtige Antwort darauf ist `null` und
     * keine Endlosschleife.
     */
    private const MAX_DAYS = 1462;

    /**
     * Die nächste Fälligkeit nach `$after` — oder `null`.
     *
     * @param  array<string,string>  $schedule  die fünf Felder, wie {@see Schedule::FIELDS} sie nennt
     */
    public static function next(array $schedule, ?DateTimeImmutable $after = null): ?DateTimeImmutable
    {
        $zone = ServerZone::current();
        $from = ($after ?? new DateTimeImmutable('now'))->setTimezone($zone);

        $minutes = self::expand($schedule['minute'] ?? '*', 0, 59);
        $hours = self::expand($schedule['hour'] ?? '*', 0, 23);
        $months = self::expand($schedule['month'] ?? '*', 1, 12);

        $domField = $schedule['day_of_month'] ?? '*';
        $dowField = $schedule['day_of_week'] ?? '*';

        $doms = self::expand($domField, 1, 31);
        $dows = self::expand($dowField, 0, 7);

        // Sonntag darf 0 oder 7 heissen; für den Vergleich mit `w` (0–6) zählt
        // beides als 0. Ohne diese Zeile fiele `0 0 * * 7` durch jedes Raster.
        if (in_array(7, $dows, true)) {
            $dows[] = 0;
        }

        // Die Suche beginnt in der Minute **nach** `$from`: Ein Zeitplan, der
        // gerade eben gelaufen ist, ist jetzt nicht wieder fällig.
        $cursor = $from->setTime((int) $from->format('G'), (int) $from->format('i'), 0)
            ->modify('+1 minute');

        if ($minutes === [] || $hours === [] || $months === [] || ($doms === [] && $dows === [])) {
            return null;
        }

        for ($day = 0; $day <= self::MAX_DAYS; $day++) {
            if (! in_array((int) $cursor->format('n'), $months, true)) {
                // Kein Treffer in diesem Monat — zum Ersten des nächsten, statt
                // einunddreissig Tage einzeln abzuklopfen.
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0, 0);

                continue;
            }

            if (! self::dayMatches($cursor, $domField, $dowField, $doms, $dows)) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);

                continue;
            }

            $found = self::withinDay($cursor, $minutes, $hours);

            if ($found instanceof DateTimeImmutable) {
                return $found->setTimezone(new DateTimeZone('UTC'));
            }

            $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);
        }

        return null;
    }

    /**
     * Passt dieser Tag — mit der ODER-Regel, wenn beide Felder gesetzt sind?
     *
     * @param  list<int>  $doms
     * @param  list<int>  $dows
     */
    private static function dayMatches(
        DateTimeImmutable $day,
        string $domField,
        string $dowField,
        array $doms,
        array $dows,
    ): bool {
        $dom = in_array((int) $day->format('j'), $doms, true);
        $dow = in_array((int) $day->format('w'), $dows, true);

        $domRestricted = $domField !== '*';
        $dowRestricted = $dowField !== '*';

        if ($domRestricted && $dowRestricted) {
            return $dom || $dow;
        }

        return $dom && $dow;
    }

    /**
     * Die erste passende Uhrzeit an diesem Tag, die nicht vor dem Zeiger liegt.
     *
     * @param  list<int>  $minutes
     * @param  list<int>  $hours
     */
    private static function withinDay(DateTimeImmutable $cursor, array $minutes, array $hours): ?DateTimeImmutable
    {
        $fromHour = (int) $cursor->format('G');
        $fromMinute = (int) $cursor->format('i');

        foreach ($hours as $hour) {
            if ($hour < $fromHour) {
                continue;
            }

            foreach ($minutes as $minute) {
                if ($hour === $fromHour && $minute < $fromMinute) {
                    continue;
                }

                return $cursor->setTime($hour, $minute, 0);
            }
        }

        return null;
    }

    /**
     * Ein Feld zu der Menge seiner Werte — aufsteigend und ohne Wiederholung.
     *
     * **Geprüft wird hier nichts.** Das tut {@see Schedule::parse()} im Agenten,
     * an der Stelle, die die Zeile schreibt; eine zweite Prüfung hier wäre die
     * Fassung, die beim nächsten Umbau abweicht. Was hier ankommt, ist bereits
     * durch sie hindurch — und was trotzdem nicht zu deuten ist, ergibt eine
     * leere Menge und damit `null` als Fälligkeit.
     *
     * @return list<int>
     */
    private static function expand(string $field, int $low, int $high): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            $step = 1;
            $slash = strpos($part, '/');

            if ($slash !== false) {
                $step = max(1, (int) substr($part, $slash + 1));
                $part = substr($part, 0, $slash);
            }

            if ($part === '*') {
                $from = $low;
                $to = $high;
            } elseif (($dash = strpos($part, '-')) !== false) {
                $from = (int) substr($part, 0, $dash);
                $to = (int) substr($part, $dash + 1);
            } else {
                if (! is_numeric($part)) {
                    continue;
                }

                $from = $to = (int) $part;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                if ($value >= $low && $value <= $high) {
                    $values[] = $value;
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
