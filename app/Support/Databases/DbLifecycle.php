<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseEngine;
use App\Enums\DbUserStatus;
use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\AfterOperation;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\WebLifecycle;

/**
 * Der Lebenslauf einer Datenbank — und die Sperre, die sie erreicht.
 *
 * **Zwei Aufgaben, und die zweite ist die wichtigere.**
 *
 * 1. Nach `db.database.remove` verschwindet die Zeile. Der Zustand folgt dem
 *    Agenten, nicht dem Klick: Erst wenn das Schema fort ist, gibt der Bestand
 *    den Namen frei. Andersherum wäre der Name frei, während die Daten noch
 *    liegen — und der nächste `CREATE DATABASE` liefe auf ein bestehendes
 *    Schema.
 *
 * 2. Nach `subscription.suspend` und `subscription.resume` werden die
 *    Datenbankzugänge gesperrt und wieder geöffnet (`docs/36 §6`). **Bis P4
 *    tat das niemand.** `subscription.suspend` nahm dem Abo-Verzeichnis das
 *    Ausführungsbit, `WebLifecycle` schrieb die Server-Blöcke auf 503 um — und
 *    die Datenbank bediente jede Anwendung weiter, die die Zugangsdaten hat.
 *    Auf demselben Server über den Socket, und bei freigeschaltetem
 *    Fernzugriff von überall. Das ist keine Sperre, sondern eine abgeschaltete
 *    Webseite.
 *
 * **Die Reihenfolge in {@see Lifecycles::HANDLERS} trägt das.** Der Lebenslauf
 * des Abonnements läuft zuerst und hat den Zustand schon gesetzt; was hier
 * entsteht, liest ihn frisch. Dieselbe Abhängigkeit wie bei
 * {@see WebLifecycle::afterSubscription()}.
 */
final class DbLifecycle implements AfterOperation
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Die Aufgaben, nach denen sich an einer Datenbank etwas ändert.
     *
     * `db.database.create`, `db.user.*` und `db.server.info` stehen **nicht**
     * darin, und das ist keine Lücke: Sie laufen als unmittelbarer Aufruf und
     * nicht über die Warteschlange — teils weil sie ein Passwort tragen
     * (`docs/36 §4`), teils weil sie Millisekunden dauern. Ihre Zeile schreibt
     * {@see Databases} selbst, nachdem der Agent geantwortet hat.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return [
            'db.database.remove',
            'db.user.lock',
            'subscription.suspend',
            'subscription.resume',
        ];
    }

    /**
     * Beim Fehlschlag bleibt alles stehen, wie es war.
     *
     * **Seit Schritt 6 ist diese Methode leer, und das ist ein Zustand und kein
     * Rest.** Was hier stand, betraf ausschliesslich Sicherungen und ist mit
     * ihnen nach {@see DumpLifecycle} gezogen (`docs/38 §21`, Entscheidung 10).
     *
     * Für das, was übrig bleibt — Rückbau und Sperre —, gilt dieselbe
     * Zurückhaltung wie in {@see PgLifecycle::afterFailure()}: Ein automatischer
     * Rückweg auf den vorigen Zustand wäre eine Behauptung über den Server, die
     * dieser Lebenslauf nicht geprüft hat.
     */
    public function afterFailure(Operation $operation): void {}

    public function afterSuccess(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if ($task === 'db.database.remove') {
            $this->removed($operation);

            return;
        }

        if ($task === 'db.user.lock') {
            $this->locked($operation);

            return;
        }

        if ($task === 'subscription.suspend' || $task === 'subscription.resume') {
            $this->afterSubscription($operation, $task === 'subscription.suspend');
        }
    }

    /**
     * Das Schema ist fort — jetzt erst geht die Zeile.
     *
     * Und mit ihr die Zugänge, die der Agent im selben Lauf entfernt hat. Was
     * er tatsächlich weggenommen hat, steht in seiner Antwort; gelesen wird
     * sie und nicht die Absicht, mit der der Vorgang eingereiht wurde. Dieselbe
     * Entscheidung wie in `WebLifecycle::appliedStatus()`: Zwischen Absenden
     * und Antwort kann sich etwas geändert haben, und dann gäbe es zwei
     * Auskünfte.
     */
    private function removed(Operation $operation): void
    {
        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $database = Database::query()->find($operation->subject_id);

            if ($database === null) {
                return;
            }

            $removedUsers = $operation->result['users_removed'] ?? [];

            if (is_array($removedUsers)) {
                foreach ($removedUsers as $account) {
                    if (! is_string($account)) {
                        continue;
                    }

                    [$name, $host] = array_pad(explode('@', $account, 2), 2, 'localhost');

                    DbUser::query()->where('name', $name)->where('host', $host)->delete();
                }
            }

            $database->delete();
        });
    }

    /** Die Zugänge sind zu — jetzt erst steht es auch im Panel. */
    private function locked(Operation $operation): void
    {
        $locked = ($operation->result['locked'] ?? null) === true;

        $this->tenancy->withoutRestriction(function () use ($operation, $locked): void {
            DbUser::query()
                ->where('subscription_id', $operation->subscription_id)
                ->where('engine', DatabaseEngine::MariaDb)
                ->get()
                ->each(static fn (DbUser $user): bool => $user->forceFill([
                    'status' => $locked ? DbUserStatus::Locked : DbUserStatus::Active,
                    'locked_at' => $locked ? now() : null,
                ])->save());
        });
    }

    /**
     * Die Sperre eines Abonnements erreicht seine Datenbanken.
     *
     * **Ein Vorgang für alle Zugänge und nicht einer je Zugang.** Bei einem
     * Kunden mit fünf Benutzern wären das fünf Vorgänge für eine Handlung, und
     * „teilweise gesperrt" ist keine Auskunft.
     *
     * **Kein Vorgang, wenn es nichts zu sperren gibt.** Ein Abonnement ohne
     * Datenbankzugang bekäme sonst bei jedem Sperren einen leeren Vorgang in
     * seiner Liste — und in der Liste steht dann etwas, das nichts getan hat.
     */
    private function afterSubscription(Operation $operation, bool $lock): void
    {
        $subscription = $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($operation->subscription_id)
        );

        if ($subscription === null) {
            return;
        }

        /*
         * **`engine` seit P5b, und ohne die Zeile wäre es ein Fehler mit
         * Ansage.** Diese Abfrage holte alle Zugänge des Abonnements — ab
         * Schritt 4 sind darunter auch PostgreSQL-Rollen, und die gingen als
         * `name@host` an `db.user.lock`. Der Agent wiese sie ab, und ein
         * gesperrtes Abonnement behielte seine MariaDB-Zugänge, weil der
         * ganze Vorgang scheitert.
         *
         * Die Trennung liegt in `engine` und nicht in der Aufgabe: Beide
         * Lebensläufe hören auf `subscription.suspend`, und jeder fasst nur
         * seine eigenen Zeilen an ({@see PgLifecycle}).
         */
        $users = $this->tenancy->withoutRestriction(
            fn (): array => DbUser::query()
                ->where('subscription_id', $subscription->id)
                ->where('engine', DatabaseEngine::MariaDb)
                ->orderBy('id')
                ->get()
                ->all()
        );

        if ($users === []) {
            return;
        }

        $follow = Operation::query()->create([
            'subscription_id' => $subscription->id,

            // Im Arbeiter gibt es keine Anfrage. Der Folgevorgang trägt deshalb
            // das Konto dessen, der die Sperre angeordnet hat — sonst stünde in
            // der Liste „—" neben einer Handlung, die jemand ausgelöst hat.
            'account_id' => $operation->account_id,
            'type' => 'db.user.lock',
            'task' => 'db.user.lock',
            'payload' => [
                'user' => (string) $subscription->system_user,
                'mode' => $lock ? 'lock' : 'unlock',
                'users' => array_map(
                    static fn (DbUser $u): array => ['name' => $u->name, 'host' => $u->host],
                    $users,
                ),
            ],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => sprintf(
                'Datenbankzugänge von %s werden %s',
                $subscription->name,
                $lock ? 'gesperrt' : 'freigegeben',
            ),
        ]);

        RunAgentOperation::dispatch((int) $follow->id);
    }
}
