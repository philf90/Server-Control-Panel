<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die fertige Sicherung bekommt der Betreiber und der Kunde — nicht jeder Admin.
 *
 * ## Warum diese eine Grenze enger ist als die daneben
 *
 * Seit P8 trägt eine Sicherung den privaten Schlüssel eines *hochgeladenen*
 * Zertifikats. `manageBackups` löst über `useFeature()` auf, und das gibt bei
 * `isAdmin()` sofort durch — `isAdmin()` fragt den **Typ** und nicht die Rolle.
 * Jeder Administrator hätte damit den Schlüssel jedes Kunden bekommen.
 *
 * > **Ein Ablageort, der ein Geheimnis vor dem Dateisystem schützt, sagt nichts
 * > darüber, wer den Knopf drücken darf, der es herausgibt.**
 *
 * `docs/117 §4` hat den Schlüssel gegen das Dateisystem abgewogen und gegen den
 * Kunden. Die Rolle aus A9 kam darin nicht vor.
 *
 * ## Gemessen wird „nicht 403" und nicht „200"
 *
 * Die Route gibt 404, wenn die Datei nicht liegt — und in einem Test liegt sie
 * nicht. Gefragt ist hier die **Tür** und nicht der Agent dahinter; ein `200`
 * zu verlangen hiesse, den Ablageort mitzuprüfen. Dieselbe Form wie in
 * {@see InspectOnlyTest}.
 *
 * ## Und genau ein Griff wird enger
 *
 * Liste, Anlegen und Entfernen bleiben beim Administrator: Sie geben kein
 * Schlüsselmaterial heraus. Der Fall daneben misst das mit — sonst wäre eine
 * Verengung, die zu viel mitnimmt, von der gewollten nicht zu unterscheiden.
 */
final class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    /** Der Administrator kommt an die Datei nicht heran. */
    public function test_an_administrator_is_refused_the_file(): void
    {
        [$subscription, $backup] = $this->backup();

        $this->actingAs(Account::factory()->administrator()->create())
            ->get($this->url($subscription, $backup))
            ->assertForbidden();
    }

    /** Der Betreiber schon — gemessen als „nicht 403". */
    public function test_the_operator_passes_the_door(): void
    {
        [$subscription, $backup] = $this->backup();

        $this->actingAs(Account::factory()->admin()->create())
            ->get($this->url($subscription, $backup))
            ->assertStatus(404);
    }

    /**
     * Und der Kunde des Abonnements — es ist sein Schlüssel.
     *
     * **Der Prüfkörper braucht dafür mehr als ein Konto**: Der Plan muss die
     * Funktion freigeben und das Konto das Recht tragen, sonst weist
     * `useFeature()` schon davor ab — und der Fall bestünde, ohne die
     * Rollenfrage je erreicht zu haben.
     */
    public function test_the_customer_of_the_subscription_passes_the_door(): void
    {
        [$subscription, $backup] = $this->backup();

        $konto = Account::factory()->customer($subscription->customer)->create();

        $this->assertTrue(
            $konto->mayAccessSubscription($subscription),
            'Der Prüfkörper erreicht sein eigenes Abonnement nicht — dann misst dieser Fall nichts.',
        );

        $this->actingAs($konto)
            ->get($this->url($subscription, $backup))
            ->assertStatus(404);
    }

    /**
     * Ein fremder Kunde nicht — und zwar **vor** der Policy.
     *
     * **Gemessen und berichtigt:** Er bekommt `404` und nicht `403`. Die
     * Mandantenklammer antwortet zuerst; für ihn gibt es das Abonnement gar
     * nicht, und die Bindung der Route scheitert, bevor eine Policy gefragt
     * wird. Das ist die bessere der beiden Auskünfte — ein `403` verriete, dass
     * es das Abonnement gibt.
     *
     * **Damit misst dieser Fall die Klammer und nicht die Verengung**, und er
     * sagt es: Ein `404` allein wäre von dem des Betreibers nicht zu
     * unterscheiden, dem nur die Datei fehlt. Getrennt werden die beiden an der
     * **Liste** — der Betreiber sieht sie, der Fremde nicht.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     */
    public function test_a_foreign_customer_never_reaches_the_subscription(): void
    {
        [$subscription, $backup] = $this->backup();

        $fremder = Account::factory()->customer(Customer::factory()->create())->create();

        $this->actingAs($fremder)
            ->get($this->url($subscription, $backup))
            ->assertStatus(404);

        // Die Gegenprobe, die diesen Fall von dem des Betreibers trennt.
        $this->actingAs($fremder)
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertStatus(404);

        $this->actingAs(Account::factory()->admin()->create())
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertSuccessful();
    }

    /**
     * **Und nur dieser eine Griff wird enger.**
     *
     * Die Liste zeigt Namen und Grössen, das Anlegen und das Entfernen geben
     * nichts heraus. Bliebe der Administrator auch dort draussen, wäre die
     * Verengung eine andere als die gewollte — und niemand sähe es.
     */
    public function test_the_administrator_keeps_the_rest(): void
    {
        [$subscription] = $this->backup();

        $this->actingAs(Account::factory()->administrator()->create())
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertSuccessful();
    }

    /**
     * Der Knopf hängt an derselben Frage wie die Route.
     *
     * `AbilityReachTest` besteht darauf, dass ein Knopf, den der Betrachter
     * nicht drücken darf, gar nicht gezeigt wird. Gemessen an der Ablage, aus
     * der die Seite ihn baut.
     */
    public function test_the_page_tells_the_button_which_way_to_go(): void
    {
        [$subscription] = $this->backup();

        $this->actingAs(Account::factory()->administrator()->create())
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertInertia(fn ($page) => $page->where('can.download', false));

        $this->actingAs(Account::factory()->admin()->create())
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertInertia(fn ($page) => $page->where('can.download', true));
    }

    private function url(Subscription $subscription, Backup $backup): string
    {
        return "/subscriptions/{$subscription->id}/backups/{$backup->id}/download";
    }

    /** @return array{0: Subscription, 1: Backup} */
    private function backup(): array
    {
        $plan = Plan::factory()->create([
            'quotas' => [Quota::Backups->value => 3],
            'features' => ['backups' => true],
        ]);

        $subscription = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);

        $backup = Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
            'storage_name' => 'stand',
            'status' => BackupStatus::Ready,
        ]);

        return [$subscription, $backup];
    }
}
