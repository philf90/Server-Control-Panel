<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonInterface;

/**
 * Die Antwort von `system.time` als Sätze, die auf der Seite stehen (A11).
 *
 * **Die eine Stelle, die aus dem Zustand Worte macht.** Stünde die Zuordnung
 * in der `.vue`, hätte sie niemand ohne Browser gemessen; stünde sie an zwei
 * Stellen, liefe die zweite auseinander. Genau dieselbe Entscheidung wie bei
 * `useUnitState.ts` für A2, nur andersherum: Dort braucht die Übersicht die
 * Zuordnung auch, hier gibt es genau eine Seite.
 *
 * ## Warum vier Sätze und nicht zwei
 *
 * Gemessen (`docs/81 §2.3r` M8/M10): `CanNTP` und `NTP` tragen zusammen drei
 * Zustände, und „nicht feststellbar" ist der vierte.
 *
 * > **Zwei Wahrheitswerte, die vier Zustände tragen, verlieren beim
 * > Zusammenziehen genau den Fall, der eine Meldung verdient.**
 *
 * „Kein Zeitdienst installiert" ist etwas anderes als „ausgeschaltet": Das eine
 * behebt man mit `apt-get install`, das andere mit einem Schalter.
 *
 * ## Die Zone kommt von woanders her
 *
 * Aus `App\Support\Cron\ServerZone` und nicht vom Agenten — als Argument,
 * damit diese Klasse formt und nicht beschafft. Der Plan sah sie in der
 * Antwort von `system.time` vor; gebaut wäre das ein zweiter Leser derselben
 * Quelle gewesen, denn `timedatectl` folgt demselben Symlink wie cron
 * (`docs/81 §2.3r` M11). Gefangen hat es `ServerZoneSourceTest`, den es seit
 * P6 dafür gibt.
 *
 * ## Warum die Hardware-Uhr dasteht
 *
 * Der Plan hat sie in der Antwort des Agenten aufgezählt (`docs/106 §5`) und in
 * der Tabelle vergessen (`§4`). Ein Feld, das der Agent liest und keine Seite
 * zeigt, ist von aussen nicht von einem zu unterscheiden, das es nicht gibt —
 * also entweder fort oder sichtbar. Sichtbar, denn `LocalRTC=yes` ist eine
 * Fehleinstellung mit Folgen: Die Uhr springt bei jedem Zeitzonenwechsel.
 */
final class ServerTime
{
    /** Was dasteht, wenn `timedatectl` nicht geantwortet hat. */
    public const UNKNOWN = 'nicht feststellbar';

    /**
     * Die Zeilen der Tabelle „Zeit des Servers".
     *
     * `$at` ist der Augenblick, den beide Zeitzeilen zeigen — **ein** Wert und
     * nicht zweimal `now()`. Zwei Aufrufe können über eine Minutengrenze
     * fallen, und dann stünden dort zwei Zeitpunkte, die sich um eine Minute
     * unterscheiden und dasselbe meinen sollen.
     *
     * **Die Zone kommt nicht aus `$answer`, sondern als Argument.** Der erste
     * Wurf holte sie vom Agenten, und `ServerZoneSourceTest` hat das gefangen:
     * Es wäre der zweite Leser derselben Quelle gewesen, und zwei Seiten
     * desselben Panels hätten verschiedene Serverzonen nennen können.
     *
     * Sie steht auch nicht als `ServerZone::known()` im Rumpf. Diese Klasse
     * formt, sie beschafft nicht — sonst liesse sich der Fall „Zone nicht
     * ablesbar" nur auf einem Rechner messen, dessen Symlink kaputt ist.
     *
     * @param  array<string,mixed>  $answer  die Antwort von `system.time`
     * @param  string|null  $zone  die Zone der Maschine, oder `null`
     * @return array<string,string>
     */
    public static function rows(array $answer, ?string $zone, CarbonInterface $at): array
    {
        $lesbar = ($answer['readable'] ?? false) === true;

        return [
            'zone' => $zone === null ? self::UNKNOWN : self::zone($zone, $at),
            'service' => self::service($lesbar, $answer),
            'synchronized' => self::yesNo($lesbar ? ($answer['synchronized'] ?? null) : null),
            'clock' => self::clock($lesbar ? ($answer['local_rtc'] ?? null) : null),

            /*
             * **Die Uhr des Servers ist die, unter der das Panel läuft.** Sie
             * kommt aus `$at` und nicht aus `TimeUSec` — ein zweiter Weg zur
             * selben Zahl wäre die zweite Fassung derselben Regel.
             *
             * Ohne bekannte Zone gibt es keine Zeile: Ein Zeitpunkt ohne Zone
             * ist genau die Angabe, deretwegen es diesen Bereich gibt.
             */
            'now' => $zone === null ? self::UNKNOWN : self::at($at, $zone),

            /*
             * **Die letzte Zeile ist der Grund für den ganzen Bereich**
             * (`docs/80`): dieselbe Angabe, die sonst verwechselt wird, in der
             * Anzeigezone daneben.
             *
             * > **Zwei Angaben, die verwechselt werden können, werden nicht
             * > durch eine Erklärung unterschieden, sondern dadurch, dass man
             * > sie nebeneinander zeigt.**
             *
             * **Auf die Minute genau, wie die Zeile darüber** — und das ist ein
             * Befund der Bilderrunde vom 6. September 2026. Der erste Wurf nahm
             * hier `Clock::display()` mit seinen Sekunden, während „Jetzt auf
             * dem Server" bei der Minute endete.
             *
             * > **Zwei Angaben, die man nebeneinander stellt, damit man sie
             * > vergleicht, brauchen dieselbe Form — sonst vergleicht der Leser
             * > die Form.**
             *
             * **Und `labelAt()` und nicht `label()`**: Berlin heisst im Januar
             * anders als im Juli, und die Beschriftung gehört zum gezeigten
             * Zeitpunkt (`docs/102 §3b`).
             */
            'display' => trim(sprintf(
                '%s %s',
                Clock::minute($at->copy()->utc()->format('Y-m-d H:i:s')) ?? '',
                Clock::labelAt($at->copy()->utc()->format('Y-m-d H:i:s')) ?? Clock::label(),
            )),
        ];
    }

    /**
     * Name **und** Beschriftung, weil beide etwas anderes sagen.
     *
     * `Etc/UTC` heisst beschriftet schlicht `UTC` (gemessen, M14): Wer nur die
     * Beschriftung zeigt, nennt die Zone nicht, und wer nur den Namen zeigt,
     * verschweigt den Versatz.
     */
    private static function zone(string $zone, CarbonInterface $at): string
    {
        $beschriftung = Clock::describeZone($zone, $at);

        return $beschriftung === null || $beschriftung === $zone
            ? $zone
            : sprintf('%s — %s', $zone, $beschriftung);
    }

    /**
     * Der Zeitabgleich in vier Sätzen.
     *
     * @param  array<string,mixed>  $answer
     */
    private static function service(bool $readable, array $answer): string
    {
        if (! $readable) {
            return self::UNKNOWN;
        }

        if (($answer['can_ntp'] ?? null) !== true) {
            return 'kein Zeitdienst installiert';
        }

        return ($answer['ntp'] ?? null) === true ? 'eingeschaltet' : 'ausgeschaltet';
    }

    private static function yesNo(?bool $wert): string
    {
        return match ($wert) {
            true => 'ja',
            false => 'nein',
            default => self::UNKNOWN,
        };
    }

    /**
     * Die Hardware-Uhr.
     *
     * `LocalRTC=no` heisst „läuft in UTC" und ist der gewollte Zustand; `yes`
     * heisst Ortszeit, und die springt mit jedem Zonenwechsel.
     */
    private static function clock(?bool $lokal): string
    {
        return match ($lokal) {
            true => 'Ortszeit',
            false => 'UTC',
            default => self::UNKNOWN,
        };
    }

    /** Ein Augenblick in einer fremden Zone, auf die Minute genau. */
    private static function at(CarbonInterface $at, string $zone): string
    {
        try {
            return $at->copy()->setTimezone($zone)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return self::UNKNOWN;
        }
    }
}
