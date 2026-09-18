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
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Jeder Sicherungsvorgang sagt, wer ihn ausgelöst hat.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `Backups::dispatch()` hat `account_id` bis zum 18. September 2026 **nirgends**
 * gesetzt. Gemessen auf `cloudsrv24` sagte die Vorgangsseite einer von Hand
 * gedrückten Sicherung „Ausgelöst von: System", während `backup.restore` in
 * derselben Stunde und von derselben Person „Administrator" sagte
 * (`docs/121 §9`, Befund 3). Ausgezählt nennt `grep -rn "'account_id' =>" app/`
 * vierzehn Stellen; `Backups.php` stand nicht darunter.
 *
 * **Der Befund ist nicht die leere Spalte, sondern ihre Bedeutung.** Seit
 * `docs/901` heisst `account_id = NULL` **Kommandozeile oder Automatik** —
 * `App\Console\Commands\Access` schreibt seinen Eintrag so, und
 * `Operations::dispatch()` tut es für jede Automatik. Der nächtliche
 * Sicherungslauf ist genau dieser Fall und soll `System` heissen.
 *
 * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen —
 * > die beiden Fälle sehen danach gleich aus.**
 *
 * ## Gemessen an der Wirkung und durch die Tür
 *
 * Nicht daran, dass die Zeile `'account_id' =>` im Quelltext steht — das wäre
 * eine Zusage über den Text. Gefahren werden die **echten Routen**, und die
 * Kennung wird auf dem Vorgang nachgelesen, den sie eingereiht haben.
 *
 * **Beide Richtungen stehen nebeneinander**, und die zweite ist die, an der
 * eine bequeme Behebung scheitert: Wer `request()->user()` einsetzt und den
 * Durchreichweg weglässt, macht den nächtlichen Lauf nicht kaputt — der hat
 * ohnehin keinen Request — aber das **Abräumen** des Verzeichnisses. Das läuft
 * im Arbeiter und ist trotzdem die Folge eines Klicks.
 *
 * ## Was er nicht hält
 *
 * `backup.verify` steht **nicht** darunter, und das ist kein Loch: Die
 * Bestandsdiagnose ruft es über `Agent::call()` unmittelbar und legt dafür
 * keinen Vorgang an. Wo kein Vorgang ist, ist auch keine Kennung zu verlieren.
 */
final class BackupActorTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

    /**
     * Wie viele Wege durch {@see Backups::dispatch()} dieser Wächter fährt.
     *
     * **Die Untergrenze, und sie ist der Sinn der Zahl.** Kommt ein fünfter
     * Aufruf dazu, misst der Wächter ihn nicht — und ohne diesen Fall merkte es
     * niemand, weil die vier alten weiter grün blieben.
     *
     * > **Eine Untergrenze ist kein Formalismus — sie ist die einzige Stelle,
     * > an der ein Wächter merkt, dass sein Ausdruck ins Leere greift.**
     */
    private const WEGE = 4;

    public function test_a_backup_from_the_page_names_the_person(): void
    {
        $admin = $this->admin();
        $subscription = $this->subscription();

        $this->actingAs($admin)
            ->post("/subscriptions/{$subscription->id}/backups")
            ->assertRedirect();

        $this->assertSame(
            $admin->id,
            $this->vorgang('backup.create')?->account_id,
            'Eine Sicherung, die jemand gedrückt hat, steht als Automatik da.',
        );
    }

    public function test_a_removal_from_the_page_names_the_person(): void
    {
        $admin = $this->admin();
        $subscription = $this->subscription();
        $backup = $this->backup($subscription);

        $this->actingAs($admin)
            ->delete("/subscriptions/{$subscription->id}/backups/{$backup->id}")
            ->assertRedirect();

        $this->assertSame(
            $admin->id,
            $this->vorgang('backup.remove')?->account_id,
            'Ein Entfernen, das jemand gedrückt hat, steht als Automatik da.',
        );
    }

    /**
     * Und die Gegenrichtung, ohne die die erste nichts belegt.
     *
     * **Der nächtliche Lauf hat keinen Request**, und `null` ist dort die
     * richtige Antwort und nicht die fehlende. Stünde hier eine Kennung, wäre
     * sie erfunden — und `ActorLabel` machte aus „System" einen Namen.
     */
    public function test_the_nightly_run_names_nobody(): void
    {
        $subscription = $this->subscription();

        app(Backups::class)->create($subscription);

        $vorgang = $this->vorgang('backup.create');

        $this->assertNotNull($vorgang, 'Es wurde gar keine Sicherung eingereiht.');
        $this->assertNull(
            $vorgang->account_id,
            'Der nächtliche Lauf hat sich einen Handelnden ausgedacht.',
        );
    }

    /**
     * Das Abräumen erbt die Kennung seines Anlasses.
     *
     * **Der Fall, der einen Request nicht hat und trotzdem einen Handelnden.**
     * `BackupLifecycle::afterSuccess()` läuft im Arbeiter; sein Anlass ist der
     * Klick, der die letzte Zeile entfernt hat. Dasselbe Muster wie in
     * `CertificateLifecycle`, das die Kennung seines Anlasses weiterreicht.
     */
    public function test_the_cleanup_inherits_the_actor_of_its_cause(): void
    {
        $admin = $this->admin();

        $this->lebenslauf($admin->id);

        $this->assertSame(
            $admin->id,
            $this->aufraeumvorgang()?->account_id,
            'Das Abräumen hat den Handelnden seines Anlasses verloren.',
        );
    }

    /**
     * Und dieselbe Naht in die andere Richtung.
     *
     * Räumt die **Aufbewahrung** nachts die letzte Zeile ab, hat ihr Vorgang
     * keinen Handelnden — und das Abräumen darf sich auch keinen holen.
     */
    public function test_the_cleanup_of_a_nightly_removal_names_nobody(): void
    {
        $this->lebenslauf(null);

        $vorgang = $this->aufraeumvorgang();

        $this->assertNotNull($vorgang, 'Es wurde gar nicht abgeräumt.');
        $this->assertNull(
            $vorgang->account_id,
            'Das Abräumen hat sich einen Handelnden ausgedacht.',
        );
    }

    /**
     * Jeder Weg durch den Helfer wird von einem Fall darüber gefahren.
     *
     * Gezählt werden die Aufrufe von `dispatch()` im Rumpf — ohne Kommentare,
     * weil dieses Repo jede Behebung mit ihrem Vorzustand daneben festhält.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     */
    public function test_every_dispatch_of_this_helper_is_measured(): void
    {
        $quelltext = $this->withoutComments(
            (string) file_get_contents(base_path('app/Support/Backups/Backups.php')),
        );

        $aufrufe = substr_count($quelltext, '$this->dispatch(');

        $this->assertSame(
            self::WEGE,
            $aufrufe,
            sprintf(
                'Backups::dispatch() hat %d Aufrufer, gemessen werden %d. '
                .'Wer einen Weg hinzufügt, misst ihn hier mit.',
                $aufrufe,
                self::WEGE,
            ),
        );
    }

    /** Den letzten Vorgang einer Aufgabe, ohne Mandantenklammer. */
    private function vorgang(string $task): ?Operation
    {
        return app(Tenancy::class)->withoutRestriction(static fn (): ?Operation => Operation::query()
            ->where('task', $task)
            ->latest('id')
            ->first());
    }

    /** Der Vorgang ohne Gegenstand — das Abräumen des Verzeichnisses. */
    private function aufraeumvorgang(): ?Operation
    {
        return app(Tenancy::class)->withoutRestriction(static fn (): ?Operation => Operation::query()
            ->where('task', 'backup.remove')
            ->whereNull('subject_id')
            ->latest('id')
            ->first());
    }

    /**
     * Den Lebenslauf über eine gelungene `backup.remove` fahren, die die
     * **letzte** Zeile eines zurückgebauten Abonnements war.
     *
     * Nur in dieser Lage räumt das Panel das Verzeichnis ab — verwaiste Zeile,
     * keine weitere daneben, kein lebendes Abonnement dieses Namens.
     */
    private function lebenslauf(?int $accountId): void
    {
        $backup = Backup::query()->create([
            'subscription_id' => null,
            'subscription_name' => 'abgebaut.invalid',
            'storage_name' => 'abgebaut-invalid-20260918-120000-aaaabbbb',
            'status' => BackupStatus::Ready,
        ]);

        $vorgang = Operation::query()->create([
            'account_id' => $accountId,
            'subject_type' => OperationSubject::Backup->value,
            'subject_id' => $backup->id,
            'type' => 'backup.remove',
            'task' => 'backup.remove',
            'payload' => [
                'subscription' => (string) $backup->subscription_name,
                'storage' => (string) $backup->storage_name,
            ],
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(BackupLifecycle::class)->afterSuccess($vorgang);
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    /** Ein Abonnement mit Verzeichnis — ohne Systembenutzer sichert nichts. */
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
