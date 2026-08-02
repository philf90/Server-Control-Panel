<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Angriffsdurchgang aus der Abnahmebedingung von P1.
 *
 * „Kein Kunde kommt durch Manipulation von IDs an fremde Objekte" — geprüft
 * wird das hier auf der Ebene der Policies, also der zweiten der vier
 * Schichten. Die erste (Mandantenklammer) hat ihre eigenen Tests; dass beide
 * dasselbe sagen, ist Absicht und keine Verdopplung aus Nachlässigkeit.
 *
 * Jeder Test hier beschreibt einen Versuch, nicht eine Funktion. Wer den
 * Namen liest, soll wissen, was jemand probiert hat und was dabei
 * herauskommen muss.
 */
final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{admin: Account, customer: Account, foreign: Account} */
    private array $accounts;

    private Customer $own;

    private Customer $foreign;

    private Subscription $ownSubscription;

    private Subscription $foreignSubscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Customer::factory()->create();
        $this->foreign = Customer::factory()->create();

        $this->ownSubscription = Subscription::factory()->create(['customer_id' => $this->own->id]);
        $this->foreignSubscription = Subscription::factory()->create(['customer_id' => $this->foreign->id]);

        $this->accounts = [
            'admin' => Account::factory()->admin()->create(),
            'customer' => Account::factory()->customer($this->own)->create(),
            'foreign' => Account::factory()->customer($this->foreign)->create(),
        ];
    }

    public function test_a_customer_does_not_reach_a_foreign_customer_record(): void
    {
        $this->assertTrue($this->accounts['customer']->can('view', $this->own));
        $this->assertFalse($this->accounts['customer']->can('view', $this->foreign));
    }

    public function test_a_customer_does_not_reach_a_foreign_subscription(): void
    {
        $this->assertTrue($this->accounts['customer']->can('view', $this->ownSubscription));
        $this->assertFalse($this->accounts['customer']->can('view', $this->foreignSubscription));
    }

    public function test_a_customer_does_not_reach_a_foreign_operation(): void
    {
        $mine = Operation::factory()->for($this->ownSubscription)->create();
        $theirs = Operation::factory()->for($this->foreignSubscription)->create();

        $this->assertTrue($this->accounts['customer']->can('view', $mine));
        $this->assertFalse($this->accounts['customer']->can('view', $theirs));
    }

    public function test_operations_of_the_operator_stay_with_the_operator(): void
    {
        $operatorJob = Operation::factory()->create(['subscription_id' => null]);

        $this->assertTrue($this->accounts['admin']->can('view', $operatorJob));
        $this->assertFalse($this->accounts['customer']->can('view', $operatorJob));
    }

    public function test_a_customer_may_not_rewrite_the_own_subscription(): void
    {
        // Das eigene Abonnement sehen: ja. Umschreiben: nein — das ist der
        // Vertrag, nicht der Inhalt.
        $this->assertTrue($this->accounts['customer']->can('view', $this->ownSubscription));
        $this->assertFalse($this->accounts['customer']->can('update', $this->ownSubscription));
        $this->assertFalse($this->accounts['customer']->can('suspend', $this->ownSubscription));
        $this->assertFalse($this->accounts['customer']->can('delete', $this->ownSubscription));
    }

    public function test_an_additional_user_reaches_only_assigned_subscriptions(): void
    {
        $second = Subscription::factory()->create(['customer_id' => $this->own->id]);
        $additional = Account::factory()->additional($this->own)->create();

        $additional->assignedSubscriptions()->attach($this->ownSubscription->id, [
            'permissions' => json_encode([Permission::FilesRead->value]),
        ]);

        $this->assertTrue($additional->can('view', $this->ownSubscription));

        // Derselbe Kunde, aber nicht zugewiesen.
        $this->assertFalse($additional->can('view', $second));
    }

    public function test_an_additional_user_without_the_permission_is_denied(): void
    {
        $additional = Account::factory()->additional($this->own)->create();
        $additional->assignedSubscriptions()->attach($this->ownSubscription->id, [
            'permissions' => json_encode([Permission::FilesRead->value]),
        ]);

        $this->assertTrue($additional->hasPermission($this->ownSubscription, Permission::FilesRead));
        $this->assertFalse($additional->hasPermission($this->ownSubscription, Permission::Databases));

        $this->assertTrue($this->useFeature($additional, Permission::FilesRead));
        $this->assertFalse($this->useFeature($additional, Permission::Databases));
    }

    public function test_a_permission_the_plan_does_not_grant_is_denied(): void
    {
        // Der Plan gibt certificate_upload nicht frei (siehe PlanFactory).
        $additional = Account::factory()->additional($this->own)->create();
        $additional->assignedSubscriptions()->attach($this->ownSubscription->id, [
            'permissions' => json_encode([Permission::Certificates->value]),
        ]);

        // Das Recht ist vergeben — der Plan trägt es trotzdem nicht.
        $this->assertTrue($additional->hasPermission($this->ownSubscription, Permission::Certificates));
        $this->assertFalse($this->useFeature($additional, Permission::Certificates));
    }

    public function test_a_suspended_subscription_stays_visible_but_unusable(): void
    {
        $this->ownSubscription->update(['status' => 'suspended']);
        $this->ownSubscription->refresh();

        // Sichtbar bleibt es — sonst wüsste der Kunde nicht, warum nichts
        // mehr geht. Benutzbar ist es nicht.
        $this->assertTrue($this->accounts['customer']->can('view', $this->ownSubscription));
        $this->assertFalse($this->useFeature($this->accounts['customer'], Permission::FilesRead));
    }

    public function test_unknown_permissions_in_the_assignment_are_ignored(): void
    {
        $additional = Account::factory()->additional($this->own)->create();
        $additional->assignedSubscriptions()->attach($this->ownSubscription->id, [
            'permissions' => json_encode(['files_read', 'ein_recht_das_es_nicht_gibt', 42]),
        ]);

        // Ein Recht aus einer älteren Fassung darf die Anmeldung nicht
        // sprengen — und muss ins Leere laufen, nicht ins Weite.
        $this->assertSame(
            [Permission::FilesRead],
            $additional->permissionsFor($this->ownSubscription),
        );
    }

    public function test_a_customer_sees_the_own_plan_and_no_other(): void
    {
        $foreignPlan = Plan::factory()->create();
        $this->foreignSubscription->update(['plan_id' => $foreignPlan->id]);

        $ownPlan = $this->ownSubscription->plan;

        $this->assertNotNull($ownPlan);
        $this->assertTrue($this->accounts['customer']->can('view', $ownPlan));
        $this->assertFalse($this->accounts['customer']->can('view', $foreignPlan));
        $this->assertFalse($this->accounts['customer']->can('update', $ownPlan));
    }

    public function test_the_log_shows_customers_only_their_own(): void
    {
        $mine = AuditEvent::factory()->create([
            'subscription_id' => $this->ownSubscription->id,
        ]);
        $theirs = AuditEvent::factory()->create([
            'subscription_id' => $this->foreignSubscription->id,
        ]);
        $anonymous = AuditEvent::factory()->create([
            'action' => 'auth.login.failed',
        ]);

        $this->assertTrue($this->accounts['customer']->can('view', $mine));
        $this->assertFalse($this->accounts['customer']->can('view', $theirs));

        // Dort stehen fehlgeschlagene Anmeldungen unbekannter Adressen. Wer
        // sie liest, sieht, unter welchen Adressen jemand geklopft hat.
        $this->assertFalse($this->accounts['customer']->can('view', $anonymous));
        $this->assertTrue($this->accounts['admin']->can('view', $anonymous));
    }

    public function test_the_log_cannot_be_changed_by_anyone(): void
    {
        $event = AuditEvent::factory()->create();

        foreach ($this->accounts as $role => $account) {
            $this->assertFalse($account->can('update', $event), "{$role} durfte das Protokoll ändern.");
            $this->assertFalse($account->can('delete', $event), "{$role} durfte das Protokoll löschen.");
        }
    }

    public function test_the_admin_reaches_everything(): void
    {
        $this->assertTrue($this->accounts['admin']->can('view', $this->foreign));
        $this->assertTrue($this->accounts['admin']->can('view', $this->foreignSubscription));
        $this->assertTrue($this->accounts['admin']->can('update', $this->foreignSubscription));
        $this->assertTrue($this->accounts['admin']->can('impersonate', $this->foreign));
    }

    public function test_a_mistyped_ability_fails_for_the_admin_too(): void
    {
        // Der Grund, warum es kein Gate::before für Admins gibt.
        //
        // Mit einer solchen Zeile lieferte diese Prüfung `true` — für eine
        // Fähigkeit, die es nicht gibt. Eine umbenannte Policy-Methode fiele
        // dann ausschließlich bei Kunden auf, und zwar im Betrieb.
        $this->assertFalse($this->accounts['admin']->can('vertippte-faehigkeit', $this->ownSubscription));
        $this->assertFalse($this->accounts['customer']->can('vertippte-faehigkeit', $this->ownSubscription));
    }

    private function useFeature(Account $account, Permission $permission): bool
    {
        return $account->can('useFeature', [$this->ownSubscription, $permission]);
    }
}
