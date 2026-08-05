<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Abonnements — und die drei Stellen, an denen das Panel etwas behauptet, das
 * auf dem Server nicht gilt.
 *
 * Erstens: ein Zustand, der gesetzt wird, bevor der Agent geantwortet hat.
 * Zweitens: ein Wert aus dem Formular, der bis zum Agenten durchreicht.
 * Drittens: ein Systembenutzer, der ein zweites Mal vergeben wird — und damit
 * ein Kunde, der die Dateien seines Vorgängers erbt.
 */
final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function plan(): Plan
    {
        return Plan::factory()->default()->create(['name' => 'Standard']);
    }

    /** @return array<string, mixed> */
    private function payload(Customer $customer, Plan $plan, string $name = 'kunde-example.de'): array
    {
        return ['customer_id' => $customer->id, 'plan_id' => $plan->id, 'name' => $name];
    }

    public function test_creating_queues_an_operation_and_marks_it_as_provisioning(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $plan = $this->plan();

        $this->actingAs($this->admin())
            ->post('/subscriptions', $this->payload($customer, $plan))
            ->assertRedirect();

        $subscription = Subscription::query()->firstOrFail();

        // **Nicht „aktiv".** Auf dem System gibt es weder Benutzer noch
        // Verzeichnis; „aktiv" wäre eine Behauptung über etwas, das erst der
        // Vorgang herstellt.
        $this->assertSame(SubscriptionStatus::Provisioning, $subscription->status);
        $this->assertSame('p1000', $subscription->system_user);

        $operation = Operation::query()->firstOrFail();
        $this->assertSame('subscription.provision', $operation->type);
        $this->assertSame((int) $subscription->id, (int) $operation->subscription_id);

        Queue::assertPushed(RunAgentOperation::class);
    }

    public function test_the_payload_comes_from_the_stored_row(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $plan = $this->plan();

        $this->actingAs($this->admin())->post('/subscriptions', $this->payload($customer, $plan));

        $operation = Operation::query()->firstOrFail();
        $payload = $operation->payload ?? [];

        $this->assertSame('kunde-example.de', $payload['name'] ?? null);
        $this->assertSame('p1000', $payload['user'] ?? null);

        // Der Speicher kommt aus dem Plan und nicht aus dem Formular.
        $this->assertSame(Quota::DiskMb->default(), $payload['quota_mb'] ?? null);
    }

    public function test_a_name_the_agent_would_refuse_does_not_get_through(): void
    {
        // Dieselbe Regel wie im Agenten, und zwar dieselbe Funktion. Käme ein
        // Name hier durch und dort nicht, bliebe das Abonnement für immer im
        // Zustand „wird angelegt".
        $customer = Customer::factory()->create();
        $plan = $this->plan();

        foreach (['../etc', 'GROSS.de', 'mit leerzeichen', '-anfang.de', 'a..b'] as $name) {
            $this->actingAs($this->admin())
                ->post('/subscriptions', $this->payload($customer, $plan, $name))
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_the_name_stays_unique(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan();
        Subscription::factory()->for($customer)->for($plan)->create(['name' => 'belegt.de']);

        $this->actingAs($this->admin())
            ->post('/subscriptions', $this->payload($customer, $plan, 'belegt.de'))
            ->assertSessionHasErrors('name');
    }

    public function test_a_customer_cannot_create_one(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)
            ->post('/subscriptions', $this->payload($customer, $this->plan()))
            ->assertForbidden();
    }

    public function test_the_system_user_counts_up_and_stays_used(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan();
        $lifecycle = app(Lifecycle::class);

        $first = Subscription::factory()->for($customer)->for($plan)->create(['system_user' => 'p1000']);
        $this->assertSame('p1001', $lifecycle->nextSystemUser());

        // **Der Kern.** Nach dem Rückbau bleibt die Zeile zurückgezogen
        // stehen; ihr Name bleibt damit verbraucht. Ohne das bekäme das
        // nächste Abonnement `p1000` und damit alles, was auf dem Dateisystem
        // noch dieser UID gehört.
        $first->delete();

        $this->assertSame('p1001', $lifecycle->nextSystemUser());

        // Die Zeile ist für jede gewöhnliche Abfrage weg und für die Vergabe da.
        $tenancy = app(Tenancy::class);
        $this->assertSame(0, $tenancy->withoutRestriction(fn (): int => Subscription::query()->count()));
        $this->assertSame(1, $tenancy->withoutRestriction(fn (): int => Subscription::query()->withTrashed()->count()));
    }

    public function test_suspending_changes_nothing_before_the_agent_answered(): void
    {
        Queue::fake();

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

        $this->actingAs($this->admin())
            ->post("/subscriptions/{$subscription->id}/suspend")
            ->assertRedirect();

        // Der Vorgang steht in der Warteschlange — der Zustand steht noch auf
        // aktiv. Alles andere wäre eine Aussage über einen Server, der noch
        // nicht gefragt wurde.
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame('subscription.suspend', Operation::query()->firstOrFail()->type);
    }

    public function test_the_state_follows_the_successful_operation(): void
    {
        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.suspend',
            'task' => 'subscription.suspend',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(Lifecycle::class)->afterSuccess($operation);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertNotNull($subscription->suspended_at);
    }

    public function test_resuming_takes_the_suspension_back_completely(): void
    {
        $subscription = Subscription::factory()->suspended()->create(['suspended_at' => now()]);

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.resume',
            'task' => 'subscription.resume',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(Lifecycle::class)->afterSuccess($operation);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->suspended_at);
    }

    public function test_the_removal_withdraws_the_row(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.remove',
            'task' => 'subscription.remove',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(Lifecycle::class)->afterSuccess($operation);

        $tenancy = app(Tenancy::class);

        $this->assertNull($tenancy->withoutRestriction(fn () => Subscription::query()->find($subscription->id)));

        $withdrawn = $tenancy->withoutRestriction(
            fn (): Subscription => Subscription::query()->withTrashed()->findOrFail($subscription->id),
        );
        $this->assertSame(SubscriptionStatus::Cancelled, $withdrawn->status);
        $this->assertNotNull($withdrawn->cancelled_at);
    }

    public function test_a_foreign_operation_changes_nothing(): void
    {
        // `afterSuccess` läuft nach jedem Vorgang. Einer, der nichts mit
        // Abonnements zu tun hat, darf keinen Zustand anfassen.
        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'service.status',
            'task' => 'agent.status',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(Lifecycle::class)->afterSuccess($operation);

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    public function test_a_subscription_being_created_cannot_be_suspended(): void
    {
        Queue::fake();

        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Provisioning]);

        $this->actingAs($this->admin())
            ->post("/subscriptions/{$subscription->id}/suspend")
            ->assertSessionHasErrors('subscription');

        Queue::assertNothingPushed();
    }

    public function test_a_customer_sees_only_the_own_subscriptions(): void
    {
        $mine = Customer::factory()->create();
        $other = Customer::factory()->create();

        $own = Subscription::factory()->for($mine)->create(['name' => 'meins.de']);
        Subscription::factory()->for($other)->create(['name' => 'fremd.de']);

        $account = Account::factory()->customer($mine)->create();

        $this->actingAs($account)
            ->get('/subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Index')
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.name', 'meins.de'));

        $this->assertNotNull($own->id);
    }

    public function test_a_customer_does_not_reach_a_foreign_subscription(): void
    {
        $mine = Customer::factory()->create();
        $other = Customer::factory()->create();
        $foreign = Subscription::factory()->for($other)->create();

        $account = Account::factory()->customer($mine)->create();

        // Die Mandantenklammer greift vor der Policy: Das Objekt ist für
        // dieses Konto nicht auffindbar, deshalb 404 und nicht 403 — aus 403
        // liesse sich ablesen, welche Kennungen es gibt.
        $this->actingAs($account)->get("/subscriptions/{$foreign->id}")->assertNotFound();
    }

    public function test_a_customer_cannot_suspend(): void
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->for($customer)->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->post("/subscriptions/{$subscription->id}/suspend")->assertForbidden();
        $this->actingAs($account)->delete("/subscriptions/{$subscription->id}")->assertForbidden();
    }
}
