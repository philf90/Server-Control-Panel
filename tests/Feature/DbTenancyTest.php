<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein Kunde sieht die Datenbanken eines fremden Abonnements nicht.
 *
 * **Die Hälfte des Abnahmekriteriums, die im Panel spielt.** Der Plan verlangt:
 * *„ein Datenbankbenutzer sieht nachweislich keine fremde Datenbank."* Das ist
 * die Seite von MariaDB, und dafür gibt es `GrantPatternTest`, `DbIsolationTest`
 * und den Abnahmelauf am echten Server. Die andere Seite ist das Panel: Ein
 * Kunde, der eine fremde Datenbank-Adresse eintippt, darf sie nicht bekommen.
 *
 * **Diesen Test sah `docs/36 §16.7` seit dem Plan vor, und geschrieben war er
 * nie.** Aufgefallen am 8. August 2026, als `GuardReachTest` entstand — der
 * Name stand in einem Kommentar, die Datei gab es nicht. Die Regel selbst hat
 * die ganze Zeit gegolten (`BelongsToSubscription` am Modell, `can:` an jeder
 * Route); ungeprüft war, ob sie hält.
 *
 * Geprüft wird beides, wie bei {@see DomainTenancyTest}: dass die Klammer
 * greift, **und** dass sie nicht mehr wegnimmt, als sie soll.
 */
final class DbTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    /**
     * Ein Kunde mit einem Abonnement, und ein fremdes daneben.
     *
     * @return array{0: Account, 1: Subscription, 2: Subscription}
     */
    private function neighbours(): array
    {
        [$own, $foreign] = $this->tenancy()->withoutRestriction(fn (): array => [
            Subscription::factory()->create(['system_user' => 'p1000']),
            Subscription::factory()->create(['system_user' => 'p1001']),
        ]);

        $customer = Customer::query()->findOrFail($own->customer_id);
        $account = Account::factory()->customer($customer)->create();

        return [$account, $own, $foreign];
    }

    public function test_without_a_tenant_no_database_is_visible(): void
    {
        $this->tenancy()->withoutRestriction(function (): void {
            Database::factory()->count(2)->create();
            DbUser::factory()->count(2)->create();
            DatabaseDump::factory()->count(2)->create();
        });

        // Der Grundzustand der Klammer ist „nichts" (CLAUDE.md, dritte Grenze).
        $this->assertSame(0, Database::query()->count());
        $this->assertSame(0, DbUser::query()->count());
        $this->assertSame(0, DatabaseDump::query()->count());
    }

    public function test_a_tenant_sees_only_what_belongs_to_its_own_subscription(): void
    {
        [, $own, $foreign] = $this->neighbours();

        $this->tenancy()->withoutRestriction(function () use ($own, $foreign): void {
            Database::factory()->forSubscription($own, 'shop')->create();
            Database::factory()->forSubscription($own, 'blog')->create();
            Database::factory()->forSubscription($foreign, 'shop')->create();
        });

        $this->tenancy()->restrictTo([(int) $own->id]);

        $this->assertSame(2, Database::query()->count());

        // Und die Gegenrichtung: nicht mehr wegnehmen als nötig. Eine Klammer,
        // die auch die eigenen Zeilen verbirgt, sähe im ersten Test genauso
        // richtig aus.
        $this->assertSame(
            ['p1000_blog', 'p1000_shop'],
            Database::query()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Und über die Adresse kommt er auch nicht daran.
     *
     * Die Klammer ist das eine, die Policy an der Route das andere. Wer nur die
     * Klammer prüft, prüft die Liste — und die Einzelseite bekommt ihre Zeile
     * über die Modellbindung.
     */
    public function test_a_customer_does_not_reach_a_foreign_database_by_its_address(): void
    {
        [$account, , $foreign] = $this->neighbours();

        $database = $this->tenancy()->withoutRestriction(
            fn (): Database => Database::factory()->forSubscription($foreign, 'shop')->create(),
        );

        $this->actingAs($account)->get("/databases/{$database->id}")->assertNotFound();
    }

    /** Auch nicht, um sie zu sichern oder zu entfernen. */
    public function test_a_customer_does_not_act_on_a_foreign_database(): void
    {
        [$account, , $foreign] = $this->neighbours();

        $database = $this->tenancy()->withoutRestriction(
            fn (): Database => Database::factory()->forSubscription($foreign, 'shop')->create(),
        );

        $this->actingAs($account)->post("/databases/{$database->id}/dumps")->assertNotFound();
        $this->actingAs($account)->post("/databases/{$database->id}/users", ['label' => 'web'])->assertNotFound();
        $this->actingAs($account)->delete("/databases/{$database->id}")->assertNotFound();
    }

    /**
     * Und eine fremde Sicherung lädt er auch nicht herunter.
     *
     * Der Weg, der am 8. August mit 404 antwortete, weil das Panel an den Pfad
     * nicht herankam (§22.3l) — hier muss dieselbe 404 aus dem richtigen Grund
     * kommen, und zwar bevor irgendetwas eine Datei sucht.
     */
    public function test_a_customer_does_not_download_a_foreign_dump(): void
    {
        [$account, , $foreign] = $this->neighbours();

        [$database, $dump] = $this->tenancy()->withoutRestriction(function () use ($foreign): array {
            $database = Database::factory()->forSubscription($foreign, 'shop')->create();

            return [$database, DatabaseDump::factory()->create([
                'subscription_id' => $foreign->id,
                'database_id' => $database->id,
                'database_name' => $database->name,
            ])];
        });

        $this->actingAs($account)
            ->get("/databases/{$database->id}/dumps/{$dump->id}")
            ->assertNotFound();
    }
}
