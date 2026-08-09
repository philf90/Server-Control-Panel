<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DumpKind;
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
use App\Support\Databases\Staging;
use App\Support\Plans\Quota;
use App\Support\Web\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Db\Names;
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
        private readonly Client $agent,
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
             * **Und das `D` am Ende gehört dazu.** Ohne es passt `$` in PCRE
             * auch vor einem abschliessenden Zeilenumbruch — `"shop\n"` käme
             * durch dieses Formular und prallte am Agenten ab, mit einer
             * Meldung, die niemand deuten kann. Genau der Fund, den CLAUDE.md
             * für neun Muster unter `agent/` festhält; `AnchoredPatternTest`
             * las bis zum 8. August 2026 nur dort und nicht hier.
             *
             * Die Gegenprobe, dass beide dasselbe sagen, steht in
             * `DatabaseFormTest` — und sie hat diesen Unterschied bei ihrem
             * ersten Lauf gefunden.
             */
            'label' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/D'],
            'collation' => ['required', 'string', Rule::in($this->collations())],

            // Ein Zugang gleich dazu — der Normalfall. Ohne ihn ist eine
            // Datenbank ein Schema, in das niemand hineinkommt.
            'user_label' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/D'],
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

        return $this->createUserFor($database, $subscription, (string) $data['user_label'], field: 'user_label');
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

            /*
             * **Die Zugänge dieses Abonnements, die diese Datenbank *nicht*
             * erreichen.** Nur sie, und nicht alle: Diese Seite handelt von
             * einer Datenbank, und wer hier steht, soll etwas mit ihr zu tun
             * haben.
             *
             * Gemessen statt geschätzt (docs/36 §22.3o): Eine Spalte mit
             * Kontrollkästchen über *alle* Zugänge macht die Seite auf 390px um
             * 69 % höher und stellt neben jeden unbeteiligten Zugang einen
             * Knopf „Entfernen", der ihn ganz löscht.
             */
            'unlinked' => $this->unlinkedUsers($database),

            /*
             * **Ob ein Zugang für eine fremde Adresse überhaupt etwas nützt.**
             * Die Antwort kommt vom Server und nicht aus einer Einstellung des
             * Panels: Der Betreiber kann die Horchadresse jederzeit von Hand
             * ändern, und eine im Panel gemerkte Fassung wäre die, die veraltet
             * — dieselbe Begründung wie bei `db.user.grant` (docs/36 §12).
             *
             * Und **angezeigt statt ausgeblendet**: Wer das Feld sucht, soll
             * lesen, warum es nicht da ist, statt es für einen Fehler zu
             * halten.
             */
            'remote' => $this->remoteAccess(),

            // Was ein Hochladen annimmt — die Zahl kommt aus der Quelle, damit
            // die Oberfläche nicht etwas anderes verspricht als die Prüfregel
            // (docs/36 §10.3).
            'import_limit' => ImportLimit::label(),
        ]);
    }

    /**
     * Horcht der Datenbankserver auf einer erreichbaren Adresse?
     *
     * **Ein Aufruf je Seitenaufruf, und das ist Absicht.** Der Wert liesse sich
     * zwischenspeichern; dann stünde nach einem `srvpanel db --remote=on` für
     * die Dauer des Zwischenspeichers das Gegenteil auf der Seite, und der
     * Betreiber suchte den Fehler bei sich. Der Agent sitzt auf demselben
     * Rechner hinter einem Unix-Socket.
     *
     * **Ein Fehler ist hier kein Fehler der Seite.** Antwortet der Agent nicht,
     * ist die Antwort „kein Fernzugriff" mit dem Grund daneben — die
     * Datenbankseite selbst funktioniert weiter.
     *
     * @return array{possible: bool, bind_address: string|null, reason: string|null}
     */
    private function remoteAccess(): array
    {
        try {
            $info = $this->agent->call('db.server.info', []);
        } catch (AgentException $error) {
            return ['possible' => false, 'bind_address' => null, 'reason' => $error->getMessage()];
        }

        $bind = is_string($info['bind_address'] ?? null) ? $info['bind_address'] : null;

        return [
            'possible' => ($info['remote'] ?? false) === true,
            'bind_address' => $bind,
            'reason' => ($info['remote'] ?? false) === true ? null : sprintf(
                'Der Datenbankserver horcht auf %s und ist damit nur lokal erreichbar. '
                .'Einschalten kann das nur der Betreiber, auf dem Server: srvpanel db --remote=on',
                $bind ?? 'einer lokalen Adresse',
            ),
        ];
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
     * Eine mitgebrachte Sicherung entgegennehmen.
     *
     * **Die Datei geht nicht über den Socket.** Sie landet im Schreibbereich
     * des Panels, und der Agent holt sie von dort ab — wortgleich der Grund,
     * aus dem eine Sicherung beim Herunterladen nicht zurückgereicht wird.
     *
     * **Die Magic Bytes werden hier schon geprüft, obwohl der Agent es noch
     * einmal tut.** Das ist keine zweite Fassung derselben Regel, sondern die
     * Reihenfolge: Wer eine ZIP-Datei hochlädt, soll die Meldung sofort am Feld
     * sehen — und nicht eine Zeile im Bestand bekommen, die auf einen Vorgang
     * wartet, der gleich scheitert. Was gilt, entscheidet trotzdem der Agent;
     * er sieht die Datei, die tatsächlich bei ihm ankommt.
     *
     * **Und die Zeile entsteht erst, wenn die Datei liegt.** Andersherum stünde
     * bei einem Fehlschlag beim Verschieben eine Sicherung im Bestand, zu der
     * es nie eine Datei gab.
     */
    public function import(Request $request, Database $database): RedirectResponse
    {
        $request->validate([
            'dump' => ['required', 'file', ImportLimit::rule()],
        ], [], ['dump' => 'Sicherung']);

        $file = $request->file('dump');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['dump' => 'Es ist keine Datei angekommen.']);
        }

        if (! $this->looksLikeGzip($file->getRealPath())) {
            throw ValidationException::withMessages([
                'dump' => 'Das ist keine gzip-Datei. Erwartet wird eine Sicherung, wie dieses Panel sie '
                    .'schreibt (.sql.gz) — die Endung allein genügt nicht.',
            ]);
        }

        /*
         * **Der Name kommt von hier und nicht vom Absender.** Ein hochgeladener
         * Dateiname ist eine Zeichenkette aus dem Netz; sie in einen Pfad zu
         * setzen ist der Vorgang, den die Positivliste des Agenten verhindert.
         * Wie die Sicherung später heisst, entscheidet ohnehin
         * `Dumps::record()` gegen den Bestand.
         */
        $name = bin2hex(random_bytes(16)).'.sql.gz';
        $staging = Staging::ensure();
        $source = $staging.'/'.$name;

        try {
            $file->move($staging, $name);
            $operation = $this->dumps->import($database, $source);
        } catch (RuntimeException|AgentException $error) {
            @unlink($source);

            throw ValidationException::withMessages(['dump' => $error->getMessage()]);
        }

        $this->audit->record('database.dump.imported', target: $database, subscriptionId: (int) $database->subscription_id);

        return to_route('operations.show', $operation)
            ->with('status', 'Die Sicherung wird übernommen.');
    }

    /**
     * Die ersten beiden Bytes — mehr sagt über eine Datei nichts Billigeres.
     *
     * `1f 8b` steht am Anfang jedes gzip-Stroms (RFC 1952). Ob dahinter SQL
     * steht, entscheidet der Datenbankserver beim Einspielen; ein Panel, das
     * SQL zu erkennen versucht, baut einen Parser, den niemand geprüft hat.
     */
    private function looksLikeGzip(string|false $path): bool
    {
        if ($path === false) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 2);
        fclose($handle);

        return $head === "\x1f\x8b";
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
                /*
                 * **Die Marke und nicht der Wert.** Das Template verglich die
                 * Herkunft bis zum 9. August selbst, als Zeichenkette — dieselbe
                 * Familie wie der Frame-Fehler aus `docs/36 §22.3w`, nur
                 * zwischen PHP und Browser: ein Wert über eine Grenze, die kein
                 * Typ prüft. Hinüber geht jetzt der fertige Text aus
                 * {@see DumpKind::label()}, genau wie `status_label` darüber.
                 *
                 * Der alte Vergleich steht hier bewusst nicht ausgeschrieben:
                 * `DumpKindTest` verbietet den Wert überall ausser in der
                 * Aufzählung, und ein Zitat in einem Kommentar ist auch ein
                 * Vorkommen.
                 */
                'kind_label' => $dump->kind->label(),
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
            'label' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/D'],

            // Der Wirt wird hier **nicht** mit einem eigenen Ausdruck geprüft:
            // Was zulässig ist, steht in `Names::host()`, und eine zweite
            // Formulierung wäre die, die beim nächsten Mal auseinanderläuft
            // (dieselbe Entscheidung wie bei `SubscriptionProvision::
            // subscriptionName()` im Abonnementformular). Geprüft wird unten,
            // mit der Regel des Agenten und einer lesbaren Meldung.
            'host' => ['nullable', 'string'],
        ]);

        $subscription = $database->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'label' => 'Zu dieser Datenbank gibt es kein Abonnement mehr — sie ist der Rest eines Rückbaus.',
            ]);
        }

        return $this->createUserFor(
            $database,
            $subscription,
            (string) $data['label'],
            field: 'label',
            host: $this->host($data['host'] ?? null),
        );
    }

    /**
     * Der Wirt eines neuen Zugangs — geprüft mit der Regel des Agenten.
     *
     * **Und die Sperre sitzt hier und nicht nur im Formular.** Das Feld
     * erscheint nur, wenn der Server auf einer erreichbaren Adresse horcht;
     * eine Anfrage, die es trotzdem mitschickt, ist damit noch nicht abgewiesen
     * — sie kommt nicht aus dem Formular. Ein Zugang für eine fremde Adresse an
     * einem Server, der nur lokal horcht, wäre ein Konto, das nichts kann und
     * das niemand mehr zuordnet, sobald der Fernzugriff eingeschaltet wird.
     */
    private function host(mixed $value): string
    {
        $host = is_string($value) ? trim($value) : '';

        if ($host === '' || $host === 'localhost') {
            return 'localhost';
        }

        if ($this->remoteAccess()['possible'] !== true) {
            throw ValidationException::withMessages([
                'host' => 'Der Datenbankserver ist nur lokal erreichbar — ein Zugang für eine fremde '
                    .'Adresse käme nie zustande. Einschalten kann das nur der Betreiber.',
            ]);
        }

        try {
            return Names::host($host);
        } catch (AgentException $error) {
            throw ValidationException::withMessages(['host' => $error->getMessage()]);
        }
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

    /**
     * Die Zugänge des Abonnements ohne Recht auf diese Datenbank.
     *
     * @return list<array{id: int, name: string, host: string}>
     */
    private function unlinkedUsers(Database $database): array
    {
        if ($database->subscription_id === null) {
            // Eine verwaiste Datenbank hat kein Abonnement, aus dem ein Zugang
            // kommen könnte — und ihre Zeile ist ohnehin der Rest eines
            // Rückbaus (docs/36 §5).
            return [];
        }

        $linked = $database->users->pluck('id')->all();

        return DbUser::query()
            ->where('subscription_id', $database->subscription_id)
            ->whereNotIn('id', $linked)
            ->orderBy('name')
            ->get()
            ->map(static fn (DbUser $user): array => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'host' => $user->host,
            ])
            ->all();
    }

    /**
     * Einen vorhandenen Zugang verbinden oder die Verbindung lösen.
     *
     * **Warum es das erst seit dem 8. August 2026 gibt.**
     * {@see Databases::grant()} und die Operation `db.user.grant` waren seit P5
     * gebaut, registriert und begründet — und kein Controller, keine Route und
     * kein Test hat sie je aufgerufen. Aufgefallen ist es, als das Anlegen
     * einen vergebenen Zugangsnamen abzuweisen begann: Damit hatte ein Kunde
     * mit einer Anwendung auf zwei Datenbanken plötzlich gar keinen Weg mehr
     * (docs/36 §22.3o).
     *
     * **Der fremde Zugang wird ausdrücklich abgewiesen.** Die Mandantenklammer
     * hält einen Kunden schon an der Modellbindung auf; ein Betreiber ist
     * unbeschränkt und könnte die Nummer eines Zugangs aus einem anderen
     * Abonnement eintragen. Ein Recht über Abonnementgrenzen hinweg ist genau
     * das, was `docs/36 §3.1` ausschliesst.
     */
    public function access(Request $request, Database $database, DbUser $user): RedirectResponse
    {
        $data = $request->validate(['granted' => ['required', 'boolean']]);

        abort_unless(
            $user->subscription_id !== null && $user->subscription_id === $database->subscription_id,
            404,
        );

        $granted = (bool) $data['granted'];

        try {
            $this->databases->grant($user, $database, $granted);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['granted' => $error->getMessage()]);
        }

        $this->audit->record(
            $granted ? 'database.user.granted' : 'database.user.revoked',
            target: $database,
            subscriptionId: (int) $database->subscription_id,
            context: ['user' => $user->name],
        );

        return to_route('databases.show', $database)->with(
            'status',
            $granted
                ? sprintf('%s hat jetzt Zugriff auf %s.', $user->name, $database->name)
                : sprintf('%s hat keinen Zugriff mehr auf %s.', $user->name, $database->name),
        );
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
    /**
     * @param  string  $field  Das Feld, an dem die Meldung erscheint
     *
     * **Der Name des Feldes kommt vom Aufrufer und steht nicht hier.** Er war
     * fest auf `user_label` verdrahtet — und das ist der Name im Formular
     * „Datenbank anlegen". Das Formular „Weiterer Zugang" auf der
     * Datenbankseite schickt `label`, und seine Zeile unter dem Feld liest
     * `errors.label`. Eine Meldung von dort landete also an einem Feld, das
     * diese Seite gar nicht hat. Unsichtbar war sie nicht — {@see FormErrors}
     * zeigt oben alles, was ankommt —, aber die Zeile *am* Feld sagt, welches
     * gemeint ist, und genau die fehlte (docs/36 §22.3n).
     */
    private function createUserFor(
        Database $database,
        Subscription $subscription,
        string $label,
        string $field,
        string $host = 'localhost',
    ): RedirectResponse {
        try {
            [$user, $password] = $this->databases->createUser($subscription, $label, [$database], $host);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages([$field => $error->getMessage()]);
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
            'size_bytes' => $database->size_bytes,
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
