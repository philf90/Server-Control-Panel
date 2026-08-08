<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DumpStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Databases\Databases;
use App\Support\Databases\Dumps;
use App\Support\Databases\ImportLimit;
use App\Support\Plans\Quota;
use App\Support\Web\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\DbDatabaseCreate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Datenbanken ansehen, anlegen, Zugänge verwalten, entfernen.
 *
 * **Der Steuerungscode prüft nichts Fachliches.** Er nimmt die Anfrage
 * entgegen, gibt sie an {@see Databases} und übersetzt das Ergebnis in eine
 * Antwort — dieselbe Aufteilung wie in {@see DomainController}. Jede Grenze —
 * Kontingent, Name, Präfix — sitzt im Dienst oder im Agenten, weil sie dort
 * auch für Aufrufer gilt, die kein Formular benutzen.
 *
 * **Das Passwort geht genau einmal über den Bildschirm.** Es kommt aus
 * {@see Databases::createUser()} zurück und wird über die Sitzung an die
 * Anzeigeseite weitergereicht — nicht als Feld im Inertia-Payload einer Liste,
 * die jemand neu lädt, und nicht im Protokoll. `docs/36 §4`: Es liegt nirgends.
 */
final class DatabaseController extends Controller
{
    public function __construct(
        private readonly Databases $databases,
        private readonly Dumps $dumps,
        private readonly Audit $audit,
    ) {}

    /**
     * Die Liste — für den Betreiber serverweit, für den Kunden seine eigene.
     *
     * Der Unterschied steht nicht hier, sondern in der Mandantenklammer. Was
     * hier steht, ist der kurze Weg zu einer neuen Datenbank, und den bekommt
     * nur der Kunde: Er führt in ein bestimmtes Abonnement, und der Betreiber
     * hat davon Hunderte. Dieselbe Begründung wie bei den Domains in P3.
     */
    public function index(Request $request): Response
    {
        $databases = Database::query()
            ->with(['subscription', 'users'])
            ->orderBy('name')
            ->paginate(Page::SIZE)
            ->withQueryString();

        return Inertia::render('Databases/Index', [
            'databases' => Page::from($databases, fn (Database $database): array => $this->row($database)),
            'creatable' => $this->creatable($request->user()),
        ]);
    }

    public function create(Subscription $subscription): Response
    {
        return Inertia::render('Databases/Create', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,

                // Das Präfix steht im Formular, damit niemand raten muss, wie
                // die Datenbank am Ende heisst — und damit klar ist, dass er
                // nur den Teil dahinter wählt.
                'prefix' => (string) $subscription->system_user,
            ],
            'collations' => $this->collations(),
            'quota' => $this->quota($subscription),
        ]);
    }

    public function store(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            /*
             * **Dieselbe Regel wie im Agenten, nicht eine zweite Formulierung
             * davon** (`docs/26 §3`): Kleinbuchstaben, Ziffern, Unterstrich,
             * beginnend mit einem Buchstaben, höchstens sechzehn Zeichen. Der
             * Ausdruck steht hier trotzdem ausgeschrieben, weil eine
             * Prüfregel eine Meldung am Feld erzeugen muss und keine Ausnahme
             * — was der Agent abweist, weist dieses Formular vorher ab, mit
             * demselben Ergebnis und einem lesbaren Grund.
             *
             * Die Gegenprobe, dass beide dasselbe sagen, steht in
             * `DatabaseFormTest`.
             */
            'label' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/'],
            'collation' => ['required', 'string', Rule::in($this->collations())],

            // Ein Zugang gleich dazu — der Normalfall. Ohne ihn ist eine
            // Datenbank ein Schema, in das niemand hineinkommt.
            'user_label' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/'],
        ]);

        try {
            $database = $this->databases->create($subscription, $data['label'], $data['collation']);
        } catch (RuntimeException|AgentException $error) {
            /*
             * **Als Fehler am Feld und nicht als Weiterleitung.** `back()`
             * weiss in diesem Panel nicht, wohin zurück ist — der Vhost
             * schickt `Referrer-Policy: no-referrer`, und Inertia navigiert
             * über XHR (`RedirectTargetTest`). Eine Ausnahme trifft dagegen
             * genau das Feld, an dem der Grund steht, und Inertia bringt den
             * Benutzer von selbst auf das Formular zurück.
             */
            throw ValidationException::withMessages(['label' => $error->getMessage()]);
        }

        $this->audit->record('database.created', target: $database, subscriptionId: (int) $subscription->id);

        if (($data['user_label'] ?? null) === null) {
            return to_route('databases.show', $database)
                ->with('status', 'Datenbank '.$database->name.' angelegt.');
        }

        return $this->createUserFor($database, $subscription, (string) $data['user_label']);
    }

    public function show(Request $request, Database $database): Response
    {
        $subscription = $database->subscription;

        return Inertia::render('Databases/Show', [
            'database' => $this->row($database),
            'subscription' => $subscription === null ? null : [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'prefix' => (string) $subscription->system_user,
            ],

            /*
             * **Die `can`-Ablage und kein `v-if` auf den Kontotyp.** Gefragt
             * wird dieselbe Policy, die die Route später prüft — eine zweite
             * Fassung im Template wäre die, die veraltet (CLAUDE.md, dritte
             * Grenze). `AbilityReachTest` prüft beide Richtungen.
             */
            'can' => [
                'update' => $request->user()?->can('update', $database) ?? false,
                'delete' => $request->user()?->can('delete', $database) ?? false,
            ],

            // Das Passwort eines gerade angelegten Zugangs — genau einmal, aus
            // der Sitzung, und danach ist es fort.
            'secret' => session('database.secret'),

            'dumps' => $this->dumpRows($database),
            'import_limit_mb' => ImportLimit::MEGABYTES,
        ]);
    }

    /**
     * Sichern — als Vorgang, weil ein mysqldump dauern kann.
     *
     * Nach dem Absenden landet man auf dem Vorgang und sieht zu; die Zeile
     * steht bis dahin auf „läuft" ({@see DumpStatus}).
     */
    public function export(Database $database): RedirectResponse
    {
        try {
            $operation = $this->dumps->export($database);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['dump' => $error->getMessage()]);
        }

        $this->audit->record('database.dump.created', target: $database, subscriptionId: (int) $database->subscription_id);

        return to_route('operations.show', $operation)
            ->with('status', 'Die Sicherung wird erstellt.');
    }

    /**
     * Herunterladen — das Panel liest die Datei, der Agent reicht sie nicht durch.
     *
     * Eine Datei von zwei Gigabyte über den Unix-Socket zurückzugeben wäre der
     * Weg, auf dem der Agent den Speicher des Servers füllt. Sie gehört deshalb
     * `root:srvpanel 0640`: Er schreibt, das Panel liest.
     */
    public function download(Database $database, DatabaseDump $dump): BinaryFileResponse
    {
        abort_unless($dump->database_id === $database->id, 404);
        abort_unless($dump->status->usable(), 404);

        $path = $dump->path();

        abort_if($path === null || ! is_file($path), 404);

        $this->audit->record('database.dump.downloaded', target: $dump, subscriptionId: (int) $database->subscription_id);

        return response()->download($path, $dump->storage_name.'.sql.gz');
    }

    /**
     * Zurückspielen — unter einem befristeten Datenbankbenutzer.
     *
     * Warum das der sicherheitsrelevanteste Weg von P5 ist, steht in
     * `agent/src/Ops/DbRestore.php`: Ein Dump ist beliebiges SQL, und als
     * Datenbank-root eingespielt wäre ein `GRANT … ON *.*` darin genau der
     * Ausbruch, den das Abnahmekriterium ausschliesst.
     */
    public function restore(Database $database, DatabaseDump $dump): RedirectResponse
    {
        abort_unless($dump->database_id === $database->id, 404);

        try {
            $operation = $this->dumps->restore($dump, $database);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['dump' => $error->getMessage()]);
        }

        $this->audit->record('database.dump.restored', target: $dump, subscriptionId: (int) $database->subscription_id);

        return to_route('operations.show', $operation)
            ->with('status', 'Die Sicherung wird zurückgespielt.');
    }

    public function destroyDump(Database $database, DatabaseDump $dump): RedirectResponse
    {
        abort_unless($dump->database_id === $database->id, 404);

        try {
            $operation = $this->dumps->remove($dump);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['dump' => $error->getMessage()]);
        }

        $this->audit->record('database.dump.removed', target: $database, subscriptionId: (int) $database->subscription_id);

        return to_route('operations.show', $operation)
            ->with('status', 'Die Sicherung wird entfernt.');
    }

    /**
     * Die Sicherungen einer Datenbank — die jüngste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    private function dumpRows(Database $database): array
    {
        return DatabaseDump::query()
            ->where('database_id', $database->id)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (DatabaseDump $dump): array => [
                'id' => (int) $dump->id,
                'name' => $dump->storage_name,
                'kind' => $dump->kind,
                'status' => $dump->status->value,
                'status_label' => $dump->status->label(),
                'usable' => $dump->status->usable(),
                'bytes' => $dump->bytes,
                'created_at' => $dump->created_at?->toIso8601String(),
                'last_error' => $dump->last_error,
            ])
            ->values()
            ->all();
    }

    /** Einen weiteren Zugang zu dieser Datenbank. */
    public function storeUser(Request $request, Database $database): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/'],
        ]);

        $subscription = $database->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'label' => 'Zu dieser Datenbank gibt es kein Abonnement mehr — sie ist der Rest eines Rückbaus.',
            ]);
        }

        return $this->createUserFor($database, $subscription, (string) $data['label']);
    }

    /**
     * Ein neues Passwort — der einzige Weg zurück zu einem verlorenen.
     *
     * Es gibt kein Nachschlagen (`docs/36 §4`), und das ist die Entscheidung
     * und nicht ihr Nebeneffekt.
     */
    public function resetPassword(Request $request, Database $database, DbUser $user): RedirectResponse
    {
        try {
            $secret = $this->databases->resetPassword($user);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['user' => $error->getMessage()]);
        }

        $this->audit->record('database.user.password', target: $user, subscriptionId: (int) $database->subscription_id);

        return to_route('databases.show', $database)
            ->with('database.secret', ['user' => $user->name, 'password' => $secret]);
    }

    public function destroyUser(Database $database, DbUser $user): RedirectResponse
    {
        try {
            $this->databases->removeUser($user);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['user' => $error->getMessage()]);
        }

        $this->audit->record('database.user.removed', target: $database, subscriptionId: (int) $database->subscription_id);

        return to_route('databases.show', $database)
            ->with('status', 'Zugang entfernt.');
    }

    /**
     * Die Datenbank entfernen — als Vorgang, weil ein `DROP` dauern kann.
     *
     * Nach dem Absenden landet man auf dem Vorgang und sieht zu; die Zeile
     * verschwindet erst, wenn der Agent geantwortet hat ({@see DbLifecycle}).
     */
    public function destroy(Database $database): RedirectResponse
    {
        $name = $database->name;
        $subscriptionId = (int) $database->subscription_id;

        $operation = $this->databases->remove($database);

        $this->audit->record('database.removed', target: $database, subscriptionId: $subscriptionId);

        return to_route('operations.show', $operation)
            ->with('status', 'Datenbank '.$name.' wird entfernt.');
    }

    /** Anlegen und danach das Passwort genau einmal zeigen. */
    private function createUserFor(Database $database, Subscription $subscription, string $label): RedirectResponse
    {
        try {
            [$user, $password] = $this->databases->createUser($subscription, $label, [$database]);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['user_label' => $error->getMessage()]);
        }

        $this->audit->record('database.user.created', target: $user, subscriptionId: (int) $subscription->id);

        return to_route('databases.show', $database)
            ->with('database.secret', ['user' => $user->name, 'password' => $password]);
    }

    /**
     * Die Sortierungen, die das Panel anbietet — aus dem Agenten.
     *
     * Sie stehen dort und nicht hier, aus demselben Grund wie
     * `SubscriptionProvision::reservedDirectories()`: Wächst die Liste im
     * Agenten, wächst das Formular mit. Eine abgetippte zweite Fassung wäre bei
     * der ersten Erweiterung falsch.
     *
     * @return list<string>
     */
    private function collations(): array
    {
        return DbDatabaseCreate::charsets()['utf8mb4'];
    }

    /**
     * Der Stand gegen das Kontingent — angezeigt, nicht durchgesetzt.
     *
     * Durchgesetzt wird er in {@see Databases::guardQuota()}. Was hier steht,
     * ist die Auskunft; `docs/20 §5.2`: „Jede Prüfung passiert serverseitig
     * beim Anlegen — die Oberfläche zeigt den Stand nur an."
     *
     * @return array{used:int, limit:int|null}
     */
    private function quota(Subscription $subscription): array
    {
        $limit = $subscription->quota(Quota::Databases->value);

        return [
            'used' => $this->databases->countFor($subscription),
            'limit' => is_numeric($limit) ? (int) $limit : null,
        ];
    }

    /**
     * Die Abonnements, in denen dieses Konto eine Datenbank anlegen darf.
     *
     * Gefragt wird dieselbe Policy, die die Route später prüft — nicht der
     * Kontotyp.
     *
     * @return list<array{id:int,name:string}>
     */
    private function creatable(?Account $account): array
    {
        if (! $account instanceof Account || $account->isAdmin()) {
            return [];
        }

        return Subscription::query()
            ->whereIn('id', $account->accessibleSubscriptionIds())
            ->whereIn('status', SubscriptionStatus::usableValues())
            ->orderBy('name')
            ->get()
            ->filter(static fn (Subscription $s): bool => $account->can('create', [Database::class, $s]))
            ->map(static fn (Subscription $s): array => ['id' => (int) $s->id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Database $database): array
    {
        return [
            'id' => (int) $database->id,
            'name' => $database->name,
            'label' => $database->label,
            'status' => $database->status->value,
            'status_label' => $database->status->label(),
            'collation' => $database->collation,
            'size_mb' => $database->size_mb,
            'size_measured_at' => $database->size_measured_at?->toIso8601String(),

            // Die Abschrift und nicht die Beziehung: Ist das Abonnement
            // zurückgebaut, steht hier weiterhin ein Name — und ohne ihn wäre
            // eine verwaiste Zeile eine Zeile ohne Auskunft (`docs/36 §5`).
            //
            // `->` und nicht `?->`: Der Null-Zusammenführungsoperator hat
            // isset-Semantik und fängt das fehlende Abonnement schon selbst ab.
            // Dieselbe Zeile steht so in `Subscription::quota()`, mit demselben
            // Kommentar — ein `?->` davor ist nicht falsch, aber überflüssig,
            // und PHPStan sagt das.
            'subscription' => $database->subscription->name ?? $database->subscription_name,
            'subscription_id' => $database->subscription_id === null ? null : (int) $database->subscription_id,
            'orphaned' => $database->orphaned(),

            'users' => $database->users->map(static fn (DbUser $user): array => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'host' => $user->host,
                'remote' => $user->remote(),
                'status' => $user->status->value,
                'status_label' => $user->status->label(),
            ])->values()->all(),
        ];
    }
}
