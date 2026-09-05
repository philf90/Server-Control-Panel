<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Names;
use Throwable;

/**
 * Der Wartungsmodus gegen seine eigene Ankündigung — `maintenance.window`
 * (`docs/101 §5`).
 *
 * ## Das ist, was von der gestrichenen Automatik übrigbleibt
 *
 * Der erste Entwurf von A12 sah ein Fenster mit Anfang und Ende vor, das ein
 * Zeitgeber schaltet. Der Betreiber hat es gestrichen, und der Grund steht in
 * `docs/101 §2`: Ein nginx-Block kennt die Uhr nicht, und fiele der Zeitgeber
 * aus, bliebe jede Kundenwebsite unbegrenzt auf 503.
 *
 * > **Ein Fenster, dessen Ende ein Zeitgeber herstellt, endet nicht, wenn der
 * > Zeitgeber ausfällt — und der Ausfall sieht aus wie ein laufendes
 * > Fenster.**
 *
 * Geblieben ist die Zeitangabe als Auskunft. Diese Prüfung ist ihr Gegenstück:
 * Der Nachtlauf meldet am Morgen, was am Abend vergessen wurde. Sie schaltet
 * nichts — sie sagt nur, dass der Satz auf den Kundenwebsites nicht mehr
 * stimmt.
 *
 * ## Ohne den Agenten
 *
 * Beide Werte liegen im Panel: `enabled` und `until` in den Einstellungen. Ob
 * die Flagdatei wirklich liegt, ist eine andere Frage — sie stellt
 * {@see Verdict::guard()} über die Vhost-Dateien, und
 * zwar je Domain.
 *
 * ## Was sie nicht kann
 *
 * Einen abgelegten Wert, der sich nicht lesen lässt, meldet sie **nicht**. Er
 * kann über die Oberfläche nicht entstehen ({@see Clock::minuteToUtc()} legt
 * ihn an), und „nicht lesbar" ist etwas anderes als „überschritten": Wer das
 * eine als das andere meldete, schickte den Betreiber zum Ausschalten, wo eine
 * Zeile in der Tabelle kaputt ist.
 */
final class MaintenanceWindow implements Check
{
    /** Die Gründe, die diese Prüfung ausspricht. */
    public const REASONS = [
        'maintenance.window' => ['overdue'],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function writes(): array
    {
        return [FindingCheck::MaintenanceWindow];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $log->replace(FindingCheck::MaintenanceWindow, $this->findings($measuredAt), $measuredAt);
    }

    /**
     * Der Befund — oder keiner.
     *
     * **Gemessen wird gegen den Zeitpunkt des Laufs und nicht gegen `now()`.**
     * Dieselbe Regel wie bei {@see Settings::saveDiagnoseRun()}: Sonst stünde
     * neben einer Zeile von 03:00:07 ein Urteil von 03:00:09, und die beiden
     * wären dieselbe Messung.
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function findings(Carbon $measuredAt): array
    {
        $stand = $this->settings->maintenance();

        if ($stand['enabled'] !== true || $stand['until'] === null) {
            return [];
        }

        try {
            $until = CarbonImmutable::parse($stand['until'], 'UTC');
        } catch (Throwable) {
            return [];
        }

        if ($until->greaterThanOrEqualTo($measuredAt)) {
            return [];
        }

        /*
         * **Zwei Zeitpunkte und keine Dauer.** „Seit drei Stunden" wäre eine
         * gezählte Angabe, und die braucht einen Plural, der von der Zahl
         * abhängt. Zwei Zeitpunkte nebeneinander sagen dasselbe und sind in
         * jeder Nacht richtig.
         */
        return [[
            'subject' => Names::host(),
            'reason' => 'overdue',
            'detail' => sprintf(
                'angekündigt bis %s %s; gemessen am %s %s',
                Clock::minute($stand['until']),
                Clock::labelAt($stand['until']),
                Clock::minute($measuredAt->utc()->format('Y-m-d H:i:s')),
                Clock::labelAt($measuredAt->utc()->format('Y-m-d H:i:s')),
            ),
        ]];
    }
}
