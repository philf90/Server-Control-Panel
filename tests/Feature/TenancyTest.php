<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Mandantenklammer — erste der vier Schichten aus §6.2.
 *
 * Die Abnahmebedingung von P1 lautet: „kein Kunde kommt durch Manipulation von
 * IDs an fremde Objekte". Diese Tests prüfen die Schicht, die das auch dann
 * trägt, wenn jemand eine `where`-Bedingung vergisst — also den Fall, den eine
 * sorgfältig geschriebene Abfrage gar nicht erst erzeugt.
 */
final class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    public function test_without_a_tenant_nothing_is_visible(): void
    {
        $subscription = Subscription::factory()->create();
        Operation::factory()->for($subscription)->create();

        // Grundzustand: niemand hat einen Mandanten gesetzt.
        $this->assertSame(0, Operation::query()->count());
        $this->assertNull(Operation::query()->first());
    }

    public function test_a_tenant_sees_only_its_own(): void
    {
        $mine = Subscription::factory()->create();
        $foreign = Subscription::factory()->create();

        Operation::factory()->for($mine)->count(2)->create();
        Operation::factory()->for($foreign)->count(3)->create();

        $this->tenancy()->restrictTo([(int) $mine->id]);

        $this->assertSame(2, Operation::query()->count());

        // Kein Datensatz zeigt auf ein fremdes Abonnement.
        $this->assertSame(
            0,
            Operation::query()->where('subscription_id', '!=', $mine->id)->count(),
        );
    }

    public function test_a_known_foreign_id_is_still_not_found(): void
    {
        $mine = Subscription::factory()->create();
        $foreign = Subscription::factory()->create();
        $secret = Operation::factory()->for($foreign)->create();

        $this->tenancy()->restrictTo([(int) $mine->id]);

        // Genau der Angriff aus der Abnahmebedingung: Die ID ist bekannt, das
        // Objekt existiert — und bleibt trotzdem unerreichbar.
        $this->assertNull(Operation::query()->find($secret->id));
        $this->assertSame(0, Operation::query()->whereKey($secret->id)->count());
    }

    public function test_the_admin_is_unrestricted(): void
    {
        Operation::factory()->for(Subscription::factory())->count(2)->create();
        Operation::factory()->for(Subscription::factory())->count(3)->create();

        $this->tenancy()->allowAll();

        $this->assertSame(5, Operation::query()->count());
    }

    public function test_operations_of_the_operator_are_invisible_to_customers(): void
    {
        $mine = Subscription::factory()->create();
        Operation::factory()->for($mine)->create();

        // Ein Vorgang ohne Abonnement: Paketinstallation, Dienstneustart.
        Operation::factory()->create(['subscription_id' => null]);

        $this->tenancy()->restrictTo([(int) $mine->id]);
        $this->assertSame(1, Operation::query()->count());

        $this->tenancy()->allowAll();
        $this->assertSame(2, Operation::query()->count());
    }

    public function test_a_new_record_inherits_the_only_active_tenant(): void
    {
        $subscription = Subscription::factory()->create();
        $this->tenancy()->restrictTo([(int) $subscription->id]);

        $operation = Operation::query()->create(['type' => 'probe']);

        $this->assertSame((int) $subscription->id, (int) $operation->subscription_id);
    }

    public function test_with_several_tenants_nothing_is_guessed(): void
    {
        $first = Subscription::factory()->create();
        $second = Subscription::factory()->create();
        $this->tenancy()->restrictTo([(int) $first->id, (int) $second->id]);

        $operation = Operation::query()->create(['type' => 'probe']);

        // Raten wäre schlimmer als leer lassen: Der Aufrufer muss sich
        // entscheiden, und ein fehlendes Abonnement fällt sofort auf.
        $this->assertNull($operation->subscription_id);
    }

    public function test_the_escape_hatch_restores_the_previous_state_even_on_error(): void
    {
        $subscription = Subscription::factory()->create();
        $this->tenancy()->restrictTo([(int) $subscription->id]);

        try {
            $this->tenancy()->withoutRestriction(static function (): never {
                throw new \RuntimeException('mittendrin');
            });
        } catch (\RuntimeException) {
            // erwartet
        }

        // Ohne das `finally` stünde die Klammer jetzt für den Rest des
        // Prozesses offen — und der nächste Zugriff sähe alles.
        $this->assertFalse($this->tenancy()->unrestricted());
        $this->assertSame([(int) $subscription->id], $this->tenancy()->subscriptionIds());
    }

    public function test_a_customer_account_reaches_exactly_its_own_subscriptions(): void
    {
        $customer = Customer::factory()->create();
        $own = Subscription::factory()->count(2)->create(['customer_id' => $customer->id]);
        Subscription::factory()->count(3)->create();

        $account = Account::factory()->customer($customer)->create();

        $this->assertEqualsCanonicalizing(
            $own->pluck('id')->map(intval(...))->all(),
            $account->accessibleSubscriptionIds(),
        );
    }

    public function test_an_additional_account_reaches_only_what_it_was_assigned(): void
    {
        $customer = Customer::factory()->create();
        $assigned = Subscription::factory()->create(['customer_id' => $customer->id]);
        $notAssigned = Subscription::factory()->create(['customer_id' => $customer->id]);

        $account = Account::factory()->additional($customer)->create();
        $account->assignedSubscriptions()->attach($assigned->id, [
            'permissions' => json_encode(['files_read' => true]),
        ]);

        // Derselbe Kunde, aber nicht zugewiesen: bleibt draußen.
        $this->assertSame([(int) $assigned->id], $account->accessibleSubscriptionIds());
        $this->assertNotContains((int) $notAssigned->id, $account->accessibleSubscriptionIds());
    }

    public function test_the_chain_carries_subcustomers(): void
    {
        $reseller = Customer::factory()->create();
        $subCustomer = Customer::factory()->create(['parent_customer_id' => $reseller->id]);

        $own = Subscription::factory()->create(['customer_id' => $reseller->id]);
        $below = Subscription::factory()->create(['customer_id' => $subCustomer->id]);

        $account = Account::factory()->customer($reseller)->create();

        // In der 1.0 bleibt parent_customer_id leer. Dass die Kette trotzdem
        // schon trägt, ist der Unterschied zwischen „später erweiterbar" und
        // „später Umbau" (§5.4).
        $this->assertEqualsCanonicalizing(
            [(int) $own->id, (int) $below->id],
            $account->accessibleSubscriptionIds(),
        );
    }

    public function test_a_cycle_in_the_chain_does_not_hang(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create(['parent_customer_id' => $first->id]);
        $first->update(['parent_customer_id' => $second->id]);

        // Ein Kreis ist im Schema nicht ausgeschlossen; ohne Tiefenbegrenzung
        // liefe die Auflösung endlos.
        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $second->id],
            $first->descendantIdsIncludingSelf(),
        );
    }

    public function test_the_admin_account_reaches_everything(): void
    {
        $subscriptions = Subscription::factory()->count(3)->create();
        $admin = Account::factory()->admin()->create();

        $this->assertEqualsCanonicalizing(
            $subscriptions->pluck('id')->map(intval(...))->all(),
            $admin->accessibleSubscriptionIds(),
        );
    }
}
