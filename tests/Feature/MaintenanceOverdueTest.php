<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Models\Finding;
use App\Support\Diagnose\Checks\MaintenanceWindow;
use App\Support\Diagnose\FindingLog;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `overdue` — der Wartungsmodus gegen seine eigene Ankündigung
 * (`docs/101 §5`).
 *
 * **Das ist, was von der gestrichenen Automatik übrigbleibt.** Ein Fenster,
 * dessen Ende ein Zeitgeber herstellt, endet nicht, wenn der Zeitgeber
 * ausfällt — und der Ausfall sähe aus wie ein laufendes Fenster. Diese Prüfung
 * schaltet nichts; sie sagt am Morgen, dass der Satz auf den Kundenwebsites
 * nicht mehr stimmt.
 */
final class MaintenanceOverdueTest extends TestCase
{
    use RefreshDatabase;

    /** Der Lauf, gegen einen genannten Zeitpunkt. */
    private function fahren(string $measuredAt): void
    {
        app(MaintenanceWindow::class)->run(Carbon::parse($measuredAt, 'UTC'), new FindingLog);
    }

    private function ablegen(bool $enabled, ?string $ortszeit): void
    {
        Clock::store('Europe/Berlin');

        $utc = $ortszeit === null ? null : Clock::minuteToUtc($ortszeit);
        app(Settings::class)->saveMaintenance($enabled, $utc);
    }

    public function test_an_overdue_window_is_reported(): void
    {
        $this->ablegen(true, '2026-09-05 13:35');
        $this->fahren('2026-09-05 20:00:00');

        $finding = Finding::query()->sole();

        self::assertSame(FindingCheck::MaintenanceWindow->value, $finding->check->value);
        self::assertSame('overdue', $finding->reason);
        self::assertSame(FindingState::Warn, $finding->check->state($finding->reason));

        // Der Satz nennt Ortszeit **und** Zone — ein Besucher der Wartungsseite
        // liest dieselbe Angabe, und der Betreiber soll sie wiedererkennen.
        self::assertStringContainsString('2026-09-05 13:35', (string) $finding->detail);
        self::assertStringContainsString('CEST (UTC+02:00)', (string) $finding->detail);
    }

    /**
     * Gemessen wird gegen den Zeitpunkt des Laufs und nicht gegen `now()`.
     *
     * **Derselbe Bestand, zwei Läufe, zwei Ergebnisse** — das ist die Aussage.
     * Ein Lauf, der `now()` fragte, hinge daran, wann jemand ihn startet, und
     * die Zeile daneben („zuletzt gemessen um …") nennte eine andere Messung.
     */
    public function test_the_run_is_judged_against_its_own_timestamp(): void
    {
        $this->ablegen(true, '2026-09-05 13:35');

        $this->fahren('2026-09-05 10:00:00');
        self::assertSame(0, Finding::query()->count(), 'Vor der Endzeit gemessen und trotzdem gemeldet.');

        $this->fahren('2026-09-05 20:00:00');
        self::assertSame(1, Finding::query()->count(), 'Nach der Endzeit gemessen und nicht gemeldet.');
    }

    /** Ausgeschaltet ist kein Befund — und der alte verschwindet wieder. */
    public function test_switching_off_clears_the_finding(): void
    {
        $this->ablegen(true, '2026-09-05 13:35');
        $this->fahren('2026-09-05 20:00:00');
        self::assertSame(1, Finding::query()->count());

        $this->ablegen(false, '2026-09-05 13:35');
        $this->fahren('2026-09-05 21:00:00');

        self::assertSame(0, Finding::query()->count(), 'Der Befund bleibt stehen, nachdem der Modus aus ist.');
    }

    /** Eingeschaltet ohne Zeitangabe: Es gibt nichts, was überschritten sein könnte. */
    public function test_without_an_end_time_there_is_nothing_to_miss(): void
    {
        $this->ablegen(true, null);
        $this->fahren('2030-01-01 00:00:00');

        self::assertSame(0, Finding::query()->count());
    }
}
