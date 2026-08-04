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
use PHPUnit\Framework\Attributes\DataProvider;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;
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

    /**
     * Eine Aufgabe mit Argument — und der Weg, auf dem das Argument kommt.
     *
     * Der Browser schickt „8.2"; was daraus wird, entsteht im Katalog. Zwischen
     * beidem liegt die Prüfung gegen dieselbe Liste, aus der die Oberfläche ihr
     * Auswahlfeld baut.
     */
    public function test_a_task_with_an_argument_carries_it_into_the_payload(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post('/operations', ['task' => Task::PhpVersionInstall->value, 'argument' => '8.2'])
            ->assertRedirect();

        $operation = Operation::query()->sole();

        $this->assertSame('php.version.install', $operation->type);
        $this->assertSame(['php_version' => '8.2'], $operation->payload);

        // Die Beschriftung nennt das Argument mit: „PHP-Version installieren"
        // allein sagt in der Liste nicht, welche.
        $this->assertStringContainsString('8.2', (string) $operation->message);
    }

    /** @return list<array{0: array<string, string>}> */
    public static function badArguments(): array
    {
        return [
            [['task' => 'php.version.install']],
            [['task' => 'php.version.install', 'argument' => '']],
            [['task' => 'php.version.install', 'argument' => '8.0']],
            [['task' => 'php.version.install', 'argument' => '5.6']],
            [['task' => 'php.version.install', 'argument' => 'fpm; reboot']],
            [['task' => 'php.version.install', 'argument' => '../../etc']],
            [['task' => 'php.version.remove', 'argument' => 'alle']],
        ];
    }

    /**
     * Ein Argument, das nicht im Katalog steht, ist kein Tippfehler.
     *
     * Es würde bei `php.version.install` zu einem Paketnamen für `apt-get`.
     * Deshalb dieselbe Antwort wie bei einem unbekannten Schlüssel — und ein
     * Eintrag im Protokoll.
     *
     * @param  array<string, string>  $payload
     */
    #[DataProvider('badArguments')]
    public function test_an_argument_outside_the_catalog_is_refused(array $payload): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post('/operations', $payload)
            ->assertSessionHasErrors('task');

        $this->assertSame(0, Operation::query()->count());
        Queue::assertNothingPushed();

        $this->assertSame(
            AuditResult::Denied,
            AuditEvent::query()->where('action', 'operation.started')->sole()->result,
        );
    }

    /** Ein Kunde kommt an keine der PHP-Aufgaben — sie sind Betreiberhandlungen. */
    public function test_a_customer_cannot_install_a_php_version(): void
    {
        Queue::fake();

        $customer = $this->customer();

        // Nicht erst am Katalog, sondern schon an der Policy der Route: Ein
        // Kunde darf überhaupt keinen Betreibervorgang auslösen.
        $this->actingAs($customer)
            ->post('/operations', ['task' => Task::PhpVersionInstall->value, 'argument' => '8.4'])
            ->assertForbidden();

        $this->assertSame(0, Operation::query()->count());

        // Und im Katalog steht sie für ihn gar nicht erst.
        $this->assertSame([], Task::for($customer));
    }

    public function test_every_task_names_an_operation_the_agent_knows(): void
    {
        // Ein Katalogeintrag mit einem Tippfehler in der Operation fiele sonst
        // erst auf, wenn jemand darauf drückt — und dann als Fehlschlag eines
        // Vorgangs statt als Fehler im Panel.
        //
        // **Gefragt wird die Registratur des Agenten, nicht eine Abschrift.**
        // Hier stand eine Liste von fünf Namen, und sie war beim Hinzunehmen
        // der P3-Aufgaben genau so falsch, wie eine abgeschriebene Liste es
        // wird: Sie kannte `webserver.detect` nicht, obwohl der Agent sie
        // kennt. Der Test hätte einen Fehler gemeldet, den es nicht gibt —
        // und beim nächsten Mal hätte jemand die Liste erweitert, statt zu
        // prüfen.
        $known = (new Registry(new Config))->names();

        $this->assertGreaterThan(10, count($known), 'Die Registratur ist leer — dann prüft dieser Test nichts.');

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
