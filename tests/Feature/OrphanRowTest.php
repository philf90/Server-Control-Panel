<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Finding;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Diagnose\Checks\Orphans;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Host;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificatePrune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Cron\CronFile;
use Tests\TestCase;

/**
 * Verwaiste Zeilen werden gemeldet und nicht gelöscht (A10, `docs/98 §3 H`).
 *
 * ## Die drei Fälle sind nicht derselbe Fall
 *
 * **Ein Zertifikat** ist ein Rest, wenn keine lebende Domain es mehr deckt —
 * und **gefragt wird die Deckung und nicht die Zuordnung** (`docs/78`). Diese
 * Prüfung fragt deshalb {@see CertificatePrune} und baut keine eigene Abfrage:
 * Die zweite Fassung meldete den Schlüssel unter einer laufenden Website.
 *
 * **Ein Systembenutzer** ist als Zeile in `system_users` **kein** Rest — die
 * Reservierung gilt für immer (`docs/35`), und eine Zeile ohne Abonnement ist
 * der Normalzustand nach jedem Rückbau. Sie jede Nacht zu melden wäre die Falle
 * aus `docs/98 §4`. Ein Rest ist erst das **Unix-Konto**, das dazu noch
 * existiert: Dann hat `subscription.remove` sein `userdel` nicht getan.
 *
 * **Eine Cron-Datei** entfernt `cron.apply`, sobald kein Job mehr aktiv ist —
 * eine, zu der es keinen aktiven Job gibt, ist also ein Rest.
 */
final class OrphanRowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $konten
     * @param  list<string>  $cron
     */
    private function host(array $konten = [], array $cron = []): Host
    {
        return new class($konten, $cron) implements Host
        {
            /**
             * @param  list<string>  $konten
             * @param  list<string>  $cron
             */
            public function __construct(private readonly array $konten, private readonly array $cron) {}

            public function uidOf(string $user): ?int
            {
                return in_array($user, $this->konten, true) ? 1000 : null;
            }

            public function ownerOf(string $path): ?int
            {
                return null;
            }

            public function cronFiles(): array
            {
                return $this->cron;
            }
        };
    }

    /**
     * Einen Lauf fahren.
     *
     * **Nicht `run()`.** Der Name gehört `PHPUnit\Framework\TestCase` und ist
     * dort `final`; eine Kollision tötet den ganzen Lauf beim **Laden** der
     * Klasse, bevor irgendein Wächter rot werden kann. Zum sechsten Mal in
     * diesem Repo — deshalb gibt es `BaseMethodClashTest`, und deshalb ist er
     * der einzige Wächter, dessen Regel sich nicht brechen lässt.
     */
    private function fahre(Host $host): void
    {
        (new Orphans(app(CertificatePrune::class), $host, app(Tenancy::class)))
            ->run(Carbon::parse('2026-09-02 03:00:00'), new FindingLog);
    }

    /** @return list<string> je Befund `reason|subject` */
    private function findings(): array
    {
        return Finding::query()
            ->where('check', FindingCheck::OrphanRow->value)
            ->orderBy('subject')
            ->get()
            ->map(fn (Finding $finding): string => $finding->reason.'|'.$finding->subject)
            ->all();
    }

    public function test_a_healthy_server_yields_nothing(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
        Certificate::factory()->covering(['kunde.invalid'])->create([
            'subscription_id' => $subscription->id,
            'storage_name' => 'kunde.invalid',
        ]);
        $subscription->domains()->create([
            'name' => 'kunde.invalid',
            'type' => 'main',
            'status' => 'active',
            'document_root' => 'httpdocs',
        ]);

        $this->fahre($this->host(['p1000']));

        $this->assertSame([], $this->findings());
    }

    /** Die Reservierung ist kein Rest — das Konto daneben schon. */
    public function test_a_reserved_number_without_an_account_is_not_a_finding(): void
    {
        SystemUser::query()->create(['number' => 1000, 'subscription' => 'weg.invalid']);

        $this->fahre($this->host());
        $this->assertSame([], $this->findings(), 'Eine Reservierung ohne Abonnement ist der Normalzustand nach jedem Rückbau.');

        $this->fahre($this->host(['p1000']));
        $this->assertSame(['system_user|p1000'], $this->findings(), 'Das Unix-Konto lebt weiter — userdel ist nicht gelaufen.');
    }

    /** Ein Konto, das seinem Abonnement gehört, ist kein Rest. */
    public function test_a_number_a_subscription_still_holds_is_not_a_finding(): void
    {
        Subscription::factory()->create(['system_user' => 'p1000']);
        SystemUser::query()->create(['number' => 1000, 'subscription' => 'kunde.invalid']);

        $this->fahre($this->host(['p1000']));

        $this->assertSame([], $this->findings());
    }

    public function test_a_certificate_without_a_covered_domain_is_reported_and_kept(): void
    {
        $subscription = Subscription::factory()->create();
        $certificate = Certificate::factory()->covering(['weg.invalid'])->create([
            'subscription_id' => $subscription->id,
            'storage_name' => 'weg.invalid',
        ]);

        $this->fahre($this->host());

        $this->assertSame(['certificate|weg.invalid'], $this->findings());
        $this->assertNotNull(
            app(Tenancy::class)->withoutRestriction(fn () => Certificate::query()->withoutGlobalScopes()->find($certificate->id)),
            'Die Zeile ist fort — eine Diagnose, die aufräumt, ist der nächste Schreiber (docs/98 §5.1).',
        );
    }

    public function test_a_cron_file_without_an_active_job_is_a_finding(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
        $pfad = CronFile::DIR.'/'.CronFile::name('p1000');

        $this->fahre($this->host([], [$pfad]));
        $this->assertSame(['cron_file|'.$pfad], $this->findings(), 'Ohne aktiven Job hätte cron.apply die Datei entfernt.');

        CronJob::factory()->create(['subscription_id' => $subscription->id, 'active' => true]);
        $this->fahre($this->host([], [$pfad]));
        $this->assertSame([], $this->findings());
    }

    /** Ein pausierter Job hält die Datei nicht — `cron.apply` entfernt sie dann. */
    public function test_a_paused_job_does_not_keep_the_file(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
        CronJob::factory()->paused()->create(['subscription_id' => $subscription->id]);
        $pfad = CronFile::DIR.'/'.CronFile::name('p1000');

        $this->fahre($this->host([], [$pfad]));

        $this->assertSame(['cron_file|'.$pfad], $this->findings());
    }

    /** Was der Lauf nicht mehr nennt, ist behoben — Punkt 2 des Abnahmekriteriums. */
    public function test_a_finding_that_is_gone_disappears(): void
    {
        SystemUser::query()->create(['number' => 1000, 'subscription' => 'weg.invalid']);

        $this->fahre($this->host(['p1000']));
        $this->assertSame(['system_user|p1000'], $this->findings());

        $this->fahre($this->host());
        $this->assertSame([], $this->findings());
    }
}
