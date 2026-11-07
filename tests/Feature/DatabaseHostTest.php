<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Datenbankseite nennt den Server, den eine Anwendung einträgt.
 *
 * **Jedes Installationsprogramm fragt nach vier Angaben** — Server, Name,
 * Benutzer, Passwort. Bis zum 7. November 2026 nannte die Seite drei davon:
 * für MariaDB keinen Server, für PostgreSQL einen in einem Satz unter der
 * Tabelle. Gefunden beim Ausschreiben des Durchlaufs mit einer fremden Person
 * (`docs/145 §0`, Befund 2) — eine Aufgabe dort fragt genau nach diesen vier.
 *
 * Gemessen wird an der Seite, die der Kunde bekommt, und nicht am Enum: Ein
 * Wert, den das Enum kennt und der Controller nicht weitergibt, stünde
 * trotzdem nirgends.
 */
final class DatabaseHostTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Account, Database} */
    private function scenario(bool $postgres): array
    {
        [$subscription, $database] = app(Tenancy::class)->withoutRestriction(function () use ($postgres): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
            $factory = $postgres ? Database::factory()->postgres() : Database::factory();

            return [$subscription, $factory->forSubscription($subscription, 'shop')->create()];
        });

        $customer = Customer::query()->findOrFail($subscription->customer_id);

        return [Account::factory()->customer($customer)->create(), $database];
    }

    /** MariaDB: `localhost`, weil die Zugänge als `name@localhost` entstehen. */
    public function test_a_mariadb_database_names_localhost(): void
    {
        [$account, $database] = $this->scenario(postgres: false);

        $this->actingAs($account)
            ->get("/databases/{$database->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Databases/Show')
                ->where('database.host', 'localhost')
                ->etc());
    }

    /** PostgreSQL: `127.0.0.1`, weil `peer` über den Socket keine Rolle `p1000_web` kennt. */
    public function test_a_postgres_database_names_the_loopback_address(): void
    {
        [$account, $database] = $this->scenario(postgres: true);

        $this->actingAs($account)
            ->get("/databases/{$database->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Databases/Show')
                ->where('database.host', '127.0.0.1')
                ->etc());
    }

    /** Und die Seite zeigt ihn — eine Angabe, die nur im Datensatz steht, liest niemand. */
    public function test_the_page_shows_it(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Databases/Show.vue');

        $this->assertMatchesRegularExpression(
            '/<td class="quiet">Server<\/td>\s*<td class="right ident">\{\{ props\.database\.host \}\}<\/td>/',
            $template,
            'Die Datenbankseite zeigt den Server nicht mehr — dann fehlt einer Anwendung wieder eine von vier Angaben.',
        );
    }
}
