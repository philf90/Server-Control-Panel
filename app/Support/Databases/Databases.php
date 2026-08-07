<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseStatus;
use App\Enums\DbUserStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Db\Names;

/**
 * Anlegen, entfernen und berechtigen — der Dienst zwischen Panel und Agent.
 *
 * ## Zwei Wege zum Agenten, und der Unterschied ist ein Passwort
 *
 * **Alles, was ein Geheimnis trägt, läuft unmittelbar** (`Client::call`) und
 * **nie** über die Warteschlange: Ein eingereihter Vorgang legt seine Argumente
 * in `operations.payload` ab, also dauerhaft und im Klartext in der Datenbank
 * des Panels. Dieselbe Regel gilt seit P4 für `tls.certificate.upload` und
 * `dns.credential.store`; seit P5 setzt sie ein Wächter durch statt einer
 * Gewohnheit — `Tests\Feature\SecretsStayOutOfTheQueueTest`. Er steht hier als
 * Name und nicht als `{@see}`: Anwendungscode verweist nicht in den Testbaum,
 * sonst zieht das Formatierungswerkzeug beim nächsten Lauf einen Import
 * dorthin.
 *
 * **Alles, was lange dauern kann, läuft über die Warteschlange.** Das ist
 * genau eines: `db.database.remove`. Ein `DROP DATABASE` über vierzig Gigabyte
 * ist kein hängender Prozess, sondern ein arbeitender, und er gehört in die
 * Vorgangsliste, wo man ihm zusehen kann.
 *
 * **Der Zustand folgt in beiden Fällen dem Agenten.** Bei einem unmittelbaren
 * Aufruf heisst das: Die Zeile entsteht, *nachdem* er geantwortet hat — so wie
 * {@see CertificateRecord} es nach dem Hochladen eines
 * Zertifikats tut. Beim Rückbau heisst es: Die Zeile verschwindet in
 * {@see DbLifecycle}, nicht beim Klick.
 *
 * ## Das Präfix erreicht den Agenten nicht aus der Anfrage
 *
 * Der Browser schickt `shop`. `p1001` liest dieser Dienst aus der abgelegten
 * Zeile des Abonnements, das durch die Mandantenklammer gekommen ist — dieselbe
 * Regel wie in `Lifecycle::payload()` und `WebLifecycle::payload()`. Der Teil
 * des Namens, der über die Mandantengrenze entscheidet, kommt aus der
 * Datenbank.
 */
final class Databases
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Client $agent,
        private readonly Secret $secret,
    ) {}

    /**
     * Eine Datenbank anlegen — unmittelbar, und die Zeile danach.
     *
     * **Ohne Warteschlange, obwohl es eine Systemänderung ist.** Plan §5.3
     * knüpft den Vorgang an „länger als eine Sekunde"; ein `CREATE DATABASE`
     * dauert Millisekunden. Wichtiger ist der zweite Grund: Wer eine Datenbank
     * anlegt, will im selben Schritt einen Benutzer dazu — und der trägt ein
     * Passwort, das genau einmal angezeigt wird. Ein eingereihter Vorgang
     * hiesse, dass das Passwort entweder in der Warteschlange liegt oder der
     * Kunde auf eine zweite Seite wartet, um es zu sehen.
     */
    public function create(Subscription $subscription, string $label, string $collation): Database
    {
        $this->guardQuota($subscription);

        $result = $this->agent->call('db.database.create', [
            'user' => (string) $subscription->system_user,
            'suffix' => $label,
            'charset' => 'utf8mb4',
            'collation' => $collation,
        ]);

        // **Was gilt, steht in der Antwort des Agenten** und nicht in dem, was
        // das Panel bestellt hat. Er hat den Namen zusammengesetzt; ihn hier
        // ein zweites Mal zu bauen wäre die zweite Fassung derselben Regel.
        $database = new Database([
            'name' => (string) ($result['name'] ?? Names::database((string) $subscription->system_user, $label)),
            'label' => $label,
            'status' => DatabaseStatus::Active,
            'charset' => (string) ($result['charset'] ?? 'utf8mb4'),
            'collation' => (string) ($result['collation'] ?? $collation),
        ]);

        $database->subscription_id = (int) $subscription->id;
        $database->save();

        return $database;
    }

    /**
     * Einen Datenbankbenutzer anlegen — und sein Passwort genau einmal
     * zurückgeben.
     *
     * Der Rückgabewert ist das einzige Mal, dass dieses Passwort in der
     * Anwendung vorkommt. Es wird nicht abgelegt, nicht protokolliert und nicht
     * in die Antwort des Vorgangs geschrieben (`docs/36 §4`).
     *
     * @param  list<Database>  $databases
     * @return array{0: DbUser, 1: string} Der Zugang und sein Passwort
     */
    public function createUser(Subscription $subscription, string $label, array $databases, string $host = 'localhost'): array
    {
        $password = $this->secret->generate();

        $result = $this->agent->call('db.user.create', [
            'user' => (string) $subscription->system_user,
            'suffix' => $label,
            'host' => $host,
            'password' => $password,
            'databases' => array_map(static fn (Database $d): string => $d->name, $databases),
        ]);

        $user = new DbUser([
            'name' => (string) ($result['name'] ?? Names::user((string) $subscription->system_user, $label)),
            'label' => $label,
            'host' => (string) ($result['host'] ?? $host),
            'status' => DbUserStatus::Active,
        ]);

        $user->subscription_id = (int) $subscription->id;
        $user->save();

        $user->databases()->sync(array_map(static fn (Database $d): int => (int) $d->id, $databases));

        return [$user, $password];
    }

    /** Ein neues Passwort — der einzige Weg zurück zu einem verlorenen. */
    public function resetPassword(DbUser $user): string
    {
        $password = $this->secret->generate();

        $this->agent->call('db.user.password', [
            'user' => $this->prefix($user->subscription_id),
            'name' => $user->name,
            'host' => $user->host,
            'password' => $password,
        ]);

        return $password;
    }

    /** Ein Recht vergeben oder zurücknehmen — ein Paar je Aufruf. */
    public function grant(DbUser $user, Database $database, bool $granted): void
    {
        $this->agent->call('db.user.grant', [
            'user' => $this->prefix($user->subscription_id),
            'name' => $user->name,
            'host' => $user->host,
            'database' => $database->name,
            'mode' => $granted ? 'grant' : 'revoke',
        ]);

        if ($granted) {
            $user->databases()->syncWithoutDetaching([(int) $database->id]);

            return;
        }

        $user->databases()->detach((int) $database->id);
    }

    /** Einen Zugang entfernen — unmittelbar; er trägt kein Geheimnis, aber auch keine Wartezeit. */
    public function removeUser(DbUser $user): void
    {
        $this->agent->call('db.user.remove', [
            'user' => $this->prefix($user->subscription_id),
            'name' => $user->name,
            'host' => $user->host,
        ]);

        $user->delete();
    }

    /**
     * Eine Datenbank entfernen — als Vorgang, mit den Benutzern, die nur an ihr
     * hängen.
     *
     * **Welche das sind, entscheidet der Bestand des Panels.** Der Agent sähe
     * dafür in `mysql.db` nach, und das wäre eine zweite Fassung derselben
     * Regel — die zweite ist die, die veraltet. Hier steht die Frage einmal:
     * ein Benutzer, dessen einzige Datenbank diese ist.
     *
     * **Der Zustand wird auf `Removing` gesetzt, bevor der Vorgang läuft** —
     * das ist kein Widerspruch zu „der Zustand folgt dem Agenten": `Removing`
     * ist keine Behauptung über das System, sondern die Aussage, dass ein
     * Vorgang läuft. Was der Agent entscheidet, ist, ob die Zeile danach
     * verschwindet, und das tut sie in {@see DbLifecycle}.
     */
    public function remove(Database $database, ?int $accountId = null): Operation
    {
        $subscription = $this->subscriptionOf($database);

        $doomed = $this->usersOnlyOn($database);

        $operation = Operation::query()->create([
            'subscription_id' => $database->subscription_id,
            'subject_type' => OperationSubject::Database->value,
            'subject_id' => $database->id,
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),
            'type' => 'db.database.remove',
            'task' => 'db.database.remove',
            'payload' => [
                'user' => (string) $subscription?->system_user,
                'name' => $database->name,
                'users' => array_map(
                    static fn (DbUser $u): array => ['name' => $u->name, 'host' => $u->host],
                    $doomed,
                ),
            ],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => 'Datenbank '.$database->name.' wird entfernt',
        ]);

        $database->forceFill(['status' => DatabaseStatus::Removing])->save();

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Alle Datenbanken eines Abonnements entfernen — vor dem Rückbau.
     *
     * **Vor `subscription.remove` und nicht danach.** Die Warteschlange hat
     * einen Arbeiter und arbeitet der Reihe nach; wer hier zuerst einreiht, ist
     * zuerst dran. Dasselbe Mittel wie in `WebLifecycle::apply()`, wo der
     * FPM-Pool vor dem Server-Block liegen muss.
     *
     * Scheitert einer der Vorgänge, bleibt seine Zeile stehen — und weil
     * `databases.subscription_id` auf `nullOnDelete` steht, bleibt sie
     * **auffindbar**, statt mit dem Abonnement zu verschwinden. Das ist der
     * ganze Grund für die Abschrift `subscription_name`, und `srvpanel db
     * prune` ist der Weg zurück.
     *
     * @return list<Operation>
     */
    public function removeAllFor(Subscription $subscription, ?int $accountId = null): array
    {
        $databases = $this->tenancy->withoutRestriction(
            fn (): array => Database::query()
                ->where('subscription_id', $subscription->id)
                ->orderBy('id')
                ->get()
                ->all()
        );

        $operations = [];

        foreach ($databases as $database) {
            $operations[] = $this->remove($database, $accountId);
        }

        return $operations;
    }

    /**
     * Wie viele Datenbanken das Abonnement noch anlegen darf.
     *
     * `null` heisst unbegrenzt — die Bedeutung aus `docs/23 §2`, und sie gilt
     * überall gleich.
     */
    public function remaining(Subscription $subscription): ?int
    {
        $limit = $subscription->quota(Quota::Databases->value);

        if (! is_numeric($limit)) {
            return null;
        }

        return max(0, (int) $limit - $this->countFor($subscription));
    }

    public function countFor(Subscription $subscription): int
    {
        return $this->tenancy->withoutRestriction(
            fn (): int => Database::query()->where('subscription_id', $subscription->id)->count()
        );
    }

    /**
     * Das Kontingent, serverseitig.
     *
     * `docs/20 §5.2`: „Jede Prüfung passiert serverseitig beim Anlegen — die
     * Oberfläche zeigt den Stand nur an." Und `docs/23 §5`: Eine gesenkte
     * Grenze verbietet die nächste Datenbank und wirft keine vorhandene weg.
     */
    private function guardQuota(Subscription $subscription): void
    {
        $remaining = $this->remaining($subscription);

        if ($remaining !== null && $remaining <= 0) {
            throw new RuntimeException(sprintf(
                'Das Kontingent für Datenbanken ist erreicht (%d von %d).',
                $this->countFor($subscription),
                (int) $subscription->quota(Quota::Databases->value),
            ));
        }
    }

    /**
     * Die Benutzer, deren einzige Datenbank diese ist.
     *
     * @return list<DbUser>
     */
    private function usersOnlyOn(Database $database): array
    {
        return $this->tenancy->withoutRestriction(function () use ($database): array {
            $doomed = [];

            foreach ($database->users()->get() as $user) {
                // `count()` über die Zuordnungstabelle und nicht über die
                // geladene Beziehung: Diese Zeilen kennt nur die Datenbank, und
                // eine geladene Beziehung wäre der Stand von vor dem letzten
                // `grant`.
                $others = DB::table('database_db_user')
                    ->where('db_user_id', $user->id)
                    ->where('database_id', '!=', $database->id)
                    ->count();

                if ($others === 0) {
                    $doomed[] = $user;
                }
            }

            return $doomed;
        });
    }

    /**
     * Das Abonnement eines Gegenstands — **ohne Mandantenklammer**.
     *
     * Die Begründung ist die Richtung, wörtlich die aus
     * `WebLifecycle::subscription()`: Die Datenbank ist bereits durch die
     * Klammer gekommen; wer sie in der Hand hat, durfte auch ihr Abonnement
     * sehen. Ohne die Ausnahme hinge der Inhalt der Argumente daran, wer gerade
     * angemeldet ist — und im Arbeiter ist das niemand.
     */
    private function subscriptionOf(Database $database): ?Subscription
    {
        return $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($database->subscription_id)
        );
    }

    /**
     * Das Präfix eines Abonnements, aus der Zeile gelesen.
     *
     * Es steht nie in einer Anfrage. Fehlt das Abonnement — weil es
     * zurückgebaut wurde —, ist das kein stiller Fall: Der Agent bekäme eine
     * leere Zeichenkette und wiese sie ab, aber die Meldung führte an eine
     * Stelle, an der niemand nach der Mandantenklammer sucht. Deshalb hier.
     */
    private function prefix(?int $subscriptionId): string
    {
        $user = $this->tenancy->withoutRestriction(
            fn (): mixed => Subscription::query()->whereKey($subscriptionId)->value('system_user')
        );

        if (! is_string($user) || $user === '') {
            throw new RuntimeException('Zu diesem Zugang gibt es kein Abonnement mehr.');
        }

        return $user;
    }
}
