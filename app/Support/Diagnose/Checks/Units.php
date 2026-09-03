<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Catalog;
use SrvPanel\Agent\Client;

/**
 * D · Units und Termine — `unit.state`, `unit.schedule` (`docs/98 §3 D`).
 *
 * ## A10 baut das nicht neu, es fragt es
 *
 * A2 hat den Leser gebaut: `system.units.list` beantwortet die Units aus
 * `Catalog::all()` in **einem** Aufruf, `Units::markScheduled()` sagt je
 * Dienst, ob ein Timer ihn startet, und `Units::hasNext()` entscheidet über den
 * nächsten Termin aus dem Feldpaar — die Realtime-Spalte allein ist auch beim
 * gesunden monotonen Timer leer (`docs/89` M4). Diese Klasse liest die Zeilen
 * und urteilt; sie fragt systemd kein zweites Mal.
 *
 * ## Das Urteil steht zweimal, und das ist benannt
 *
 * Die Dienste-Seite urteilt in `useUnitState.ts` (`rang()`), die Nacht in
 * {@see self::judge()}. Beide können sich keinen Code teilen: das eine läuft
 * im Browser, das andere im Panel. `UnitVerdictTest` hält deshalb den Rumpf
 * der Seite als **Schnappschuss** fest — ändert sich die Seite, wird er rot und
 * verlangt, dass die Nacht nachzieht. Das belegt keine Gleichheit; es
 * verhindert, dass eine der beiden Fassungen still abdriftet (`docs/98 §11`).
 *
 * ## Was nachts anders ist als auf der Seite
 *
 * **`activating` ist nachts kein Befund.** Die Seite färbt es gelb („startet
 * neu"), weil sie den Augenblick zeigt. Ein `Type=oneshot`-Dienst steht
 * während seines ganzen Laufs auf `activating` — `srvpanel-usage.service`
 * etwa, dessen Timer in derselben Stunde feuern kann wie dieser Lauf. Als
 * Befund wäre das jede Nacht eine Zeile über einen Dienst, der tut, was er
 * soll (`docs/98 §4`). Eine Neustartschleife endet in `failed`, und das wird
 * gemeldet.
 *
 * **Ein Timer ohne Termin ist ein Befund an `unit.schedule` und keiner an
 * `unit.state`.** Ein gestoppter Timer meldet `ActiveState=inactive` **und**
 * keinen Termin; gemeldet wird der Termin, weil er das ist, woran der
 * Betreiber erkennt, dass nichts mehr läuft (`docs/98 §7`, Punkt 3) — und
 * weil zwei Zeilen für einen Schaden die Falle aus §4 wären.
 *
 * **Ein Dienst, den ein Timer startet, darf stillstehen.** `scheduled` kommt
 * aus `Triggers` am Timer und nicht aus einer Liste hier (`docs/91`). Die vier
 * `Type=oneshot`-Dienste dieses Pakets stehen deshalb den ganzen Tag auf
 * `inactive`, ohne dass etwas fehlt; `srvpanel-metrics.service` dagegen hat
 * keinen Timer und `Restart=always` — es **muss** laufen. Beides steht in den
 * Unit-Dateien und nicht in einer Liste hier.
 *
 * > **Wer eine Erwartung an eine Unit aufschreibt, liest vorher ihre
 * > Unit-Datei.** (`docs/91`)
 */
final class Units implements Check
{
    /**
     * Die Gründe, die diese Prüfung ausspricht — je Schlüssel.
     *
     * `DiagnoseSeamTest` hält sie gegen `FindingCheck` in beide Richtungen.
     */
    public const REASONS = [
        'unit.state' => ['inactive', 'failed', 'not_installed', FindingCheck::UNREACHABLE],
        'unit.schedule' => ['no_next', FindingCheck::UNREACHABLE],
    ];

    public function __construct(private readonly Client $agent) {}

    public function writes(): array
    {
        return [FindingCheck::UnitState, FindingCheck::UnitSchedule];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        try {
            $answer = $this->agent->call('system.units.list');
        } catch (AgentException $e) {
            // Die Gegenstände bleiben die eigenen Units: „worüber gerade nichts
            // bekannt ist" braucht einen Ort, sonst kann niemand etwas damit
            // anfangen (`FindingLog::unreachable()`).
            $log->unreachable(FindingCheck::UnitState, Catalog::OWN, $measuredAt, $e->getMessage());
            $log->unreachable(FindingCheck::UnitSchedule, self::ownTimers(), $measuredAt, $e->getMessage());

            return;
        }

        $units = $answer['units'] ?? [];
        $verdict = self::judge(is_array($units) ? array_values($units) : []);

        $log->replace(FindingCheck::UnitState, $verdict['state'], $measuredAt);
        $log->replace(FindingCheck::UnitSchedule, $verdict['schedule'], $measuredAt);
    }

    /**
     * Das Urteil über die Zeilen von `system.units.list` — ohne den Agenten.
     *
     * Die Reihenfolge ist die der Seite: erst „gibt es die Unit", dann „hat der
     * Timer einen Termin", dann der Zustand. Ein Timer ohne Termin kommt
     * deshalb nie zusätzlich als `inactive` heraus.
     *
     * @param  list<mixed>  $units
     * @return array{state: list<array{subject: string, reason: string, detail: null|string}>, schedule: list<array{subject: string, reason: string, detail: null|string}>}
     */
    public static function judge(array $units): array
    {
        $state = [];
        $schedule = [];

        foreach ($units as $unit) {
            if (! is_array($unit) || ! is_string($unit['unit'] ?? null) || $unit['unit'] === '') {
                continue;
            }

            $name = $unit['unit'];
            $active = is_string($unit['active_state'] ?? null) ? $unit['active_state'] : 'unknown';
            $sub = is_string($unit['sub_state'] ?? null) ? $unit['sub_state'] : 'unknown';

            // Der Wortlaut von systemd ist die Ausgabe — gezeigt, nie gedeutet.
            $detail = sprintf('ActiveState=%s SubState=%s', $active, $sub);

            if (($unit['present'] ?? false) !== true) {
                // **Nur die eigenen.** `Catalog::pick()` fällt auf den ersten
                // Kandidaten einer Rolle zurück, wenn keiner installiert ist —
                // auf einem Server ohne MariaDB kommt `mariadb.service` als
                // `not-found` zurück, und das ist keine Auskunft über einen
                // Schaden, sondern über die Geschmacksrichtung. Gemeldet würde
                // sie jede Nacht: die Falle aus `docs/98 §4`.
                //
                // Für eine fremde Unit sagt A10 über die **Anwesenheit** nichts.
                // Das Panel installiert sie nicht, und welche Unit eine Rolle
                // bedient, entscheidet der Betreiber.
                if (($unit['own'] ?? false) === true) {
                    $state[] = self::finding($name, 'not_installed', 'LoadState=not-found');
                }

                continue;
            }

            if (($unit['kind'] ?? null) === 'timer' && ($unit['has_next'] ?? null) === false) {
                $schedule[] = self::finding($name, 'no_next', $detail);

                continue;
            }

            if ($active === 'active' || $active === 'activating') {
                continue;
            }

            if ($active === 'failed') {
                $state[] = self::finding($name, 'failed', $detail);

                continue;
            }

            if ($active === 'inactive' && ($unit['scheduled'] ?? null) === true) {
                continue;
            }

            $state[] = self::finding($name, 'inactive', $detail);
        }

        return ['state' => $state, 'schedule' => $schedule];
    }

    /**
     * Die eigenen Timer — die Gegenstände von `unit.schedule`, wenn der Agent
     * nicht antwortet.
     *
     * @return list<string>
     */
    public static function ownTimers(): array
    {
        return array_values(array_filter(
            Catalog::OWN,
            static fn (string $unit): bool => str_ends_with($unit, '.timer'),
        ));
    }

    /** @return array{subject: string, reason: string, detail: null|string} */
    private static function finding(string $subject, string $reason, ?string $detail): array
    {
        return ['subject' => $subject, 'reason' => $reason, 'detail' => $detail];
    }
}
