<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was die Domainseite über ihr Zertifikat sagt — und der Knopf daneben.
 *
 * **Bestellt wird von selbst**, sobald der Server-Block steht. Der Knopf ist
 * für den Fall danach: Scheitert die Prüfung, weil der DNS-Eintrag falsch war
 * oder Port 80 zu, wartet die Domain sonst auf den nächsten Anlass — und den
 * gibt es womöglich nicht. Wer den Eintrag gerade berichtigt hat, will es
 * jetzt versuchen.
 *
 * **Ohne Kontaktadresse passiert nichts, und das muss dastehen.** Ein Knopf,
 * der eine leere Vorgangsliste hinterlässt, ist schlimmer als keiner: Er sieht
 * aus, als hätte er gewirkt.
 */
final class DomainCertificateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function domain(): Domain
    {
        app(Tenancy::class)->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);

        return Domain::factory()->for($subscription)->create(['name' => 'beispiel.de']);
    }

    private function withContact(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => AcmeSettings::KEY],
            ['value' => ['contact' => 'post@beispiel.de', 'directory' => 'staging']],
        );
    }

    public function test_a_domain_without_a_certificate_says_so(): void
    {
        $domain = $this->domain();

        $this->actingAs($this->admin())
            ->get('/domains/'.$domain->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Domains/Show')
                ->where('certificate', null)
                ->where('acme.configured', false));
    }

    /**
     * Mit Zertifikat steht da, was es deckt — und ob das reicht.
     *
     * `covers_all` ist die Angabe, die man übersieht: Ein Alias, der nach der
     * Ausstellung dazukam, steht im `server_name` und nicht im Zertifikat. Der
     * Browser warnt dann, und im Panel sieht alles grün aus.
     */
    public function test_an_assigned_certificate_shows_what_it_covers(): void
    {
        $domain = $this->domain();

        $certificate = Certificate::factory()->covering(['beispiel.de'])->create([
            'subscription_id' => $domain->subscription_id,
            'issuer' => 'R11',
        ]);

        $domain->certificate_id = (int) $certificate->id;
        $domain->save();

        $this->actingAs($this->admin())
            ->get('/domains/'.$domain->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificate.issuer', 'R11')
                ->where('certificate.trusted', true)
                ->where('certificate.covers_all', true)
                ->where('certificate.names', ['beispiel.de']));
    }

    public function test_the_button_orders_one(): void
    {
        $this->withContact();
        $domain = $this->domain();

        $this->actingAs($this->admin())
            ->post('/domains/'.$domain->id.'/certificate')
            ->assertRedirect();

        app(Tenancy::class)->allowAll();

        $this->assertSame(1, Operation::query()->where('task', 'acme.certificate.issue')->count());
    }

    /** Ohne Kontaktadresse bestellt es nichts — und sagt, warum. */
    public function test_without_a_contact_address_it_says_why_nothing_happened(): void
    {
        $domain = $this->domain();

        $this->actingAs($this->admin())
            ->post('/domains/'.$domain->id.'/certificate')
            ->assertRedirect('/domains/'.$domain->id)
            ->assertSessionHas('error');

        app(Tenancy::class)->allowAll();

        $this->assertSame(0, Operation::query()->where('task', 'acme.certificate.issue')->count());
    }
}
