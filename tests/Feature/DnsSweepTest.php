<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\DomainDnsCheck;
use App\Models\Subscription;
use App\Support\Dns\Budget;
use App\Support\Dns\Dns;
use App\Support\Dns\Measurement;
use App\Support\Dns\Sweep;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Der regelmässige Abgleich — wer drankommt und wann Schluss ist.
 *
 * **Der Lauf hat kein angemeldetes Konto.** Ihn startet ein Timer, und im
 * Grundzustand klammert {@see Tenancy} auf `0 = 1`. Genau daran ist der
 * Einsammler der Cron-Läufe wochenlang gescheitert, ohne dass es jemand
 * gemerkt hätte: Er meldete „88 eingesammelt, 0 eingepflegt" und war grün.
 *
 * > **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie: Die
 * > andere fällt nicht auf, weil sie leise das Richtige tut — nämlich nichts.**
 *
 * **Die Reihenfolge ist die andere Hälfte.** Ein Deckel ohne Reihenfolge
 * bevorzugt immer dieselben Domains — die mit der kleinsten Kennung —, und die
 * hinteren würden nie gemessen. Auch das wäre grün: Der Bericht meldete jeden
 * Lauf „25 geprüft".
 *
 * > **Eine Obergrenze ohne Reihenfolge ist keine Begrenzung, sondern eine
 * > Bevorzugung.**
 */
final class DnsSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Die Gegenprobe zu allem, was hier steht, und sie kommt zuerst.**
     *
     * Jeder Fall unten behauptet etwas über die Lage „kein Konto angemeldet".
     * Wäre die Klammer in Tests offen, prüften sie eine andere Anwendung als
     * die, die auf dem Server läuft.
     *
     * > **Ein Test, der eine andere Ausgangslage hat als der Server, prüft eine
     * > andere Anwendung.**
     */
    public function test_the_clamp_is_closed_in_this_situation(): void
    {
        $this->domain('beispiel.de');

        $this->assertSame(
            0,
            Domain::query()->count(),
            implode("\n", [
                'Die Mandantenklammer ist in dieser Lage offen.',
                'Dann sagen die Faelle unten nichts ueber den Systemdienst aus, der',
                'ohne angemeldetes Konto laeuft.',
            ]),
        );
    }

    /**
     * Ohne angemeldetes Konto wird trotzdem gemessen.
     */
    public function test_it_measures_without_a_logged_in_account(): void
    {
        $domain = $this->domain('beispiel.de');

        $bericht = $this->sweep()->run();

        $this->assertSame(1, $bericht['due'], 'Der Lauf findet die Domain nicht — die Klammer greift.');
        $this->assertSame(1, $bericht['checked'], 'Der Lauf misst die Domain nicht.');
        $this->assertSame([$domain->id], $this->measured());
    }

    /**
     * Wer noch nie gemessen wurde, kommt zuerst.
     */
    public function test_the_never_measured_come_first(): void
    {
        $alt = $this->domain('alt.de');
        $this->seedCheck($alt, minutes: 300);

        $nie = $this->domain('nie.de');

        $this->sweep(domains: 1)->run();

        $this->assertSame(
            [$nie->id],
            $this->measured(since: 60),
            'Eine laengst gemessene Domain kommt vor einer, die noch nie gemessen wurde.',
        );
    }

    /**
     * Und danach der älteste Befund.
     */
    public function test_the_oldest_finding_comes_next(): void
    {
        $mittel = $this->domain('mittel.de');
        $aeltest = $this->domain('aeltest.de');
        $juengst = $this->domain('juengst.de');

        $this->seedCheck($mittel, minutes: 180);
        $this->seedCheck($aeltest, minutes: 400);
        $this->seedCheck($juengst, minutes: 90);

        $this->sweep(domains: 1)->run();

        $this->assertSame(
            [$aeltest->id],
            $this->measured(since: 60),
            'Nicht der aelteste Befund kommt dran — dann verhungern die hinteren Domains.',
        );
    }

    /**
     * Ein frischer Befund wird nicht sofort wiederholt.
     *
     * Sonst fragte ein Server mit drei Domains dieselben fremden Nameserver
     * viermal in der Stunde, und zwar für nichts.
     */
    public function test_a_fresh_finding_is_not_measured_again(): void
    {
        $domain = $this->domain('frisch.de');
        $this->seedCheck($domain, minutes: 5);

        $bericht = $this->sweep()->run();

        $this->assertSame(0, $bericht['due'], 'Ein fuenf Minuten alter Befund wird noch einmal gemessen.');

        // **`since: 1` und nicht `since: 60`.** Der vorbereitete Befund ist
        // fuenf Minuten alt und laege in einem Fenster von sechzig Minuten mit
        // drin — der Fall waere dann rot, obwohl nichts gemessen wurde.
        $this->assertSame([], $this->measured());
    }

    /**
     * Eine Domain im Rückbau wird übergangen.
     *
     * Ihre Zeile nähme der Fremdschlüssel Sekunden später wieder mit.
     */
    public function test_a_domain_being_removed_is_skipped(): void
    {
        $this->domain('geht.de', status: DomainStatus::Removing);

        $this->assertSame(0, $this->sweep()->run()['due']);
    }

    /**
     * Was nicht mehr hineinpasst, bleibt für den nächsten Lauf liegen — und
     * wird genannt.
     *
     * **Eine Obergrenze, die nichts sagt, wenn sie greift, sieht aus wie
     * „alles gemessen".**
     */
    public function test_the_bound_leaves_the_rest_for_the_next_run(): void
    {
        foreach (['eins.de', 'zwei.de', 'drei.de'] as $name) {
            $this->domain($name);
        }

        $bericht = $this->sweep(domains: 2)->run();

        $this->assertSame(3, $bericht['due']);
        $this->assertSame(2, $bericht['checked']);
        $this->assertSame(1, $bericht['left'], 'Was liegen bleibt, steht nicht im Bericht.');
    }

    /**
     * Eine Domain, die scheitert, beendet den Lauf nicht.
     *
     * **Sonst bliebe sie beim nächsten Mal wieder die älteste** — und der Lauf
     * käme nie an den Rest, für immer, ohne eine einzige Meldung darüber.
     */
    public function test_a_failing_domain_does_not_stop_the_run(): void
    {
        $kaputt = $this->domain('kaputt.de');
        $heil = $this->domain('heil.de');

        $bericht = $this->sweep(explode: 'kaputt.de')->run();

        $this->assertSame(1, $bericht['failed'], 'Der Fehlschlag wird nicht gezaehlt.');
        $this->assertSame(1, $bericht['checked'], 'Die zweite Domain ist nicht gemessen worden.');
        $this->assertSame([$heil->id], $this->measured());
        $this->assertNotContains($kaputt->id, $this->measured());
    }

    /**
     * Ein Alias wird mitgemessen.
     *
     * **Er hat eine eigene Seite mit einem eigenen Abgleich.** Wer ihn hier
     * überspränge, liesse genau diese Seite für immer auf „noch nie geprüft"
     * stehen — und der Unterschied zwischen dem Knopf und dem Lauf stünde
     * nirgends.
     */
    public function test_an_alias_is_measured_too(): void
    {
        $eltern = $this->domain('eltern.de');

        $alias = Domain::factory()->alias($eltern)->create(['name' => 'alias.at']);

        $this->sweep()->run();

        $this->assertContains($alias->id, $this->measured(), 'Der Alias bekommt keinen eigenen Befund.');
    }

    // ------------------------------------------------------------------
    // Aufbau
    // ------------------------------------------------------------------

    /**
     * Ein Durchgang mit einer Messung, die nichts nach draussen fragt.
     *
     * `$explode` nennt den Namen, bei dem die Messung wirft — das ist der
     * Fehlschlag, den {@see Sweep} auffangen muss.
     */
    private function sweep(int $domains = 25, ?string $explode = null): Sweep
    {
        $this->app->instance(Measurement::class, new class($explode) implements Measurement
        {
            public function __construct(private readonly ?string $explode) {}

            /**
             * @param  list<array{name: string, type: string}>  $queries
             * @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}|null
             */
            public function of(string $zone, array $queries): ?array
            {
                if ($this->explode !== null && $zone === $this->explode) {
                    throw new RuntimeException('Diese Zone kostet den Aufruf.');
                }

                return ['nameservers' => ['198.51.100.1'], 'records' => [], 'authorities' => []];
            }
        });

        return new Sweep(
            $this->app->make(Dns::class),
            $this->app->make(Tenancy::class),
            new Budget(domains: $domains, seconds: 1000),
        );
    }

    private function domain(
        string $name,
        DomainStatus $status = DomainStatus::Active,
        DomainType $type = DomainType::Addon,
    ): Domain {
        return Domain::factory()->create([
            'subscription_id' => Subscription::factory(),
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);
    }

    /** Ein Befund, der so viele Minuten alt ist. */
    private function seedCheck(Domain $domain, int $minutes): void
    {
        $this->app->make(Tenancy::class)->withoutRestriction(function () use ($domain, $minutes): void {
            DomainDnsCheck::query()->create([
                'domain_id' => $domain->id,
                'subscription_id' => $domain->subscription_id,
                'checked_at' => now()->subMinutes($minutes),
                'findings' => [],
            ]);
        });
    }

    /**
     * Welche Domains in diesem Lauf einen Befund bekommen haben.
     *
     * `$since` grenzt auf die Minuten davor ein — die vorbereiteten Befunde
     * sind älter und zählen damit nicht mit.
     *
     * @return list<int>
     */
    private function measured(int $since = 1): array
    {
        /** @var list<int> $ids */
        $ids = [];

        $this->app->make(Tenancy::class)->withoutRestriction(function () use ($since, &$ids): void {
            $ids = array_values(array_map(intval(...), DomainDnsCheck::query()
                ->where('checked_at', '>=', now()->subMinutes($since))
                ->orderBy('checked_at')
                ->orderBy('id')
                ->pluck('domain_id')
                ->all()));
        });

        return $ids;
    }
}
