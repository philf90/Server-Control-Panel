<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\BackupLifecycle;
use App\Support\Backups\Backups;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WithoutMarkupComments;
use Tests\TestCase;

/**
 * Das Entfernen einer Sicherung hat einen Zustand, und beide Listen zeigen ihn.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Befund 4 des Nachlaufs zu P8 (`docs/121 §9`), gemeldet vom Betreiber beim
 * Benutzen: *„/backups aktualisiert sich nicht automatisch wenn das Backup
 * entfernt wurde. Es wird auch nicht auf die entsprechende operation
 * umgeleitet."*
 *
 * Ausgezählt war es zweierlei. `BackupPick.vue` hatte **gar keinen** Takt, und
 * `Subscriptions/Backups.vue` hatte einen, der an `status === 'pending'` hing —
 * `Backups::remove()` änderte den Zustand der Zeile aber **nicht**. Das Anlegen
 * war damit verfolgt und das Entfernen nicht.
 *
 * > **Ein Vorgang ohne Zustand in seiner Zeile ist von einem, den niemand
 * > ausgelöst hat, nicht zu unterscheiden.**
 *
 * ## Warum ein Zustand und kein Merker auf der Seite
 *
 * Ein Merker, den der Klick setzt, wüsste nichts von einer Entfernung aus dem
 * nächtlichen Lauf der Aufbewahrung oder aus einem zweiten Reiter — dieselbe
 * Begründung, aus der `Backups.vue` seinen Takt seit dem 17. September an die
 * Zeilen hängt.
 *
 * ## Und warum `running` und nicht zwei Vergleiche
 *
 * Zwei Listen zeigen Sicherungen, und beide brauchen denselben Takt. Zwei
 * Bedingungen über dieselben Zustandsnamen wären zwei Fassungen derselben
 * Regel, und die zweite ist die, die veraltet.
 * {@see BackupStatus::running()} ist die eine Stelle; die Seiten bekommen die
 * **Antwort** und nicht die Aufzählung.
 *
 * ## Was er nicht hält
 *
 * Ob der Takt wirklich feuert — das entscheidet der Browser. Gehalten ist, dass
 * die Bedingung an der richtigen Grösse hängt und dass beide Seiten sie
 * bekommen.
 */
final class BackupRemovalStateTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMarkupComments;

    /**
     * Die Seiten, die eine Liste von Sicherungen zeigen.
     *
     * **Aus dem Bestand und nicht aus einer Liste hier**: gesucht wird nach
     * `const laeuft`, und der Name kommt in genau diesen beiden Dateien vor.
     * Käme eine dritte Liste dazu, fiele sie dem Wächter zu — eine Aufzählung
     * hier täte das nicht.
     */
    private const MINDESTENS = 2;

    public function test_a_removal_marks_its_row(): void
    {
        /*
         * **Ohne das misst dieser Fall das Ende und nicht den Zustand.**
         *
         * Die Warteschlange steht im Prüfstand auf `sync`: `remove()` reiht den
         * Vorgang ein, der Auftrag läuft **in derselben Zeile**, der Agent
         * antwortet nicht — und `afterFailure()` setzt die Zeile zurück auf
         * `Ready`. Gemessen wurde dann das richtige Ergebnis eines ganzen
         * Umlaufs und nicht der Zustand dazwischen, um den es hier geht.
         *
         * > **Ein `show` unmittelbar nach einem `set` misst den Übergang und
         * > nicht den Zustand** — hier andersherum: ohne das Anhalten misst der
         * > Prüfkörper den Zustand danach und nicht den Übergang.
         */
        Queue::fake();

        $subscription = $this->subscription();
        $backup = $this->backup($subscription);

        app(Backups::class)->remove($backup);

        $this->assertSame(
            BackupStatus::Removing,
            $backup->fresh()?->status,
            'Die Zeile steht während des Entfernens auf demselben Zustand wie davor.',
        );
    }

    /**
     * Und sie kommt zurück, wenn es schiefgeht.
     *
     * **Der Fall, der aus dem Zustand sonst eine Sackgasse macht.** Die Datei
     * liegt dann noch da; bliebe die Zeile auf „wird entfernt", fragte die
     * Seite endlos nach, der Knopf wäre fort, und ein zweiter Versuch ginge
     * nicht mehr.
     *
     * > **Ein Zustand, der nur beim Gelingen wieder verlassen wird, ist beim
     * > Fehlschlag eine Sackgasse.**
     */
    public function test_a_failed_removal_puts_the_row_back(): void
    {
        $subscription = $this->subscription();
        $backup = $this->backup($subscription);
        $backup->forceFill(['status' => BackupStatus::Removing])->save();

        app(BackupLifecycle::class)->afterFailure($this->removalOperation($backup, OperationStatus::Failed));

        $frisch = $backup->fresh();

        // **Die Zeile muss noch da sein**, und das ist keine Formalität: Ein
        // Fehlschlag beim Entfernen lässt die Datei liegen, also auch ihre
        // Zeile. Wäre sie fort, ginge der Rest des Falls auf `null` und
        // meldete etwas anderes, als er misst.
        $this->assertNotNull($frisch, 'Die Zeile ist beim Fehlschlag verschwunden.');

        $this->assertSame(BackupStatus::Ready, $frisch->status, 'Die Zeile steckt auf „wird entfernt" fest.');
        $this->assertNotNull($frisch->last_error, 'Der Fehlschlag steht nirgends.');
    }

    /**
     * Eine verwaiste Zeile bleibt während des Entfernens in der Liste.
     *
     * **Ohne das wäre die ganze Behebung wirkungslos.** Fiele sie im Augenblick
     * des Klicks aus der Abfrage, verschwände sie, bevor der Agent geantwortet
     * hat — und die Seite zeigte dasselbe wie vorher: nichts.
     */
    public function test_a_row_being_removed_stays_in_the_orphan_list(): void
    {
        $verwaist = Backup::query()->create([
            'subscription_id' => null,
            'subscription_name' => 'abgebaut.invalid',
            'storage_name' => 'abgebaut-invalid-20260918-120000-aaaabbbb',
            'status' => BackupStatus::Removing,
        ]);

        $liste = app(Backups::class)->orphaned();

        $this->assertTrue(
            $liste->contains(static fn (Backup $b): bool => $b->id === $verwaist->id),
            'Die Zeile ist aus der Liste verschwunden, bevor der Agent geantwortet hat.',
        );
    }

    /**
     * Und die Gegenrichtung, ohne die die erste nichts belegt.
     *
     * `Pending` und `Failed` gehören **nicht** in die Liste der verwaisten: Eine
     * verwaiste Sicherung entsteht nur aus einer fertigen, und `Failed`
     * beschreibt eine Datei, von der niemand weiss, wie weit sie kam. Stünde
     * dort `whereNotNull('status')`, wäre der Fall darüber auch grün.
     */
    public function test_the_orphan_list_still_leaves_out_the_others(): void
    {
        foreach ([BackupStatus::Pending, BackupStatus::Failed] as $zustand) {
            Backup::query()->create([
                'subscription_id' => null,
                'subscription_name' => 'abgebaut.invalid',
                'storage_name' => 'abgebaut-invalid-'.$zustand->value,
                'status' => $zustand,
            ]);
        }

        $this->assertCount(
            0,
            app(Backups::class)->orphaned(),
            'Die Liste der verwaisten Sicherungen zeigt Zustände, die keine sind.',
        );
    }

    /** Aus einer Zeile, die gerade verschwindet, spielt niemand zurück. */
    public function test_a_row_being_removed_is_not_usable(): void
    {
        $this->assertFalse(BackupStatus::Removing->usable());
        $this->assertTrue(BackupStatus::Ready->usable());
    }

    /**
     * Beide Listen bekommen die Antwort — gemessen durch die Tür.
     *
     * Nicht daran, dass `'running' =>` im Steuerungscode steht: Das wäre eine
     * Zusage über den Quelltext. Gefahren werden die echten Routen.
     */
    public function test_both_lists_carry_the_running_answer(): void
    {
        $admin = $this->admin();
        $subscription = $this->subscription();
        $backup = $this->backup($subscription);

        $this->actingAs($admin)
            ->get("/subscriptions/{$subscription->id}/backups")
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->where('backups.0.id', $backup->id)
                ->where('backups.0.running', false));

        $verwaist = Backup::query()->create([
            'subscription_id' => null,
            'subscription_name' => 'abgebaut.invalid',
            'storage_name' => 'abgebaut-invalid-20260918-120000-aaaabbbb',
            'status' => BackupStatus::Removing,
        ]);

        $this->actingAs($admin)
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->where('orphaned.0.id', $verwaist->id)
                ->where('orphaned.0.running', true)
                ->where('orphaned.0.status_label', BackupStatus::Removing->label()));
    }

    /**
     * Und beide Seiten hängen ihren Takt an dieser Antwort.
     *
     * **Die Gegenrichtung ist die wertvollere**: Nicht, dass `running`
     * irgendwo vorkommt, sondern dass die Bedingung des Taktes **keinen**
     * Zustandsnamen vergleicht. Genau so stand sie bis zum 18. September da —
     * `status === 'pending'` —, und damit war das Entfernen nicht verfolgt.
     */
    public function test_both_pages_hang_their_takt_on_that_answer(): void
    {
        $gefunden = 0;
        $falsch = [];

        foreach ($this->seiten() as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));

            if (preg_match('/^const laeuft = .*$/m', $quelle, $treffer) !== 1) {
                continue;
            }

            $gefunden++;
            $zeile = $treffer[0];
            $name = basename($pfad);

            if (! str_contains($zeile, '.running')) {
                $falsch[] = $name.': fragt nicht nach `running`';
            }

            if (str_contains($zeile, 'status')) {
                $falsch[] = $name.': vergleicht einen Zustandsnamen statt der Antwort';
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINDESTENS,
            $gefunden,
            'Es wird kaum eine Liste gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $falsch, sprintf(
            "Diese Listen entscheiden ihren Takt an der falschen Grösse:\n  %s\n\n"
            .'Zwei Bedingungen über dieselben Zustandsnamen sind zwei Fassungen derselben Regel. '
            .'`BackupStatus::running()` ist die eine Stelle; die Seite bekommt die Antwort.',
            implode("\n  ", $falsch),
        ));
    }

    /** @return list<string> */
    private function seiten(): array
    {
        return array_values(array_filter(
            (array) glob(base_path('resources/js/Pages/Subscriptions/*.vue')),
            static fn (mixed $pfad): bool => is_string($pfad)
                && str_contains((string) file_get_contents($pfad), 'const laeuft'),
        ));
    }

    private function removalOperation(Backup $backup, OperationStatus $status): Operation
    {
        return app(Tenancy::class)->withoutRestriction(static fn (): Operation => Operation::query()->create([
            'subscription_id' => $backup->subscription_id,
            'subject_type' => OperationSubject::Backup->value,
            'subject_id' => $backup->id,
            'type' => 'backup.remove',
            'task' => 'backup.remove',
            'payload' => [
                'subscription' => (string) $backup->subscription_name,
                'storage' => (string) $backup->storage_name,
            ],
            'status' => $status,
            'progress' => 100,
            'message' => 'Der Agent hat abgelehnt.',
        ]));
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    private function subscription(): Subscription
    {
        app(Settings::class)->saveBackups(automatic: false, beforeRemoval: false);

        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);

        return Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);
    }

    private function backup(Subscription $subscription): Backup
    {
        return Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
            'storage_name' => $subscription->name.'-20260918-120000-ccccdddd',
            'status' => BackupStatus::Ready,
        ]);
    }
}
