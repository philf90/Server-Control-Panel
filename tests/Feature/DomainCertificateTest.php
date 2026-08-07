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
     * **Ein Einzelzertifikat nimmt den Platzhalter nicht vom Tisch.**
     *
     * Der Fund vom Zielserver am 7. August 2026: Das Kästchen „Als Platzhalter
     * bestellen" hing an `!certificate`. Die Automatik bestellt aber, sobald
     * der Server-Block steht, und der Arbeiter ist schneller als jeder Mensch
     * — die Seite stand mit einem gültigen Zertifikat für die Hauptdomain da
     * und bot weder Platzhalter noch Bestellung an. **Der Weg von
     * Einzelzertifikaten zu einem Platzhalter existierte über die Oberfläche
     * gar nicht**, obwohl die Route ihn annimmt.
     *
     * Geprüft wird hier die Angabe, an der das Kästchen jetzt hängt. Dass sie
     * das Richtige beantwortet, prüft `WildcardOrderTest`; dass sie überhaupt
     * auf der Seite ankommt, nur dieser Durchgang — eine Angabe, die im
     * Zusammenbau der Seite fehlt, fällt sonst erst im Browser auf.
     */
    public function test_a_single_certificate_leaves_the_wildcard_on_offer(): void
    {
        $domain = $this->domain();

        Certificate::factory()->covering([$domain->name])->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => now()->addDays(60),
        ]);

        $this->actingAs($this->admin())
            ->get('/domains/'.$domain->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Domains/Show')
                ->where('wildcard.covered', false)
                ->where('can.order_wildcard', true));
    }

    /** Und liegt der Platzhalter, ist das Angebot weg — sonst bestellt jemand doppelt. */
    public function test_an_existing_wildcard_takes_the_offer_away(): void
    {
        $domain = $this->domain();

        Certificate::factory()->covering(['*.'.$domain->name, $domain->name])->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => now()->addDays(60),
        ]);

        $this->actingAs($this->admin())
            ->get('/domains/'.$domain->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wildcard.covered', true));
    }

    /** Wo die Domainseite steht. */
    private const PAGE = 'resources/js/Pages/Domains/Show.vue';

    /**
     * **Eine Auskunft über den Zustand hängt nicht an einer Absicht.**
     *
     * `*.example.de` deckt `a.b.example.de` nicht — eine Grenze, die ACME zieht
     * und die auf die Seite gehört, statt als Browserwarnung zu entstehen. Die
     * Bedingung dafür hing bis zum 7. August 2026 allein am Kästchen „Als
     * Platzhalter bestellen", also an der **Absicht**, einen zu bestellen.
     *
     * **Damit fehlte der Satz genau dann, wenn er keine Vorhersage mehr ist.**
     * Sobald der Platzhalter ausgestellt war, verschwand das Kästchen — es gibt
     * nichts mehr zu bestellen —, und mit ihm die Auskunft darüber, was er
     * nicht deckt. Im Abnahmelauf auf `cloudlab24.ipv64.de` aufgefallen: Die
     * Unterdomain eine Ebene tiefer war angelegt, und die Seite schwieg.
     *
     * Geprüft wird die Bedingung im Markup und nicht das Bild — gerendert wird
     * hier nichts. Das ist die schwächere Prüfung, aber sie hält genau den
     * Rückschritt auf, der hier passiert ist: die Bedingung wieder auf die
     * Absicht allein zu verkürzen.
     */
    public function test_the_uncovered_names_do_not_depend_on_the_checkbox_alone(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::PAGE);

        preg_match_all('/v-if="([^"]*wildcard\.uncovered[^"]*)"/', $source, $treffer);

        $this->assertNotSame([], $treffer[1], sprintf(
            'In %s hängt nichts mehr an `wildcard.uncovered` — dann sagt die Seite nicht, was ein '.
            'Platzhalter nicht deckt.',
            self::PAGE,
        ));

        foreach ($treffer[1] as $bedingung) {
            $this->assertStringContainsString('wildcard.covered', $bedingung, sprintf(
                "Die Bedingung „%s\" in %s fragt nicht, ob der Platzhalter schon liegt.\n\n".
                'Sie hängt damit allein an der Absicht, einen zu bestellen — und sobald er ausgestellt '.
                'ist, verschwindet das Kästchen und mit ihm die Auskunft. Genau dann ist sie keine '.
                'Vorhersage mehr, sondern eine Tatsache.',
                $bedingung,
                self::PAGE,
            ));
        }
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
