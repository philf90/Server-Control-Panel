<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DailyMetric;
use App\Http\Middleware\ApplyTenancy;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Metrics\History;
use App\Support\Metrics\Points;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die Verläufe auf der Abonnement- und der Domainseite — B4, `docs/129 §6`.
 *
 * Geprüft wird {@see History} und nicht die Kachel: Hier entstehen die
 * Stützstellen, und was hier falsch ist, zeichnet der Browser getreu nach.
 * Die Form des Feldes hält `SparklineShapeTest`, die Herkunft der Zahlen
 * `SeriesSourceTest`.
 */
final class DailyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function history(): History
    {
        return app(History::class);
    }

    /**
     * Die Klammer öffnen, wie es die Mittelschicht tut.
     *
     * **Ohne das misst dieser Prüfstand nichts.** Der erste Wurf dieser Datei
     * hat sich nirgends angemeldet; sieben Fälle waren rot, und zwei waren
     * grün — darunter der über den einzelnen Tag, der `has === false`
     * erwartet. Er hätte auch dann bestanden, wenn der Leser die Regel gar
     * nicht kennte.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     *
     * Gerufen wird {@see Tenancy::forAccount()} und nicht `allowAll()`: Das
     * ist die Stelle, die {@see ApplyTenancy} bei jeder
     * Anfrage ruft.
     */
    private function anmelden(?Account $account = null): void
    {
        app(Tenancy::class)->forAccount($account ?? Account::factory()->admin()->create());
    }

    /**
     * @param  array<string, mixed>  $quotas
     * @return array{Subscription, Domain}
     */
    private function abonnement(array $quotas = []): array
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($quotas): array {
            $subscription = Subscription::factory()->create([
                'name' => 'p1000',
                'quota_overrides' => $quotas,
            ]);

            $domain = Domain::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => 'beispiel.de',
            ]);

            return [$subscription, $domain];
        });
    }

    /**
     * @param  array<string, mixed>  $quotas
     * @return array{Subscription, Domain}
     */
    private function angemeldetesAbonnement(array $quotas = []): array
    {
        $paar = $this->abonnement($quotas);
        $this->anmelden();

        return $paar;
    }

    /**
     * Einen Tag in beide Tabellen legen.
     *
     * Geschrieben wird ohne {@see Daily}, und das ist Absicht: Dieser Wächter
     * prüft den **Leser**. Ginge der Prüfkörper durch den Schreiber, prüfte er
     * beide — und ein Fehler im Schreiber sähe wie einer im Leser aus.
     */
    private function tag(Subscription $subscription, Domain $domain, string $day, int $requests, int $errors, int $sent, int $received, int $disk = 0, int $database = 0): void
    {
        $werte = [
            DailyMetric::Requests->value => $requests,
            DailyMetric::Errors->value => $errors,
            DailyMetric::TrafficSentBytes->value => $sent,
            DailyMetric::TrafficReceivedBytes->value => $received,
        ];

        app(Tenancy::class)->withoutRestriction(function () use ($subscription, $domain, $day, $werte, $disk, $database): void {
            foreach ($werte + [
                DailyMetric::DiskMb->value => $disk,
                DailyMetric::DatabaseBytes->value => $database,
            ] as $metric => $value) {
                SubscriptionMetric::query()->create([
                    'subscription_id' => $subscription->id,
                    'day' => $day,
                    'metric' => $metric,
                    'value' => $value,
                ]);
            }

            foreach ($werte as $metric => $value) {
                DomainMetric::query()->create([
                    'subscription_id' => $subscription->id,
                    'domain_id' => $domain->id,
                    'day' => $day,
                    'metric' => $metric,
                    'value' => $value,
                ]);
            }
        });
    }

    /**
     * @param  list<array<string,mixed>>  $tiles
     * @return array<string,mixed>
     */
    private function kachel(array $tiles, string $key): array
    {
        foreach ($tiles as $tile) {
            if ($tile['key'] === $key) {
                return $tile;
            }
        }

        self::fail(sprintf('Keine Kachel „%s" — vorhanden: %s', $key, implode(', ', array_column($tiles, 'key'))));
    }

    public function test_a_subscription_gets_five_tiles_and_a_domain_three(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        self::assertSame(
            ['disk', 'traffic', 'requests', 'errors', 'databases'],
            array_column($this->history()->forSubscription($subscription), 'key'),
            'Fünf Kacheln, und die Reihenfolge steht fest — die Seite ordnet nicht nach.',
        );

        self::assertSame(
            ['traffic', 'requests', 'errors'],
            array_column($this->history()->forDomain($domain), 'key'),
            'Platz und Datenbanken gehören dem Abonnement und nicht einer seiner Domains.',
        );
    }

    /**
     * **Die Reihe steht auch ohne eine einzige Zeile da** — fünf
     * beziehungsweise drei leere Kacheln und keine leere Liste.
     *
     * Entschieden am 28. September 2026 (`docs/139 §6` Frage 2): Ein Kunde
     * erfährt am ersten Tag, was kommt, und die Seite springt am zweiten nicht
     * um eine Reihe. Beide Seiten zeigen die Reihe seitdem ohne Bedingung; bis
     * dahin stand dort ein `v-if` auf eine Liste, die nie leer ist, unter einem
     * Kommentar, der das Gegenteil versprach. Dieser Fall hält die Hälfte, die
     * am Server liegt. Dass die Vorlage keine Bedingung trägt, hält kein
     * Wächter.
     */
    public function test_the_row_stands_without_a_single_row(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();

        $abo = $this->history()->forSubscription($subscription);
        $dom = $this->history()->forDomain($domain);

        self::assertCount(5, $abo);
        self::assertCount(3, $dom);

        foreach ([...$abo, ...$dom] as $kachel) {
            self::assertFalse($kachel['series']['has'], $kachel['key']);
            self::assertSame('—', $kachel['value'], $kachel['key']);
        }
    }

    /**
     * Eine Kurve aus einem Punkt ist keine.
     *
     * Das ist der Fall eines Abonnements am zweiten Tag — und ohne ihn wäre
     * `$i / $lastIndex` in {@see Points::build()} eine
     * Division durch null.
     */
    public function test_a_single_day_is_not_a_curve(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        $kachel = $this->kachel($this->history()->forSubscription($subscription), 'requests');

        self::assertFalse($kachel['series']['has'],
            'Ein Tag ergibt keine Kurve — und die Kachel sagt das, statt einen Strich zu zeichnen.');
        self::assertSame([], $kachel['series']['points']);
        self::assertSame('—', $kachel['value'],
            'Die grosse Zahl ist „noch nichts gemessen" und nicht die eine Zahl, die dasteht — '
            .'sonst stünde über einer leeren Kurve ein Wert, den sie nicht zeigt.');
    }

    /** Und die Gegenrichtung: zwei Tage ergeben zwei Stützstellen. */
    public function test_two_days_are_a_curve(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        $kachel = $this->kachel($this->history()->forSubscription($subscription), 'requests');

        self::assertTrue($kachel['series']['has']);
        self::assertCount(2, $kachel['series']['points']);
        self::assertSame('120', $kachel['value'],
            'Die grosse Zahl ist der **letzte** Wert der Reihe und nicht ihr grösster.');
    }

    /**
     * Die Beschriftung ist ein Tag und keine Uhrzeit.
     *
     * Der Ringpuffer schreibt `H:i`; über dreissig Tage gäbe das dreissigmal
     * `00:00`, und die Ablesung beantwortete jede Frage gleich.
     */
    public function test_the_reading_names_the_day(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        $punkte = $this->kachel($this->history()->forSubscription($subscription), 'requests')['series']['points'];

        self::assertSame(['19.09.', '20.09.'], array_column($punkte, 't'));
    }

    /**
     * Die Fehlerquote wird gerechnet und nicht abgelegt.
     *
     * {@see DailyMetric::Errors} begründet, warum die Tabelle zwei ganze
     * Zahlen führt: Zwei abgelegte Zahlen, aus denen sich die dritte ergibt,
     * sind besser als drei, von denen eine veralten kann.
     */
    public function test_the_error_rate_is_computed_from_two_numbers(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 4, 1, 6_000, 950);

        $kachel = $this->kachel($this->history()->forDomain($domain), 'errors');

        self::assertSame('25', $kachel['value'],
            'Ein Fehler auf vier Anfragen sind 25 % — gerechnet und nicht gelesen.');
        // „5,0 %" und nicht „5 %": Die Stellenzahl richtet sich nach der Grösse
        // des Wertes (unter 10 eine Stelle, unter 1 zwei) — sonst läse eine
        // Fehlerquote von 0,4 % als „0 %", und das ist der Unterschied zwischen
        // einer gesunden Website und einer, die jede zweihundertfünfzigste
        // Anfrage verliert.
        self::assertSame('5,0 %', $kachel['series']['points'][0]['v']);
    }

    /** Ein Tag ohne Anfragen teilt nicht durch null. */
    public function test_a_day_without_requests_has_no_rate(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 0, 0, 0, 0);
        $this->tag($subscription, $domain, '2026-09-20', 4, 1, 6_000, 950);

        $punkte = $this->kachel($this->history()->forDomain($domain), 'errors')['series']['points'];

        self::assertSame('0,00 %', $punkte[0]['v'],
            'Zwei Stellen unter 1 — dieselbe Regel, die „0,42 %" vor dem Abrunden auf null rettet.');
    }

    /**
     * Beide Richtungen des Verkehrs teilen sich **eine** Achse.
     *
     * Gerechnete jede für sich, füllte auch die tausendfach kleinere die 24
     * Einheiten der Kachel aus — und wer das Bild ansieht, läse „beide etwa
     * gleich". `Store::pair()` nennt denselben Grund für die Netzkachel; hier
     * steht er noch einmal, weil die Quelle eine andere ist und der Fehler
     * derselbe wäre.
     */
    public function test_both_directions_share_one_axis(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 1_000_000, 500);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 2_000_000, 900);

        $kachel = $this->kachel($this->history()->forDomain($domain), 'traffic');

        $raus = array_column($kachel['series']['points'], 'y');
        $rein = array_column($kachel['second']['series']['points'], 'y');

        /*
         * Gemessen wird der **Ausschlag** und nicht ein Festwert.
         *
         * y = 28 ist die Grundlinie, y = 4 der obere Rand; 24 Einheiten liegen
         * dazwischen. Die kleinere Richtung geht von 500 auf 900 Byte — gegen
         * eine Spanne von zwei Millionen sind das 0,005 Einheiten, also flach.
         * Ein `assertSame(28.0, …)` wäre hier falsch: Es verlangte, dass die
         * kleine Richtung gar nicht gezeichnet wird, und genau das tut eine
         * geteilte Achse nicht.
         */
        self::assertLessThan(0.1, abs($rein[1] - $rein[0]),
            'Die kleinere Richtung liegt flach unten — sonst ist das Bild eine Lüge.');
        self::assertSame(4.0, $raus[1],
            'Der höchste Wert beider Richtungen liegt am oberen Rand des Feldes (y = 4).');
        self::assertGreaterThan(10.0, $raus[0] - $raus[1],
            'Die grössere Richtung nutzt das Feld — sonst teilten sich die beiden keine Achse, '
            .'sondern jede hätte ihre eigene.');
    }

    /**
     * Jede Richtung behält ihre eigene Einheit.
     *
     * Die gemeinsame Spanne ist eine Aussage über die Geometrie; „0,0 MB" für
     * 900 Byte wäre eine über den Messwert, und sie wäre falsch.
     */
    public function test_each_direction_keeps_its_own_unit(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 1_000_000, 500);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 2_000_000, 900);

        $kachel = $this->kachel($this->history()->forDomain($domain), 'traffic');

        self::assertSame('MB', $kachel['unit']);
        self::assertSame('B', $kachel['second']['unit']);
    }

    /**
     * Die Verkehrsmenge trägt **kein** „/s".
     *
     * Der Ringpuffer misst eine Rate, diese Tabelle eine Menge. Dieselbe
     * Grössenordnung, dieselben Schritte, zwei verschiedene Grössen — und die
     * Nachsilbe ist der einzige Unterschied, den man sieht.
     */
    public function test_a_daily_amount_is_not_a_rate(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 1_000_000, 500);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 2_000_000, 900);

        $kachel = $this->kachel($this->history()->forDomain($domain), 'traffic');

        self::assertStringNotContainsString('/s', $kachel['unit']);
        self::assertStringNotContainsString('/s', $kachel['series']['points'][1]['v']);
        self::assertSame('2,0 MB', $kachel['series']['points'][1]['v']);
    }

    /**
     * Die Datenbanken stehen in MB und nicht in Byte.
     *
     * **Die Kachel richtet sich nach der Zahl im Bereich darunter** und nicht
     * nach der Ablage: Der Bereich „Datenbanken" derselben Seite zeigt seit P5
     * `used_mb`. Eine Kachel in Byte daneben wäre dieselbe Grösse in zwei
     * Einheiten — und der Leser rechnete um, statt zu lesen.
     *
     * > **Eine Anzeige, die dieselbe Grösse zweimal verschieden schreibt,
     * > lässt den Leser rechnen.**
     *
     * **Dieser Fall hält die Einheit und nicht die Rundung.** Hier stand bis
     * zum 28. September 2026 auch die Rundung, geprüft an einem Gigabyte — und
     * das ist der eine Wert, an dem Teilen und Abrunden dasselbe ergeben. Die
     * Kachel zeigte dabei `3,5 MB` über `3 MB` (`docs/139 §0` Punkt 1). Die
     * Rundung hält seitdem
     * `test_the_database_tile_shows_the_number_of_the_section_below`.
     */
    public function test_the_database_tile_speaks_the_unit_of_its_page(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 1, 0, 1, 1, database: 536_870_912);
        $this->tag($subscription, $domain, '2026-09-20', 1, 0, 1, 1, database: 1_073_741_824);

        $kachel = $this->kachel($this->history()->forSubscription($subscription), 'databases');

        self::assertSame('MB', $kachel['unit']);
        self::assertSame('1.024', $kachel['value'], 'Ein Gigabyte sind 1.024 MB und nicht 1.073.741.824 B.');
    }

    /**
     * **Oben und unten dieselbe Zahl — gemessen an der Seite, wie sie
     * hinausgeht.**
     *
     * Der Befund aus `docs/139 §0` Punkt 1: Die Kachel teilte, der Bereich
     * darunter rundet über {@see Subscription::databaseUsedMb()} ab, und
     * dieselbe Messung stand zur selben Minute oben als `3,5 MB` und unten als
     * `3 MB`. Der Wächter davor war grün, weil sein Prüfkörper ein Gigabyte
     * war.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im
     * > Erfolgsfall, misst nicht.**
     *
     * **3,75 MiB trennen drei Rechnungen:** abgerundet 3, kaufmännisch
     * gerundet 4, geteilt `3,8`. Der Fall liest beide Zahlen aus der Antwort
     * der echten Route und nicht aus zwei Aufrufen hier: Rechnete der
     * Controller den Bereich einmal anders, stünde der Unterschied nur dort.
     * Die Seite schreibt `used_mb` mit `toLocaleString('de-DE')`; für eine
     * ganze Zahl ist das `number_format($zahl, 0, ',', '.')`.
     *
     * Dasselbe für den Speicherplatz, an 3 MB: Dort stand `3,0 MB` über
     * `3 MB`.
     */
    public function test_the_database_tile_shows_the_number_of_the_section_below(): void
    {
        [$subscription, $domain] = $this->abonnement();
        $bytes = 3 * 1_048_576 + 786_432;

        app(Tenancy::class)->withoutRestriction(function () use ($subscription, $bytes): void {
            Database::factory()->create([
                'subscription_id' => $subscription->id,
                'size_bytes' => 1_048_576,
                'size_measured_at' => now(),
            ]);
            Database::factory()->create([
                'subscription_id' => $subscription->id,
                'size_bytes' => $bytes - 1_048_576,
                'size_measured_at' => now(),
            ]);

            // `disk_used_mb` ist gemessen und nicht füllbar; ein `update()`
            // täte hier wortlos nichts.
            $subscription->forceFill(['disk_used_mb' => 3, 'disk_usage_measured_at' => now()])->save();
        });

        $this->tag($subscription, $domain, '2026-09-27', 1, 0, 1, 1, disk: 2, database: 2 * 1_048_576);
        $this->tag($subscription, $domain, '2026-09-28', 1, 0, 1, 1, disk: 3, database: $bytes);

        $props = $this->actingAs(Account::factory()->admin()->create())
            ->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->inertiaProps();

        $unten = $props['database_usage']['used_mb'];

        self::assertSame(3, $unten, 'Der Bereich rundet ab: 3,75 MiB sind dort 3 MB.');
        self::assertSame(
            number_format($unten, 0, ',', '.'),
            $this->kachel($props['history'], 'databases')['value'],
            'Die Kachel zeigt dieselbe Zahl wie der Bereich darunter — 3,75 MiB trennen Abrunden (3), '
            .'Runden (4) und Teilen (3,8).',
        );

        self::assertSame(
            number_format((int) $props['usage']['used_mb'], 0, ',', '.'),
            $this->kachel($props['history'], 'disk')['value'],
            'Der Speicherplatz steht oben wie unten in ganzen MB: 3 und nicht 3,0.',
        );
    }

    /**
     * Abgerundet wird **vor** der Kurve und nicht erst an der Zahl.
     *
     * 3 MiB und 3,75 MiB sind im Bereich darunter beide 3 MB. Rundete erst
     * die Zahl, stiege die Kurve zwischen den beiden Tagen, und ihre Ablesung
     * nennte zweimal `3 MB`: ein Ausschlag, den keine Zahl benennt.
     */
    public function test_the_curve_is_rounded_before_it_is_drawn(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 1, 0, 1, 1, database: 3 * 1_048_576);
        $this->tag($subscription, $domain, '2026-09-20', 1, 0, 1, 1, database: 3 * 1_048_576 + 786_432);

        $punkte = $this->kachel($this->history()->forSubscription($subscription), 'databases')['series']['points'];

        self::assertSame(['3 MB', '3 MB'], array_column($punkte, 'v'));
        self::assertSame($punkte[0]['y'], $punkte[1]['y'],
            'Zwei Tage mit derselben Zahl liegen auf derselben Höhe.');
    }

    /**
     * Eine Anzahl steht ganz da — `5` und nicht `5,0`, `0` und nicht `0,00`.
     *
     * Die Zugriffe liefen bis zum 28. September 2026 durch die Regel für
     * Raten (`docs/139 §0` Punkt 1). Auf `cloudsrv24` hätte ab dem 29. auf den
     * Seiten der ruhigen Domains `Zugriffe 0,00` gestanden.
     *
     * > **Ein Format, das für eine Rate reicht, reicht nicht für eine
     * > Anzahl.**
     */
    public function test_a_count_stands_whole(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-18', 5, 0, 1, 1);
        $this->tag($subscription, $domain, '2026-09-19', 0, 0, 0, 0);
        $this->tag($subscription, $domain, '2026-09-20', 2389, 0, 1, 1);

        foreach ([
            'Abonnement' => $this->history()->forSubscription($subscription),
            'Domain' => $this->history()->forDomain($domain),
        ] as $seite => $kacheln) {
            $kachel = $this->kachel($kacheln, 'requests');

            self::assertSame(['5', '0', '2.389'], array_column($kachel['series']['points'], 'v'), $seite);
            self::assertSame('2.389', $kachel['value'], $seite);
        }
    }

    /**
     * Und der Speicherplatz steht in ganzen MB — `3 MB` und nicht `3,0 MB`.
     *
     * `disk_used_mb` ist eine ganze Zahl, und der Bereich darunter schreibt sie
     * so. Die Stelle hinter dem Komma gab es nicht.
     */
    public function test_the_disk_tile_stands_in_whole_mb(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 1, 0, 1, 1, disk: 3);
        $this->tag($subscription, $domain, '2026-09-20', 1, 0, 1, 1, disk: 1250);

        $kachel = $this->kachel($this->history()->forSubscription($subscription), 'disk');

        self::assertSame(['3 MB', '1.250 MB'], array_column($kachel['series']['points'], 'v'));
        self::assertSame('1.250', $kachel['value']);
    }

    /**
     * Ein leerer Wert trägt keine Einheit — `—` und nicht `— MB`.
     *
     * Bis zum 28. September 2026 stand an jeder leeren Kachel dieser beiden
     * Seiten eine Einheit neben dem Strich: `— MB`, `— B`, `— %`
     * (`docs/139 §0` Punkt 1). Ein Tag ergibt keine Kurve, und damit ist jede
     * Kachel leer.
     *
     * Die Gegenrichtung steht daneben: Mit zwei Tagen tragen dieselben
     * Kacheln ihre Einheit. Ohne sie bestünde der Fall auch, wenn die Einheit
     * überall fehlte.
     */
    public function test_an_empty_tile_carries_no_unit(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900, disk: 3, database: 1_048_576);

        $leer = [
            ...$this->history()->forSubscription($subscription),
            ...$this->history()->forDomain($domain),
        ];

        foreach ($leer as $kachel) {
            self::assertSame('—', $kachel['value'], $kachel['key']);
            self::assertSame('', $kachel['unit'], "Die Kachel „{$kachel['key']}“ trägt eine Einheit neben dem Strich.");
        }

        self::assertSame('', $this->kachel($leer, 'traffic')['second']['unit'],
            'Auch die zweite Richtung schreibt keine Einheit neben ihren Strich.');

        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950, disk: 4, database: 2 * 1_048_576);
        $voll = $this->history()->forSubscription($subscription);

        self::assertSame(
            ['disk' => 'MB', 'traffic' => 'kB', 'requests' => '', 'errors' => '%', 'databases' => 'MB'],
            array_column($voll, 'unit', 'key'),
        );
        self::assertSame('B', $this->kachel($voll, 'traffic')['second']['unit']);
    }

    /**
     * Die Schwelle des Speicherplatzes kommt aus dem Kontingent.
     *
     * Ab wann es eng wird, ist eine Aussage über den Betrieb und keine über
     * die Darstellung — und auf diesem Server steht sie im Plan.
     */
    public function test_the_disk_curve_warns_at_its_quota(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement(['disk_mb' => 100]);
        $this->tag($subscription, $domain, '2026-09-19', 1, 0, 1, 1, disk: 40);
        $this->tag($subscription, $domain, '2026-09-20', 1, 0, 1, 1, disk: 120);

        self::assertTrue(
            $this->kachel($this->history()->forSubscription($subscription), 'disk')['series']['warns'],
            'Der letzte Tag liegt über dem Kontingent — die Kurve trägt die Warnfarbe.',
        );
    }

    /** Und die Gegenrichtung: ohne Kontingent warnt nichts. */
    public function test_without_a_quota_the_disk_curve_stays_quiet(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 1, 0, 1, 1, disk: 40);
        $this->tag($subscription, $domain, '2026-09-20', 1, 0, 1, 1, disk: 120);

        self::assertFalse(
            $this->kachel($this->history()->forSubscription($subscription), 'disk')['series']['warns'],
            '`null` heisst „für dieses Abonnement gibt es keine Grenze" und nicht „Schwelle null".',
        );
    }

    /**
     * Die Verkehrskachel hat **keine** Schwelle, obwohl der Katalog eine führt.
     *
     * `Quota::TrafficGb` ist eine Menge je **Monat**, die Kurve zeigt Tage.
     * Eine Tageszahl gegen ein Monatskontingent zu halten hiesse, dreissigmal
     * zu früh zu warnen.
     *
     * > **Eine Schwelle, die eine andere Grösse misst als die Kurve, ist
     * > keine.**
     */
    public function test_a_monthly_quota_is_no_threshold_for_a_daily_curve(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement(['traffic_gb' => 1]);
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000_000_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 9_000_000_000, 950);

        $kachel = $this->kachel($this->history()->forSubscription($subscription), 'traffic');

        self::assertFalse($kachel['series']['warns']);
        self::assertFalse($kachel['second']['series']['warns']);
    }

    /**
     * Gezeigt werden dreissig Tage — auch wenn mehr dastehen.
     *
     * Das Abräumen in `Daily::forget()` hält die Tabelle klein. Hinge die
     * Zusage der Seite allein daran, zeigte sie nach einem ausgefallenen
     * Nachtlauf neunzig Tage und behauptete dreissig.
     */
    public function test_only_the_last_thirty_days_are_shown(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();

        for ($i = 0; $i < 40; $i++) {
            $this->tag($subscription, $domain,
                Carbon::parse('2026-09-20')->subDays($i)->toDateString(),
                100 + $i, 1, 1_000, 100);
        }

        $punkte = $this->kachel($this->history()->forSubscription($subscription), 'requests')['series']['points'];

        self::assertCount(History::DAYS, $punkte);
        self::assertSame('20.09.', $punkte[count($punkte) - 1]['t'],
            'Der jüngste Tag steht rechts — abgeschnitten wird vorn und nicht hinten.');
        self::assertSame('22.08.', $punkte[0]['t']);
    }

    /**
     * **Ein Stand, der den laufenden Tag trägt, nimmt dem Verkehr keinen Tag.**
     *
     * Seit dem 28. September 2026 legt `srvpanel:usage` Platz und Datenbanken
     * für den laufenden Tag ab; der Verkehr endet am Vortag. Ein Fenster über
     * die Tage **aller** Kennzahlen endete damit heute, und die Kacheln des
     * Verkehrs zeigten neunundzwanzig. Gezählt wird je Kennzahl: dreissig
     * Tage Verkehr bis gestern, dreissig Tage Platz bis heute.
     *
     * Die Zeilen stehen so da, wie `Daily::forget()` sie lässt — der älteste
     * Tag `heute − 30` für beide.
     */
    public function test_a_level_that_carries_today_takes_no_day_from_the_traffic(): void
    {
        [$subscription] = $this->angemeldetesAbonnement();

        app(Tenancy::class)->withoutRestriction(function () use ($subscription): void {
            for ($i = 0; $i <= 30; $i++) {
                $tag = Carbon::parse('2026-09-28')->subDays($i)->toDateString();

                SubscriptionMetric::query()->create([
                    'subscription_id' => $subscription->id,
                    'day' => $tag,
                    'metric' => DailyMetric::DiskMb->value,
                    'value' => 500 + $i,
                ]);

                if ($i === 0) {
                    continue;
                }

                SubscriptionMetric::query()->create([
                    'subscription_id' => $subscription->id,
                    'day' => $tag,
                    'metric' => DailyMetric::Requests->value,
                    'value' => 100 + $i,
                ]);
            }
        });

        $kacheln = $this->history()->forSubscription($subscription);
        $zugriffe = $this->kachel($kacheln, 'requests')['series']['points'];
        $platz = $this->kachel($kacheln, 'disk')['series']['points'];

        self::assertCount(History::DAYS, $zugriffe, 'Der Verkehr hat dreissig Tage bis gestern — und zeigt sie alle.');
        self::assertSame('29.08.', $zugriffe[0]['t'], 'Der älteste Tag des Verkehrs ist heute − 30 und bleibt stehen.');
        self::assertSame('27.09.', $zugriffe[count($zugriffe) - 1]['t']);

        self::assertCount(History::DAYS, $platz);
        self::assertSame('28.09.', $platz[count($platz) - 1]['t'], 'Der Platz reicht bis heute.');
    }

    /**
     * Eine Abfrage je Seite und nicht eine je Kachel.
     *
     * Gemessen (`docs/128` M4): 150 Punkte kosten als eine Abfrage 0,0006 s,
     * als fünf 0,0040 s. Beides ist billig — die eine ist trotzdem die
     * richtige, weil sie nicht mit der Zahl der Kacheln wächst.
     */
    public function test_the_page_asks_once(): void
    {
        [$subscription, $domain] = $this->angemeldetesAbonnement();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->history()->forSubscription($subscription);

        $gelesen = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains((string) $q['query'], 'from "subscription_metrics"'),
        );

        self::assertCount(1, $gelesen,
            'Fünf Kacheln, eine Abfrage. Die Tabelle ist lang und nicht breit — '
            .'alle Kennzahlen eines Abonnements kommen mit einem `where` heraus.');
    }

    /**
     * Und die Mandantenklammer bleibt dran.
     *
     * Beide Modelle tragen `BelongsToSubscription`. Diese Klasse tut dafür
     * nichts — und genau das ist die Zusage: Wer hier ein
     * `withoutRestriction()` einbaute, zeigte einem Kunden die Zahlen eines
     * fremden Abonnements.
     */
    public function test_a_foreign_customer_sees_nothing(): void
    {
        [$subscription, $domain] = $this->abonnement();
        $this->anmelden();
        $this->tag($subscription, $domain, '2026-09-19', 100, 5, 5_000, 900);
        $this->tag($subscription, $domain, '2026-09-20', 120, 6, 6_000, 950);

        // Die Gegenrichtung zuerst: Mit offener Klammer stehen die Zahlen da.
        self::assertTrue(
            $this->kachel($this->history()->forSubscription($subscription), 'requests')['series']['has'],
            'Ohne diese Richtung wäre die Messung daneben eine Null ohne Bedeutung.',
        );

        $fremder = app(Tenancy::class)->withoutRestriction(
            static fn (): Account => Account::factory()->customer(Customer::factory()->create())->create(),
        );

        $this->anmelden($fremder);

        self::assertFalse(
            $this->kachel($this->history()->forSubscription($subscription), 'requests')['series']['has'],
            'Ein Kunde, dem dieses Abonnement nicht gehört, bekommt keine Zeile — und zwar ohne dass '
            .'{@see History} etwas dafür tut. Wer hier ein `withoutRestriction()` einbaute, zeigte ihm '
            .'fremde Zahlen.',
        );
    }
}
