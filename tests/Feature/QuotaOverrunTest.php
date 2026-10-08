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

    /** Der Wert neben einem Grund — auf der Grenze stehen zwei Befunde. */
    private function detail(string $reason, string $subject = 'p1000'): ?string
    {
        return Finding::query()
            ->where('check', FindingCheck::QuotaExceeded->value)
            ->where('subject', $subject)
            ->where('reason', $reason)
            ->firstOrFail()
            ->detail;
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

        self::assertSame(['disk_near_limit', 'disk_over'], $this->reasons());
        self::assertSame('1.024 MB von 500 MB', $this->detail('disk_over'),
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
     * **An der Grenze ist der Platz ausgeschöpft — nicht erst darüber.**
     *
     * Der Fall, den es vor dem 5. Oktober 2026 nicht gab (`docs/141 §0`
     * Befund 3): Die Dateisystem-Quota setzt weiche und harte Grenze auf
     * denselben Wert, und `repquota` rundet auf ganze MB ab. Ein voller Platz
     * steht deshalb **auf** seiner Grenze, und die alte Frage nach „darüber"
     * meldete ihn nie.
     */
    public function test_disk_at_its_quota_is_exhausted(): void
    {
        $this->abonnement(['disk_used_mb' => 500, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));

        self::assertSame(['disk_near_limit', 'disk_over'], $this->reasons(),
            'Ein Platz auf seiner Grenze ist voll — ab hier scheitert jeder Schreibzugriff, und die Vorwarnung steht daneben.');
        self::assertSame('500 MB von 500 MB', $this->detail('disk_over'));
    }

    /**
     * Ab 95 % warnt die Prüfung — und darunter nicht.
     *
     * Entschieden hat der Betreiber am 5. Oktober 2026: dieselbe Schwelle wie
     * bei „Platte voll" für den Server. **Beide Seiten der Schwelle in einem
     * Fall**, weil ein Wächter, der nur die eine misst, auch eine Prüfung
     * durchliesse, die immer oder nie warnt.
     */
    public function test_disk_near_its_quota_warns_from_95_percent(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 474, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));

        self::assertSame([], $this->reasons(), '474 von 500 MB sind 94,8 % — darunter warnt nichts.');

        $this->belegt($abo, 475);
        $this->fahre(Carbon::parse('2026-10-06 03:00:00'));

        self::assertSame(['disk_near_limit'], $this->reasons(), '475 von 500 MB sind genau 95 %.');
        self::assertSame('475 MB von 500 MB (95,0 %)', Finding::query()->firstOrFail()->detail);
    }

    /**
     * Unter der Grenze sagt „fast ausgeschöpft" nie 100 %.
     *
     * Der Anteil wird **abgerundet** und nicht gerundet: 3.999 von 4.000 MB
     * sind 99,975 %, und gerundet stünde „100,0 %" neben „fast" — ein Satz,
     * der sich selbst widerspricht. Auf der Grenze steht „ausgeschöpft"
     * daneben, und in der Mail nur das (`QuotaSection::shown()`).
     */
    public function test_the_warning_never_reads_a_full_hundred(): void
    {
        $this->abonnement(['disk_used_mb' => 3999, 'quota_overrides' => ['disk_mb' => 4000]]);
        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));

        self::assertSame(['disk_near_limit'], $this->reasons());
        self::assertSame('3.999 MB von 4.000 MB (99,9 %)', Finding::query()->firstOrFail()->detail);
    }

    /**
     * **Der Rückweg liegt unter 90 % und nicht unter 95 %.**
     *
     * Ohne ihn meldete ein Platz, der um die Schwelle pendelt, nach jedem
     * Durchgang darüber neu — und das ist eine Mail an den Kunden. Gefahren
     * über vier Läufe: Warnung, Halt über der Rückkehrschwelle, Entwarnung
     * darunter, und danach **keine** Warnung zwischen 90 und 95 %, weil es dann
     * keinen Befund vom vorigen Lauf gibt.
     */
    public function test_the_warning_holds_until_below_90_percent(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 480, 'quota_overrides' => ['disk_mb' => 500]]);

        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));
        self::assertSame(['disk_near_limit'], $this->reasons(), '96 % — die Warnung beginnt.');

        $this->belegt($abo, 452);
        $this->fahre(Carbon::parse('2026-10-06 03:00:00'));
        self::assertSame(['disk_near_limit'], $this->reasons(), '90,4 % — die Warnung hält.');

        $this->belegt($abo, 449);
        $this->fahre(Carbon::parse('2026-10-07 03:00:00'));
        self::assertSame([], $this->reasons(), '89,8 % — die Warnung endet.');

        $this->belegt($abo, 460);
        $this->fahre(Carbon::parse('2026-10-08 03:00:00'));
        self::assertSame([], $this->reasons(), '92 % ohne Befund vom vorigen Lauf — darunter beginnt keine Warnung.');
    }

    /**
     * **Ein voller Platz entwarnt seine Vorwarnung nicht.**
     *
     * Getrennt entschieden wie in `DiskSpace`: Auf der Grenze stehen beide
     * Befunde da, und fällt der Platz wieder auf 92 %, geht nur
     * „ausgeschöpft". Die Vorwarnung behält dabei ihr `first_seen_at` — sie
     * ist gemeldet und wird es nicht noch einmal.
     *
     * Beim Bauen schlossen die beiden einander aus (`docs/141 §0` Befund 7).
     * Das Meldeziel bekam „erledigt" für die Vorwarnung in dem Augenblick, in
     * dem der Platz voll war, und nach dem Freiräumen begann sie neu — mit
     * einer zweiten Mail an den Kunden. **Drei Läufe und nicht zwei:** Ob die
     * Vorwarnung den vollen Platz überdauert, zeigt erst der Lauf danach.
     */
    public function test_a_full_disk_keeps_its_warning(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 480, 'quota_overrides' => ['disk_mb' => 500]]);
        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));
        self::assertSame(['disk_near_limit'], $this->reasons(), '96 % — die Vorwarnung beginnt.');

        $this->belegt($abo, 500);
        $this->fahre(Carbon::parse('2026-10-06 03:00:00'));
        self::assertSame(['disk_near_limit', 'disk_over'], $this->reasons(), 'Voll — die Vorwarnung bleibt neben „ausgeschöpft".');

        $this->belegt($abo, 460);
        $this->fahre(Carbon::parse('2026-10-07 03:00:00'));
        self::assertSame(['disk_near_limit'], $this->reasons(), '92 % — „ausgeschöpft" geht, die Vorwarnung bleibt.');

        $vorwarnung = Finding::query()->where('reason', 'disk_near_limit')->firstOrFail();

        self::assertSame('2026-10-05 03:00:00', $vorwarnung->first_seen_at->toDateTimeString(),
            'Die Vorwarnung steht seit dem ersten Lauf da. Begänne sie neu, käme nach der Haltezeit eine zweite Mail.');
    }

    /**
     * Und das Gedächtnis kennt nur den Platz.
     *
     * Ein Befund über die Datenbanken desselben Abonnements ist kein Grund, den
     * Platz unter 95 % weiter zu warnen. Gefragt wird nach den eigenen Zeilen
     * und nach den Gründen des Platzes — die Regel aus `DiskSpace`, dass keine
     * Prüfung liest, was eine andere geschrieben hat, eine Ebene tiefer.
     *
     * **Zwei Läufe und nicht einer.** Das Gedächtnis ist der vorige Lauf; im
     * ersten ist es leer, und ein Fall über einen einzigen Lauf bliebe grün,
     * gleich welche Gründe es läse.
     */
    public function test_only_the_disk_remembers_the_disk(): void
    {
        $abo = $this->abonnement(['disk_used_mb' => 460, 'quota_overrides' => ['disk_mb' => 500, 'database_mb' => 100]]);

        app(Tenancy::class)->withoutRestriction(static function () use ($abo): void {
            Database::factory()->create([
                'subscription_id' => $abo->id,
                'size_bytes' => 300 * 1024 * 1024,
            ]);
        });

        $this->fahre(Carbon::parse('2026-10-05 03:00:00'));
        self::assertSame(['databases_over'], $this->reasons());

        $this->fahre(Carbon::parse('2026-10-06 03:00:00'));

        self::assertSame(['databases_over'], $this->reasons(),
            '92 % Platz ohne eigenen Befund vom vorigen Lauf — der Befund über die Datenbanken zählt nicht als Gedächtnis.');
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
        self::assertSame(['disk_near_limit', 'disk_over'], $this->reasons());

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

    /**
     * Den gemessenen Platz setzen — mit `forceFill()`, aus dem Grund, der in
     * {@see self::test_a_resolved_overrun_disappears()} steht.
     */
    private function belegt(Subscription $abo, int $mb): void
    {
        app(Tenancy::class)->withoutRestriction(static function () use ($abo, $mb): void {
            $abo->forceFill(['disk_used_mb' => $mb])->save();
        });
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
