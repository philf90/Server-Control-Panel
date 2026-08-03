<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Einen Kunden sperren heisst: seine Abonnements sperren.
 *
 * **Warum das der ganze Inhalt ist.** Ein Kunde, der „gesperrt" heisst und
 * dessen Webseiten weiterlaufen, ist nicht gesperrt, sondern anders
 * beschriftet — dieselbe Sorte Fehler wie ein Abonnement, dessen Zustand beim
 * Klick gesetzt wird und nicht, wenn der Agent geantwortet hat.
 *
 * **Und die Freigabe ist die schwierigere Hälfte.** „Alle gesperrten wieder
 * an" wäre die naheliegende Umkehrung und wäre falsch: Ein Abonnement, das der
 * Betreiber vorher einzeln gesperrt hat — wegen Missbrauch, wegen eines
 * Umzugs —, war nie Teil der Kundensperre. Am Zustand ist das nicht zu
 * erkennen; „gesperrt" sieht in beiden Fällen gleich aus. Deshalb merkt sich
 * das Abonnement, zu welcher Sperre es gehört.
 */
final class CustomerSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['number' => 'K10001']);
    }

    private function subscription(Customer $customer, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
        ]);
    }

    public function test_suspending_a_customer_queues_one_operation_per_subscription(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $first = $this->subscription($customer);
        $second = $this->subscription($customer);

        $this->actingAs($this->admin())
            ->post("/customers/{$customer->id}/suspend")
            ->assertRedirect("/customers/{$customer->id}");

        $this->assertSame(CustomerStatus::Suspended, $customer->refresh()->status);

        /*
         * Je Abonnement ein Vorgang und nicht einer für alle. Ein Sammelvorgang
         * wäre bequemer und beantwortete die Frage nicht, die man nachher
         * stellt: welches es erwischt hat. „Teilweise erfolgreich" ist keine
         * Auskunft.
         */
        $operations = Operation::query()->where('type', 'subscription.suspend')->get();

        $this->assertCount(2, $operations);
        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $second->id],
            $operations->pluck('subscription_id')->map(static fn ($id): int => (int) $id)->all(),
        );

        Queue::assertPushed(RunAgentOperation::class, 2);
    }

    public function test_the_subscription_state_still_waits_for_the_agent(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        // Der Kundenzustand ist eine Angabe im Panel — für ihn gibt es nichts
        // auszuführen. Ob ein Abonnement wirklich aus ist, entscheidet
        // weiterhin der Agent (docs/26 §2).
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertTrue($subscription->suspended_with_customer);
    }

    public function test_resuming_brings_back_only_what_went_down_with_the_customer(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $withCustomer = $this->subscription($customer);

        // Vorher einzeln gesperrt — aus einem eigenen Grund.
        $onItsOwn = $this->subscription($customer, SubscriptionStatus::Suspended);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        Operation::query()->delete();

        $this->actingAs($this->admin())
            ->post("/customers/{$customer->id}/resume")
            ->assertRedirect("/customers/{$customer->id}");

        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);

        $resumed = Operation::query()->where('type', 'subscription.resume')->get();

        $this->assertCount(1, $resumed);
        $this->assertSame((int) $withCustomer->id, (int) $resumed->first()->subscription_id);

        // Das einzeln gesperrte bleibt gesperrt. Käme es mit der Freigabe
        // zurück, hätte die Kundensperre eine Entscheidung aufgehoben, mit der
        // sie nichts zu tun hatte.
        $this->assertSame(SubscriptionStatus::Suspended, $onItsOwn->refresh()->status);
        $this->assertFalse($onItsOwn->suspended_with_customer);
    }

    public function test_the_mark_is_cleared_after_the_release(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");
        $this->actingAs($this->admin())->post("/customers/{$customer->id}/resume");

        // Bliebe sie stehen, holte die übernächste Freigabe ein Abonnement
        // zurück, das längst aus einem anderen Grund gesperrt ist.
        $this->assertFalse($subscription->refresh()->suspended_with_customer);
    }

    public function test_suspending_a_subscription_on_its_own_clears_the_mark(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");
        $this->actingAs($this->admin())->post("/customers/{$customer->id}/resume");

        // Jetzt einzeln sperren: Die Kennzeichnung von vorhin darf nicht
        // wieder greifen.
        $this->actingAs($this->admin())->post("/subscriptions/{$subscription->id}/suspend");

        $this->assertFalse($subscription->refresh()->suspended_with_customer);
    }

    public function test_a_subscription_being_provisioned_is_left_alone(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer, SubscriptionStatus::Provisioning);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        // Es hat noch keinen Systembenutzer, den man sperren könnte — der
        // Vorgang scheiterte am Agenten. Bekannte Kante, notiert in docs/26.
        $this->assertSame(0, Operation::query()->count());
        $this->assertFalse($subscription->refresh()->suspended_with_customer);
    }

    public function test_a_subscription_cannot_be_resumed_while_the_customer_is_suspended(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        Operation::query()->delete();

        /*
         * Sonst liesse sich die Kundensperre von unten aushebeln: Ein
         * Abonnement käme zurück, während im Panel weiter „Kunde gesperrt"
         * steht — und die Freigabe des Kunden wüsste später nicht mehr, was zu
         * ihr gehört.
         */
        $this->actingAs($this->admin())
            ->post("/subscriptions/{$subscription->id}/resume")
            ->assertSessionHasErrors('subscription');

        $this->assertSame(0, Operation::query()->count());
    }

    public function test_a_customer_without_subscriptions_can_be_suspended(): void
    {
        Queue::fake();

        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post("/customers/{$customer->id}/suspend")
            ->assertSessionHasNoErrors();

        // Null Abonnements sind eine Antwort und kein Fehler.
        $this->assertSame(CustomerStatus::Suspended, $customer->refresh()->status);
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_suspending_twice_is_refused(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        // Ohne diese Schranke liefe die Kaskade ein zweites Mal und
        // kennzeichnete Abonnements, die inzwischen einzeln gesperrt wurden.
        $this->actingAs($this->admin())
            ->post("/customers/{$customer->id}/suspend")
            ->assertSessionHasErrors('customer');
    }

    public function test_the_record_names_the_affected_subscriptions(): void
    {
        Queue::fake();

        $customer = $this->customer();
        $subscription = $this->subscription($customer);

        $this->actingAs($this->admin())->post("/customers/{$customer->id}/suspend");

        $event = AuditEvent::query()->where('action', 'customer.suspended')->firstOrFail();

        // Welche mitgingen, ist die Frage, die man Wochen später an das
        // Protokoll stellt — die Zahl allein beantwortet sie nicht.
        $this->assertSame([$subscription->name], ($event->context ?? [])['subscriptions'] ?? null);
    }

    public function test_a_customer_may_not_suspend_anyone(): void
    {
        $customer = $this->customer();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->post("/customers/{$customer->id}/suspend")->assertForbidden();
        $this->actingAs($account)->post("/customers/{$customer->id}/resume")->assertForbidden();

        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }
}
