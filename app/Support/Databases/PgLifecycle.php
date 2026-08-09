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
use App\Support\Databases\Engines\PostgresDriver;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\Client;

/**
 * Was nach einem PostgreSQL-Vorgang am Bestand nachzuziehen ist.
 *
 * **Ein eigener Lebenslauf und keine Erweiterung von {@see DbLifecycle}**, und
 * der Grund ist derselbe wie für `pg.*` neben `db.*` (`docs/38 §8`): Die
 * Antworten der beiden Operationen haben nicht dieselbe Form. `db.database.remove`
 * meldet `users_removed` als `name@host`, `pg.database.remove` meldet
 * `roles_removed` als blosse Rollennamen — ein gemeinsamer Lebenslauf müsste in
 * jeder Methode danach fragen, welches System gemeint ist, und das wären zwei
 * Fassungen derselben Regel in einer Datei.
 *
 * **Er ist deutlich kürzer, und das ist kein Zwischenstand.** `DbLifecycle`
 * beantwortet acht Aufgaben, weil P5 Sicherungen, Zurückspielen und die Sperre
 * mitbringt. Hier steht bis Schritt 5 und 6 nur der Rückbau; was dazukommt,
 * kommt mit seiner Operation.
 */
final class PgLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Client $agent,
    ) {}

    /**
     * **Nur der Rückbau, und das ist die vollständige Liste.**
     *
     * Anlegen, Zugang, Passwort und Rechte laufen unmittelbar und nicht über
     * die Warteschlange — teils weil sie ein Passwort tragen (`docs/38 §4`),
     * teils weil sie Millisekunden dauern. Ihre Zeile schreibt {@see Databases}
     * selbst, nachdem der Agent geantwortet hat.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return [
            'pg.database.remove',
            'pg.role.lock',

            /*
             * **Beide Lebensläufe hören auf dieselben zwei Aufgaben**, und das
             * ist kein Versehen: Ein gesperrtes Abonnement soll seine Zugänge
             * in *jedem* System verlieren. Jeder reiht seinen eigenen
             * Folgevorgang ein und fasst nur seine eigenen Zeilen an — die
             * Trennung liegt in `engine` und nicht in der Aufgabe.
             */
            'subscription.suspend',
            'subscription.resume',
        ];
    }

    /**
     * Beim Fehlschlag bleibt alles stehen, wie es war.
     *
     * **Und das ist hier mehr als eine leere Methode.** `pg.database.remove`
     * wirft die Datenbank und danach die Rollen (gemessen, `docs/38 §24.3`);
     * bricht sie ab, ist der Zustand der von vorher — der Kunde hat seine Daten
     * und seinen Zugang. Die Zeile steht auf `Removing` und der Vorgang auf
     * „gescheitert"; beides ist wahr und beides gehört gesehen, bevor jemand
     * einen zweiten Anlauf nimmt.
     *
     * Ein automatischer Rückweg auf `Active` wäre eine Behauptung über den
     * Server, die dieser Lebenslauf nicht geprüft hat.
     */
    public function afterFailure(Operation $operation): void {}

    public function afterSuccess(Operation $operation): void
    {
        match ((string) ($operation->task ?? '')) {
            'pg.database.remove' => $this->removed($operation),
            'pg.role.lock' => $this->locked($operation),
            'subscription.suspend' => $this->afterSubscription($operation, true),
            'subscription.resume' => $this->afterSubscription($operation, false),
            default => null,
        };
    }

    /**
     * Die Sperre des Abonnements erreicht die Rollen — als Folgevorgang.
     *
     * Wörtlich {@see DbLifecycle::afterSubscription()}, mit `NOLOGIN` statt
     * `ACCOUNT LOCK` (`docs/38 §11`).
     *
     * **Und die Grenze, die P5 nie aufgeschrieben hat:** `NOLOGIN` nimmt die
     * Anmeldung und beendet **keine** bestehende Sitzung — `ACCOUNT LOCK` tut
     * das auch nicht. Eine Anwendung mit offenem Verbindungspool arbeitet nach
     * der Sperre weiter, bis sie neu verbindet. Wer das schliessen wollte,
     * bräuchte `pg_terminate_backend`, und das hiesse: ein Kunde sieht mitten
     * in einer Transaktion einen Abbruch. **P5b sperrt und beendet nicht**
     * (`docs/38 §22`).
     */
    private function afterSubscription(Operation $operation, bool $lock): void
    {
        $subscription = $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($operation->subscription_id)
        );

        if ($subscription === null) {
            return;
        }

        $roles = $this->rolesOf($subscription);

        // Kein Abonnement ohne PostgreSQL-Zugang bekommt einen leeren
        // Folgevorgang. Er stünde in der Liste, täte nichts und wäre auf jedem
        // Server ohne PostgreSQL die Hälfte aller Zeilen.
        if ($roles === []) {
            return;
        }

        $follow = Operation::query()->create([
            'subscription_id' => $subscription->id,

            // Im Arbeiter gibt es keine Anfrage. Der Folgevorgang trägt deshalb
            // das Konto dessen, der die Sperre angeordnet hat.
            'account_id' => $operation->account_id,
            'type' => 'pg.role.lock',
            'task' => 'pg.role.lock',
            'payload' => [
                'prefix' => (new PostgresDriver($this->agent, $this->tenancy))->prefix($subscription),
                'mode' => $lock ? 'lock' : 'unlock',
                'roles' => $roles,
            ],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => sprintf(
                'PostgreSQL-Zugänge von %s werden %s',
                $subscription->name,
                $lock ? 'gesperrt' : 'freigegeben',
            ),
        ]);

        RunAgentOperation::dispatch((int) $follow->id);
    }

    /**
     * Was die Sperre an den Zeilen ändert — **nur an den eigenen**.
     *
     * `engine` ist die Trennung. Ohne sie setzte dieser Lebenslauf die
     * MariaDB-Zugänge desselben Abonnements mit, und der andere umgekehrt: Zwei
     * Vorgänge schrieben denselben Zustand, und der zweite gewänne. Sichtbar
     * würde es erst, wenn einer der beiden scheitert — dann stünde ein Zugang
     * als gesperrt da, den niemand gesperrt hat.
     */
    private function locked(Operation $operation): void
    {
        $locked = ($operation->result['locked'] ?? null) === true;

        $this->tenancy->withoutRestriction(function () use ($operation, $locked): void {
            DbUser::query()
                ->where('subscription_id', $operation->subscription_id)
                ->where('engine', DatabaseEngine::Postgres)
                ->get()
                ->each(static fn (DbUser $user): bool => $user->forceFill([
                    'status' => $locked ? DbUserStatus::Locked : DbUserStatus::Active,
                    'locked_at' => $locked ? now() : null,
                ])->save());
        });
    }

    /**
     * Die PostgreSQL-Rollen eines Abonnements, aus dem Bestand.
     *
     * @return list<string>
     */
    private function rolesOf(Subscription $subscription): array
    {
        return $this->tenancy->withoutRestriction(
            static fn (): array => DbUser::query()
                ->where('subscription_id', $subscription->id)
                ->where('engine', DatabaseEngine::Postgres)
                ->orderBy('id')
                ->pluck('name')
                ->all()
        );
    }

    /**
     * Die Datenbank ist fort — jetzt erst geht die Zeile, und die Rollen mit.
     *
     * **Gelesen wird die Antwort und nicht die Absicht.** Was der Agent
     * tatsächlich entfernt hat, steht in `roles_removed`; zwischen Einreihen
     * und Antwort kann sich etwas geändert haben. Dieselbe Entscheidung wie in
     * {@see DbLifecycle::removed()} und in `WebLifecycle::appliedStatus()`.
     *
     * **Der Name allein genügt als Schlüssel.** In MariaDB gehört der Wirt
     * dazu — `'p1001_web'@'localhost'` und derselbe Name an einer anderen
     * Adresse sind zwei Benutzer. Eine PostgreSQL-Rolle ist clusterweit
     * eindeutig, und die Zeile im Panel trägt ihren Wirt nur, weil das
     * Datenmodell eines ist.
     */
    private function removed(Operation $operation): void
    {
        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $database = Database::query()->find($operation->subject_id);

            if ($database === null) {
                return;
            }

            $removed = $operation->result['roles_removed'] ?? [];

            if (is_array($removed)) {
                foreach ($removed as $role) {
                    if (! is_string($role)) {
                        continue;
                    }

                    DbUser::query()->where('name', $role)->delete();
                }
            }

            $database->delete();
        });
    }
}
