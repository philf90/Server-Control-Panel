<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Models\Backup;
use App\Models\CronJob;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\BackupLifecycle;
use App\Support\Backups\RestoreLifecycle;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein Lebenslauf wird nur für seine eigenen Aufgaben gerufen.
 *
 * ## Der Befund, für den es diesen Wächter gibt
 *
 * Gemessen am 18. September 2026 auf `cloudsrv24`, im Nachlauf zu P8: Nach
 * einer **Sicherung** stand der Cronjob des Kunden zweimal in `/etc/cron.d/`,
 * beide Zeilen aktiv — sein Job lief doppelt. Im Ergebnis des
 * `backup.create`-Vorgangs stand ausserdem ein `restored`-Block mit sechs
 * Fehlschlägen, obwohl niemand etwas zurückgespielt hatte.
 *
 * {@see Lifecycles::afterSuccess()} rief **jeden** Lebenslauf für **jeden**
 * Vorgang. Sieben von acht prüften `$operation->task` selbst;
 * {@see RestoreLifecycle} prüfte nur, ob es ein
 * Abonnement und eine Sicherung gibt — und bei einem `backup.create` gibt es
 * beides, denn der Gegenstand **ist** die Sicherung.
 *
 * > **Ein Verteiler, der jeden Empfänger für jede Nachricht ruft, verlagert
 * > die Zuständigkeitsfrage in die Empfänger — und der erste, der sie nicht
 * > stellt, tut etwas, das niemand bestellt hat.**
 *
 * ## Gemessen am Schaden und nicht am Quelltext
 *
 * Ein Ausdruck über `Lifecycles.php` sagte, dass dort `handles()` vorkommt —
 * nicht, dass ein fremder Lebenslauf dadurch stillsteht. Dieser Wächter fährt
 * den Verteiler mit einem echten `backup.create` und sieht nach, was der
 * Bestand danach sagt.
 *
 * ## Was er nicht kann
 *
 * Er misst diesen einen Übergriff und nicht jeden denkbaren. Die Regel selbst
 * — *jeder* Lebenslauf nur für seine Aufgaben — hängt an acht Klassen, von
 * denen sieben ihren Schaden erst auf einem Server zeigen würden. Was hier
 * steht, ist der Fall, der eingetreten ist.
 *
 * > **Wer einen Wächter über eine Aufzählung baut, prüft ihn an dem Fall, der
 * > ihn ausgelöst hat.**
 */
final class LifecycleDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Eine Sicherung legt den Cronjob nicht noch einmal an.**
     *
     * Das ist der Schaden, den der Kunde spürt: Zwei Zeilen in `/etc/cron.d/`,
     * beide aktiv, und sein Job läuft doppelt.
     */
    public function test_a_backup_does_not_duplicate_the_cron_job(): void
    {
        [$abo, $sicherung] = $this->prüfkörper();

        $job = CronJob::query()->create([
            'subscription_id' => $abo->id,
            'label' => 'test',
            'command' => 'true',
            'minute' => '0',
            'hour' => '9',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '1-5',
            'active' => true,
        ]);

        $this->verteile($this->vorgang($abo, $sicherung, 'backup.create'));

        $this->assertSame(
            [(int) $job->id],
            $this->ungeklammert(static fn (): array => CronJob::query()
                ->where('subscription_id', $abo->id)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all()),
            'Die Sicherung hat den Cronjob noch einmal angelegt — der Job des Kunden läuft danach doppelt.',
        );
    }

    /**
     * **Und sie schreibt keinen Bericht über eine Wiederherstellung.**
     *
     * Die zweite Hälfte desselben Übergriffs, und die sichtbare: `restored`
     * steht im Ergebnis eines Vorgangs, der nichts zurückgespielt hat — mit
     * Fehlschlägen, die aus dem Anlegen dessen kommen, was längst dasteht.
     *
     * > **Ein Ergebnis, das eine Handlung beschreibt, die niemand ausgelöst
     * > hat, behauptet etwas über einen Zustand, den niemand hergestellt
     * > hat.**
     */
    public function test_a_backup_does_not_report_a_restore(): void
    {
        [$abo, $sicherung] = $this->prüfkörper();

        $vorgang = $this->vorgang($abo, $sicherung, 'backup.create');

        $this->verteile($vorgang);

        $ergebnis = $this->ungeklammert(static fn (): mixed => Operation::query()->find($vorgang->id)?->result);

        $this->assertArrayNotHasKey(
            'restored',
            is_array($ergebnis) ? $ergebnis : [],
            'Das Ergebnis der Sicherung trägt einen Bericht über eine Wiederherstellung, die es nicht gab.',
        );
    }

    /**
     * **Die Gegenprobe: Der zuständige Lebenslauf wird sehr wohl gerufen.**
     *
     * Ohne sie wäre ein Verteiler, der **gar nichts** mehr ruft, in beiden
     * Fällen darüber grün — und dann stünde jeder Vorgang dieses Panels für
     * immer auf „wartet".
     *
     * > **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas
     * > anwesend ist, das dieselbe Hülle braucht.**
     *
     * Gemessen an `backup.remove`: Das ist die eine Aufgabe, deren Wirkung im
     * Bestand steht und keinen Agenten braucht — {@see BackupLifecycle}
     * löscht die Zeile, sobald der Agent geantwortet hat.
     */
    public function test_the_lifecycle_that_owns_the_task_still_runs(): void
    {
        [$abo, $sicherung] = $this->prüfkörper();

        $this->verteile($this->vorgang($abo, $sicherung, 'backup.remove'));

        $this->assertNull(
            $this->ungeklammert(static fn (): ?Backup => Backup::query()->find($sicherung->id)),
            'Der zuständige Lebenslauf ist nicht gerufen worden — dann ruft der Verteiler gar keinen mehr.',
        );
    }

    /** @return array{0: Subscription, 1: Backup} */
    private function prüfkörper(): array
    {
        $abo = Subscription::factory()->create([
            'plan_id' => Plan::factory()->create()->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);

        $sicherung = Backup::query()->create([
            'subscription_id' => $abo->id,
            'subscription_name' => (string) $abo->name,
            'storage_name' => 'lauf-20260918-091108',
            'status' => BackupStatus::Ready,
        ]);

        return [$abo, $sicherung];
    }

    private function vorgang(Subscription $abo, Backup $sicherung, string $task): Operation
    {
        return Operation::query()->create([
            'subscription_id' => $abo->id,
            'subject_type' => OperationSubject::Backup->value,
            'subject_id' => $sicherung->id,
            'type' => $task,
            'task' => $task,
            'payload' => ['subscription' => (string) $abo->name],
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);
    }

    /**
     * Den Verteiler fahren, wie ihn `RunAgentOperation` fährt.
     *
     * Mit gelöster Mandantenklammer, weil der Warteschlangen-Arbeiter kein
     * angemeldetes Konto hat — geklammert fänden die Lebensläufe weder
     * Abonnement noch Zeile und täten wortlos nichts, und dieser Wächter wäre
     * grün, ohne etwas gemessen zu haben.
     */
    private function verteile(Operation $operation): void
    {
        app(Tenancy::class)->withoutRestriction(function () use ($operation): void {
            app(Lifecycles::class)->afterSuccess($operation);
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $frage
     * @return T
     */
    private function ungeklammert(\Closure $frage): mixed
    {
        return app(Tenancy::class)->withoutRestriction($frage);
    }
}
