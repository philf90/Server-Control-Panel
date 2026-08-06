<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use App\Support\Tls\AcmeSettings;
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

    /**
     * Die Seite trägt die beiden Angaben, ohne die nichts bestellt wird.
     *
     * **Bis P4 gab es sie nur auf der Kommandozeile.** Ein Betreiber, der das
     * Panel benutzt und nicht die Konsole, sah TLS als etwas, das still nichts
     * tut — und „still nichts" ist der Zustand, den von aussen niemand erkennt.
     */
    public function test_the_page_carries_the_acme_settings(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/tls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Tls')
                ->where('acme.configured', false)
                ->where('acme.staging', true)
                ->has('directories', 2));
    }

    public function test_the_contact_address_is_stored(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/tls/acme', ['contact' => 'post@beispiel.de', 'directory' => 'production'])
            ->assertRedirect('/settings/tls');

        $settings = app(AcmeSettings::class);

        $this->assertSame('post@beispiel.de', $settings->contact());
        $this->assertFalse($settings->staging());

        // Wer die Zertifizierungsstelle umstellt, entscheidet darüber, was auf
        // diesem Server ausgestellt wird. Das gehört ins Protokoll.
        $this->assertTrue(
            AuditEvent::query()->where('action', 'panel.acme.settings')->exists(),
            'Die Änderung steht nicht im Protokoll.',
        );
    }

    /**
     * Eine Adresse, die keine ist, wird hier abgewiesen und nicht später.
     *
     * Sonst fiele sie erst auf, wenn ein Kunde eine Domain anlegt — und dann
     * als Vorgang, der ohne Zutun scheitert.
     */
    public function test_an_address_that_is_none_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings/tls')
            ->put('/settings/tls/acme', ['contact' => 'post-at-beispiel', 'directory' => 'staging'])
            ->assertSessionHasErrors('contact');

        $this->assertNull(app(AcmeSettings::class)->contact());
    }

    /** Und nur die bekannten Zertifizierungsstellen. */
    public function test_only_the_known_certificate_authorities_are_accepted(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings/tls')
            ->put('/settings/tls/acme', [
                'contact' => 'post@beispiel.de',
                'directory' => 'https://acme.example.org/directory',
            ])
            ->assertSessionHasErrors('directory');
    }
}
