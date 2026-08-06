<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kunden bearbeiten — und die drei Felder, die dabei nicht mitgehen dürfen.
 *
 * Die **Kundennummer** ist der Bezeichner in Rechnungen; sie zu ändern hiesse,
 * zwei Belege desselben Vorgangs unter zwei Nummern zu führen. Der **Zustand**
 * hängt an Abonnements und Anmeldung und bekommt eine eigene Aktion. Die
 * **Anmeldeadresse** gehört dem Konto und nicht dem Vertragspartner — ein Kunde
 * kann mehrere Konten haben, und welches hier gemeint wäre, ist nicht zu
 * erraten.
 */
final class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Erika',
            'last_name' => 'Musterfrau',
            'email' => 'erika@example.test',
            'phone' => '030 123456',
            'street' => 'Hauptstrasse 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'de',
            'notes' => 'Zahlt per Rechnung.',
        ], $overrides);
    }

    public function test_the_form_shows_the_stored_data(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'Erika', 'city' => 'Berlin']);

        $this->actingAs($this->admin())
            ->get("/customers/{$customer->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Edit')
                ->where('customer.first_name', 'Erika')
                ->where('customer.city', 'Berlin'));
    }

    public function test_the_data_is_saved(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload())
            ->assertRedirect("/customers/{$customer->id}");

        $customer->refresh();

        $this->assertSame('Erika', $customer->first_name);
        $this->assertSame('Berlin', $customer->city);

        // Kleingeschrieben eingegeben, gross abgelegt: Sonst stünden „de" und
        // „DE" nebeneinander, und die erste Rechnungsvorlage müsste raten.
        $this->assertSame('DE', $customer->country);
    }

    public function test_the_customer_number_does_not_change(): void
    {
        $customer = Customer::factory()->create(['number' => 'K10001']);

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload(['number' => 'K99999']));

        $this->assertSame('K10001', $customer->refresh()->number);
    }

    public function test_the_status_does_not_change(): void
    {
        $customer = Customer::factory()->create();
        $before = $customer->status;

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload(['status' => 'suspended']));

        $this->assertSame($before, $customer->refresh()->status);
    }

    public function test_a_login_address_in_the_form_does_not_touch_an_account(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create(['email' => 'konto@example.test']);

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload(['login_email' => 'fremd@example.test']));

        $this->assertSame('konto@example.test', $account->refresh()->email);
    }

    public function test_an_impossible_country_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload(['country' => 'Deutschland']))
            ->assertSessionHasErrors('country');
    }

    public function test_an_empty_country_stays_empty(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}", $this->payload(['country' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($customer->refresh()->country);
    }

    public function test_the_record_names_the_changed_fields_and_not_their_content(): void
    {
        $customer = Customer::factory()->create(['city' => 'Hamburg']);

        $this->actingAs($this->admin())->patch("/customers/{$customer->id}", $this->payload());

        $event = AuditEvent::query()->where('action', 'customer.updated')->firstOrFail();
        $context = $event->context ?? [];

        $this->assertContains('city', $context['changed'] ?? []);

        // Eine Anschrift gehört in den Datensatz und nicht zusätzlich in jeden
        // Protokolleintrag, der sie je berührt hat.
        $this->assertStringNotContainsString('Hauptstrasse', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_a_customer_cannot_edit_anyone(): void
    {
        $mine = Customer::factory()->create();
        $account = Account::factory()->customer($mine)->create();

        // Auch nicht sich selbst: Die Stammdaten sind der Vertrag, und den
        // ändert der Betreiber.
        $this->actingAs($account)->get("/customers/{$mine->id}/edit")->assertForbidden();
        $this->actingAs($account)->patch("/customers/{$mine->id}", $this->payload())->assertForbidden();
    }

    public function test_a_customer_with_running_subscriptions_is_not_withdrawn(): void
    {
        /*
         * Der bequeme Weg wäre, die Abonnements mit zurückzubauen. Dann wäre
         * dieser Knopf einer, der als Nebenwirkung Verzeichnisbäume als root
         * löscht — und die Rückfrage davor spräche von einem Kunden. Dieselbe
         * Regel wie beim Plan mit gebundenen Abonnements.
         */
        $customer = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin())
            ->delete("/customers/{$customer->id}")
            ->assertSessionHasErrors('customer');

        $this->assertNull($customer->refresh()->deleted_at);

        $event = AuditEvent::query()->where('action', 'customer.withdrawn')->firstOrFail();
        $this->assertSame('denied', $event->result->value);
        $this->assertSame(1, ($event->context ?? [])['subscriptions'] ?? null);
    }

    public function test_a_withdrawn_subscription_no_longer_blocks(): void
    {
        // Ein zurückgebautes Abonnement trägt `deleted_at`. Zählte es mit,
        // liesse sich ein Kunde, der einmal ein Abonnement hatte, nie wieder
        // zurückziehen.
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $subscription->delete();

        $this->actingAs($this->admin())
            ->delete("/customers/{$customer->id}")
            ->assertRedirect('/customers');

        $this->assertNotNull($customer->refresh()->deleted_at);
    }

    public function test_the_number_stays_taken_after_a_withdrawal(): void
    {
        $customer = Customer::factory()->create(['number' => 'K10001']);

        $this->actingAs($this->admin())->delete("/customers/{$customer->id}");

        // **Der Kern der ganzen Sache.** Ein echtes DELETE gäbe die Nummer
        // frei, und der nächste Kunde bekäme sie — danach trügen zwei
        // Vertragspartner in zwei Rechnungen dieselbe.
        $this->actingAs($this->admin())->post('/customers', $this->payload([
            'email' => 'zweite@example.test',
            'login_email' => 'zweite@example.test',
            'password' => 'Ein-langes-Passwort9',
            'password_confirmation' => 'Ein-langes-Passwort9',
        ]));

        $this->assertSame('K10002', Customer::query()->orderByDesc('id')->firstOrFail()->number);
    }

    /**
     * Die Nummer bleibt vergeben, die Anmeldeadresse wird frei.
     *
     * **Der Fall aus dem Betrieb, und er hat einen halben Tag gekostet.** Ein
     * Kunde wurde zurückgezogen; beim Anlegen des nächsten mit derselben
     * Anmeldeadresse passierte scheinbar nichts. `accounts.email` trägt einen
     * Unique-Index, und der galt weiter für ein Konto, das sich nie wieder
     * anmelden kann.
     *
     * **Die beiden Bezeichner sind verschieden zu behandeln.** Die
     * Kundennummer steht in Rechnungen — zwei Vertragspartner mit derselben
     * wären ein Buchhaltungsproblem, sie bleibt gesperrt. Die Adresse gehört
     * einem Menschen, und wer einen Kunden zurückzieht und neu anlegt, hat
     * denselben vor sich.
     */
    public function test_the_address_is_free_again_after_a_withdrawal(): void
    {
        $customer = Customer::factory()->create(['number' => 'K10001']);

        $account = Account::factory()->create([
            'customer_id' => $customer->id,
            'email' => 'wieder@example.test',
        ]);

        $this->actingAs($this->admin())->delete("/customers/{$customer->id}");

        $this->assertNull($account->refresh()->email, 'Die Adresse belegt die Zeile noch.');

        // Und sie lässt sich wieder vergeben — das ist der Punkt.
        $this->actingAs($this->admin())
            ->post('/customers', $this->payload([
                'email' => 'wieder@example.test',
                'login_email' => 'wieder@example.test',
                'password' => 'Ein-langes-Passwort9',
                'password_confirmation' => 'Ein-langes-Passwort9',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'wieder@example.test',
            Account::query()->whereNotNull('email')->orderByDesc('id')->firstOrFail()->email,
        );
    }

    /**
     * Und was sie war, steht im Prüfprotokoll.
     *
     * Ohne diese Zeile wäre die Adresse weg, ohne dass irgendwo stünde, welche
     * es war — ein Protokoll, das die Änderung verschweigt, die es begleitet.
     */
    public function test_the_released_address_is_recorded(): void
    {
        $customer = Customer::factory()->create(['number' => 'K10001']);

        Account::factory()->create([
            'customer_id' => $customer->id,
            'email' => 'notiert@example.test',
        ]);

        $this->actingAs($this->admin())->delete("/customers/{$customer->id}");

        $event = AuditEvent::query()->where('action', 'customer.withdrawn')->firstOrFail();

        $this->assertSame(['notiert@example.test'], $event->context['released_addresses'] ?? null);
    }

    public function test_a_withdrawn_customer_is_gone_from_the_list(): void
    {
        $customer = Customer::factory()->create(['number' => 'K10001']);

        $this->actingAs($this->admin())->delete("/customers/{$customer->id}");

        // Zurückgezogen heisst nicht unsichtbar in der Datenbank, aber sehr
        // wohl unsichtbar in der Liste: Wer dort steht, ist Vertragspartner.
        $this->actingAs($this->admin())
            ->get('/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 0)
                ->where('customers.total', 0));
    }

    public function test_a_customer_may_not_withdraw_anyone(): void
    {
        $mine = Customer::factory()->create();
        $account = Account::factory()->customer($mine)->create();

        $this->actingAs($account)->delete("/customers/{$mine->id}")->assertForbidden();

        $this->assertNull($mine->refresh()->deleted_at);
    }
}
