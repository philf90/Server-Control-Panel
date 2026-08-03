<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
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
}
