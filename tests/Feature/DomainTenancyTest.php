<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die zweite Klammer: einzelne Domains innerhalb eines Abonnements.
 *
 * §6.1 verspricht sie seit P1 — „zusätzlich eine Einschränkung auf einzelne
 * Domains eines Abonnements" — und die Spalte `domain_ids` lag seitdem in der
 * Verknüpfungstabelle. Gelesen hat sie nichts. Solange es keine Domains gab,
 * war das folgenlos; mit P3 wäre es ein Feld im Formular gewesen, das nichts
 * bewirkt, und niemand hätte es bemerkt, weil alles funktioniert.
 *
 * Geprüft wird deshalb beides: dass die Einschränkung greift, **und** dass sie
 * nicht mehr wegnimmt, als sie soll. Der zweite Fall ist der, an dem eine zu
 * einfache Abfrage scheitert.
 */
final class DomainTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    public function test_without_a_tenant_no_domain_is_visible(): void
    {
        $this->tenancy()->withoutRestriction(function (): void {
            Domain::factory()->count(2)->create();
        });

        $this->assertSame(0, Domain::query()->count());
    }

    public function test_a_tenant_sees_only_the_domains_of_its_own_subscriptions(): void
    {
        [$mine, $foreign] = $this->tenancy()->withoutRestriction(function (): array {
            $mine = Subscription::factory()->create();
            $foreign = Subscription::factory()->create();

            Domain::factory()->count(2)->for($mine)->create();
            Domain::factory()->count(3)->for($foreign)->create();

            return [$mine, $foreign];
        });

        $this->tenancy()->restrictTo([(int) $mine->id]);

        $this->assertSame(2, Domain::query()->count());
        $this->assertSame(0, Domain::query()->where('subscription_id', $foreign->id)->count());
    }

    /**
     * Der Angriff aus der Abnahmebedingung, eine Ebene tiefer: Die ID einer
     * fremden Domain ist bekannt, das Objekt existiert — und bleibt trotzdem
     * unerreichbar.
     */
    public function test_a_known_foreign_domain_id_is_still_not_found(): void
    {
        [$mine, $secret] = $this->tenancy()->withoutRestriction(function (): array {
            $mine = Subscription::factory()->create();
            $secret = Domain::factory()->create();

            return [$mine, $secret];
        });

        $this->tenancy()->restrictTo([(int) $mine->id]);

        $this->assertNull(Domain::query()->find($secret->id));
        $this->assertSame(0, Domain::query()->whereKey($secret->id)->count());
    }

    /**
     * Ein Zusatzbenutzer, der auf zwei von drei Domains beschränkt ist, sieht
     * zwei.
     */
    public function test_a_domain_restriction_hides_the_other_domains_of_the_same_subscription(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->additional($customer)->create();

        [$subscription, $allowed, $hidden] = $this->tenancy()->withoutRestriction(function () use ($customer): array {
            $subscription = Subscription::factory()->for($customer)->create();

            $allowed = Domain::factory()->count(2)->for($subscription)->create();
            $hidden = Domain::factory()->for($subscription)->create();

            return [$subscription, $allowed, $hidden];
        });

        $this->assign($account, $subscription, $allowed->pluck('id')->map(intval(...))->all());

        $this->tenancy()->forAccount($account->refresh());

        $this->assertSame(2, Domain::query()->count());
        $this->assertNull(Domain::query()->find($hidden->id));
        $this->assertNotNull(Domain::query()->find($allowed[0]->id));
    }

    /**
     * **Der Fall, an dem eine flache Liste scheitert.**
     *
     * Derselbe Mensch ist in einem Abonnement auf zwei Domains beschränkt und
     * arbeitet im zweiten an allem. Eine Klammer, die nur „erlaubte
     * Domain-IDs" kennte, hätte ihm das zweite Abonnement leer geräumt — und
     * das wäre als „er sieht ja seine Domains" durchgegangen, solange niemand
     * mit zwei Zuweisungen getestet hätte.
     */
    public function test_a_restriction_in_one_subscription_does_not_reach_into_another(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->additional($customer)->create();

        [$restricted, $free, $allowed] = $this->tenancy()->withoutRestriction(function () use ($customer): array {
            $restricted = Subscription::factory()->for($customer)->create();
            $free = Subscription::factory()->for($customer)->create();

            $allowed = Domain::factory()->for($restricted)->create();
            Domain::factory()->count(2)->for($restricted)->create();
            Domain::factory()->count(3)->for($free)->create();

            return [$restricted, $free, $allowed];
        });

        $this->assign($account, $restricted, [(int) $allowed->id]);
        $this->assign($account, $free, null);

        $this->tenancy()->forAccount($account->refresh());

        // Eine aus dem eingeschränkten, drei aus dem freien Abonnement.
        $this->assertSame(4, Domain::query()->count());
        $this->assertSame(1, Domain::query()->where('subscription_id', $restricted->id)->count());
        $this->assertSame(3, Domain::query()->where('subscription_id', $free->id)->count());
    }

    /**
     * Die Einschränkung veraltet nicht.
     *
     * Eine Domain, die nach dem Anmelden im *freien* Abonnement entsteht, ist
     * sofort sichtbar — die Klammer fragt die Bedingung bei jeder Abfrage neu,
     * statt einmal eine Liste erlaubter IDs zu bauen. Genau daran wäre die
     * einfachere Umsetzung gescheitert, und zwar still.
     */
    public function test_a_domain_created_later_in_an_unrestricted_subscription_is_visible(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->additional($customer)->create();

        [$restricted, $free, $allowed] = $this->tenancy()->withoutRestriction(function () use ($customer): array {
            $restricted = Subscription::factory()->for($customer)->create();
            $free = Subscription::factory()->for($customer)->create();
            $allowed = Domain::factory()->for($restricted)->create();

            return [$restricted, $free, $allowed];
        });

        $this->assign($account, $restricted, [(int) $allowed->id]);
        $this->assign($account, $free, null);

        $this->tenancy()->forAccount($account->refresh());

        $this->assertSame(1, Domain::query()->count());

        $this->tenancy()->withoutRestriction(function () use ($free): void {
            Domain::factory()->for($free)->create();
        });

        $this->assertSame(2, Domain::query()->count());
    }

    /** Ein Kundenkonto trägt keine Domain-Einschränkung — sie gilt nur für Zusatzbenutzer. */
    public function test_a_customer_account_has_no_domain_restriction(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->tenancy()->withoutRestriction(function () use ($customer): void {
            $subscription = Subscription::factory()->for($customer)->create();
            Domain::factory()->count(3)->for($subscription)->create();
        });

        $this->tenancy()->forAccount($account->refresh());

        $this->assertSame([], $account->domainRestrictions());
        $this->assertSame(3, Domain::query()->count());
    }

    public function test_an_admin_sees_every_domain(): void
    {
        $account = Account::factory()->admin()->create();

        $this->tenancy()->withoutRestriction(function (): void {
            Domain::factory()->count(4)->create();
        });

        $this->tenancy()->forAccount($account);

        $this->assertSame(4, Domain::query()->count());
    }

    /**
     * Eine Zuweisung ohne gewählte Domain heißt „alle des Abonnements".
     *
     * Das ist der Zustand direkt nach dem Anlegen eines Zusatzbenutzers. Die
     * Gegenauslegung — „keine" — wäre ein Konto, das sich anmeldet und nichts
     * sieht, ohne dass jemand das so eingestellt hätte.
     */
    public function test_an_empty_selection_means_no_restriction(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->additional($customer)->create();

        $subscription = $this->tenancy()->withoutRestriction(function () use ($customer): Subscription {
            $subscription = Subscription::factory()->for($customer)->create();
            Domain::factory()->count(2)->for($subscription)->create();

            return $subscription;
        });

        $this->assign($account, $subscription, []);

        $this->tenancy()->forAccount($account->refresh());

        $this->assertSame([], $account->domainRestrictions());
        $this->assertSame(2, Domain::query()->count());
    }

    /**
     * Einem Zusatzbenutzer ein Abonnement zuweisen.
     *
     * `null` heißt „ohne Domain-Einschränkung", genau wie in der
     * Verknüpfungstabelle.
     *
     * @param  list<int>|null  $domainIds
     */
    private function assign(Account $account, Subscription $subscription, ?array $domainIds): void
    {
        $this->tenancy()->withoutRestriction(function () use ($account, $subscription, $domainIds): void {
            $account->assignedSubscriptions()->attach($subscription->id, [
                'permissions' => json_encode([]),
                'domain_ids' => $domainIds === null ? null : json_encode($domainIds),
            ]);
        });
    }
}
