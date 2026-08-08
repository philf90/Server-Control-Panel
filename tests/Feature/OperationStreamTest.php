<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\OperationRecorder;
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

    /**
     * Was die Seite laufend zeigt, schickt der Kanal auch.
     *
     * **Der Anlass ist eine Aufnahme vom 8. August 2026.** Ein fehlgeschlagener
     * Vorgang stand mit „Begonnen —" und „Beendet —" auf dem Bildschirm. Beide
     * Zeitstempel *waren* gesetzt — `OperationRecorder` schreibt sie —, aber
     * die Seite zeigte die Werte aus der ersten
     * Inertia-Antwort, und zu dem Zeitpunkt stand der Vorgang in der
     * Warteschlange. Der Kanal führte Zustand, Fortschritt und Meldung nach und
     * die Zeiten nicht.
     *
     * **Zwei Quellen für dieselbe Angabe, und eine wird nicht nachgezogen** —
     * dasselbe Muster wie überall in diesem Projekt, hier zwischen Server und
     * Browser. Ein Neuladen zeigte die richtigen Werte; wer zusieht, sah einen
     * Zustand, den es nie gab.
     *
     * Geprüft wird am ausgelieferten Ereignis und nicht am Quelltext: Was der
     * Browser bekommt, ist die Frage.
     */
    public function test_the_state_event_carries_the_times(): void
    {
        [$account, $subscription] = $this->customerWithSubscription();

        $operation = Operation::factory()->for($subscription)->create([
            'status' => OperationStatus::Queued,
            'progress' => 0,
        ]);

        // Über den Schreiber und nicht mit `forceFill`: So entstehen die
        // Zeitstempel im Betrieb, und die beiden Spalten sind mit Absicht nicht
        // in `$fillable` — gemessen wird und nicht eingegeben.
        $recorder = new OperationRecorder($operation);
        $recorder->start();
        $recorder->fail('Das Zurückspielen ist gescheitert.');

        $operation->refresh();

        $this->assertNotNull($operation->started_at, 'Ohne gesetzte Zeit prüft der Rest nichts.');
        $this->assertNotNull($operation->finished_at);

        $body = $this->actingAs($account)
            ->get("/operations/{$operation->id}/stream")
            ->streamedContent();

        foreach (['started_at', 'finished_at'] as $feld) {
            $this->assertStringContainsString(
                '"'.$feld.'":"',
                $body,
                sprintf(
                    'Der Kanal schickt `%s` nicht — dann zeigt die Vorgangsseite dafür den Wert '.
                    'aus der ersten Antwort, und der stammt aus der Warteschlange.',
                    $feld,
                ),
            );
        }
    }

    /**
     * Und die Seite liest es auch von dort.
     *
     * **Die Gegenrichtung, und sie ist der eigentliche Fehler von damals.** Der
     * Kanal hätte die Zeiten schicken können, und solange die Vorlage
     * `props.operation.started_at` ausgibt, ändert das nichts: Das ist der Wert
     * aus der ersten Antwort. Was der Kanal laufend nachführt, darf die Vorlage
     * nicht aus der Erstantwort drucken.
     *
     * **Nur die Vorlage, nicht das Skript.** Im `<script>` steht
     * `live?.state.value?.x ?? props.operation.x` — das ist der richtige
     * Rückfall für den Augenblick, bevor das erste Ereignis da ist.
     *
     * Die Namen kommen aus dem Controller und nicht aus einer Liste hier: Eine
     * Liste müsste jemand pflegen, und wer sie pflegt, denkt auch an die
     * Vorlage.
     */
    public function test_the_page_does_not_print_a_live_field_from_the_first_answer(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OperationStreamController.php'));

        $this->assertSame(
            1,
            preg_match("/send\('state'.*?\]\);/s", $controller, $treffer),
            'Die Nutzlast des Ereignisses ist nicht auffindbar — dann prüft dieser Wächter nichts.',
        );

        preg_match_all("/'([a-z_]+)' =>/", $treffer[0], $namen);
        $live = array_unique($namen[1]);

        $this->assertGreaterThan(4, count($live), 'Es werden kaum Felder gefunden.');

        $page = (string) file_get_contents(base_path('resources/js/Pages/Operations/Show.vue'));

        $this->assertSame(1, preg_match('#<template>(.*)</template>#s', $page, $vorlage));

        preg_match_all('/props\.operation\.([a-z_]+)/', $vorlage[1], $gedruckt);

        $this->assertSame(
            [],
            array_values(array_intersect(array_unique($gedruckt[1]), $live)),
            'Diese Angabe führt der Kanal nach, und die Vorlage druckt sie aus der ersten '.
            'Antwort. Solange der Vorgang läuft, zeigt die Seite damit einen Zustand, den es '.
            'nicht mehr gibt — dafür gibt es im <script> den Rückfall mit `??`.',
        );
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
