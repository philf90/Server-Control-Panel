<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\FindingCheck;
use App\Models\Backup;
use App\Models\Finding;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Diagnose\Checks\LatestBackups;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Notices;
use App\Support\Plans\Feature;
use App\Support\Plans\Quotas;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ist die jüngste Sicherung eines Abonnements gescheitert? — `backup.latest`
 * (B9, `docs/142 §8`).
 *
 * Gefahren wird die Prüfung so, wie der Nachtlauf sie fährt: **ohne
 * angemeldetes Konto.** Im Grundzustand steht jede Abfrage auf
 * `whereRaw('0 = 1')`, und eine Prüfung ohne Klammerlösung fände nichts — und
 * das sähe aus wie „alle Sicherungen gelungen".
 *
 * **Jede Richtung hat ihren eigenen Fall**, weil jede an einer eigenen
 * Bedingung hängt: die jüngste fertige gescheitert, eine jüngere gelungen,
 * eine laufende übergangen, eine, die gerade entfernt wird, als gelungen
 * gezählt, und ein Abonnement, das gar nicht gesichert werden kann.
 */
final class LatestBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die gemerkte Zone vergessen — sie überlebt `RefreshDatabase`, und der
     * nächste Test läse sonst Berlin, ohne es gesetzt zu haben.
     */
    protected function tearDown(): void
    {
        Clock::forget();

        parent::tearDown();
    }

    private function fahre(): void
    {
        (new LatestBackups(app(Tenancy::class)))->run(Carbon::parse('2026-10-08 00:40:00', 'UTC'), app(FindingLog::class));
    }

    /** @return list<string> die Abonnements mit Befund */
    private function befunde(): array
    {
        return Finding::query()
            ->where('check', FindingCheck::BackupLatest->value)
            ->orderBy('subject')
            ->pluck('subject')
            ->all();
    }

    private function abo(string $name, bool $sicherungen = true): Subscription
    {
        $features = Quotas::featureDefaults();
        $features[Feature::Backups->value] = $sicherungen;

        return Subscription::factory()->create([
            'name' => $name,
            'plan_id' => Plan::factory()->create(['features' => $features])->id,
        ]);
    }

    /** Eine Sicherung mit festem Zeitpunkt — jünger heisst später angelegt. */
    private function sicherung(Subscription $abo, BackupStatus $status, string $erstellt, ?string $meldung = null): Backup
    {
        $backup = Backup::factory()->forSubscription($abo)->create([
            'status' => $status,
            'last_error' => $meldung,
        ]);

        $backup->forceFill(['created_at' => Carbon::parse($erstellt, 'UTC')])->save();

        return $backup;
    }

    public function test_a_failed_latest_backup_is_a_finding_with_its_time_and_reason(): void
    {
        // Eine Zone mit Versatz: In UTC sähe eine fehlende Umrechnung aus wie
        // eine gelungene.
        Clock::store('Europe/Berlin');

        $abo = $this->abo('kunde-a');
        $this->sicherung($abo, BackupStatus::Ready, '2026-10-06 01:31:00');
        $this->sicherung($abo, BackupStatus::Failed, '2026-10-07 01:31:00', 'Zu wenig Platz für die Sicherung');

        $this->fahre();

        $finding = Finding::query()->where('check', FindingCheck::BackupLatest->value)->sole();

        $this->assertSame('kunde-a', $finding->subject);
        $this->assertSame('failed', $finding->reason);
        $this->assertSame('erstellt 2026-10-07 03:31 CEST (UTC+02:00): Zu wenig Platz für die Sicherung', $finding->detail);
    }

    /** Eine gelungene danach — gleich ob von Hand oder aus der Nacht — nimmt ihn zurück (Frage 4). */
    public function test_a_younger_success_takes_it_back(): void
    {
        $abo = $this->abo('kunde-a');
        $this->sicherung($abo, BackupStatus::Failed, '2026-10-06 01:31:00', 'Zu wenig Platz');
        $this->sicherung($abo, BackupStatus::Ready, '2026-10-07 01:31:00');

        $this->fahre();

        $this->assertSame([], $this->befunde());
    }

    /**
     * Eine laufende hat noch keinen Ausgang — neben ihr zählt die gescheiterte.
     *
     * Zählte sie als gelungen, verschwände der Befund in jeder Nacht, in der
     * die Diagnose neben einer laufenden Sicherung steht, und käme danach mit
     * neuem „steht seit" zurück.
     */
    public function test_a_running_backup_is_passed_over(): void
    {
        $abo = $this->abo('kunde-a');
        $this->sicherung($abo, BackupStatus::Failed, '2026-10-07 01:31:00', 'Zu wenig Platz');
        $this->sicherung($abo, BackupStatus::Pending, '2026-10-08 00:35:00');

        $this->fahre();

        $this->assertSame(['kunde-a'], $this->befunde());
    }

    /** Eine, die gerade entfernt wird, war gelungen, bevor jemand auf „Entfernen" drückte. */
    public function test_a_backup_being_removed_counts_as_a_success(): void
    {
        $abo = $this->abo('kunde-a');
        $this->sicherung($abo, BackupStatus::Failed, '2026-10-06 01:31:00', 'Zu wenig Platz');
        $this->sicherung($abo, BackupStatus::Removing, '2026-10-07 01:31:00');

        $this->fahre();

        $this->assertSame([], $this->befunde());
    }

    /**
     * Ein Plan ohne Sicherungen und ein gesperrtes Abonnement haben keinen —
     * daneben steht eines, das ihn hat, damit die Null eine Messung ist.
     */
    public function test_only_what_can_be_backed_up_is_judged(): void
    {
        $ohne = $this->abo('ohne-sicherungen', sicherungen: false);
        $this->sicherung($ohne, BackupStatus::Failed, '2026-10-07 01:31:00', 'Zu wenig Platz');

        $gesperrt = $this->abo('gesperrt');
        $gesperrt->forceFill(['status' => 'suspended'])->save();
        $this->sicherung($gesperrt, BackupStatus::Failed, '2026-10-07 01:31:00', 'Zu wenig Platz');

        $mit = $this->abo('kunde-a');
        $this->sicherung($mit, BackupStatus::Failed, '2026-10-07 01:31:00', 'Zu wenig Platz');

        $this->fahre();

        $this->assertSame(['kunde-a'], $this->befunde(),
            'Ein Abonnement, das keine Sicherung mehr bekommen kann, hätte einen Befund, den nichts mehr ablöst.');
    }

    /** Ohne Sicherung gibt es nichts zu melden — und eine leere Meldung trägt ihren Satz. */
    public function test_no_backup_is_no_finding_and_a_missing_reason_says_so(): void
    {
        $this->abo('noch-nie');

        $abo = $this->abo('kunde-a');
        $this->sicherung($abo, BackupStatus::Failed, '2026-10-07 01:31:00');

        $this->fahre();

        $this->assertSame(['kunde-a'], $this->befunde());
        $this->assertStringEndsWith(': ohne Meldung des Vorgangs', (string) Finding::query()->sole()->detail);
    }

    /**
     * Gemeldet in der ersten Nacht, die sie sieht (`docs/142 §6`, Frage 3).
     *
     * Mit den zwanzig Stunden aus B1 würfelte die Meldung (`docs/142 §3` M3).
     */
    public function test_it_is_due_the_moment_it_is_seen(): void
    {
        $this->assertSame(0, Notices::holdMinutes(FindingCheck::BackupLatest));
        $this->assertSame(Notices::HOLD_HOURS * 60, Notices::holdMinutes(FindingCheck::TlsExpiry),
            'Die Laufzeit eines Zertifikats meldet wie alles aus der Nacht nach zwei Läufen.');
    }
}
