<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DailyMetric;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Database;
use App\Models\Finding;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Cron\ServerZone;
use App\Support\Diagnose\Checks\QuotaOverrun;
use App\Support\Diagnose\FindingLog;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Liegt ein Abonnement über einem seiner Kontingente — B5, `docs/129 §9`.
 *
 * Gefahren wird die Prüfung so, wie der Nachtlauf sie fährt: **ohne
 * angemeldetes Konto**. Das ist kein Beiwerk, sondern der Fall, an dem der
 * erste Wurf dieser Klasse gescheitert wäre —
 * `Subscription::databaseUsedMb()` fragt eine zweite Tabelle, und im
 * Grundzustand steht jede Abfrage auf `whereRaw('0 = 1')`.
 *
 * > **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie: Die
 * > andere fällt nicht auf, weil sie leise das Richtige tut — nämlich
 * > nichts.**
 *
 * ## Was dieser Wächter nicht kann
 *
 * Den Grund `traffic_unknown` stellt er nicht her. Er entsteht, wenn
 * {@see ServerZone::current()} `null` gibt; der Symlink, den sie liest, steht
 * dort als private Konstante und ist in diesem Prüfstand lesbar. Dass ein
 * solcher Befund **nicht** an den Kunden geht, misst `NotificationLedgerTest`.
 *
 * **Der Pfad steht hier absichtlich nicht ausgeschrieben.**
 * `ServerZoneSourceTest` liest den Quelltext **roh** und streift die Kommentare
 * nicht ab; ein Satz, der ihn nennt, gilt ihm als zweite Stelle, die den
 * Rechner nach seiner Zone fragt. Der erste Wurf dieses Kopfes war genau
 * deshalb rot.
 *
 * > **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
 * > Messung fälschlich rot.**
 */
final class QuotaOverrunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Einen Lauf fahren.
     *
     * **Nicht `run()`** — der Name gehört `PHPUnit\Framework\TestCase` und ist
     * dort `final`. Eine Klasse, die ihn überschreibt, stirbt beim **Laden**,
     * und dann läuft nicht ein Fall weniger, sondern gar keiner.
     * `BaseMethodClashTest` hält es; hier hat es der Fatal Error getan, weil
     * der Name beim Schreiben naheliegend war.
     *
     * > **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern
     * > eine Gewohnheit.**
     */
    private function fahre(Carbon $at): void
    {
        (new QuotaOverrun(app(Tenancy::class)))->run($at, app(FindingLog::class));
    }

    /** @return list<string> die Gründe, die zu diesem Abonnement stehen */
    private function reasons(string $subject = 'p1000'): array
    {
        return Finding::query()
            ->where('check', FindingCheck::QuotaExceeded->value)
            ->where('subject', $subject)
            ->orderBy('reason')
            ->pluck('reason')
            ->all();
    }

    /** @param array<string, mixed> $attributes */
    private function abonnement(array $attributes): Subscription
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): Subscription => Subscription::factory()->create(['name' => 'p1000'] + $attributes),
        );
    }

    public function test_disk_over_its_quota_is_a_finding(): void
    {
        $this->abonnement(['disk_used_mb' => 1024, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame(['disk_over'], $this->reasons());
        self::assertSame('1.024 MB von 500 MB', Finding::query()->firstOrFail()->detail,
            'Der gemessene Wert und seine Grenze stehen daneben — ohne sie ist der Befund eine '
            .'Behauptung, die der Kunde nicht nachrechnen kann.');
    }

    /** Und die Gegenrichtung: unter der Grenze steht nichts. */
    public function test_disk_below_its_quota_is_quiet(): void
    {
        $this->abonnement(['disk_used_mb' => 400, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame([], $this->reasons());
    }

    /**
     * Ein ungemessener Wert ist kein Befund.
     *
     * Der Rückfall auf 0 wäre die bequemere von zwei falschen Auskünften: Er
     * sagte „alles in Ordnung" über etwas, das niemand nachgesehen hat.
     */
    public function test_an_unmeasured_value_is_not_a_finding(): void
    {
        $this->abonnement(['disk_used_mb' => null, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame([], $this->reasons());
    }

    /**
     * Und ein Kontingent von 0 ist keine Grenze.
     *
     * Im Katalog heisst 0 „unbegrenzt" oder „nicht angeboten"; dagegen zu
     * vergleichen machte aus jedem Kunden einen Überschreiter.
     */
    public function test_a_quota_of_zero_is_no_limit(): void
    {
        $this->abonnement(['disk_used_mb' => 5000, 'quota_overrides' => ['disk_mb' => 0]]);
        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame([], $this->reasons());
    }

    /**
     * Die Datenbanken — und dieser Fall misst zugleich die Mandantenklammer.
     *
     * Die Grösse steht in `databases`, nicht in `subscriptions`. Liefe die
     * Abfrage ohne gelöste Klammer, käme „nicht gemessen" heraus und dieser
     * Fall wäre rot.
     */
    public function test_databases_over_their_quota_is_a_finding(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 1, 'quota_overrides' => ['disk_mb' => 5000, 'database_mb' => 100]]);

        app(Tenancy::class)->withoutRestriction(static function () use ($abo): void {
            Database::factory()->create([
                'subscription_id' => $abo->id,
                'size_bytes' => 300 * 1024 * 1024,
            ]);
        });

        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame(['databases_over'], $this->reasons());
    }

    /**
     * Der Verkehr wird über den **Kalendermonat** gezählt.
     *
     * Ein rollendes Fenster von dreissig Tagen ergäbe eine andere Zahl, und
     * das Kontingent heisst „Traffic je Monat".
     */
    public function test_traffic_counts_the_calendar_month(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 1, 'quota_overrides' => ['disk_mb' => 5000, 'traffic_gb' => 2]]);

        $this->traffic($abo, '2026-08-31', 9_000_000_000);
        $this->traffic($abo, '2026-09-01', 1_500_000_000);
        $this->traffic($abo, '2026-09-02', 1_000_000_000);

        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame(['traffic_over'], $this->reasons());
        self::assertSame('2,5 GB von 2 GB in diesem Monat', Finding::query()->firstOrFail()->detail,
            'Der letzte Tag des Vormonats zählt nicht mit — sonst stünde hier 11,5.');
    }

    /** Und gezählt wird, was hinausgeht, nicht was hereinkommt. */
    public function test_only_the_outgoing_direction_counts(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 1, 'quota_overrides' => ['disk_mb' => 5000, 'traffic_gb' => 2]]);

        $this->traffic($abo, '2026-09-01', 1_000_000_000);
        $this->traffic($abo, '2026-09-01', 9_000_000_000, DailyMetric::TrafficReceivedBytes);

        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame([], $this->reasons(),
            'Eingehend zählt nicht mit — zusammen wären es 10 GB und der Befund stünde da.');
    }

    /**
     * Ein gesperrtes Abonnement wird nicht gemeldet.
     *
     * Eine Mail über ein Kontingent von etwas, das der Kunde nicht mehr
     * benutzt, ist keine Auskunft, sondern Lärm.
     */
    public function test_a_suspended_subscription_is_left_alone(): void
    {
        $this->abonnement([
            'disk_used_mb' => 5000,
            'quota_overrides' => ['disk_mb' => 100],
            'status' => SubscriptionStatus::Suspended,
        ]);

        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));

        self::assertSame([], $this->reasons());
    }

    /** Und ein behobener Befund verschwindet beim nächsten Lauf. */
    public function test_a_resolved_overrun_disappears(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 1024, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-09-22 03:00:00'));
        self::assertSame(['disk_over'], $this->reasons());

        /*
         * **`forceFill()` und nicht `update()`.** `disk_used_mb` steht nicht in
         * `$fillable` — es ist ein **gemessener** Wert und keiner, den ein
         * Formular setzt. Ein `update()` darauf tut wortlos nichts, und der
         * erste Wurf dieses Falls war genau deshalb rot: Der Prüfkörper hatte
         * den Zustand gar nicht hergestellt. Die Fabrik daneben setzt ihn, weil
         * sie den Schutz umgeht.
         *
         * > **Ein Prüfkörper, der überspringt, meldet das Überspringen nicht.**
         */
        app(Tenancy::class)->withoutRestriction(static function () use ($abo): void {
            $abo->forceFill(['disk_used_mb' => 100])->save();
        });

        $this->fahre(Carbon::parse('2026-09-23 03:00:00'));

        self::assertSame([], $this->reasons(),
            'Was der Lauf nicht mehr nennt, ist behoben — und mit der Zeile geht die Erinnerung an '
            .'die Zustellung.');
    }

    private function traffic(Subscription $abo, string $day, int $value, ?DailyMetric $metric = null): void
    {
        app(Tenancy::class)->withoutRestriction(static function () use ($abo, $day, $value, $metric): void {
            SubscriptionMetric::query()->create([
                'subscription_id' => $abo->id,
                'day' => $day,
                'metric' => ($metric ?? DailyMetric::TrafficSentBytes)->value,
                'value' => $value,
            ]);
        });
    }
}
