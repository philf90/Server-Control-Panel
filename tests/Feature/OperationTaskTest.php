<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Der Weg vom Knopf bis in die Warteschlange.
 *
 * Der Agent selbst hat eigene Tests gegen einen echten Socket
 * (tests/Feature/AgentProtocolTest.php). Hier geht es um die Strecke davor:
 * Wer darf auslösen, was genau wird ausgelöst — und vor allem, was ein
 * Browser dabei *nicht* bestimmen kann.
 */
final class OperationTaskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function customer(): Account
    {
        $customer = Customer::factory()->create();
        Subscription::factory()->create(['customer_id' => $customer->id]);

        return Account::factory()->customer($customer)->create();
    }

    public function test_the_operator_sees_the_catalogue(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->get('/operations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Operations/Index')
                ->has('tasks', count(Task::cases()))
            );
    }

    public function test_a_customer_sees_an_empty_catalogue_and_cannot_start(): void
    {
        $account = $this->customer();

        // Die Liste bleibt erreichbar — was darauf steht, entscheidet die
        // Mandantenklammer. Der Katalog dagegen ist leer, weil es in P1 keine
        // Aufgabe gibt, die einem einzelnen Kunden gehörte.
        $this->actingAs($account)->get('/operations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tasks', 0));

        $this->actingAs($account)
            ->post('/operations', ['task' => Task::WebserverReload->value])
            ->assertForbidden();

        $this->assertSame(0, Operation::query()->count());
    }

    public function test_starting_a_task_queues_it_with_the_arguments_from_the_catalogue(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/operations', ['task' => Task::WebserverReload->value])
            ->assertRedirect();

        $operation = Operation::query()->sole();

        $this->assertSame('service.action', $operation->type);
        $this->assertSame('webserver.reload', $operation->task);
        $this->assertSame(['unit' => 'nginx.service', 'action' => 'reload'], $operation->payload);

        // Betreibervorgänge tragen kein Abonnement — sonst wären sie für einen
        // Kunden sichtbar.
        $this->assertNull($operation->subscription_id);
        $this->assertSame((int) $admin->id, (int) $operation->account_id);

        Queue::assertPushed(RunAgentOperation::class);
    }

    public function test_the_browser_cannot_choose_what_the_agent_is_told(): void
    {
        Queue::fake();

        // Der Kern der Sache. Wer diese Anfrage von Hand stellt, hängt an, was
        // er will — hier eine fremde Unit und eine andere Aktion. Landete
        // davon irgendetwas in der Nutzlast, wäre das Panel eine Fernsteuerung
        // für beliebige systemd-Units, und die Positivliste im Agenten die
        // einzige verbliebene Schranke.
        $this->actingAs($this->admin())->post('/operations', [
            'task' => Task::WebserverReload->value,
            'unit' => 'ssh.service',
            'action' => 'stop',
            'payload' => ['unit' => 'ssh.service', 'action' => 'stop'],
            'type' => 'service.action',
        ])->assertRedirect();

        $operation = Operation::query()->sole();

        $this->assertSame(['unit' => 'nginx.service', 'action' => 'reload'], $operation->payload);
    }

    public function test_an_unknown_task_is_refused_and_recorded(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post('/operations', ['task' => 'service.action'])
            ->assertSessionHasErrors('task');

        $this->assertSame(0, Operation::query()->count());
        Queue::assertNothingPushed();

        // „service.action" ist eine echte Operation des Agenten und trotzdem
        // kein Schlüssel des Katalogs. Genau diese Verwechslung soll die
        // Aufzählung ausschließen.
        $event = AuditEvent::query()->where('action', 'operation.started')->sole();
        $this->assertSame(AuditResult::Denied, $event->result);
    }

    public function test_every_task_names_an_operation_the_agent_knows(): void
    {
        // Ein Katalogeintrag mit einem Tippfehler in der Operation fiele sonst
        // erst auf, wenn jemand darauf drückt — und dann als Fehlschlag eines
        // Vorgangs statt als Fehler im Panel.
        $known = ['agent.ping', 'service.status', 'service.action', 'config.validate', 'system.info'];

        foreach (Task::cases() as $task) {
            $this->assertContains($task->operation(), $known, $task->value);
        }
    }

    public function test_a_customer_does_not_see_an_operator_operation(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post('/operations', ['task' => Task::AgentPing->value]);
        $operation = Operation::query()->sole();

        // Nicht „verboten", sondern „nicht gefunden": Die Modellbindung läuft
        // unter der Mandantenklammer, der Vorgang existiert für dieses Konto
        // gar nicht. Ein 403 verriete, dass es ihn gibt.
        $this->actingAs($this->customer())
            ->get("/operations/{$operation->id}")
            ->assertNotFound();
    }

    public function test_the_detail_page_shows_arguments_and_output(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $this->actingAs($admin)->post('/operations', ['task' => Task::AgentStatus->value]);

        $operation = Operation::query()->sole();
        $operation->forceFill(['output' => "systemctl show\n"])->save();

        $this->actingAs($admin)->get("/operations/{$operation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Operations/Show')
                ->where('operation.label', 'Status srvpanel-agentd')
                ->where('operation.payload.unit', 'srvpanel-agentd.service')
                ->where('operation.output', "systemctl show\n")
            );
    }

    public function test_an_operation_from_a_task_that_no_longer_exists_still_lists(): void
    {
        $admin = $this->admin();

        Operation::query()->create([
            'account_id' => $admin->id,
            'type' => 'service.status',
            'task' => 'gibt.es.nicht.mehr',
            'payload' => ['unit' => 'nginx.service'],
        ]);

        // Ein Vorgang bleibt stehen, ein Katalogeintrag kann verschwinden. Die
        // Liste muss das aushalten, statt an einem alten Eintrag abzubrechen.
        $this->actingAs($admin)->get('/operations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('operations.data.0.label', 'service.status')
            );
    }
}
