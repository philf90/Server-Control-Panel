<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Der Angriffsdurchgang für die Domainfläche (§8.8).
 *
 * „Kein Kunde kommt durch Manipulation von IDs an fremde Objekte" — das ist
 * die Abnahmebedingung aus P1, und sie gilt für jede Fläche, die dazukommt.
 * Geprüft wird deshalb nicht nur, dass die eigene Domain geht, sondern jede
 * Route mit einer fremden ID.
 *
 * **Fremd heisst „nicht gefunden" und nicht „verboten".** Die Modellbindung
 * läuft unter der Mandantenklammer; die Domain existiert für dieses Konto gar
 * nicht. Ein 403 verriete, dass es sie gibt.
 */
final class DomainRouteTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $mine;

    private Subscription $foreign;

    private Account $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        app(Tenancy::class)->allowAll();
        app(Settings::class)->savePhpVersions(['8.3', '8.4']);

        $customer = Customer::factory()->create();

        $this->mine = Subscription::factory()->for($customer)->create(['name' => 'meins.de', 'system_user' => 'p1001']);
        $this->foreign = Subscription::factory()->create(['name' => 'fremd.de', 'system_user' => 'p1002']);

        $this->customer = Account::factory()->customer($customer)->create();

        app(Tenancy::class)->reset();
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function domainIn(Subscription $subscription, string $name): Domain
    {
        return app(Tenancy::class)->withoutRestriction(
            fn (): Domain => Domain::factory()->for($subscription)->create([
                'name' => $name,
                'status' => DomainStatus::Active,
            ])
        );
    }

    public function test_a_customer_sees_only_its_own_domains(): void
    {
        $this->domainIn($this->mine, 'eins.de');
        $this->domainIn($this->foreign, 'zwei.de');

        $this->actingAs($this->customer)
            ->get('/domains')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Domains/Index')
                ->has('domains.data', 1)
                ->where('domains.data.0.name', 'eins.de')
            );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function foreignRoutes(): array
    {
        return [
            ['get', '/domains/%d'],
            ['get', '/domains/%d/logs'],
            ['patch', '/domains/%d'],
            ['delete', '/domains/%d'],
        ];
    }

    /**
     * Jede Route mit einer fremden ID — der Kern des Angriffsdurchgangs.
     */
    #[DataProvider('foreignRoutes')]
    public function test_a_foreign_domain_is_not_found(string $method, string $path): void
    {
        $foreign = $this->domainIn($this->foreign, 'geheim.de');

        $this->actingAs($this->customer)
            ->{$method}(sprintf($path, $foreign->id))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_create_a_domain_in_a_foreign_subscription(): void
    {
        $this->actingAs($this->customer)
            ->get("/subscriptions/{$this->foreign->id}/domains/create")
            ->assertNotFound();

        $this->actingAs($this->customer)
            ->post("/subscriptions/{$this->foreign->id}/domains", [
                'type' => DomainType::Addon->value,
                'name' => 'uebernommen.de',
            ])
            ->assertNotFound();

        $this->assertSame(0, app(Tenancy::class)->withoutRestriction(
            fn (): int => Domain::query()->count()
        ));
    }

    /**
     * Ein Kundenkonto ohne Schreibrecht am eigenen Abonnement.
     *
     * Der Fall ist nicht theoretisch: Ein Zusatzbenutzer bekommt vom Kunden
     * genau die Rechte, die er braucht. Wer nur lesen darf, darf keine Domain
     * anlegen — und die Prüfung dafür sitzt an der Route, nicht im Menü.
     */
    public function test_an_additional_account_without_write_may_not_create(): void
    {
        $account = Account::factory()->additional($this->mine->customer)->create();

        app(Tenancy::class)->withoutRestriction(function () use ($account): void {
            $account->assignedSubscriptions()->attach($this->mine->id, [
                'permissions' => json_encode([Permission::FilesRead->value]),
                'domain_ids' => null,
            ]);
        });

        $this->actingAs($account)
            ->get("/subscriptions/{$this->mine->id}/domains/create")
            ->assertForbidden();
    }

    public function test_an_additional_account_with_write_may_create(): void
    {
        $account = Account::factory()->additional($this->mine->customer)->create();

        app(Tenancy::class)->withoutRestriction(function () use ($account): void {
            $account->assignedSubscriptions()->attach($this->mine->id, [
                'permissions' => json_encode([Permission::FilesRead->value, Permission::FilesWrite->value]),
                'domain_ids' => null,
            ]);
        });

        $this->actingAs($account)
            ->get("/subscriptions/{$this->mine->id}/domains/create")
            ->assertOk();
    }

    /**
     * Die Domain-Einschränkung eines Zusatzbenutzers wirkt bis in die Route.
     *
     * Sie ist seit P1 versprochen und wurde in P3 eingelöst; hier steht der
     * Beleg, dass sie nicht nur die Liste filtert, sondern auch den direkten
     * Aufruf einer Adresse abfängt.
     */
    public function test_a_domain_restriction_reaches_the_single_page(): void
    {
        $erlaubt = $this->domainIn($this->mine, 'erlaubt.de');
        $verborgen = $this->domainIn($this->mine, 'verborgen.de');

        $account = Account::factory()->additional($this->mine->customer)->create();

        app(Tenancy::class)->withoutRestriction(function () use ($account, $erlaubt): void {
            $account->assignedSubscriptions()->attach($this->mine->id, [
                'permissions' => json_encode([Permission::FilesRead->value, Permission::FilesWrite->value]),
                'domain_ids' => json_encode([(int) $erlaubt->id]),
            ]);
        });

        $this->actingAs($account)->get("/domains/{$erlaubt->id}")->assertOk();
        $this->actingAs($account)->get("/domains/{$verborgen->id}")->assertNotFound();
    }

    /**
     * Ohne Planfreigabe erreichen die PHP-Einstellungen den Bestand nicht.
     *
     * Der Steuerungscode wirft sie weg, statt die ganze Änderung abzuweisen:
     * Wer das DocumentRoot ändert und dabei ein Feld mitschickt, das ihm nicht
     * zusteht, soll nicht auf eine Fehlermeldung stossen, die er nicht
     * versteht.
     */
    public function test_php_settings_are_dropped_without_the_feature(): void
    {
        $domain = $this->domainIn($this->mine, 'ohne-freigabe.de');

        app(Tenancy::class)->withoutRestriction(function (): void {
            $this->mine->plan->update([
                'features' => [Feature::PhpSettings->value => false] + $this->mine->plan->features,
            ]);
        });

        $this->actingAs($this->customer)
            ->patch("/domains/{$domain->id}", [
                'document_root' => 'anders',
                'php_settings' => ['memory_limit' => '4096M'],
            ])
            ->assertRedirect();

        app(Tenancy::class)->allowAll();

        $this->assertSame('anders', $domain->refresh()->document_root);
        $this->assertSame([], $domain->php_settings ?? []);
    }

    /**
     * Ein eigenes Zertifikat hängt an einer Planfreigabe.
     *
     * **Und an einer eigenen Fähigkeit, nicht an `update`.** Wer hier hochlädt,
     * legt einen privaten Schlüssel auf den Server, und was danach ausgeliefert
     * wird, sieht jeder Besucher — eine andere Grössenordnung als ein
     * DocumentRoot zu ändern. Ohne `certificate_upload` weist die Route ab, und
     * zwar bevor irgendetwas den Socket erreicht.
     */
    private function allowUpload(bool $erlaubt): void
    {
        app(Tenancy::class)->withoutRestriction(function () use ($erlaubt): void {
            $this->mine->plan->update([
                'features' => [Feature::CertificateUpload->value => $erlaubt] + $this->mine->plan->features,
            ]);
        });
    }

    public function test_without_the_plan_feature_no_certificate_may_be_uploaded(): void
    {
        $domain = $this->domainIn($this->mine, 'ohne-zertifikat.de');
        $this->allowUpload(false);

        $this->actingAs($this->customer)
            ->post("/domains/{$domain->id}/certificate/upload", [
                'certificate' => '-----BEGIN CERTIFICATE-----',
                'private_key' => '-----BEGIN PRIVATE KEY-----',
            ])
            ->assertForbidden();
    }

    /**
     * Mit Freigabe kommt es durch — und der Grund des Agenten kommt zurück.
     *
     * In dieser Umgebung antwortet kein Agent, und genau das ist hier der
     * Durchgang: Die Meldung wird wörtlich durchgereicht statt in ein
     * „ungültig" übersetzt. Sie ist das Wertvollste an einem Fehlschlag — ein
     * Kunde liest diese Seite und sonst nichts.
     */
    public function test_with_the_plan_feature_the_answer_of_the_agent_reaches_the_page(): void
    {
        $domain = $this->domainIn($this->mine, 'mit-zertifikat.de');
        $this->allowUpload(true);

        $this->actingAs($this->customer)
            ->post("/domains/{$domain->id}/certificate/upload", [
                'certificate' => '-----BEGIN CERTIFICATE-----',
                'private_key' => '-----BEGIN PRIVATE KEY-----',
            ])
            ->assertRedirect("/domains/{$domain->id}")
            ->assertSessionHas('error');
    }

    /** Beide Teile gehören zusammen — eines allein ergibt kein Zertifikat. */
    public function test_an_upload_without_both_parts_is_refused(): void
    {
        $domain = $this->domainIn($this->mine, 'halb.de');
        $this->allowUpload(true);

        $this->actingAs($this->customer)
            ->post("/domains/{$domain->id}/certificate/upload", ['certificate' => '-----BEGIN CERTIFICATE-----'])
            ->assertSessionHasErrors('private_key');
    }

    public function test_the_main_domain_cannot_be_removed_over_the_route(): void
    {
        $main = app(Tenancy::class)->withoutRestriction(
            fn (): Domain => Domain::factory()->main()->for($this->mine)->create([
                'name' => 'meins.de',
                'status' => DomainStatus::Active,
            ])
        );

        $this->actingAs($this->customer)
            ->delete("/domains/{$main->id}")
            ->assertSessionHasErrors('domain');

        app(Tenancy::class)->allowAll();

        $this->assertNotNull(Domain::query()->find($main->id));
    }

    /**
     * Sehen und Protokolle lesen sind zwei verschiedene Rechte.
     *
     * **Der Fall fehlte, und die Gegenprobe hat es gezeigt:** Die Route auf
     * `can:view` umzustellen blieb grün, weil `viewLogs` weiterhin aus der
     * Ansicht heraus aufgerufen wird und damit als „erreichbar" galt. Was
     * `PolicyReachTest` prüft, ist die Erreichbarkeit einer Fähigkeit — nicht,
     * ob die richtige an der richtigen Route steht. Das prüft dieser Test.
     *
     * Ein Zusatzbenutzer mit dem Recht „Statistik" darf die Domain ansehen. Ein
     * Fehlerprotokoll enthält Pfade, Dateinamen und Bruchstücke aus dem
     * Quelltext — das ist etwas anderes, und dafür gibt es `FilesRead`.
     */
    public function test_seeing_a_domain_is_not_reading_its_logs(): void
    {
        $domain = $this->domainIn($this->mine, 'nur-sehen.de');

        $account = Account::factory()->additional($this->mine->customer)->create();

        app(Tenancy::class)->withoutRestriction(function () use ($account): void {
            $account->assignedSubscriptions()->attach($this->mine->id, [
                'permissions' => json_encode([Permission::Statistics->value]),
                'domain_ids' => null,
            ]);
        });

        $this->actingAs($account)->get("/domains/{$domain->id}")->assertOk();
        $this->actingAs($account)->get("/domains/{$domain->id}/logs")->assertForbidden();
    }

    /** Die PHP-Fläche ist Betreibersache. */
    public function test_only_an_operator_reaches_the_php_page(): void
    {
        $this->actingAs($this->customer)->get('/settings/php')->assertForbidden();

        // Der Agent fehlt in diesem Container; die Seite muss trotzdem
        // antworten — mit dem letzten bekannten Stand.
        $this->actingAs($this->admin())
            ->get('/settings/php')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Php')
                ->where('live', false)
                ->has('versions', 4)
            );
    }

    public function test_a_domain_can_be_created_and_shows_up(): void
    {
        $this->actingAs($this->customer)
            ->post("/subscriptions/{$this->mine->id}/domains", [
                'type' => DomainType::Addon->value,
                'name' => 'neu.de',
            ])
            ->assertRedirect();

        app(Tenancy::class)->allowAll();

        $domain = Domain::query()->where('name', 'neu.de')->sole();

        $this->assertSame(DomainStatus::Provisioning, $domain->status);
        $this->assertSame((int) $this->mine->id, $domain->subscription_id);
    }
}
