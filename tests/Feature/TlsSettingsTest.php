<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Das Zertifikat der Oberfläche im Panel.
 *
 * **Ansehen ist kein Vorgang, Neuausstellen schon.** Eine Seite, die bei
 * jedem Aufruf einen verändernden Vorgang ins Protokoll schreibt, öffnet man
 * nicht gern — und im Protokoll stünde danach ein Dutzend „Zertifikat
 * geprüft" für jedes Mal, das jemand nachgesehen hat. Das Neuausstellen
 * dagegen tauscht das Zertifikat des laufenden Webservers und lädt nginx neu.
 */
final class TlsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    public function test_the_page_says_what_it_does_not_know(): void
    {
        /*
         * Ohne Agenten gibt es keine Auskunft über das Zertifikat — im Test
         * ist das immer so. Die Seite muss das sagen können, statt mit einer
         * Fehlerseite zu antworten: Dieselbe Haltung wie in der Übersicht,
         * wo ein nicht erreichbarer Agent auch kein 500 ist.
         */
        $this->actingAs($this->admin())
            ->get('/settings/tls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Tls')
                ->where('certificate.present', false)
                ->has('certificate.reason'));
    }

    public function test_looking_at_it_creates_no_operation(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->get('/settings/tls')->assertOk();

        $this->assertSame(0, Operation::query()->count());
    }

    public function test_reissuing_is_an_operation(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post('/settings/tls')
            ->assertRedirect();

        $operation = Operation::query()->firstOrFail();

        $this->assertSame('panel.tls.ensure', $operation->type);

        // **Immer mit `force`.** Wer den Knopf drückt, hat einen Grund, den
        // das Panel nicht kennt — meistens eine Adresse, die im Zertifikat
        // fehlt. Die Prüfung „gilt ja noch" würde genau diesen Fall abweisen.
        $this->assertTrue(($operation->payload ?? [])['force'] ?? false);

        // Der Vorgang gehört dem Betreiber und keinem Abonnement.
        $this->assertNull($operation->subscription_id);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'panel.tls.reissued')->count(),
        );
    }

    public function test_a_customer_reaches_neither(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        // Das Zertifikat der Oberfläche ist Betreibersache: Es gehört zum
        // Server und nicht zu einem Abonnement.
        $this->actingAs($account)->get('/settings/tls')->assertForbidden();
        $this->actingAs($account)->post('/settings/tls')->assertForbidden();

        $this->assertSame(0, Operation::query()->count());
    }
}
