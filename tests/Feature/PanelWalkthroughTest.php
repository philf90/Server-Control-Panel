<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Audit\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Abnahmebedingung von P1, als ein Durchgang.
 *
 * „Ein Admin legt einen Kunden an, dieser meldet sich an, sieht seine (leere)
 * Übersicht, der Admin kann auf seiner Übersicht die Verläufe des Servers
 * ablesen, und kein Kunde kommt durch Manipulation von IDs an fremde Objekte."
 *
 * Die einzelnen Schichten haben ihre eigenen Tests. Dieser hier prüft, ob sie
 * zusammen das ergeben, was im Plan steht — ein Durchgang, wie ihn ein Mensch
 * gehen würde.
 */
final class PanelWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_creates_a_customer_who_then_signs_in(): void
    {
        $admin = Account::factory()->admin()->create();

        // 1. Der Admin legt einen Kunden an — mit Anmeldekonto.
        $this->actingAs($admin)->post('/customers', [
            'number' => 'K10001',
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
            'email' => 'erika@example.test',
            'login_email' => 'erika@example.test',
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertRedirect('/customers');

        $customer = Customer::query()->where('number', 'K10001')->firstOrFail();
        $account = $customer->accounts()->firstOrFail();

        $this->assertSame(AccountType::Customer, $account->type);
        $this->assertStringStartsWith('$argon2id$', $account->password);

        // 2. Der Kunde meldet sich an.
        $this->post('/logout');
        $this->post('/login', [
            'email' => 'erika@example.test',
            'password' => 'ein-langes-passwort',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($account);

        // 3. Und sieht seine leere Übersicht.
        $this->get('/')->assertOk();
    }

    public function test_a_half_created_customer_does_not_stay_behind(): void
    {
        $admin = Account::factory()->admin()->create();
        Account::factory()->admin()->create(['email' => 'belegt@example.test']);

        // Die Anmeldeadresse ist schon vergeben — der Kunde darf davon nichts
        // zurücklassen, sonst ist die Nummer verbraucht und niemand kann sich
        // anmelden.
        $this->actingAs($admin)->post('/customers', [
            'number' => 'K10002',
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.test',
            'login_email' => 'belegt@example.test',
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertSessionHasErrors('login_email');

        $this->assertNull(Customer::query()->where('number', 'K10002')->first());
    }

    public function test_a_customer_does_not_see_the_operator_pages(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        // Die Navigation zeigt ihm den Weg nicht — und die Policy weist ihn
        // ab, wenn er die Adresse von Hand einträgt.
        $this->actingAs($account)->get('/customers')->assertForbidden();
        $this->actingAs($account)->get('/customers/create')->assertForbidden();
    }

    public function test_a_customer_does_not_reach_a_foreign_customer_page(): void
    {
        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        $account = Account::factory()->customer($own)->create();

        $this->actingAs($account)->get("/customers/{$foreign->id}")->assertForbidden();
    }

    public function test_the_operator_overview_carries_the_server_readings(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Overview')
                ->has('tiles', 5)
                ->has('server')
                ->has('services')
                ->has('filesystems')
                ->has('processes'));
    }

    public function test_the_customer_overview_never_carries_them(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        // Nicht ausgeblendet, sondern gar nicht geschickt: Wer die Antwort
        // ansieht, findet die Serverwerte auch dort nicht.
        $this->actingAs($account)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CustomerOverview')
                ->has('subscriptions', 0)
                ->missing('server')
                ->missing('tiles')
                ->missing('services')
                // Prozessliste und Dateisysteme sind die Auskunft über den
                // Server, die am wenigsten in eine Kundenantwort gehört: Sie
                // nennt fremde Dienste, fremde Speicherbelegung und die
                // Einhängepunkte des Betreibers.
                ->missing('filesystems')
                ->missing('processes'));
    }

    public function test_signing_in_as_a_customer_and_back_again(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->create();
        Subscription::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($admin)
            ->post("/customers/{$customer->id}/impersonate")
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($target);
        $this->assertSame((int) $admin->id, session(Impersonation::SESSION_KEY));

        // In fremder Sicht sieht er die Kundenfläche, nicht die des Betreibers.
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('CustomerOverview'));

        $this->post('/impersonation/stop')->assertRedirect('/customers');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    public function test_actions_during_the_switch_name_both_people(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->create();

        $this->actingAs($admin)->post("/customers/{$customer->id}/impersonate");
        $this->post('/impersonation/stop');

        $stop = AuditEvent::query()->where('action', 'impersonation.stop')->firstOrFail();

        // §6.3: handelnde Person und Kontext. Stünde hier nur der Kunde,
        // verschwiege das Protokoll genau den Fall, für den man es liest.
        $this->assertSame((int) $admin->id, (int) $stop->account_id);
        $this->assertSame((int) $target->id, (int) $stop->acting_as_account_id);
    }

    public function test_a_customer_cannot_switch_into_anyone(): void
    {
        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        Account::factory()->customer($foreign)->create();
        $account = Account::factory()->customer($own)->create();

        $this->actingAs($account)
            ->post("/customers/{$foreign->id}/impersonate")
            ->assertForbidden();
    }

    public function test_there_is_no_switch_out_of_a_switch(): void
    {
        $admin = Account::factory()->admin()->create();
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        Account::factory()->customer($first)->create();
        Account::factory()->customer($second)->create();

        $this->actingAs($admin)->post("/customers/{$first->id}/impersonate");

        // Aus fremder Sicht in eine dritte zu springen, machte den Rückweg
        // mehrdeutig — und im Protokoll stünde eine Kette, die niemand mehr
        // auflöst. Hier scheitert es schon an der Policy: In der Sicht des
        // Kunden gibt es die Fähigkeit nicht mehr.
        $this->post("/customers/{$second->id}/impersonate")->assertForbidden();
    }

    public function test_a_customer_without_an_account_cannot_be_switched_into(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($admin)
            ->post("/customers/{$customer->id}/impersonate")
            ->assertSessionHasErrors('impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_return_works_even_if_the_admin_account_was_disabled(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        Account::factory()->customer($customer)->create();

        $this->actingAs($admin)->post("/customers/{$customer->id}/impersonate");

        $admin->forceFill(['status' => 'disabled'])->save();

        // Der Rückweg ist versperrt — dann bleibt nur das Abmelden, und zwar
        // sofort. In fremder Sicht weiterzuarbeiten wäre der schlechtere
        // Zustand.
        $this->post('/impersonation/stop')->assertRedirect('/login');
        $this->assertGuest();
    }
}
