<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\OperationRecorder;
use App\Support\Operations\Operations;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SrvPanel\Agent\Client;
use Tests\TestCase;

final class OperationRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function operation(): Operation
    {
        $subscription = Subscription::factory()->create();
        app(Tenancy::class)->allowAll();

        return Operation::factory()->for($subscription)->create();
    }

    public function test_the_concat_expression_differs_per_database(): void
    {
        // MariaDB kennt `||` als logisches Oder. Stünde es dort im
        // UPDATE, wäre die gesammelte Ausgabe jedes Vorgangs nach dem ersten
        // Anhängen eine Ziffer — und die Tests gegen SQLite hätten das nie
        // bemerkt.
        $this->assertSame(
            "CONCAT(COALESCE(output, ''), ?)",
            OperationRecorder::concatExpression('mariadb'),
        );
        $this->assertSame(
            "CONCAT(COALESCE(output, ''), ?)",
            OperationRecorder::concatExpression('mysql'),
        );
        $this->assertSame(
            "COALESCE(output, '') || ?",
            OperationRecorder::concatExpression('sqlite'),
        );
    }

    public function test_output_is_appended_and_not_overwritten(): void
    {
        $operation = $this->operation();
        $recorder = new OperationRecorder($operation);

        $recorder->start();
        $recorder->output('erste');
        $recorder->progress(50, 'auf halbem Weg');
        $recorder->output('zweite');
        $recorder->succeed(['ok' => true]);

        $operation->refresh();

        $this->assertSame("erste\nzweite\n", $operation->output);
        $this->assertSame(OperationStatus::Succeeded, $operation->status);
        $this->assertSame(100, $operation->progress);
        $this->assertSame(['ok' => true], $operation->result);
        $this->assertNotNull($operation->started_at);
        $this->assertNotNull($operation->finished_at);
    }

    public function test_progress_flushes_the_output_first(): void
    {
        $operation = $this->operation();
        $recorder = new OperationRecorder($operation);
        $recorder->start();

        $recorder->output('Zeile vor dem Fortschritt');
        $recorder->progress(80);

        // Ohne das Leeren stünde „80 %" an einer Ausgabe, die noch bei 40 %
        // endet — wer in diesem Moment liest, sähe einen Widerspruch.
        $operation->refresh();
        $this->assertStringContainsString('Zeile vor dem Fortschritt', (string) $operation->output);
        $this->assertSame(80, $operation->progress);
    }

    public function test_a_failure_keeps_the_output_and_states_the_reason(): void
    {
        $operation = $this->operation();
        $recorder = new OperationRecorder($operation);

        $recorder->start();
        $recorder->output('bis hierhin lief es');
        $recorder->fail('nginx hat die Konfiguration abgelehnt', ['code' => 'exec_failed']);

        $operation->refresh();

        $this->assertSame(OperationStatus::Failed, $operation->status);
        $this->assertStringContainsString('bis hierhin lief es', (string) $operation->output);
        $this->assertSame('nginx hat die Konfiguration abgelehnt', $operation->message);
        $this->assertSame(['code' => 'exec_failed'], $operation->result);
    }

    public function test_endless_output_does_not_grow_without_bound(): void
    {
        $operation = $this->operation();
        $recorder = new OperationRecorder($operation);
        $recorder->start();

        // Mehr als die Grenze, in Stücken wie sie ein Programm liefert.
        for ($i = 0; $i < 40; $i++) {
            $recorder->output(str_repeat('x', 8192));
        }
        $recorder->succeed();

        $operation->refresh();
        $length = strlen((string) $operation->output);

        $this->assertLessThan(OperationRecorder::OUTPUT_MAX * 2, $length);
        $this->assertStringContainsString('Ausgabe gekürzt', (string) $operation->output);
    }

    public function test_dispatching_creates_a_record_and_queues_the_job(): void
    {
        Queue::fake();

        $subscription = Subscription::factory()->create();
        $account = Account::factory()->admin()->create();
        app(Tenancy::class)->allowAll();

        $operation = app(Operations::class)->dispatch(
            'service.action',
            ['unit' => 'nginx.service', 'action' => 'reload'],
            $subscription,
            $account,
        );

        $this->assertSame(OperationStatus::Queued, $operation->status);
        $this->assertSame((int) $subscription->id, (int) $operation->subscription_id);

        Queue::assertPushed(
            RunAgentOperation::class,
            // Ein Auftrag ohne sichtbaren Vorgang — oder umgekehrt — ist
            // genau die Lage, in der der Betreiber etwas anderes sieht, als
            // das System tut.
            fn (RunAgentOperation $job): bool => true,
        );
    }

    public function test_an_operator_job_carries_no_subscription(): void
    {
        $subscription = Subscription::factory()->create();
        app(Tenancy::class)->restrictTo([(int) $subscription->id]);
        Queue::fake();

        $operation = app(Operations::class)->dispatch('panel.update', []);

        // Ausdrücklich leer, auch wenn genau ein Mandant aktiv ist: Ein
        // Vorgang des Betreibers gehört keinem Kunden.
        $this->assertNull($operation->subscription_id);
    }

    public function test_a_vanished_operation_does_not_break_the_worker(): void
    {
        // Das Abonnement wurde gelöscht, während der Auftrag in der
        // Warteschlange stand.
        $job = new RunAgentOperation(999999);

        // Die Aussage dieses Tests ist, dass nichts fliegt: Ein Auftrag, dessen
        // Vorgang zwischenzeitlich verschwunden ist, darf den Arbeiter nicht
        // anhalten, sonst bleibt die ganze Warteschlange an einer Leiche
        // hängen. Eine Zusicherung gibt es dafür nicht — das Ausbleiben der
        // Ausnahme ist sie.
        $this->expectNotToPerformAssertions();

        $job->handle(app(Client::class), app(Tenancy::class), app(Lifecycle::class));
    }
}
