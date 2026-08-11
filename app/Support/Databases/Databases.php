<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Enums\DbUserStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\Engines\EngineDriver;
use App\Support\Databases\Engines\MariaDbDriver;
use App\Support\Databases\Engines\PostgresDriver;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SrvPanel\Agent\Client;

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
     * Die eine Stelle, an der über das Datenbanksystem entschieden wird.
     *
     * **`docs/38 §8` verlangt genau das:** ein Modell, eine Fläche, eine
     * Verzweigung. Alles darunter — welcher Aufruf, welche Argumente, welches
     * Präfix — steht in {@see EngineDriver} und seinen zwei Umsetzungen; alles
     * darüber — Kontingent, Namensprüfung, Klammer, Bestand — steht in dieser
     * Klasse genau einmal.
     *
     * Der `match` ist vollständig ohne `default`: Käme ein drittes System
     * hinzu, meldete es der Übersetzer hier und nicht ein Kunde später.
     */
    private function driver(DatabaseEngine $engine): EngineDriver
    {
        return match ($engine) {
            DatabaseEngine::MariaDb => new MariaDbDriver($this->agent),
            DatabaseEngine::Postgres => new PostgresDriver($this->agent, $this->tenancy),
        };
    }

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
    public function create(
        Subscription $subscription,
        string $label,
        ?string $collation,

        /*
         * **Ohne Vorgabewert, und das ist die Lehre des 10. August 2026.**
         * Hier stand `= DatabaseEngine::MariaDb`, und in {@see self::createUser()}
         * ebenso — dort hat es zugeschlagen: `DatabaseController::createUserFor()`
         * rief sie ohne dieses Argument, und **jeder Zugang zu einer
         * PostgreSQL-Datenbank entstand damit in MariaDB.** Kein Test hat es
         * gemerkt, weil beide Wege für sich richtig aussehen.
         *
         * Es ist derselbe Fehler wie `env('SRVPANEL_VERSION', '0.1.0-dev')` und
         * wie `handed_over => false` im Grundzustand von `Pg\Server::describe()`
         * — drei an einem Tag, und immer dieselbe Bauform: *Ein Vorgabewert, den
         * niemand überschreibt, ist kein Vorgabewert, sondern die Antwort.*
         *
         * **Der Wächter dagegen ist kein Test, sondern der Übersetzer.** Ohne
         * Vorgabewert kann kein Aufrufer die Frage übersehen; ein Test hätte
         * jeden künftigen Aufrufer einzeln erwischen müssen. `EngineDefaultTest`
         * hält nur fest, dass der Vorgabewert nicht zurückkommt.
         */
        DatabaseEngine $engine,
    ): Database {
        $this->guardQuota($subscription);

        $driver = $this->driver($engine);
        $result = $driver->createDatabase($driver->prefix($subscription), $label, $collation);

        // **Was gilt, steht in der Antwort des Agenten** und nicht in dem, was
        // das Panel bestellt hat. Er hat den Namen zusammengesetzt; ihn hier
        // ein zweites Mal zu bauen wäre die zweite Fassung derselben Regel.
        $database = new Database([
            'name' => $result['name'],
            'label' => $label,
            'engine' => $engine,
            'status' => DatabaseStatus::Active,
            'charset' => $result['charset'],
            'collation' => $result['collation'],
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
    public function createUser(
        Subscription $subscription,
        string $label,
        array $databases,
        string $host,

        // Ohne Vorgabewert — die Begründung steht an {@see self::create()}.
        // Genau hier ist der Zugang einer PostgreSQL-Datenbank in MariaDB
        // gelandet, weil der Aufrufer das Argument nicht mitgab.
        DatabaseEngine $engine,
    ): array {
        $driver = $this->driver($engine);
        $prefix = $driver->prefix($subscription);

        $this->guardFreeName($driver, $prefix, $label, $host);

        $password = $this->secret->generate();

        $result = $driver->createUser(
            $prefix,
            $label,
            array_map(static fn (Database $d): string => $d->name, $databases),
            $host,
            $password,
        );

        $user = new DbUser([
            'name' => $result['name'],
            'label' => $label,
            'engine' => $engine,
            'host' => $result['host'],
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
        $driver = $this->driver($user->engine);

        $driver->setPassword(
            $this->prefix($driver, $user->subscription_id),
            $user,
            $this->databaseNamesOf($user),
            $password,
        );

        return $password;
    }

    /**
     * Die Datenbanken, an denen ein Zugang gerade hängt — aus dem Bestand.
     *
     * Gebraucht von PostgreSQL, wo Passwort setzen und Freigeben dieselbe
     * Operation sind ({@see PostgresDriver::setPassword()}), und beim Entfernen
     * einer Rolle, die vorher aufgeräumt werden muss.
     *
     * **Ohne Mandantenklammer**, aus derselben Richtung wie überall hier: Der
     * Zugang ist bereits durch sie gekommen, und im Arbeiter ist niemand
     * angemeldet.
     *
     * @return list<string>
     */
    private function databaseNamesOf(DbUser $user): array
    {
        return $this->tenancy->withoutRestriction(
            static fn (): array => $user->databases()->pluck('name')->all()
        );
    }

    /**
     * Einen Zugangsnamen, den es schon gibt, gar nicht erst anlegen.
     *
     * **Der Anlass steht in einer Rechtezeile vom 8. August 2026.** Der Agent
     * baut `CREATE USER IF NOT EXISTS` und danach `ALTER USER … IDENTIFIED BY`.
     * Der `ALTER` ist dort richtig und begründet: Ein zweiter Anlauf nach einem
     * abgebrochenen Vorgang bekäme sonst den Benutzer mit dem *alten* Passwort,
     * während der Kunde das neue in der Hand hält.
     *
     * **Nur gilt derselbe Weg auch für den ganz normalen zweiten Klick.** Wer
     * eine zweite Datenbank anlegt und den vorbelegten Namen `user` stehen
     * lässt, bekommt keinen zweiten Zugang, sondern denselben mit einem neuen
     * Passwort — und die Anwendung, die das alte in ihrer Konfigurationsdatei
     * hat, ist ab diesem Moment ausgesperrt. Das Panel meldete dazu „Zugang
     * angelegt". Auf `cloudsrv24` ist genau das passiert; gesehen hat es
     * niemand, weil nichts danach fragt (docs/36 §22.3n).
     *
     * **Geprüft wird vor dem Aufruf und nicht danach.** Der Agent ist
     * absichtlich wiederholbar; die Frage „gibt es diesen Namen schon" gehört
     * dorthin, wo eine Absicht bekannt ist — und das ist hier.
     *
     * **Ohne Mandantenklammer, und das ist keine Bequemlichkeit.** Der Name ist
     * serverweit eindeutig, weil er das Präfix des Systembenutzers trägt. Ob er
     * frei ist, darf nicht davon abhängen, wer gerade fragt: Ein Kunde, dem die
     * Klammer die Zeile verbirgt, bekäme sonst ein „ja, frei" und danach ein
     * ersetztes Passwort.
     */
    private function guardFreeName(EngineDriver $driver, string $prefix, string $label, string $host): void
    {
        $name = $driver->userName($prefix, $label);

        $vergeben = $this->tenancy->withoutRestriction(
            static fn (): bool => DbUser::query()->where('name', $name)->where('host', $host)->exists(),
        );

        if ($vergeben !== true) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Den Zugang %s gibt es schon. Ein zweiter mit demselben Namen würde sein Passwort '.
            'ersetzen — und die Anwendung, die das alte benutzt, wäre ausgesperrt. Wähle einen '.
            'anderen Namen; ein neues Passwort für den vorhandenen setzt man an ihm selbst.',
            $name,
        ));
    }

    /** Ein Recht vergeben oder zurücknehmen — ein Paar je Aufruf. */
    public function grant(DbUser $user, Database $database, bool $granted): void
    {
        $driver = $this->driver($user->engine);

        $driver->grant(
            $this->prefix($driver, $user->subscription_id),
            $user,
            $database->name,
            $granted,
        );

        if ($granted) {
            $user->databases()->syncWithoutDetaching([(int) $database->id]);

            return;
        }

        $user->databases()->detach((int) $database->id);
    }

    /** Einen Zugang entfernen — unmittelbar; er trägt kein Geheimnis, aber auch keine Wartezeit. */
    public function removeUser(DbUser $user): void
    {
        $driver = $this->driver($user->engine);

        $driver->removeUser(
            $this->prefix($driver, $user->subscription_id),
            $user,
            $this->databaseNamesOf($user),
        );

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
     * **Und wer bleibt, wird trotzdem genannt.** `DROP DATABASE` nimmt in
     * MariaDB die Rechte auf das Schema nicht mit; ein Zugang, der an einer
     * zweiten Datenbank hängt und darum überlebt, behielt bis zum 8. August
     * 2026 sein `GRANT ALL` auf die entfernte (`docs/36 §22.3p`). Deshalb
     * stehen beide Listen im Auftrag: `users` geht, `revoke` bleibt und
     * verliert das Recht. Die Aufteilung fällt hier und nicht im Agenten — aus
     * demselben Grund wie oben.
     *
     * **Der Zustand wird auf `Removing` gesetzt, bevor der Vorgang läuft** —
     * das ist kein Widerspruch zu „der Zustand folgt dem Agenten": `Removing`
     * ist keine Behauptung über das System, sondern die Aussage, dass ein
     * Vorgang läuft. Was der Agent entscheidet, ist, ob die Zeile danach
     * verschwindet, und das tut sie in {@see DbLifecycle}.
     */
    public function remove(Database $database, ?int $accountId = null, bool $withdrawing = false): Operation
    {
        $subscription = $this->subscriptionOf($database);

        if ($subscription === null) {
            throw new RuntimeException('Zu dieser Datenbank gibt es kein Abonnement mehr.');
        }

        $driver = $this->driver($database->engine);
        $prefix = $driver->prefix($subscription);

        [$doomed, $staying] = $this->usersOf($database, $withdrawing);

        $task = $driver->removalTask();

        $operation = Operation::query()->create([
            'subscription_id' => $database->subscription_id,
            'subject_type' => OperationSubject::Database->value,
            'subject_id' => $database->id,
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => $driver->removalPayload($prefix, $database, $doomed, $staying),
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
     * **Und jeder Zugang geht mit** — siehe {@see self::usersOf()}. Wer hier
     * fragt, ob ein Zugang noch an einer anderen Datenbank hängt, bekommt für
     * jede dieser Datenbanken „ja", weil sie alle noch dastehen und erst
     * nacheinander verschwinden. Genau so blieb am 11. August 2026 eine Rolle
     * auf `cloudsrv24` stehen, während der Vorgang „fertig" meldete.
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
            $operations[] = $this->remove($database, $accountId, withdrawing: true);
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
     * Die Zugänge dieser Datenbank, aufgeteilt: die mitgehen, und die bleiben.
     *
     * **Jeder verbundene Zugang steht in genau einer der beiden Listen.** Das
     * ist die Eigenschaft, an der `OrphanedGrantTest` hängt: Wer aus der ersten
     * herausfällt, landet in der zweiten und verliert dort sein Recht — es gibt
     * keinen dritten Ausgang, an dem einer unerwähnt bleibt.
     *
     * **Beim Rückbau gilt die Frage nicht**, und das hat ein Abnahmelauf
     * gekostet. Verschwindet das ganze Abonnement, werden alle seine
     * Datenbanken **auf einmal** eingereiht — und jeder dieser Vorgänge
     * berechnet seine Listen beim Einreihen, also während die anderen
     * Datenbanken noch dastehen. Ein Zugang an zwei Datenbanken zählt damit in
     * beiden Vorgängen als „hängt noch woanders" und geht mit keinem mit.
     *
     * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt
     * > die anderen Vorgänge derselben Reihe nicht.**
     *
     * Gemessen am 11. August 2026 auf `cloudsrv24`: Nach dem Rückbau von
     * `cloudlab24.de` stand `x45c97683d84c369c_web` noch im Cluster, während
     * Datenbanken, Sicherungen und die Eigentümerrolle fort waren. Gemeldet hat
     * es `srvpanel db` — der Vorgang selbst stand auf „fertig".
     *
     * `$withdrawing` beantwortet sie deshalb ohne Blick auf den Bestand: Wenn
     * das Abonnement geht, geht jeder seiner Zugänge. Doppelt genannte Rollen
     * schaden nicht, der Agent entfernt sie mit `IF EXISTS`.
     *
     * @return array{0: list<DbUser>, 1: list<DbUser>}
     */
    private function usersOf(Database $database, bool $withdrawing = false): array
    {
        return $this->tenancy->withoutRestriction(function () use ($database, $withdrawing): array {
            $doomed = [];
            $staying = [];

            foreach ($database->users()->get() as $user) {
                if ($withdrawing) {
                    $doomed[] = $user;

                    continue;
                }

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

                    continue;
                }

                $staying[] = $user;
            }

            return [$doomed, $staying];
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
    private function prefix(EngineDriver $driver, ?int $subscriptionId): string
    {
        $subscription = $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($subscriptionId)
        );

        if ($subscription === null) {
            throw new RuntimeException('Zu diesem Zugang gibt es kein Abonnement mehr.');
        }

        return $driver->prefix($subscription);
    }
}
