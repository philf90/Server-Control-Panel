<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MeasureUsage;
use App\Enums\DailyMetric;
use App\Models\Database;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Databases\Usage as DatabaseUsage;
use App\Support\Metrics\Daily;
use App\Support\Metrics\History;
use App\Support\Subscriptions\Usage as DiskUsage;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\AccessCounts;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SrvPanel\Agent\Client;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Jede Kennzahl, die die Seite liest, hat einen Schreiber.
 *
 * ## Der Fund
 *
 * `docs/138 §0` Punkt 1, 28. September 2026, beim Ausschreiben des
 * Abnahmelaufs für B3: {@see DailyMetric::ofASubscription()} nennt sechs
 * Kennzahlen, {@see Daily::record()} schrieb vier. Die Kacheln „Speicherplatz"
 * und „Datenbanken" standen auf jeder Abonnementseite leer, neben einem
 * gemessenen Wert — gemessen wurde alle fünfzehn Minuten, abgelegt nur der
 * gegenwärtige Wert.
 *
 * **Kein Wächter hat es gesehen, und das lag an ihrer Aufteilung.**
 * `DailyMetricsTest` hält den Schreiber an den vier Kennzahlen, die er
 * schreibt; `DailyHistoryTest` legt die Zeilen für den Leser von Hand an, mit
 * Absicht. Jeder hielt seine Seite, die Naht dazwischen keiner.
 *
 * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
 * > geschriebenen Wert füttern, prüfen die Naht nicht.**
 *
 * ## Wie gemessen wird
 *
 * **Durch die echten Teile bis in die Tabelle und wieder heraus.** Die
 * Messungen gehen durch `apply()` der beiden Messklassen, der Verkehr durch
 * {@see AccessCounts::split()}, abgelegt wird mit beiden Schreibern von
 * {@see Daily}, gelesen mit {@see History}. Nachgebaut ist allein, wer sie
 * ruft: Beide Kommandos brauchen einen Agenten, und {@see Client} ist
 * `final`. Dass `srvpanel:usage` den Schreiber auch ruft, hält deshalb ein
 * eigener Fall am Quelltext — derselbe Griff wie
 * `DailyMetricsTest::test_the_nightly_run_records_before_it_forgets`.
 *
 * ## Was er nicht kann
 *
 * Er sieht nicht, ob der Zeitgeber läuft. Das misst der Abnahmelauf
 * (`docs/138 §1` Block 1).
 */
final class DailyWriterTest extends TestCase
{
    use ReadsMethodSource;
    use RefreshDatabase;
    use WithoutPhpComments;

    private const ZONE = 'Europe/Berlin';

    private function daily(): Daily
    {
        return app(Daily::class);
    }

    private function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }

    /** Ein Abonnement mit Systembenutzer, Domain und einer Datenbank. */
    private function abonnement(string $name = 'beispiel.de', string $systemUser = 'p1001'): Subscription
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($name, $systemUser): Subscription {
            $subscription = Subscription::factory()->create(['name' => $name, 'system_user' => $systemUser]);

            Domain::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => $name,
                'created_at' => '2026-09-01 10:00:00',
            ]);

            Database::factory()->forSubscription($subscription)->create();

            return $subscription;
        });
    }

    /** Eine Messung, wie `srvpanel:usage` sie macht — Platz und Datenbanken. */
    private function messen(Subscription $subscription, int $platzMb, int $datenbankBytes): void
    {
        app(DiskUsage::class)->apply([
            'available' => true,
            'users' => [(string) $subscription->system_user => ['used_mb' => $platzMb]],
        ]);

        $datenbank = app(Tenancy::class)->withoutRestriction(
            fn () => Database::query()->where('subscription_id', $subscription->id)->firstOrFail(),
        );

        app(DatabaseUsage::class)->apply(['available' => true, 'databases' => [(string) $datenbank->name => $datenbankBytes]]);
    }

    /**
     * Ein Nachtlauf, wie `srvpanel:traffic` ihn macht — ohne den Agenten.
     *
     * @param  array<string, array<string, int>>  $tage
     */
    private function nachtlauf(Subscription $subscription, string $heute, array $tage): void
    {
        $split = AccessCounts::split(['domains' => [[
            'subscription' => $subscription->name,
            'domain' => $subscription->name,
            'files' => 2,
            'unreadable' => 0,
            'days' => $tage,
        ]]], $heute);

        $this->daily()->record($split['countable'], $split['quiet'], array_merge($split['skipped'], $split['unread']));
    }

    /** @return array<string, int> Kennzahl → Zahl der Zeilen */
    private function zeilen(string $tabelle, string $spalte, int $kennung): array
    {
        return DB::table($tabelle)
            ->where($spalte, $kennung)
            ->selectRaw('metric, count(*) AS n')
            ->groupBy('metric')
            ->pluck('n', 'metric')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /** @return array<string, int> */
    private function tag(int $kennung, string $day): array
    {
        return DB::table('subscription_metrics')
            ->where('subscription_id', $kennung)
            ->whereDate('day', $day)
            ->pluck('value', 'metric')
            ->map(fn ($wert): int => (int) $wert)
            ->all();
    }

    /**
     * **Die Naht selbst.** Zwei Tage, jeder mit einer Messung und einem
     * Nachtlauf, danach steht jede Kennzahl beider Tabellen da — und keine
     * Kachel der Abonnementseite auf „—".
     *
     * Kommt eine siebte Kennzahl in {@see DailyMetric::ofASubscription()}, ohne
     * dass ein Lauf sie schreibt, ist dieser Fall rot. Genau das war der Befund:
     * eine Kennzahl im Katalog, die der Leser las und niemand schrieb.
     */
    public function test_every_metric_the_page_reads_has_a_writer(): void
    {
        $subscription = $this->abonnement();

        Carbon::setTestNow('2026-09-27 10:00:00');
        $this->messen($subscription, 900, 2_097_152);
        $this->daily()->levels('2026-09-27', $this->zone());
        $this->nachtlauf($subscription, '2026-09-27', ['2026-09-26' => ['requests' => 4, 'sent' => 3011, 'received' => 372, 'errors' => 1, 'legacy' => 0]]);

        Carbon::setTestNow('2026-09-28 10:00:00');
        $this->messen($subscription, 1000, 3_145_728);
        $this->daily()->levels('2026-09-28', $this->zone());
        $this->nachtlauf($subscription, '2026-09-28', []);

        $domain = app(Tenancy::class)->withoutRestriction(fn () => Domain::query()->where('subscription_id', $subscription->id)->firstOrFail());

        $abo = $this->zeilen('subscription_metrics', 'subscription_id', (int) $subscription->id);
        $dom = $this->zeilen('domain_metrics', 'domain_id', (int) $domain->id);

        foreach (DailyMetric::ofASubscription() as $metric) {
            $this->assertSame(2, $abo[$metric->value] ?? 0, sprintf(
                'Die Kennzahl %s eines Abonnements hat nach zwei Tagen nicht zwei Zeilen — liest die Seite sie, ohne dass jemand sie schreibt?',
                $metric->value,
            ));
        }

        foreach (DailyMetric::ofADomain() as $metric) {
            $this->assertSame(2, $dom[$metric->value] ?? 0, sprintf('Die Kennzahl %s einer Domain hat nicht zwei Zeilen.', $metric->value));
        }

        $kacheln = app(Tenancy::class)->withoutRestriction(fn (): array => app(History::class)->forSubscription($subscription));

        foreach ($kacheln as $kachel) {
            $this->assertNotSame('—', $kachel['value'], sprintf('Die Kachel „%s" steht nach zwei Tagen leer.', $kachel['label']));
        }

        Carbon::setTestNow();
    }

    /**
     * **Und `srvpanel:usage` ruft den Schreiber — nach beiden Messungen und mit
     * dem Tag des Servers.**
     *
     * Gelesen am Quelltext, ohne Kommentare: Der Rumpf erklärt, warum der
     * Verlauf nach den Messungen kommt, und nennt dabei dieselben Namen.
     * Davor gerufen, schriebe er die Werte der vorigen Viertelstunde; ohne
     * `setTimezone` gehörte eine Messung um 01:30 Ortszeit noch zum Vortag;
     * und ohne die Frage nach `null` fiele eine unlesbare Zone auf UTC zurück.
     */
    public function test_the_measurement_run_writes_the_levels_after_measuring(): void
    {
        $handle = $this->withoutComments("<?php\n".($this->methodSource(MeasureUsage::class, 'handle') ?? ''));

        $platz = strpos($handle, '->measureDisk(');
        $datenbanken = strpos($handle, '->measureDatabases(');
        $verlauf = strpos($handle, '->recordLevels(');

        $this->assertIsInt($verlauf, 'srvpanel:usage schreibt den Verlauf nicht mehr.');
        $this->assertIsInt($platz);
        $this->assertIsInt($datenbanken);
        $this->assertGreaterThan(max($platz, $datenbanken), $verlauf, 'Der Verlauf wird vor einer der beiden Messungen geschrieben.');

        $schreiben = $this->withoutComments("<?php\n".($this->methodSource(MeasureUsage::class, 'recordLevels') ?? ''));

        $this->assertStringContainsString('ServerZone::current()', $schreiben);
        $this->assertStringContainsString('setTimezone($zone)', $schreiben, 'Der Tag wird nicht in der Zone des Servers gerechnet.');
        $this->assertStringContainsString('$daily->levels(', $schreiben);
        $this->assertMatchesRegularExpression(
            '/if \(\$zone === null\) \{[^}]*return;[^}]*\}.*\$daily->levels\(/s',
            $schreiben,
            'Ohne lesbare Zone muss der Lauf vor dem Schreiben aufhören und darf nicht auf eine andere Uhr zurückfallen.',
        );
    }

    /** Die nächste Messung desselben Tages ersetzt die vorige — der Tag behält die letzte. */
    public function test_the_running_day_is_overwritten_by_the_next_measurement(): void
    {
        $subscription = $this->abonnement();

        Carbon::setTestNow('2026-09-28 08:00:00');
        $this->messen($subscription, 100, 1_000);
        $this->daily()->levels('2026-09-28', $this->zone());

        Carbon::setTestNow('2026-09-28 20:00:00');
        $this->messen($subscription, 250, 5_000);
        $this->daily()->levels('2026-09-28', $this->zone());

        $this->assertSame(
            [DailyMetric::DatabaseBytes->value => 5_000, DailyMetric::DiskMb->value => 250],
            collect($this->tag((int) $subscription->id, '2026-09-28'))->sortKeys()->all(),
        );
        $this->assertSame(2, DB::table('subscription_metrics')->where('subscription_id', $subscription->id)->count());

        Carbon::setTestNow();
    }

    /**
     * **Eine Messung gehört zum Tag des Servers.** 23:30 UTC ist in Berlin
     * schon der nächste Tag. Nach UTC abgelegt, stünde sie am Vortag und
     * überschriebe dessen letzte Messung.
     */
    public function test_a_measurement_belongs_to_the_day_of_the_server(): void
    {
        $subscription = $this->abonnement();

        Carbon::setTestNow('2026-09-27 23:30:00');
        $this->messen($subscription, 700, 1_000);

        $this->assertSame(['disk' => 0, 'databases' => 0], $this->daily()->levels('2026-09-27', $this->zone()),
            'Um 23:30 UTC ist in Berlin der 28. — für den 27. ist diese Messung zu spät.');
        $this->assertSame(['disk' => 1, 'databases' => 1], $this->daily()->levels('2026-09-28', $this->zone()));

        Carbon::setTestNow();
    }

    /**
     * **Ein Tag bekommt nur, was an ihm gemessen wurde.** Fällt die Messung aus,
     * bleibt der Tag ohne Zeile, statt den Wert von gestern als heutigen
     * abzulegen.
     */
    public function test_a_value_from_yesterday_is_not_written_for_today(): void
    {
        $subscription = $this->abonnement();

        Carbon::setTestNow('2026-09-27 10:00:00');
        $this->messen($subscription, 700, 1_000);

        Carbon::setTestNow('2026-09-28 10:00:00');
        $geschrieben = $this->daily()->levels('2026-09-28', $this->zone());

        $this->assertSame(['disk' => 0, 'databases' => 0], $geschrieben);
        $this->assertSame([], $this->tag((int) $subscription->id, '2026-09-28'));

        Carbon::setTestNow();
    }

    /**
     * **Die Datenbanken zählen nur, wenn jede an dem Tag gemessen wurde.** Eine
     * Summe aus einem frischen und einem alten Wert wäre eine Zahl, die es nie
     * gab — und in beide Richtungen: Sind beide frisch, steht ihre Summe da.
     */
    public function test_the_databases_count_only_when_every_one_was_measured_that_day(): void
    {
        $subscription = $this->abonnement();

        $zweite = app(Tenancy::class)->withoutRestriction(
            fn () => Database::factory()->forSubscription($subscription, 'blog')->create(),
        );

        Carbon::setTestNow('2026-09-27 10:00:00');
        $this->messen($subscription, 700, 1_000);
        app(DatabaseUsage::class)->apply(['available' => true, 'databases' => [(string) $zweite->name => 20]]);

        Carbon::setTestNow('2026-09-28 10:00:00');
        $this->messen($subscription, 700, 3_000);

        $einer = app(Tenancy::class)->withoutRestriction(fn () => Database::query()->whereKey($zweite->id)->firstOrFail());
        $einer->forceFill(['size_measured_at' => '2026-09-27 10:00:00'])->saveQuietly();

        $this->daily()->levels('2026-09-28', $this->zone());
        $this->assertArrayNotHasKey(DailyMetric::DatabaseBytes->value, $this->tag((int) $subscription->id, '2026-09-28'),
            'Eine der beiden Datenbanken ist von gestern — der Tag bekommt keine Summe.');

        $einer->forceFill(['size_bytes' => 40, 'size_measured_at' => '2026-09-28 09:00:00'])->saveQuietly();

        $this->daily()->levels('2026-09-28', $this->zone());
        $this->assertSame(3_040, $this->tag((int) $subscription->id, '2026-09-28')[DailyMetric::DatabaseBytes->value] ?? -1);

        Carbon::setTestNow();
    }

    /** Ein Abonnement ohne Datenbank belegt dort nichts — und das steht als Null da. */
    public function test_a_subscription_without_a_database_gets_a_zero(): void
    {
        $subscription = app(Tenancy::class)->withoutRestriction(
            fn () => Subscription::factory()->create(['name' => 'leer.de', 'system_user' => 'p1002']),
        );

        Carbon::setTestNow('2026-09-28 10:00:00');
        $this->daily()->levels('2026-09-28', $this->zone());

        $this->assertSame(0, $this->tag((int) $subscription->id, '2026-09-28')[DailyMetric::DatabaseBytes->value] ?? -1);
        $this->assertArrayNotHasKey(DailyMetric::DiskMb->value, $this->tag((int) $subscription->id, '2026-09-28'),
            'Der Platz ist nie gemessen worden — eine Null wäre erfunden.');

        Carbon::setTestNow();
    }
}
