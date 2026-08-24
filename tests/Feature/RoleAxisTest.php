<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Rolle allein gewährt nichts — gemessen am Model.
 *
 * ## Warum dieser Test neben `AdminRoleTest` steht
 *
 * `AdminRoleTest` hält dieselbe Regel im Quelltext: `isOperator()` und
 * `fulfils()` müssen die Mandantenachse mitfragen. Das ist eine Prüfung über
 * die **Erreichbarkeit** und keine über die **Wirkung** — und sie steht dort,
 * weil sie ohne Framework läuft, also auch dort, wo `vendor/` fehlt.
 *
 * Hier steht die Wirkung: ein echtes Konto, eine echte Frage, eine echte
 * Antwort.
 *
 * > **Zwei Wächter für eine Regel sind keine Verdopplung, wenn der eine die
 * > Wirkung misst und der andere sie dort hält, wo die Wirkung nicht messbar
 * > ist.**
 *
 * ## Die Richtung, in die ein Fehler hier fällt
 *
 * Fiele die **Rollenfrage** weg, wäre jeder Admin Betreiber — das fiele beim
 * ersten Blick auf die Kontenliste auf. Fällt die **Ebenenfrage** weg, genügt
 * ein Kundenkonto mit `role = 'operator'`, und das fällt niemandem auf, weil es
 * dort normalerweise nicht steht.
 *
 * > **Ein Fehler, der eine Vollmacht erzeugt, muss nicht wahrscheinlich sein,
 * > um teuer zu werden.**
 */
final class RoleAxisTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_with_the_operator_role_is_an_operator(): void
    {
        $account = Account::factory()->create([
            'type' => AccountType::Admin,
            'role' => AdminRole::Operator,
        ]);

        $this->assertTrue($account->isOperator());
        $this->assertTrue($account->fulfils(AdminRole::Operator));
        $this->assertTrue($account->fulfils(AdminRole::Administrator));
    }

    public function test_an_admin_with_the_administrator_role_is_not_an_operator(): void
    {
        $account = Account::factory()->create([
            'type' => AccountType::Admin,
            'role' => AdminRole::Administrator,
        ]);

        $this->assertFalse($account->isOperator());
        $this->assertFalse($account->fulfils(AdminRole::Operator));
        $this->assertTrue($account->fulfils(AdminRole::Administrator));
    }

    /**
     * **Der Fall, um den es geht.** Ein Kundenkonto mit der Rolle eines
     * Betreibers — durch einen Fehler, eine Migration von Hand, einen
     * versehentlichen Massenupdate — ist trotzdem keiner.
     */
    public function test_a_customer_account_with_an_operator_role_is_not_an_operator(): void
    {
        $customer = Customer::factory()->create();

        $account = Account::factory()->create([
            'type' => AccountType::Customer,
            'customer_id' => $customer->id,
            'role' => AdminRole::Operator,
        ]);

        $this->assertFalse($account->isOperator(), 'Die Rolle allein hat eine Vollmacht erzeugt.');
        $this->assertFalse($account->fulfils(AdminRole::Operator));
        $this->assertFalse($account->fulfils(AdminRole::Administrator));
    }

    /**
     * Ein Adminkonto ohne Rolle genügt keiner.
     *
     * Das ist der Zustand nach einem Update, dessen Migration noch nicht
     * gelaufen ist. **Die sichere Richtung ist die Ablehnung** — eine stille
     * Vollmacht für jeden Admin wäre die andere.
     */
    public function test_an_admin_without_a_role_fulfils_nothing(): void
    {
        $account = Account::factory()->create([
            'type' => AccountType::Admin,
            'role' => null,
        ]);

        $this->assertTrue($account->isAdmin(), 'isAdmin() bedeutet unverändert „kein Kunde".');
        $this->assertFalse($account->isOperator());
        $this->assertFalse($account->fulfils(AdminRole::Administrator));
    }

    /**
     * `isAdmin()` bleibt, was es war.
     *
     * An 52 Stellen heisst es „kein Kunde" — die Mandantenfrage. Wer es zu „ist
     * Betreiber" umdeutet, nimmt dem Administrator die Kundenverwaltung, also
     * genau die Arbeit, für die es ihn gibt (`docs/82 §5.2`).
     */
    public function test_is_admin_still_means_not_a_customer(): void
    {
        foreach ([AdminRole::Operator, AdminRole::Administrator] as $role) {
            $account = Account::factory()->create(['type' => AccountType::Admin, 'role' => $role]);

            $this->assertTrue($account->isAdmin(), $role->value.' gilt nicht mehr als Admin.');
        }
    }
}
