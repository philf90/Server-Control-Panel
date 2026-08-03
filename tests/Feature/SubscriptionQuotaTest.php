<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Subscriptions\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kontingente eines Abonnements — übersteuern, anwenden, messen.
 *
 * **Die drei Stellen, an denen es still schiefgeht.**
 *
 * Erstens: Eine geänderte Speichergrenze, die in der Datenbank steht und nie
 * auf dem Dateisystem ankommt. Sie sieht in der Oberfläche richtig aus, und
 * das Abonnement schreibt trotzdem weiter bis zur alten Grenze.
 *
 * Zweitens: eine Übersteuerung, die entsteht, ohne dass jemand sie gesetzt
 * hat. Ein Abonnement, das jedes Kontingent übersteuert, hängt nicht mehr am
 * Plan — eine Planänderung erreicht es nie wieder, und niemand sucht den
 * Grund am Abonnement.
 *
 * Drittens: ein gemessener Verbrauch ohne Zeitpunkt. „412 MB" von vor drei
 * Tagen sieht aus wie „412 MB" von vor einer Minute.
 */
final class SubscriptionQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function subscription(SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        $plan = Plan::factory()->default()->create([
            'name' => 'Standard',
            'quotas' => ['disk_mb' => 5_120, 'databases' => 5, 'php_versions' => ['8.3', '8.4']],
        ]);

        return Subscription::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'plan_id' => $plan->id,
            'name' => 'kunde-example.de',
            'system_user' => 'p1000',
            'status' => $status,
            'quota_overrides' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(Subscription $subscription, array $overrides = []): array
    {
        return ['plan_id' => (int) $subscription->plan_id, 'overrides' => $overrides];
    }

    public function test_the_form_shows_the_plan_value_next_to_every_quota(): void
    {
        $subscription = $this->subscription();

        $response = $this->actingAs($this->admin())->get("/subscriptions/{$subscription->id}/edit");

        $response->assertOk();

        // Ohne den Planwert im Formular müsste jemand in einem zweiten Fenster
        // nachsehen, wovon er abweicht.
        $quotas = collect($response->viewData('page')['props']['quotas'])->keyBy('key');

        $this->assertSame('5.120 MB', $quotas[Quota::DiskMb->value]['plan_value']);
        $this->assertNull($quotas[Quota::DiskMb->value]['override']);
    }

    public function test_a_quota_that_is_not_sent_stays_with_the_plan(): void
    {
        Queue::fake();

        $subscription = $this->subscription();

        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['databases' => 20]))
            ->assertRedirect();

        $subscription->refresh();

        // Nur der eine Schlüssel — und nicht acht weitere mit Vorgabewerten.
        $this->assertSame(['databases' => 20], $subscription->quota_overrides);
        $this->assertFalse($subscription->quotaDiffersFromPlan(Quota::DiskMb->value));
        $this->assertSame(5_120, $subscription->quota(Quota::DiskMb->value));
    }

    public function test_no_override_at_all_is_stored_as_null(): void
    {
        Queue::fake();

        $subscription = $this->subscription();
        $subscription->forceFill(['quota_overrides' => ['databases' => 20]])->save();

        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription));

        // Nicht `[]`. Ein leeres Objekt in der Spalte sähe für jeden Leser aus
        // wie „hier war mal etwas" — und `array_key_exists` in
        // Subscription::quota() unterscheidet die beiden ohnehin nicht.
        $this->assertNull($subscription->refresh()->quota_overrides);
    }

    public function test_a_changed_disk_limit_becomes_an_operation(): void
    {
        Queue::fake();

        $subscription = $this->subscription();

        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 10_240]))
            ->assertRedirect();

        $operation = Operation::query()->firstOrFail();

        // `subscription.quota` und nicht `subscription.provision`: Provision
        // rückt die Rechte der Chroot-Wurzel gerade und höbe damit eine
        // bestehende Sperre auf, ohne dass es irgendwo stünde.
        $this->assertSame('subscription.quota', $operation->type);
        $this->assertSame(10_240, ($operation->payload ?? [])['quota_mb'] ?? null);
        $this->assertSame('p1000', ($operation->payload ?? [])['user'] ?? null);

        Queue::assertPushed(RunAgentOperation::class);
    }

    public function test_an_unchanged_disk_limit_becomes_no_operation(): void
    {
        Queue::fake();

        $subscription = $this->subscription();

        // Eine Übersteuerung auf denselben Wert, den der Plan ohnehin sagt.
        // Am System ändert sich nichts — ein Vorgang dafür wäre eine Zeile im
        // Protokoll über einen Server, der gleich bleibt.
        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 5_120]))
            ->assertRedirect("/subscriptions/{$subscription->id}");

        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(['disk_mb' => 5_120], $subscription->refresh()->quota_overrides);
    }

    public function test_a_suspended_subscription_still_gets_its_new_limit_applied(): void
    {
        Queue::fake();

        /*
         * Die Stelle, an der `usable()` die falsche Frage war. Ein gesperrtes
         * Abonnement ist nicht benutzbar, hat aber weiterhin einen
         * Systembenutzer und eine Quota. Ohne Vorgang stünde die neue Grenze
         * in der Datenbank und käme nie an: Das Entsperren setzt keine Quota,
         * und einen zweiten Anlass gäbe es nicht.
         */
        $subscription = $this->subscription(SubscriptionStatus::Suspended);

        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 1_024]));

        $this->assertSame('subscription.quota', Operation::query()->firstOrFail()->type);
    }

    public function test_a_subscription_being_provisioned_gets_no_operation(): void
    {
        Queue::fake();

        // Es gibt noch kein Konto, dem eine Quota gälte — `subscription.provision`
        // setzt sie in wenigen Sekunden ohnehin, und zwar aus derselben Zeile.
        $subscription = $this->subscription(SubscriptionStatus::Provisioning);

        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 1_024]))
            ->assertRedirect("/subscriptions/{$subscription->id}");

        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(1_024, $subscription->refresh()->quota(Quota::DiskMb->value));
    }

    public function test_a_disk_limit_below_the_minimum_is_refused(): void
    {
        $subscription = $this->subscription();

        // 64 MB ist das Minimum aus dem Katalog. Darunter liesse sich das
        // Verzeichnisschema nicht anlegen.
        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 1]))
            ->assertSessionHasErrors('overrides.disk_mb');

        $this->assertNull($subscription->refresh()->quota_overrides);
    }

    public function test_an_unknown_php_version_is_refused(): void
    {
        $subscription = $this->subscription();

        // Für jede PHP-Version braucht es eine FPM-Vorlage, ein Paket und
        // einen Handler. „8.9" gibt es nicht, und ein Abonnement, das sie
        // vorgibt, ergäbe einen vhost, den nginx nicht laden kann.
        $this->actingAs($this->admin())
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['php_versions' => ['8.9']]))
            ->assertSessionHasErrors('overrides.php_versions.0');
    }

    public function test_a_customer_may_not_change_the_quotas_of_the_own_subscription(): void
    {
        $subscription = $this->subscription();

        $account = Account::factory()->additional($subscription->customer)->create();
        $account->assignedSubscriptions()->attach($subscription->id, [
            'permissions' => json_encode(['files_read' => true]),
        ]);

        // Die Grenzen sind der Vertrag. Ein Kunde sieht sie an seinem
        // Abonnement; wer sie ändern darf, ist der Betreiber.
        $this->actingAs($account)->get("/subscriptions/{$subscription->id}/edit")->assertForbidden();
        $this->actingAs($account)
            ->patch("/subscriptions/{$subscription->id}", $this->form($subscription, ['disk_mb' => 1_000_000]))
            ->assertForbidden();
    }

    public function test_the_measurement_writes_the_used_space_and_the_time(): void
    {
        $subscription = $this->subscription();

        $result = app(Usage::class)->apply([
            'available' => true,
            'device' => '/dev/sda1',
            'users' => ['p1000' => ['used_mb' => 412, 'limit_mb' => 5_120]],
        ]);

        $this->assertSame(['measured' => 1, 'available' => true], $result);

        $subscription->refresh();

        $this->assertSame(412, (int) $subscription->disk_used_mb);
        $this->assertNotNull($subscription->disk_usage_measured_at);
        $this->assertSame(8.0, $subscription->diskUsagePercent());
    }

    public function test_a_subscription_missing_from_the_measurement_counts_as_empty(): void
    {
        // Die Quota-Datei kennt einen Benutzer erst, wenn ihm ein Block
        // gehört. Ein frisch angelegtes Abonnement fehlt dort — das ist eine
        // gemessene Null und keine fehlende Messung.
        $subscription = $this->subscription();

        app(Usage::class)->apply(['available' => true, 'device' => '/dev/sda1', 'users' => []]);

        $this->assertSame(0, (int) $subscription->refresh()->disk_used_mb);
        $this->assertNotNull($subscription->disk_usage_measured_at);
    }

    public function test_without_filesystem_quota_no_value_is_overwritten(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill(['disk_used_mb' => 412, 'disk_usage_measured_at' => now()->subDay()])->save();

        $result = app(Usage::class)->apply(['available' => false, 'reason' => 'kein Mount gefunden', 'users' => []]);

        $this->assertFalse($result['available']);

        // **Nicht auf null zurückgesetzt.** Ohne Quota-Unterstützung weiss das
        // Panel nichts Neues, und „nichts Neues" ist kein Grund, eine Messung
        // von gestern zu verwerfen. Wie alt sie ist, steht daneben.
        $this->assertSame(412, (int) $subscription->refresh()->disk_used_mb);
    }

    public function test_the_usage_is_not_fillable(): void
    {
        $subscription = $this->subscription();

        // Gemessen und nicht eingegeben. Stünde die Spalte in `$fillable`,
        // wäre ein Formularfeld genug, um den Verbrauch herbeizuschreiben —
        // und ein Kunde an der Grenze meldete sich nie wieder.
        $subscription->update(['disk_used_mb' => 999_999]);

        $this->assertNull($subscription->refresh()->disk_used_mb);
    }

    public function test_the_percentage_needs_both_numbers(): void
    {
        $subscription = $this->subscription();

        // Ohne Messung gibt es nichts ins Verhältnis zu setzen. Das ist etwas
        // anderes als „0 %", und die Oberfläche muss den Unterschied zeigen.
        $this->assertNull($subscription->diskUsagePercent());

        $subscription->forceFill(['disk_used_mb' => 6_000])->save();

        // Über der Grenze wird nicht gedeckelt: 117,2 % ist die Wahrheit, und
        // ein auf 100 gekappter Wert wäre ausgerechnet dann beruhigend, wenn
        // er es nicht sein darf.
        $this->assertSame(117.2, $subscription->diskUsagePercent());
    }
}
