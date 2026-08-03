<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\Task;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SrvPanel\Agent\Client;
use Tests\TestCase;

/**
 * Einen Vorgang abbrechen.
 *
 * Zwei Fälle, die sich grundsätzlich unterscheiden:
 *
 * 1. **Er wartet noch.** Der Abbruch ist vollständig und sofort — es gibt
 *    nichts zu beenden, weil noch nichts läuft.
 * 2. **Er läuft.** Dann ist der Knopf eine Bitte. Der Arbeiter läuft in einem
 *    anderen Prozess, das Programm im Agenten in einem dritten. Deshalb setzt
 *    die Anfrage den Zustand *nicht* auf „abgebrochen": Das stünde in der
 *    Datenbank, während das Programm weiterläuft.
 *
 * Dass der Abbruch am Ende auch wirkt, prüft
 * tests/Feature/AgentCancelTest.php gegen einen echten Agenten.
 */
final class OperationCancelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function queued(Account $account): Operation
    {
        return Operation::query()->create([
            'account_id' => $account->id,
            'type' => Task::WebserverReload->operation(),
            'task' => Task::WebserverReload->value,
            'payload' => Task::WebserverReload->payload(),
            'status' => OperationStatus::Queued,
        ]);
    }

    public function test_the_request_is_recorded_but_the_state_is_not_yet_cancelled(): void
    {
        $admin = $this->admin();
        $operation = $this->queued($admin);

        $this->actingAs($admin)->post("/operations/{$operation->id}/cancel")
            ->assertRedirect("/operations/{$operation->id}");

        $operation->refresh();

        $this->assertNotNull($operation->cancel_requested_at);
        $this->assertSame((int) $admin->id, (int) $operation->cancelled_by);

        // Der entscheidende Punkt: noch nicht „abgebrochen". Wer den Zustand
        // hier setzte, schriebe eine Behauptung über einen anderen Prozess.
        $this->assertSame(OperationStatus::Queued, $operation->status);

        $this->assertNotNull(AuditEvent::query()->where('action', 'operation.cancelled')->first());
    }

    public function test_a_waiting_operation_never_reaches_the_agent(): void
    {
        $admin = $this->admin();
        $operation = $this->queued($admin);
        $operation->forceFill(['cancel_requested_at' => now()])->save();

        // Ein Client auf einen Socket, den es nicht gibt. Würde der Agent
        // trotzdem angesprochen, endete der Vorgang als „fehlgeschlagen" —
        // dass er stattdessen „abgebrochen" ist, ist der Beleg, dass der
        // Aufruf nie stattgefunden hat.
        $client = new Client('/tmp/es-gibt-diesen-socket-nicht.sock');

        (new RunAgentOperation((int) $operation->id))->handle($client, app(Tenancy::class));

        $operation->refresh();

        $this->assertSame(OperationStatus::Cancelled, $operation->status);
        $this->assertNotNull($operation->finished_at);
    }

    public function test_a_finished_operation_cannot_be_cancelled(): void
    {
        $admin = $this->admin();
        $operation = $this->queued($admin);
        $operation->forceFill(['status' => OperationStatus::Succeeded])->save();

        // Die Policy lässt einen abgeschlossenen Vorgang gar nicht erst durch.
        $this->actingAs($admin)->post("/operations/{$operation->id}/cancel")
            ->assertForbidden();

        $operation->refresh();
        $this->assertNull($operation->cancel_requested_at);
    }

    public function test_a_second_request_does_not_overwrite_the_first(): void
    {
        $admin = $this->admin();
        $operation = $this->queued($admin);

        $this->actingAs($admin)->post("/operations/{$operation->id}/cancel");
        $operation->refresh();
        $first = $operation->cancel_requested_at;

        $this->travel(5)->seconds();
        $this->actingAs($admin)->post("/operations/{$operation->id}/cancel");
        $operation->refresh();

        // Wer zweimal drückt, verschiebt den Zeitpunkt nicht. Sonst stünde im
        // Protokoll ein späterer Wunsch als der, der tatsächlich zählte.
        $this->assertEquals($first, $operation->cancel_requested_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'operation.cancelled')->count());
    }

    public function test_a_customer_cannot_cancel_a_foreign_operation(): void
    {
        Queue::fake();

        $operation = $this->queued($this->admin());

        $customer = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $customer->id]);
        $account = Account::factory()->customer($customer)->create();

        // Nicht gefunden, nicht verboten: Die Modellbindung läuft unter der
        // Mandantenklammer.
        $this->actingAs($account)->post("/operations/{$operation->id}/cancel")
            ->assertNotFound();

        $operation->refresh();
        $this->assertNull($operation->cancel_requested_at);
    }
}
