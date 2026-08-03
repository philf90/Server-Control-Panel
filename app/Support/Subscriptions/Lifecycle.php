<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;

/**
 * Der Lebenslauf eines Abonnements — und wer den Zustand setzt.
 *
 * **Der Zustand folgt dem System, nicht der Absicht.** Das ist die
 * Entscheidung, um die es in dieser Klasse geht. Der naheliegende Weg wäre,
 * beim Klick auf „Sperren" den Zustand sofort auf `suspended` zu setzen und
 * den Vorgang nebenher laufen zu lassen. Dann steht in der Liste „gesperrt",
 * während das Abonnement weiter ausliefert — und niemand sieht den
 * Unterschied, denn genau danach schaut man ja in der Liste.
 *
 * Deshalb setzt hier nichts einen Zustand, bevor der Agent geantwortet hat.
 * {@see self::afterSuccess()} läuft im Arbeiter, nachdem die Operation
 * durchgelaufen ist. Scheitert sie, bleibt der alte Zustand stehen, und der
 * Vorgang ist sichtbar fehlgeschlagen. Beides zusammen ist die Wahrheit.
 *
 * **Der Arbeiter hat keinen Mandanten.** Er läuft ohne angemeldetes Konto;
 * der Grundzustand der Klammer ist „nichts", und damit fände er das
 * Abonnement nicht, dessen Zustand er setzen soll. Deshalb steht auch hier
 * ein ausdrückliches `withoutRestriction` — an einer Stelle, mit einem Namen,
 * der beim Lesen auffällt.
 */
final class Lifecycle
{
    /** Die erste Nummer eines Systembenutzers. Vier Stellen, wie der Agent verlangt. */
    public const FIRST_USER = 1000;

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Der nächste freie Systembenutzer.
     *
     * **`withTrashed()` ist der Kern und keine Feinheit** — derselbe Grund wie
     * bei der Kundennummer, nur mit schärferer Folge: Ein zurückgezogenes
     * Abonnement hat seinen Namen verbraucht. Sähe die Vergabe ihn nicht,
     * bekäme ein neuer Kunde `p1000` ein zweites Mal und damit alles, was auf
     * dem Dateisystem noch der alten UID gehört.
     *
     * Gesucht wird in PHP und nicht in SQL: Ein `CAST(SUBSTRING(...))` läuft
     * auf MariaDB und nicht auf SQLite, und dann prüften die Tests etwas
     * anderes als der Server — ausgerechnet bei der Vergabe eines Bezeichners.
     */
    public function nextSystemUser(): string
    {
        // **Ohne Mandantenklammer, und zwar ausdrücklich.** Der Name ist über
        // den ganzen Server eindeutig; die Klammer zeigt aber nur, was das
        // anfragende Konto sehen darf. Ein Kunde — oder ein Kommando ohne
        // gesetzten Mandanten — sähe damit kein einziges Abonnement und
        // bekäme `p1000` zurück, den es längst gibt. Aufgefallen im Test, der
        // nach dem Rückbau erneut vergibt.
        return $this->tenancy->withoutRestriction(function (): string {
            $highest = Subscription::query()
                ->withTrashed()
                ->whereNotNull('system_user')
                ->where('system_user', 'like', 'p%')
                ->pluck('system_user')
                ->map(static fn (string $user): int => (int) mb_substr($user, 1))
                ->max();

            return 'p'.max(self::FIRST_USER, ((int) $highest) + 1);
        });
    }

    /**
     * Die Argumente für eine Operation des Agenten.
     *
     * **Sie kommen aus der abgelegten Zeile und nicht aus der Anfrage.** Das
     * ist dieselbe Regel wie im Aufgabenkatalog (App\Support\Operations\Task),
     * nur an einem Objekt statt an einer festen Liste: Der Browser nennt ein
     * Abonnement, die Mandantenklammer entscheidet, ob er es überhaupt sehen
     * darf, und die Werte, die den Agenten erreichen, stehen in der Datenbank.
     * Ein Name aus dem Formular käme nie bis hierher.
     *
     * @return array<string, mixed>
     */
    public function payload(Subscription $subscription): array
    {
        $payload = [
            'name' => (string) $subscription->name,
            'user' => (string) $subscription->system_user,
        ];

        // Der Speicher steht am Plan und kann am Abonnement übersteuert sein.
        // `null` heisst unbegrenzt — nur darf er das hier nicht sein (siehe
        // App\Support\Plans\Quota), deshalb kommt er nie als `null` an.
        $disk = $subscription->quota(Quota::DiskMb->value);

        if (is_numeric($disk)) {
            $payload['quota_mb'] = (int) $disk;
        }

        return $payload;
    }

    /**
     * Einen Vorgang für ein Abonnement einreihen.
     *
     * **Hier und nicht im Controller, seit es zwei Auslöser gibt.** Bis August
     * 2026 stand das als private Methode in `SubscriptionController`; dann kam
     * die Kundensperre dazu, die dieselben Vorgänge für alle Abonnements eines
     * Kunden einreiht. Zwei Fassungen davon hiessen: zwei Stellen, an denen
     * die Argumente entstehen, und die eine, die beim nächsten Mal nachgezogen
     * wird, ist erfahrungsgemäss nicht beide.
     *
     * Die Argumente kommen aus der abgelegten Zeile und nicht aus einer
     * Anfrage — siehe {@see self::payload()}. Der Vorgang trägt das
     * Abonnement, damit ihn der Kunde in seiner eigenen Liste sieht.
     */
    public function dispatch(Subscription $subscription, string $task, string $message): Operation
    {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => $this->payload($subscription),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Was ein erfolgreicher Vorgang am Abonnement ändert.
     *
     * Aufgerufen aus dem Arbeiter, nachdem der Agent geantwortet hat.
     */
    public function afterSuccess(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if (! str_starts_with($task, 'subscription.')) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation, $task): void {
            $subscription = Subscription::query()->find($operation->subscription_id);

            if ($subscription === null) {
                return;
            }

            match ($task) {
                'subscription.provision' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'suspended_at' => null,
                ])->save(),

                'subscription.suspend' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Suspended,
                    'suspended_at' => now(),
                ])->save(),

                'subscription.resume' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'suspended_at' => null,
                ])->save(),

                // Der Rückbau ist durch: Verzeichnis weg, Konto weg. Die Zeile
                // bleibt mit `deleted_at` stehen, damit der Systembenutzer
                // verbraucht bleibt.
                'subscription.remove' => $this->withdraw($subscription),

                default => null,
            };
        });
    }

    private function withdraw(Subscription $subscription): void
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $subscription->delete();
    }
}
