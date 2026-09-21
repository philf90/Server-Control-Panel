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
 * derselbe Tag nicht zweimal zählt.** `web.access.count` liest `access.log`
 * **und** `access.log.1`, weil `logrotate` in einem Fenster läuft und nicht zu
 * einer Uhrzeit (gemessen, `CLAUDE.md`). Derselbe Tag kommt deshalb an
 * mehreren Nächten vorbei, und ein Lauf, der addierte, verdoppelte ihn.
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

    /** @return array{Subscription, Domain} */
    private function abonnement(string $name = 'beispiel.de', string $domain = 'beispiel.de'): array
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($name, $domain): array {
            $subscription = Subscription::factory()->create(['name' => $name]);
            $eintrag = Domain::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => $domain,
            ]);

            return [$subscription, $eintrag];
        });
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
}
