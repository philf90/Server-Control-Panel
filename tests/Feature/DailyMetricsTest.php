<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DailyMetric;
use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Metrics\Daily;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die verdichtete Tabelle — B3, `docs/129 §6`.
 *
 * **Die tragende Zusage ist nicht, dass geschrieben wird, sondern dass
 * derselbe Tag nicht zweimal zählt.** Derselbe Vortag kann mehr als einmal
 * vorbeikommen — ein Lauf von Hand, ein nachgeholter —, und ein Lauf, der
 * addierte, verdoppelte ihn. Dass jede dieser Sichten auch **vollständig** ist,
 * hält `TrafficRotationTest`; bis zum 24. September 2026 war sie es nicht
 * (`docs/134 §0` Punkt 2).
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht, darf ihn nicht mehrfach
 * > zählen.**
 */
final class DailyMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function daily(): Daily
    {
        return new Daily(app(Tenancy::class));
    }

    /**
     * Ein Abonnement mit einer Domain.
     *
     * `$angelegt` setzt den Zeitpunkt, zu dem es die Domain gibt. Eine Null
     * bekommt nur eine Domain, die es vor dem Tag schon gab — ohne diese
     * Angabe entstünde sie im Prüfstand „jetzt", und jede Null für einen Tag
     * davor fiele weg.
     *
     * @return array{Subscription, Domain}
     */
    private function abonnement(string $name = 'beispiel.de', string $domain = 'beispiel.de', ?string $angelegt = null): array
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($name, $domain, $angelegt): array {
            $subscription = Subscription::factory()->create(['name' => $name]);
            $eintrag = Domain::factory()->create(array_filter([
                'subscription_id' => $subscription->id,
                'name' => $domain,
                'created_at' => $angelegt,
            ], fn ($wert): bool => $wert !== null));

            return [$subscription, $eintrag];
        });
    }

    /** @return list<array{subscription:string, domain:string, day:string}> */
    private function ruhig(string $subscription, string $domain, string $day): array
    {
        return [['subscription' => $subscription, 'domain' => $domain, 'day' => $day]];
    }

    /** @return array<string, int> */
    private function werte(string $table, string $spalte, int $kennung, string $day): array
    {
        return DB::table($table)
            ->where($spalte, $kennung)
            ->whereDate('day', $day)
            ->pluck('value', 'metric')
            ->map(fn ($wert): int => (int) $wert)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function zeile(string $subscription, string $domain, string $day, int $requests = 4, int $sent = 3011, int $received = 372, int $errors = 1): array
    {
        return [[
            'subscription' => $subscription,
            'domain' => $domain,
            'day' => $day,
            'requests' => $requests,
            'sent' => $sent,
            'received' => $received,
            'errors' => $errors,
        ]];
    }

    public function test_a_countable_day_lands_in_both_tables(): void
    {
        [$subscription, $domain] = $this->abonnement();

        $ergebnis = $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', '2026-09-20'));

        $this->assertSame(4, $ergebnis['domains']);
        $this->assertSame(4, $ergebnis['subscriptions']);
        $this->assertSame([], $ergebnis['unknown']);

        app(Tenancy::class)->withoutRestriction(function () use ($subscription, $domain): void {
            $this->assertSame(3011, (int) DomainMetric::query()
                ->where('domain_id', $domain->id)
                ->where('metric', DailyMetric::TrafficSentBytes->value)
                ->value('value'));

            $this->assertSame(3011, (int) SubscriptionMetric::query()
                ->where('subscription_id', $subscription->id)
                ->where('metric', DailyMetric::TrafficSentBytes->value)
                ->value('value'));
        });
    }

    /**
     * **Derselbe Tag zweimal ergibt denselben Wert und nicht den doppelten.**
     *
     * Das ist die Zusage, an der B2 und B3 zusammenhängen. Sie hält an einem
     * eindeutigen Schlüssel, dessen Spalten alle `NOT NULL` sind — die Messung
     * dazu steht in der Migration.
     */
    public function test_the_same_day_twice_overwrites_instead_of_adding(): void
    {
        [$subscription, $domain] = $this->abonnement();

        $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', '2026-09-20'));
        $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', '2026-09-20'));

        app(Tenancy::class)->withoutRestriction(function () use ($subscription, $domain): void {
            $this->assertSame(4, DomainMetric::query()->where('domain_id', $domain->id)->count());
            $this->assertSame(4, SubscriptionMetric::query()->where('subscription_id', $subscription->id)->count());

            $this->assertSame(3011, (int) DomainMetric::query()
                ->where('domain_id', $domain->id)
                ->where('metric', DailyMetric::TrafficSentBytes->value)
                ->value('value'));
        });
    }

    /** Und ein neuer Wert für denselben Tag ersetzt den alten. */
    public function test_a_corrected_day_replaces_the_number(): void
    {
        [, $domain] = $this->abonnement();

        $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', '2026-09-20', sent: 100));
        $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', '2026-09-20', sent: 900));

        app(Tenancy::class)->withoutRestriction(function () use ($domain): void {
            $this->assertSame(900, (int) DomainMetric::query()
                ->where('domain_id', $domain->id)
                ->where('metric', DailyMetric::TrafficSentBytes->value)
                ->value('value'));
        });
    }

    /**
     * **Das Abonnement ist die Summe über seine Domains.**
     *
     * Es gibt keinen Zähler, der es unmittelbar zählte — nginx protokolliert je
     * Domain. Eine zweite Quelle wäre eine zweite Zahl über dieselbe Grösse.
     */
    public function test_the_subscription_is_the_sum_over_its_domains(): void
    {
        [$subscription] = $this->abonnement('abo.de', 'eins.de');

        app(Tenancy::class)->withoutRestriction(fn () => Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'zwei.de',
        ]));

        $this->daily()->record(array_merge(
            $this->zeile('abo.de', 'eins.de', '2026-09-20', requests: 10, sent: 100),
            $this->zeile('abo.de', 'zwei.de', '2026-09-20', requests: 5, sent: 50),
        ));

        app(Tenancy::class)->withoutRestriction(function () use ($subscription): void {
            $this->assertSame(150, (int) SubscriptionMetric::query()
                ->where('subscription_id', $subscription->id)
                ->where('metric', DailyMetric::TrafficSentBytes->value)
                ->value('value'));

            $this->assertSame(15, (int) SubscriptionMetric::query()
                ->where('subscription_id', $subscription->id)
                ->where('metric', DailyMetric::Requests->value)
                ->value('value'));
        });
    }

    /**
     * **Ein Verzeichnis ohne Zeile im Panel wird gemeldet und nicht
     * übergangen.**
     *
     * Der Agent zählt, was dasteht; welche Zeile das ist, weiss nur die
     * Datenbank. Ein Rest eines Rückbaus und ein fehlendes Abonnement sehen in
     * einer Summe beide wie „nichts" aus.
     */
    public function test_a_directory_without_a_row_is_named(): void
    {
        $this->abonnement();

        $ergebnis = $this->daily()->record(array_merge(
            $this->zeile('beispiel.de', 'beispiel.de', '2026-09-20'),
            $this->zeile('fort.de', 'fort.de', '2026-09-20'),
        ));

        $this->assertSame([['subscription' => 'fort.de', 'domain' => 'fort.de']], $ergebnis['unknown']);
        $this->assertSame(4, $ergebnis['domains']);
    }

    /**
     * **Eine Domain, die einem anderen Abonnement gehört, zählt nicht mit.**
     *
     * Zwei Kunden dürfen denselben Domainnamen im Verzeichnis haben, solange
     * ihn nur einer wirklich betreibt — gezählt wird das Paar und nicht der
     * Name.
     */
    public function test_a_domain_of_another_subscription_does_not_count(): void
    {
        $this->abonnement('eins.de', 'gemeinsam.de');
        $this->abonnement('zwei.de', 'eigen.de');

        $ergebnis = $this->daily()->record($this->zeile('zwei.de', 'gemeinsam.de', '2026-09-20'));

        $this->assertSame([['subscription' => 'zwei.de', 'domain' => 'gemeinsam.de']], $ergebnis['unknown']);
        $this->assertSame(0, $ergebnis['domains']);
    }

    /**
     * **Abgeräumt wird vom übergebenen Tag und nicht von `now()`.**
     *
     * Derselbe Bestand an zwei Läufen desselben Tages muss dasselbe Ergebnis
     * geben; `MaintenanceOverdue` hat denselben Griff aus demselben Grund.
     *
     * **Der erste Prüfkörper dazu war keiner.** Er übergab als „heute" den Tag,
     * an dem der Lauf fuhr — und damit sagten `$today` und `now()` dasselbe.
     * Der Eingriff des Bruchskripts tauschte das eine gegen das andere, und der
     * Test blieb grün.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     *
     * Die Uhr steht deshalb fest, und der übergebene Tag liegt weit von ihr
     * entfernt: Nach `now()` bliebe die Hälfte stehen, nach `$today` geht
     * alles. Erst dadurch unterscheiden sich die beiden Fälle.
     */
    public function test_retention_counts_from_the_given_day_and_not_from_now(): void
    {
        Carbon::setTestNow('2026-09-21 03:00:00');
        $this->abonnement();

        foreach (['2026-08-20', '2026-08-22', '2026-09-20'] as $tag) {
            $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', $tag));
        }

        // 2026-12-01 minus 30 Tage ist 2026-11-01 — davor liegt alles.
        // Nach `now()` läge die Grenze bei 2026-08-22, und zwei Tage blieben.
        $ergebnis = $this->daily()->forget('2026-12-01');

        $this->assertSame(12, $ergebnis['domains']);
        $this->assertSame(12, $ergebnis['subscriptions']);

        app(Tenancy::class)->withoutRestriction(function (): void {
            $this->assertSame(0, DomainMetric::query()->count());
            $this->assertSame(0, SubscriptionMetric::query()->count());
        });

        Carbon::setTestNow();
    }

    /**
     * **Und die Grenze selbst wird mitgemessen.**
     *
     * Der Tag, der genau auf `heute − 30` fällt, bleibt; der davor geht. Ohne
     * diesen Fall wäre `<` gegen `<=` eine Entscheidung, die niemand trifft.
     */
    public function test_retention_keeps_the_day_on_the_boundary(): void
    {
        [, $domain] = $this->abonnement();

        foreach (['2026-08-20', '2026-08-22', '2026-09-20'] as $tag) {
            $this->daily()->record($this->zeile('beispiel.de', 'beispiel.de', $tag));
        }

        $ergebnis = $this->daily()->forget('2026-09-21');

        $this->assertSame(4, $ergebnis['domains']);
        $this->assertSame(4, $ergebnis['subscriptions']);

        app(Tenancy::class)->withoutRestriction(function () use ($domain): void {
            $tage = DomainMetric::query()
                ->where('domain_id', $domain->id)
                ->distinct()
                ->orderBy('day')
                ->pluck('day')
                ->map(fn ($tag): string => $tag instanceof Carbon ? $tag->toDateString() : (string) $tag)
                ->all();

            $this->assertSame(['2026-08-22', '2026-09-20'], $tage);
        });
    }

    /**
     * **Erst ablegen, dann abräumen.**
     *
     * Andersherum nähme der Lauf einer frisch geschriebenen Zeile ihren Tag
     * weg, sobald die Aufbewahrungsgrenze genau auf ihn fällt — ein Fehler,
     * der einmal im Monat sichtbar wäre und dann wie ein verlorener Tag
     * aussähe. Gemessen wird die **Reihenfolge** im Quelltext, weil beide
     * Aufrufe für sich richtig sind und nur zusammen falsch sein können.
     */
    public function test_the_nightly_run_records_before_it_forgets(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../app/Console/Commands/CollectTraffic.php');

        $this->assertIsString($quelle);

        $ablegen = strpos($quelle, '$daily->record(');
        $abraeumen = strpos($quelle, '$daily->forget(');

        $this->assertIsInt($ablegen, 'Der Nachtlauf legt nichts ab.');
        $this->assertIsInt($abraeumen, 'Der Nachtlauf räumt nichts ab.');
        $this->assertLessThan($abraeumen, $ablegen, 'Abgeräumt wird nach dem Ablegen und nicht davor.');
    }

    /**
     * **Und der eindeutige Schlüssel liegt wirklich auf der Tabelle.**
     *
     * Das `upsert()` darüber ist wirkungslos, wenn er fehlt — es legte dann
     * jede Nacht eine zweite Zeile an, und die Summe darüber wäre jeden Tag
     * eine andere. Gemessen wird hier die **Datenbank** und nicht der Code.
     */
    public function test_the_database_itself_refuses_a_second_row(): void
    {
        [$subscription, $domain] = $this->abonnement();

        $zeile = [
            'subscription_id' => $subscription->id,
            'domain_id' => $domain->id,
            'day' => '2026-09-20',
            'metric' => DailyMetric::Requests->value,
            'value' => 1,
        ];

        DB::table('domain_metrics')->insert($zeile);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('domain_metrics')->insert($zeile);
    }

    /**
     * **Ein ruhiger Tag bekommt vier Nullen — die Domain und ihr Abonnement.**
     *
     * `docs/138 §0` Punkt 2, entschieden am 28. September 2026: Bis dahin bekam
     * ein Tag ohne Anfrage keine Zeile, und die Kurve rückte seine Nachbarn
     * zusammen.
     */
    public function test_a_quiet_domain_gets_four_zeros_and_its_subscription_too(): void
    {
        [$subscription, $domain] = $this->abonnement(angelegt: '2026-09-01 10:00:00');

        $ergebnis = $this->daily()->record([], $this->ruhig('beispiel.de', 'beispiel.de', '2026-09-20'));

        $this->assertSame(4, $ergebnis['domains']);
        $this->assertSame(4, $ergebnis['subscriptions']);

        $nullen = array_fill_keys(array_map(fn (DailyMetric $m): string => $m->value, DailyMetric::ofADomain()), 0);
        ksort($nullen);

        foreach ([['domain_metrics', 'domain_id', $domain->id], ['subscription_metrics', 'subscription_id', $subscription->id]] as [$tabelle, $spalte, $kennung]) {
            $werte = $this->werte($tabelle, $spalte, (int) $kennung, '2026-09-20');
            ksort($werte);

            $this->assertSame($nullen, $werte, "{$tabelle}: ein ruhiger Tag sind vier Nullen und keine fehlende Zeile.");
        }
    }

    /** Neben einer gezählten Domain ändert eine ruhige an der Summe nichts. */
    public function test_a_quiet_domain_beside_a_counted_one_adds_nothing(): void
    {
        [$subscription] = $this->abonnement('abo.de', 'eins.de', '2026-09-01 10:00:00');

        app(Tenancy::class)->withoutRestriction(fn () => Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'zwei.de',
            'created_at' => '2026-09-01 10:00:00',
        ]));

        $this->daily()->record(
            $this->zeile('abo.de', 'eins.de', '2026-09-20', requests: 10, sent: 100),
            $this->ruhig('abo.de', 'zwei.de', '2026-09-20'),
        );

        $werte = $this->werte('subscription_metrics', 'subscription_id', (int) $subscription->id, '2026-09-20');

        $this->assertSame(10, $werte[DailyMetric::Requests->value] ?? -1);
        $this->assertSame(100, $werte[DailyMetric::TrafficSentBytes->value] ?? -1);
    }

    /**
     * **Die Null eines Abonnements nur, wo keine seiner Domains eine Lücke
     * hatte.** Die ruhige Domain bekommt ihre Null; das Abonnement nicht, denn
     * neben ihr stand eine, die an dem Tag gelesen und nicht gezählt wurde —
     * seine Null sagte „nichts gewesen" über einen Tag, an dem etwas gewesen
     * sein kann.
     */
    public function test_a_quiet_domain_beside_a_gap_keeps_its_zero_and_the_subscription_gets_none(): void
    {
        [$subscription, $ruhig] = $this->abonnement('abo.de', 'ruhig.de', '2026-09-01 10:00:00');

        app(Tenancy::class)->withoutRestriction(fn () => Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'alt.de',
            'created_at' => '2026-09-01 10:00:00',
        ]));

        $ergebnis = $this->daily()->record(
            [],
            $this->ruhig('abo.de', 'ruhig.de', '2026-09-20'),
            $this->ruhig('abo.de', 'alt.de', '2026-09-20'),
        );

        $this->assertSame(4, $ergebnis['domains'], 'Die ruhige Domain bekommt ihre Null trotzdem.');
        $this->assertSame(0, $ergebnis['subscriptions']);
        $this->assertCount(4, $this->werte('domain_metrics', 'domain_id', (int) $ruhig->id, '2026-09-20'));
        $this->assertSame([], $this->werte('subscription_metrics', 'subscription_id', (int) $subscription->id, '2026-09-20'));
    }

    /**
     * **Und eine Summe mit Zählbarem bleibt neben einer Lücke stehen.** Wer
     * sie wegen der Lücke fallen liesse, verlöre bei einer einzigen kaputten
     * Datei in einer ruhigen Domain — die logrotate nie wieder dreht — jeden
     * weiteren Tag des ganzen Abonnements.
     */
    public function test_a_counted_domain_beside_a_gap_keeps_the_subscription_sum(): void
    {
        [$subscription] = $this->abonnement('abo.de', 'eins.de', '2026-09-01 10:00:00');

        app(Tenancy::class)->withoutRestriction(fn () => Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'alt.de',
            'created_at' => '2026-09-01 10:00:00',
        ]));

        $ergebnis = $this->daily()->record(
            $this->zeile('abo.de', 'eins.de', '2026-09-20', requests: 7),
            [],
            $this->ruhig('abo.de', 'alt.de', '2026-09-20'),
        );

        $this->assertSame(4, $ergebnis['subscriptions']);
        $this->assertSame(7, $this->werte('subscription_metrics', 'subscription_id', (int) $subscription->id, '2026-09-20')[DailyMetric::Requests->value] ?? -1);
    }

    /** Eine Lücke in einem Verzeichnis, das das Panel nicht kennt, gehört niemandem. */
    public function test_a_gap_in_a_directory_the_panel_does_not_know_blocks_nothing(): void
    {
        [$subscription] = $this->abonnement(angelegt: '2026-09-01 10:00:00');

        $ergebnis = $this->daily()->record(
            [],
            $this->ruhig('beispiel.de', 'beispiel.de', '2026-09-20'),
            $this->ruhig('beispiel.de', 'rest-eines-rueckbaus.de', '2026-09-20'),
        );

        $this->assertSame(4, $ergebnis['subscriptions']);
        $this->assertCount(4, $this->werte('subscription_metrics', 'subscription_id', (int) $subscription->id, '2026-09-20'));
    }

    /**
     * **Eine Null nur für einen Tag, an dem es die Domain schon gab** — in
     * beide Richtungen. Angelegt am Tag selbst (UTC) bekommt sie für ihn keine,
     * angelegt am Tag davor schon. Eine Null vor der Anlage wäre der Anfang
     * einer Kurve vor dem Anfang der Domain.
     */
    public function test_a_domain_younger_than_the_day_gets_no_zero(): void
    {
        [, $jung] = $this->abonnement('jung.de', 'jung.de', '2026-09-20 21:30:00');
        [, $alt] = $this->abonnement('alt.de', 'alt.de', '2026-09-19 23:59:00');

        $this->daily()->record([], array_merge(
            $this->ruhig('jung.de', 'jung.de', '2026-09-20'),
            $this->ruhig('alt.de', 'alt.de', '2026-09-20'),
        ));

        $this->assertSame([], $this->werte('domain_metrics', 'domain_id', (int) $jung->id, '2026-09-20'),
            'Angelegt am Tag selbst: keine Null für ihn — auf einem Server in +0200 war das schon der Folgetag.');
        $this->assertCount(4, $this->werte('domain_metrics', 'domain_id', (int) $alt->id, '2026-09-20'),
            'Angelegt am Tag davor: die Null gehört ihr.');
    }

    /** Ein ruhiges Verzeichnis ohne Zeile im Panel wird genannt wie ein gezähltes. */
    public function test_a_quiet_directory_without_a_row_is_named(): void
    {
        $this->abonnement(angelegt: '2026-09-01 10:00:00');

        $ergebnis = $this->daily()->record([], $this->ruhig('fort.de', 'fort.de', '2026-09-20'));

        $this->assertSame([['subscription' => 'fort.de', 'domain' => 'fort.de']], $ergebnis['unknown']);
        $this->assertSame(0, $ergebnis['domains']);
    }
}
