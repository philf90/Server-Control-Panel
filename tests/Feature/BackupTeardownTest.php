<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Rückbau nimmt die Sicherungen **nicht** mit.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `Backups::removeAll()` stand von Schritt 3 bis zum 16. September 2026 da und
 * hatte **nie einen Aufrufer**. Sein Dokumentblock nannte den Rückbau als
 * seinen Ort — und genau dort darf er nicht laufen:
 * `backups.subscription_id` steht auf `nullOnDelete`, und der Kopf der
 * Migration sagt warum. *„Die Sicherung überlebt ihr Abonnement."*
 *
 * Seit Schritt 10 hängt ein Merkmal daran: Die Sicherung **vor** dem Rückbau
 * wäre die erste, die der Rückbau mitnähme. Ein Griff, der sichert und die
 * Sicherung im selben Zug löscht, ist schlimmer als keiner — er sieht aus wie
 * Vorsicht.
 *
 * > **Eine Methode, die niemand ruft, ist von aussen nicht von einer zu
 * > unterscheiden, die es nicht gibt — und eine, deren einziger denkbarer Ort
 * > ihr widerspricht, ist schlimmer als keine.**
 *
 * ## Gemessen an der Wirkung
 *
 * Nicht daran, dass die Methode fort ist — das wäre eine Zusage über den
 * Quelltext. Gefahren wird die **echte Route**, und danach zweierlei: dass
 * kein `backup.remove` über das ganze Verzeichnis eingereiht wurde, und dass
 * die Zeilen den `forceDelete()` überleben, mit dem `Lifecycle::withdraw()`
 * endet — ohne Abonnement und mit der Abschrift ihres Namens.
 *
 * ## Was er nicht hält
 *
 * Ob die **Datei** danach noch auf der Platte liegt, sagt nur ein Server:
 * `subscription.remove` läuft im Agenten, und dieser Test hat keinen. Gehalten
 * ist, dass das Panel nichts einreiht, was sie entfernen würde.
 */
final class BackupTeardownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Sicherung **vor** dem Rückbau überlebt den Rückbau.
     *
     * **Gemessen über den ganzen Weg und nicht über die Route allein.**
     * `destroy()` löscht nichts — es reiht `subscription.remove` ein, und
     * gelöscht wird erst, wenn der Agent geantwortet hat
     * ({@see Lifecycle}, zweite Grenze). Der
     * erste Wurf dieses Falls prüfte unmittelbar nach der Route und fand das
     * Abonnement noch vor.
     *
     * > **Ein Prüfkörper, der vor der Wirkung misst, misst den Klick und nicht
     * > den Zustand.**
     *
     * Der Rückbau selbst steht hier deshalb als `forceDelete()` da — genau die
     * Zeile, mit der `Lifecycle::withdraw()` endet.
     */
    public function test_the_backup_taken_before_a_teardown_survives_it(): void
    {
        $subscription = $this->subscription();
        $name = (string) $subscription->name;

        $alt = $this->backup($subscription, 'von-hand');

        $this->actingAs($this->admin())
            ->delete("/subscriptions/{$subscription->id}")
            ->assertRedirect();

        $sicherheit = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Backup => Backup::query()
                ->where('subscription_id', $subscription->id)
                ->where('storage_name', '!=', 'von-hand')
                ->first(),
        );

        $this->assertNotNull($sicherheit, implode("\n", [
            'Der Rückbau hat nicht vorher gesichert — dann misst dieser Fall nichts.',
            'Er hängt an Settings::backups()[before_removal], und der Prüfkörper schaltet es ein.',
        ]));

        // Was `Lifecycle::withdraw()` tut, nachdem der Agent geantwortet hat.
        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->forceDelete());

        foreach ([$alt->id => 'die von Hand angelegte', $sicherheit->id => 'die vor dem Rückbau angelegte'] as $id => $was) {
            $zeile = app(Tenancy::class)->withoutRestriction(
                static fn (): ?Backup => Backup::query()->find($id),
            );

            $this->assertNotNull($zeile, sprintf(
                "Der Rückbau hat %s Sicherung mitgenommen.\n".
                "backups.subscription_id steht auf nullOnDelete, damit sie ihn überlebt — und seit\n".
                'Schritt 10 legt der Rückbau selbst eine an, die es sonst als Erste träfe.',
                $was,
            ));

            $this->assertNull($zeile->subscription_id, 'Die Zeile hängt noch an ihrem Abonnement.');
            $this->assertSame($name, $zeile->subscription_name, 'Die Abschrift des Namens fehlt.');
        }
    }

    /**
     * Und kein Vorgang räumt das ganze Verzeichnis ab.
     *
     * **`backup.remove` ohne `storage` ist der Griff, der es täte.** Er bleibt
     * im Agenten — er ist der Weg zurück, den `docs/35` verlangt —, und
     * automatisch geht ihn niemand. Was liegenbleibt, meldet die
     * Bestandsdiagnose, statt es zu löschen.
     *
     * > **Ein Rest wird gemeldet und nicht gelöscht.**
     */
    public function test_no_operation_wipes_the_whole_directory(): void
    {
        $subscription = $this->subscription();
        $this->backup($subscription, 'eine');
        $this->backup($subscription, 'zwei');

        $this->actingAs($this->admin())->delete("/subscriptions/{$subscription->id}");

        $vorgaenge = app(Tenancy::class)->withoutRestriction(
            static fn (): array => Operation::query()->where('task', 'backup.remove')->get()->all(),
        );

        foreach ($vorgaenge as $vorgang) {
            $this->assertNotSame(
                '',
                (string) ($vorgang->payload['storage'] ?? ''),
                'Ein backup.remove ohne `storage` räumt das ganze Verzeichnis ab — samt der '.
                'Sicherung, die der Rückbau gerade erst angelegt hat.',
            );
        }

        /*
         * **Die Gegenprobe zur Behauptung darüber.** Ohne sie wäre die Schleife
         * auch dann grün, wenn gar kein `backup.remove` entstünde — und dann
         * misst sie nichts. Der Rückbau legt eine Sicherung an; abgeräumt wird
         * dabei nichts, also steht hier null.
         */
        $this->assertSame(0, count($vorgaenge), 'Der Rückbau räumt Sicherungen ab, statt sie zu behalten.');

        $angelegt = app(Tenancy::class)->withoutRestriction(
            static fn (): int => Operation::query()->where('task', 'backup.create')->count(),
        );

        $this->assertSame(1, $angelegt, 'Der Rückbau hat nicht vorher gesichert — dann misst dieser Fall nichts.');
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    /** Ein Abonnement mit Verzeichnis — ohne Systembenutzer sichert der Rückbau zu Recht nicht. */
    private function subscription(): Subscription
    {
        app(Settings::class)->saveBackups(automatic: false, beforeRemoval: true);

        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);

        $subscription = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);

        $this->assertNotNull($subscription->system_user, 'Der Prüfkörper hat kein Verzeichnis.');

        return $subscription;
    }

    private function backup(Subscription $subscription, string $name): Backup
    {
        return Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
            'storage_name' => $name,
            'status' => BackupStatus::Ready,
        ]);
    }
}
