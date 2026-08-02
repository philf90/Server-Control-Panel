<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Live-Ausgabe eines Vorgangs.
 *
 * Geprüft wird nicht nur, dass etwas ankommt, sondern die drei Eigenschaften,
 * von denen der Betrieb abhängt: dass der Strom endet, dass er sich fortsetzen
 * lässt, und dass er fremde Vorgänge nicht zeigt.
 */
final class OperationStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Im Test soll der Strom nicht fünf Minuten offen bleiben.
        config([
            'srvpanel.operations.poll_ms' => 10,
            'srvpanel.operations.stream_seconds' => 1,
        ]);
    }

    /** @return array{0: Account, 1: Subscription} */
    private function customerWithSubscription(): array
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        return [Account::factory()->customer($customer)->create(), $subscription];
    }

    public function test_a_finished_operation_is_delivered_and_the_stream_ends(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $operation = Operation::factory()->for($subscription)->create([
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
            'message' => 'fertig',
            'output' => "erste Zeile\nzweite Zeile\n",
        ]);

        $response = $this->actingAs($account)
            ->get("/operations/{$operation->id}/stream");

        $response->assertOk();

        // Symfony hängt den Zeichensatz an; entscheidend ist der Typ.
        $this->assertStringStartsWith(
            'text/event-stream',
            (string) $response->headers->get('Content-Type'),
        );

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: state', $body);
        $this->assertStringContainsString('zweite Zeile', $body);

        // Das Ende ist der Punkt: Ohne `done` wartete der Browser weiter.
        $this->assertStringContainsString('event: done', $body);
    }

    public function test_the_stream_resumes_where_it_left_off(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $operation = Operation::factory()->for($subscription)->create([
            'status' => OperationStatus::Succeeded,
            'output' => "alt\nneu\n",
        ]);

        // „alt\n" sind vier Zeichen — der Browser hätte sie schon.
        $body = $this->actingAs($account)
            ->withHeaders(['Last-Event-ID' => '4'])
            ->get("/operations/{$operation->id}/stream")
            ->streamedContent();

        $this->assertStringContainsString('neu', $body);
        $this->assertStringNotContainsString('alt', $body);
    }

    public function test_a_nonsense_resume_marker_starts_from_the_beginning(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $operation = Operation::factory()->for($subscription)->create([
            'status' => OperationStatus::Succeeded,
            'output' => "alles\n",
        ]);

        foreach (['-5', 'abc', '99999999999999999999'] as $header) {
            $body = $this->actingAs($account)
                ->withHeaders(['Last-Event-ID' => $header])
                ->get("/operations/{$operation->id}/stream")
                ->streamedContent();

            $this->assertStringContainsString('event: done', $body, "Kennung {$header} hat den Strom zerlegt.");
        }
    }

    public function test_a_running_operation_ends_with_reconnect_instead_of_hanging(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $operation = Operation::factory()->for($subscription)->create([
            'status' => OperationStatus::Running,
            'progress' => 40,
        ]);

        $body = $this->actingAs($account)
            ->get("/operations/{$operation->id}/stream")
            ->streamedContent();

        // Jede offene Verbindung belegt einen FPM-Arbeiter. Der Strom muss von
        // selbst enden und den Browser zum Neuaufbau auffordern.
        $this->assertStringContainsString('event: reconnect', $body);
        $this->assertStringNotContainsString('event: done', $body);
    }

    public function test_a_foreign_operation_is_not_found(): void
    {
        [$account] = $this->customerWithSubscription();
        $foreign = Subscription::factory()->create();
        $secret = Operation::factory()->for($foreign)->create(['output' => 'geheim']);

        $response = $this->actingAs($account)->get("/operations/{$secret->id}/stream");

        // 404, nicht 403: Die Mandantenklammer greift schon bei der
        // Modellbindung. Ein 403 verriete, dass es diesen Vorgang gibt.
        $response->assertNotFound();
    }

    public function test_an_operation_of_the_operator_is_not_found_for_a_customer(): void
    {
        [$account] = $this->customerWithSubscription();
        $operatorJob = Operation::factory()->create(['subscription_id' => null]);

        $this->actingAs($account)
            ->get("/operations/{$operatorJob->id}/stream")
            ->assertNotFound();
    }

    public function test_the_admin_reaches_an_operation_of_the_operator(): void
    {
        $admin = Account::factory()->admin()->create();
        $operatorJob = Operation::factory()->create([
            'subscription_id' => null,
            'status' => OperationStatus::Succeeded,
            'output' => "Paket installiert\n",
        ]);

        $body = $this->actingAs($admin)
            ->get("/operations/{$operatorJob->id}/stream")
            ->streamedContent();

        $this->assertStringContainsString('Paket installiert', $body);
    }

    public function test_the_stream_needs_an_account(): void
    {
        $operation = Operation::factory()->create();

        $this->get("/operations/{$operation->id}/stream")->assertRedirect('/login');
    }
}
