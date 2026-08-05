<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pläne — und die drei Stellen, an denen ein Formular echten Schaden anrichtet.
 *
 * Erstens: Ein Plan ohne Standardmarke. Ein neues Abonnement bekäme keinen
 * Plan, und der Fehler zeigte sich erst beim Anlegen. Zweitens: ein gelöschter
 * Plan mit gebundenen Abonnements — die Abonnements hätten danach keine
 * Kontingente mehr. Drittens: ein leeres Feld, das als „unbegrenzt" ankommt,
 * ausgerechnet beim Speicherplatz.
 */
final class PlanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Paket M',
            'description' => 'Für kleine Auftritte.',
            'is_default' => false,
            'quotas' => Quotas::defaults(),
            'features' => Quotas::featureDefaults(),
        ], $overrides);
    }

    public function test_the_list_shows_the_plans(): void
    {
        Plan::factory()->create(['name' => 'Paket S']);

        $this->actingAs($this->admin())
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plans/Index')
                ->where('plans.data.0.name', 'Paket S'));
    }

    public function test_a_customer_does_not_reach_the_plans(): void
    {
        // Der Plan ist die Vorlage, nicht der Stand. Ein Kunde, der sie
        // aufrufen könnte, sähe die Kontingente aller anderen im selben Plan.
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->get('/plans')->assertForbidden();
        $this->actingAs($account)->get('/plans/create')->assertForbidden();
        $this->actingAs($account)->post('/plans', $this->payload())->assertForbidden();
    }

    public function test_a_plan_is_created_with_the_catalog_keys(): void
    {
        $this->actingAs($this->admin())
            ->post('/plans', $this->payload())
            ->assertRedirect('/plans');

        $plan = Plan::query()->where('name', 'Paket M')->firstOrFail();

        // Genau die Schlüssel des Katalogs, keiner mehr und keiner weniger.
        $this->assertSame(Quota::keys(), array_keys($plan->quotas ?? []));
        $this->assertSame(Feature::keys(), array_keys($plan->features ?? []));
    }

    public function test_the_first_plan_can_be_the_default(): void
    {
        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['is_default' => true]));

        $this->assertSame('Paket M', Plan::standard()?->name);
    }

    public function test_only_one_plan_carries_the_default_mark(): void
    {
        $first = Plan::factory()->default()->create(['name' => 'Alt']);

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['name' => 'Neu', 'is_default' => true]));

        $this->assertFalse($first->refresh()->is_default);
        $this->assertSame('Neu', Plan::standard()?->name);
        $this->assertSame(1, Plan::query()->where('is_default', true)->count());
    }

    public function test_unchecking_the_default_leaves_it_where_it_is(): void
    {
        // Das Abwählen tut nichts, und das ist die Absicht: Ohne Standardplan
        // bekäme ein neues Abonnement keinen — ein Fehler, der erst beim
        // nächsten Anlegen aufträte und nicht bei dem Häkchen, das ihn
        // verursacht hat.
        $plan = Plan::factory()->default()->create(['name' => 'Standard']);

        $this->actingAs($this->admin())
            ->put("/plans/{$plan->id}", $this->payload(['name' => 'Standard', 'is_default' => false]))
            ->assertRedirect('/plans');

        $this->assertTrue($plan->refresh()->is_default);
    }

    public function test_an_unlimited_disk_quota_is_refused(): void
    {
        // Der einzige Wert, bei dem „leer" nicht „unbegrenzt" heissen darf:
        // Ein Abonnement ohne Speichergrenze füllt das Dateisystem und nimmt
        // jedes andere auf demselben Server mit.
        $quotas = Quotas::defaults();
        $quotas[Quota::DiskMb->value] = null;

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['quotas' => $quotas]))
            ->assertSessionHasErrors('quotas.'.Quota::DiskMb->value);

        $this->assertSame(0, Plan::query()->count());
    }

    public function test_an_unlimited_count_is_stored_as_null(): void
    {
        $quotas = Quotas::defaults();
        $quotas[Quota::Databases->value] = null;

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['quotas' => $quotas]))
            ->assertSessionHasNoErrors();

        $plan = Plan::query()->where('name', 'Paket M')->firstOrFail();

        $this->assertNull($plan->quota(Quota::Databases));
        $this->assertSame('unbegrenzt', Quotas::format(Quota::Databases, $plan->quota(Quota::Databases)));
    }

    public function test_an_unknown_php_version_does_not_get_through(): void
    {
        $quotas = Quotas::defaults();
        $quotas[Quota::PhpVersions->value] = ['8.4', '5.6'];

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['quotas' => $quotas]))
            ->assertSessionHasErrors('quotas.'.Quota::PhpVersions->value.'.1');
    }

    public function test_a_plan_without_a_php_version_is_refused(): void
    {
        // Ein Abonnement ohne erlaubte PHP-Version hat keinen Handler und
        // liefert nichts aus. Das ist kein kleines Paket, das ist ein kaputtes.
        $quotas = Quotas::defaults();
        $quotas[Quota::PhpVersions->value] = [];

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload(['quotas' => $quotas]))
            ->assertSessionHasErrors('quotas.'.Quota::PhpVersions->value);
    }

    public function test_the_name_stays_unique(): void
    {
        Plan::factory()->create(['name' => 'Paket M']);

        $this->actingAs($this->admin())
            ->post('/plans', $this->payload())
            ->assertSessionHasErrors('name');
    }

    public function test_a_plan_keeps_its_own_name_while_being_edited(): void
    {
        $plan = Plan::factory()->create(['name' => 'Paket M']);

        $this->actingAs($this->admin())
            ->put("/plans/{$plan->id}", $this->payload(['name' => 'Paket M']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_change_reaches_the_bound_subscriptions(): void
    {
        // Der Sinn einer Vorlage — und zugleich das, was sie gefährlich macht.
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->for($plan)->create();

        $quotas = Quotas::defaults();
        $quotas[Quota::Databases->value] = 2;

        $this->actingAs($this->admin())
            ->put("/plans/{$plan->id}", $this->payload(['name' => $plan->name, 'quotas' => $quotas]));

        $this->assertSame(2, $subscription->refresh()->quota(Quota::Databases->value));
    }

    public function test_an_override_survives_a_plan_change(): void
    {
        // Die Gegenprobe: Was am Abonnement steht, bleibt stehen. Sonst wäre
        // „abweichend vom Plan" eine Angabe, die die nächste Planänderung
        // stillschweigend zurücknimmt.
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->for($plan)->create([
            'quota_overrides' => [Quota::Databases->value => 99],
        ]);

        $quotas = Quotas::defaults();
        $quotas[Quota::Databases->value] = 2;

        $this->actingAs($this->admin())
            ->put("/plans/{$plan->id}", $this->payload(['name' => $plan->name, 'quotas' => $quotas]));

        $this->assertSame(99, $subscription->refresh()->quota(Quota::Databases->value));
    }

    public function test_the_record_of_a_change_names_what_changed(): void
    {
        $plan = Plan::factory()->create(['name' => 'Paket M']);

        $quotas = Quotas::defaults();
        $quotas[Quota::Databases->value] = 2;

        $this->actingAs($this->admin())
            ->put("/plans/{$plan->id}", $this->payload(['name' => 'Paket M', 'quotas' => $quotas]));

        $event = AuditEvent::query()->where('action', 'plan.updated')->firstOrFail();
        $changed = ($event->context ?? [])['changed'] ?? [];

        $this->assertArrayHasKey(Quota::Databases->value, $changed);
        $this->assertSame('2', $changed[Quota::Databases->value]['auf']);

        // Nur das Geänderte. Ein Eintrag mit neun unveränderten Werten ist
        // beim Nachlesen wertlos.
        $this->assertCount(1, $changed);
    }

    public function test_a_plan_with_subscriptions_is_not_deleted(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->for($plan)->create();

        $this->actingAs($this->admin())
            ->delete("/plans/{$plan->id}")
            ->assertSessionHasErrors('plan');

        $this->assertNotNull(Plan::query()->find($plan->id));
    }

    public function test_the_refused_deletion_is_recorded(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->for($plan)->create();

        $this->actingAs($this->admin())->delete("/plans/{$plan->id}");

        $this->assertNotNull(
            AuditEvent::query()->where('action', 'plan.deleted')->where('result', 'denied')->first(),
        );
    }

    public function test_deleting_the_default_promotes_the_oldest_remaining(): void
    {
        $standard = Plan::factory()->default()->create(['name' => 'Standard']);
        $other = Plan::factory()->create(['name' => 'Zweiter']);

        $this->actingAs($this->admin())
            ->delete("/plans/{$standard->id}")
            ->assertRedirect('/plans');

        $this->assertNull(Plan::query()->find($standard->id));
        $this->assertTrue($other->refresh()->is_default);
    }

    public function test_deleting_the_last_plan_leaves_no_default(): void
    {
        $plan = Plan::factory()->default()->create();

        $this->actingAs($this->admin())->delete("/plans/{$plan->id}");

        $this->assertNull(Plan::standard());
        $this->assertSame(0, Plan::query()->count());
    }

    public function test_the_form_carries_the_catalog(): void
    {
        // Die Oberfläche kennt kein Kontingent beim Namen — sie rendert, was
        // sie bekommt. Bleibt der Katalog aus, ist das Formular leer, und ein
        // leeres Formular speichert einen Plan ohne Kontingente.
        $this->actingAs($this->admin())
            ->get('/plans/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plans/Form')
                ->has('catalog.quotas', count(Quota::cases()))
                ->has('catalog.features', count(Feature::cases()))
                ->where('catalog.php_versions', Quota::PHP_VERSIONS));
    }

    public function test_the_form_fills_a_quota_an_old_plan_does_not_know(): void
    {
        // Ein Plan aus einer älteren Version kennt ein Kontingent noch nicht,
        // das es inzwischen gibt. Das Formular darf dafür kein leeres Feld
        // zeigen — leer käme beim Speichern als „unbegrenzt" an.
        $plan = Plan::factory()->create();
        $plan->forceFill(['quotas' => [Quota::Domains->value => 3]])->save();

        $this->actingAs($this->admin())
            ->get("/plans/{$plan->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('values.quotas.'.Quota::Domains->value, 3)
                ->where('values.quotas.'.Quota::DiskMb->value, Quota::DiskMb->default()));
    }
}
