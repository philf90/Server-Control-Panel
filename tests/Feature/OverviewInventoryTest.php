<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Database;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Der Bestand auf der Übersicht zählt, was die verlinkte Liste zeigt.
 *
 * **Warum das ein eigener Wächter ist.** Eine Zahl auf einer Übersichtsseite
 * ist die billigste Sorte Unwahrheit: Sie sieht immer plausibel aus. Weicht sie
 * von der Liste dahinter ab, merkt es niemand — ausser dem, der beides
 * nebeneinander legt, und der tut es nur, wenn er ohnehin schon etwas sucht.
 *
 * Die teuerste Abweichung ist dabei die stille: Eine verwaiste Datenbank —
 * eine, deren Rückbau steckengeblieben ist und deren Schema mit Kundendaten
 * noch auf der Platte liegt (docs/36 §5) — gehört auf beide Seiten. Wer sie aus
 * der Zählung nähme, weil sie zu keinem Abonnement mehr gehört, bekäme genau
 * dann eine zu kleine Zahl, wenn etwas nicht stimmt.
 *
 * **Und die Verweise werden mitgeprüft.** „Kunden", „Abonnements", „Domains",
 * „Datenbanken" sind Links, damit man von der Zahl zur Liste kommt. Eine
 * Adresse in einem Template ist wortwörtlich das Muster aus CLAUDE.md — *eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft.*
 */
final class OverviewInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Übersicht als Betreiber holen und den Bestand herausnehmen.
     *
     * @return array<string, array<string, int>>
     */
    private function hosting(): array
    {
        $response = $this->actingAs(Account::factory()->admin()->create())->get('/');

        $response->assertOk();

        /** @var array{props: array{hosting: array<string, array<string, int>>}} $page */
        $page = $response->viewData('page');

        return $page['props']['hosting'];
    }

    public function test_domains_and_databases_are_counted(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

        Domain::factory()->count(3)->create(['subscription_id' => $subscription->id]);
        Database::factory()->forSubscription($subscription, 'shop')->create();
        Database::factory()->forSubscription($subscription, 'blog')->create();

        $hosting = $this->hosting();

        $this->assertSame(3, $hosting['domains']['total']);
        $this->assertSame(2, $hosting['databases']['total']);
    }

    /**
     * Eine Datenbank ohne Abonnement zählt mit.
     *
     * Die Liste unter `/databases` führt sie als verwaist; die Übersicht muss
     * dieselbe Zahl nennen. Eine Zählung, die den Sonderfall auslässt, ist
     * ausgerechnet dann zu niedrig, wenn er eingetreten ist.
     */
    public function test_an_orphaned_database_is_counted_too(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
        $database = Database::factory()->forSubscription($subscription, 'rest')->create();

        app(Tenancy::class)->withoutRestriction(fn (): int => Database::query()
            ->whereKey($database->id)
            ->update(['subscription_id' => null]));

        $this->assertSame(1, $this->hosting()['databases']['total']);
    }

    /** Ohne Bestand steht überall null — und keine Zahl fehlt. */
    public function test_an_empty_server_reports_zero_and_not_nothing(): void
    {
        $hosting = $this->hosting();

        foreach (['domains', 'databases'] as $kind) {
            $this->assertSame(0, $hosting[$kind]['total'], $kind.' fehlt auf einem leeren Server.');
            $this->assertSame(0, $hosting[$kind]['active']);
        }
    }

    /**
     * Jeder Verweis im Bestand zeigt auf eine Adresse, die es gibt.
     *
     * Geprüft am Router und nicht an einer Liste im Test: Eine zweite Liste
     * wäre eine zweite Fassung derselben Auskunft, und die veraltet.
     */
    public function test_every_link_in_the_inventory_points_at_a_route(): void
    {
        $section = $this->inventorySection();
        $found = preg_match_all('/href="(\/[a-z\/-]*)"/', $section, $matches);

        $this->assertGreaterThanOrEqual(
            4,
            $found,
            'Ohne gefundene Verweise prüft dieser Wächter nichts.',
        );

        $known = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $known[] = '/'.ltrim($route->uri(), '/');
            }
        }

        foreach ($matches[1] as $href) {
            $this->assertContains($href, $known, $href.' steht im Bestand und ist keine Adresse dieses Panels.');
        }
    }

    /**
     * Und alle vier Bestandsarten sind verlinkt.
     *
     * Die Gegenrichtung: Der Ausdruck oben bliebe grün, wenn jemand einen Link
     * entfernte — dann stünde die Zahl da, und wer sie liest, müsste den Weg
     * zur Liste selbst suchen.
     */
    public function test_all_four_kinds_are_linked(): void
    {
        $section = $this->inventorySection();

        foreach (['/customers', '/subscriptions', '/domains', '/databases'] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $section, $href.' fehlt im Bestand.');
        }
    }

    /** Der Abschnitt „Bestand" aus der Übersicht, als Text. */
    private function inventorySection(): string
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Overview.vue'));

        $this->assertSame(
            1,
            preg_match('#<Section title="Bestand">(?<inhalt>.*?)</Section>#su', $source, $match),
            'Der Abschnitt heisst nicht mehr „Bestand" — dann prüft dieser Wächter die falsche Stelle.',
        );

        return $match['inhalt'];
    }
}
