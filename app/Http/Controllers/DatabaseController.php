<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseEngine;
use App\Enums\DumpKind;
use App\Enums\DumpStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Databases\Console;
use App\Support\Databases\Databases;
use App\Support\Databases\Dumps;
use App\Support\Databases\Engines\PostgresDriver;
use App\Support\Databases\ImportLimit;
use App\Support\Databases\RemoteAccess;
use App\Support\Databases\Staging;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use App\Support\Web\Page;
use Illuminate\Http\JsonResponse;
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
    /**
     * Wie lange ein Eintrag „Konsole geöffnet" den nächsten unterdrückt.
     *
     * **Eine Stunde** (`docs/46 §3`, Entscheidung 5, Punkt 4). Sie steht als
     * Konstante da, weil der Wächter dazu die Zahl nennt — hier ist
     * ausnahmsweise genau sie die Regel, und ein `3600` mitten im Griff wäre
     * eine Zahl ohne Namen.
     */
    private const CONSOLE_AUDIT_SECONDS = 3600;

    /**
     * Die drei ändernden Handlungen der Konsole, je eine mit eigenem Namen.
     *
     * **Drei Namen und nicht einer mit `mode` im Kontext.** Wer das Protokoll
     * nach „hier wurde gelöscht" durchsucht, filtert nach einer Aktion und nicht
     * nach einem Feld in einem JSON; die vorhandenen Namen dieser Fläche —
     * `database.dump.created`, `database.user.removed` — machen es ebenso.
     *
     * @var array<string, string>
     */
    private const CONSOLE_WRITE_ACTIONS = [
        'insert' => 'database.console.row.created',
        'update' => 'database.console.row.changed',
        'delete' => 'database.console.row.removed',
    ];

    public function __construct(
        private readonly Databases $databases,
        private readonly Dumps $dumps,
        private readonly Audit $audit,
        private readonly Client $agent,
        private readonly RemoteAccess $remote,
        private readonly Console $console,
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
            'engines' => $this->engines($subscription),
        ]);
    }

    /**
     * Die Systeme, in denen dieses Abonnement eine Datenbank anlegen darf.
     *
     * **MariaDB steht immer da, PostgreSQL nur wenn der Betreiber es anbietet**
     * (`docs/38 §16`). Das ist die eine Stelle in P5b, an der eine *Absicht*
     * die richtige Bedingung ist und kein Zustand: Ob PostgreSQL läuft, sagt
     * `pg.server.info`; ob es Kunden angeboten wird, entscheidet der Betreiber
     * auf der Seite „Datenbankserver". Ein laufender Server, den er nicht
     * anbieten will, gehört nicht in dieses Formular.
     *
     * **Jedes System bringt sein eigenes Präfix mit.** In MariaDB ist es der
     * Systembenutzer (`p1001`), in PostgreSQL die gewürfelte Kennung aus
     * `system_users` (`x7f3a…`) — und der Unterschied ist nicht kosmetisch: Der
     * Satz „das Präfix ist der Systembenutzer des Abonnements" wäre für
     * PostgreSQL schlicht falsch, und der Name im Formular auch.
     *
     * **Und jedes sagt selbst, ob es eine Sortierung wählen lässt.** Der erste
     * Entwurf hat das im Formular an „der erste Eintrag ist MariaDB"
     * festgemacht — eine Annahme über die Reihenfolge dieser Liste, also
     * derselbe Stellvertreter, den P5b schon viermal erwischt hat. Die Frage
     * gehört zum System und wandert mit ihm.
     *
     * @return list<array{value: string, label: string, prefix: string, collations: bool}>
     */
    private function engines(Subscription $subscription): array
    {
        $engines = [[
            'value' => DatabaseEngine::MariaDb->value,
            'label' => DatabaseEngine::MariaDb->label(),
            'prefix' => (string) $subscription->system_user,
            'collations' => true,
        ]];

        if (! app(Settings::class)->postgres()) {
            return $engines;
        }

        $engines[] = [
            'value' => DatabaseEngine::Postgres->value,
            'label' => DatabaseEngine::Postgres->label(),
            'prefix' => PostgresDriver::prefixOf(app(Tenancy::class), $subscription),

            // In PostgreSQL entstehen Zeichensatz und Sortierung aus der
            // Vorlage; dieses Panel wählt sie nicht (`docs/38 §5`).
            'collations' => false,
        ];

        return $engines;
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

            /*
             * **Geprüft wird gegen die Liste, die das Formular gezeigt hat**,
             * und nicht bloss gegen das Enum. Ein `Rule::enum()` allein liesse
             * `postgres` auch dann durch, wenn der Betreiber es gar nicht
             * anbietet — die Angabe kommt aus einer Anfrage, und was in der
             * Oberfläche nicht wählbar war, ist keine gültige Wahl.
             *
             * Fehlt sie ganz, ist es MariaDB: Ein Formular ohne Auswahl —
             * weil PostgreSQL nicht angeboten wird — schickt das Feld nicht
             * mit, und ein Pflichtfeld daraus zu machen hiesse, jeden
             * bestehenden Aufruf zu brechen.
             */
            'engine' => ['nullable', 'string', Rule::in(array_column($this->engines($subscription), 'value'))],

            /*
             * **Nur MariaDB kennt eine Sortierung in diesem Sinn.** In
             * PostgreSQL entstehen Zeichensatz und Sortierung aus der Vorlage
             * und werden von diesem Panel nicht gewählt (`docs/38 §5`); das
             * Formular zeigt das Feld dort gar nicht erst.
             */
            'collation' => [
                Rule::requiredIf(fn (): bool => ($request->input('engine') ?? DatabaseEngine::MariaDb->value) === DatabaseEngine::MariaDb->value),
                'nullable', 'string', Rule::in($this->collations()),
            ],

            // Ein Zugang gleich dazu — der Normalfall. Ohne ihn ist eine
            // Datenbank ein Schema, in das niemand hineinkommt.
            'user_label' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,15}$/D'],
        ], [], [
            /*
             * **Der Name muss heissen wie das Feld auf der Seite** (`docs/66`, Befund 3).
             * Die Liste in `lang/de/validation.php` trägt den Namen, der über alle Seiten
             * passt; wo eine Seite ein anderes Wort benutzt, steht es hier. Sonst sucht der
             * Leser ein Feld, das er nicht sieht.
             *
             * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
             */
            'label' => 'Name',
            'engine' => 'System',
        ]);

        try {
            $engine = DatabaseEngine::from($data['engine'] ?? DatabaseEngine::MariaDb->value);

            $database = $this->databases->create(
                $subscription,
                $data['label'],
                /*
                 * **Kein Ersatzwert.** Hier stand `?? $this->collations()[0]`,
                 * also die erste **MariaDB**-Sortierung — und für PostgreSQL
                 * schickte das Formular keine, weil es das Feld dort nicht
                 * zeigt. Der Ersatzwert griff also genau dort, wo er nicht
                 * hingehörte, und PostgreSQL wies jedes Anlegen ab
                 * (`docs/39`, Punkt 3).
                 *
                 * `null` heisst „nicht gewählt"; was daraus wird, weiss der
                 * Treiber und nicht dieser Ort.
                 */
                $data['collation'] ?? null,
                $engine,
            );
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

        // Ohne das eine Abfrage je Zugang für die Netze — bei zehn Zugängen elf
        // Abfragen für eine Seite, die eine braucht.
        $database->loadMissing('users.networks');

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

                /*
                 * **`console` ist die eine Fähigkeit, die ein Betreiberkonto
                 * nicht bekommt** (Entscheidung 3, `DatabasePolicy::console()`).
                 * Sie steht seit Schritt 4 hier, weil es den Knopf gibt, der sie
                 * liest — und keinen Beitrag früher.
                 *
                 * {@see \Tests\Feature\AbilityReachTest} prüft **beide**
                 * Richtungen: Eine Fahne, die die Seite abfragt und niemand
                 * schickt, ist in Vue `undefined` — der Knopf verschwindet dann
                 * für alle. Eine, die geschickt wird und die niemand abfragt,
                 * ist eine Zusage ins Leere. Beides ist dasselbe Muster, und der
                 * erste Anlauf von Schritt 3 ist in die zweite Hälfte gelaufen.
                 */
                'console' => $request->user()?->can('console', $database) ?? false,
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
            'remote' => $this->remoteAccess($database->engine),

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
    private function remoteAccess(DatabaseEngine $engine = DatabaseEngine::MariaDb): array
    {
        /*
         * **Gefragt wird das System, um das es geht.** Bis zum 11. August 2026
         * stand hier `db.server.info` ohne Fallunterscheidung — auch auf der
         * Seite einer PostgreSQL-Datenbank. Die Antwort war dann die
         * `bind-address` von *MariaDB*, und daraus wurde entschieden, ob ein
         * PostgreSQL-Zugang ein Netz eintragen darf. Auf einem Server, der nur
         * eines der beiden von aussen erreichbar hat, ist das genau falsch
         * herum — und es fiele niemandem auf, weil beide Antworten dieselbe
         * Form haben.
         */
        $operation = $engine === DatabaseEngine::Postgres ? 'pg.server.info' : 'db.server.info';

        try {
            $info = $this->agent->call($operation, []);
        } catch (AgentException $error) {
            return ['possible' => false, 'bind_address' => null, 'reason' => $error->getMessage()];
        }

        // MariaDB meldet eine Adresse (`bind_address`), PostgreSQL eine Liste
        // (`listen_addresses`). Beides beantwortet dieselbe Frage, und die
        // Oberfläche zeigt es an derselben Stelle — deshalb steht hier ein
        // Feld und nicht zwei.
        $bind = match (true) {
            is_string($info['listen_addresses'] ?? null) && $info['listen_addresses'] !== '' => $info['listen_addresses'],
            is_string($info['bind_address'] ?? null) => $info['bind_address'],
            default => null,
        };

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
     * Ein Netz eintragen, aus dem dieser Zugang hereindarf — **nur PostgreSQL**.
     *
     * **Und der Zugang muss zu dieser Datenbank gehören.** Die Route prüft
     * `can:update` auf die *Datenbank*; ein Zugang aus einem anderen
     * Abonnement wäre damit noch nicht abgewiesen. Dieselbe Überlegung und
     * dieselbe Prüfung wie in {@see self::access()}.
     */
    public function storeNetwork(Request $request, Database $database, DbUser $user): RedirectResponse
    {
        $data = $request->validate([
            // Wie beim Wirt in {@see self::storeUser()}: Was zulässig ist,
            // steht in `Hba::cidr()` — der Stelle, die die Zeile später
            // schreibt. Eine zweite Formulierung hier wäre die, die
            // auseinanderläuft.
            'cidr' => ['required', 'string'],
        ]);

        $this->guardNetwork($database, $user);

        try {
            $this->remote->add($user, (string) $data['cidr']);
        } catch (AgentException $error) {
            throw ValidationException::withMessages(['cidr' => $error->getMessage()]);
        }

        $this->audit->record('database.user.network', target: $user, subscriptionId: (int) $database->subscription_id);

        return to_route('databases.show', $database);
    }

    /** Ein Netz zurücknehmen. */
    public function destroyNetwork(Database $database, DbUser $user, DbUserNetwork $network): RedirectResponse
    {
        $this->guardNetwork($database, $user);

        if ((int) $network->db_user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'cidr' => 'Dieses Netz gehört zu einem anderen Zugang.',
            ]);
        }

        try {
            $this->remote->remove($network);
        } catch (AgentException $error) {
            throw ValidationException::withMessages(['cidr' => $error->getMessage()]);
        }

        $this->audit->record('database.user.network', target: $user, subscriptionId: (int) $database->subscription_id);

        return to_route('databases.show', $database);
    }

    /**
     * Darf an den Netzen dieses Zugangs überhaupt etwas geändert werden?
     *
     * Drei Bedingungen, und jede fängt einen anderen Fall:
     *
     * 1. **Der Zugang gehört zur Datenbank.** Sonst schriebe eine Anfrage über
     *    die Seite der einen Datenbank an einem Zugang, der zu einer anderen
     *    gehört — die Policy der Route sieht das nicht.
     * 2. **Es ist PostgreSQL.** In MariaDB steht der Wirt im Benutzernamen; ein
     *    Netz an einem MariaDB-Zugang wäre eine Zeile, die nirgends ankommt.
     * 3. **Der Server horcht erreichbar.** Wie beim Wirt in
     *    {@see self::host()}: Das Formular zeigt sich nur dann, aber eine
     *    Anfrage, die es trotzdem schickt, kommt nicht aus dem Formular.
     */
    private function guardNetwork(Database $database, DbUser $user): void
    {
        if (! $database->users->contains('id', $user->id)) {
            throw ValidationException::withMessages([
                'cidr' => 'Dieser Zugang gehört nicht zu dieser Datenbank.',
            ]);
        }

        if ($user->engine !== DatabaseEngine::Postgres) {
            throw ValidationException::withMessages([
                'cidr' => 'Für MariaDB steht die Herkunft im Benutzernamen — ein zweiter Wirt ist dort '
                    .'ein zweiter Zugang mit eigenem Passwort.',
            ]);
        }

        if ($this->remoteAccess(DatabaseEngine::Postgres)['possible'] !== true) {
            throw ValidationException::withMessages([
                'cidr' => 'Der Datenbankserver ist nur lokal erreichbar — eine Zugangsregel für eine '
                    .'fremde Adresse käme nie zustande. Einschalten kann das nur der Betreiber.',
            ]);
        }
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
                /*
                 * **Derselbe Text wie überall sonst im Panel.** Hier stand
                 * `toIso8601String()`, und der Wert wurde von niemandem
                 * gelesen — die Sicherungen zeigten keinen Zeitstempel. Wer
                 * ihn jetzt anzeigt, soll nicht ein zweites Zeitformat neben
                 * „Begonnen", „Beendet" und „Gemessen am" stellen.
                 *
                 * Die Zeit steht in UTC, wie jede andere im Panel; dass der
                 * Betreiber sie auf einer Uhr liest, die zwei Stunden weiter
                 * ist, regelt `docs/40` für alle Stellen zugleich und nicht
                 * für diese eine.
                 */
                'created_at' => Clock::display($dump->created_at),
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
        ], [], [
            // `host` heisst in der gemeinsamen Liste „Server" — das ist der
            // Mailserver. Hier steht am Feld „Erreichbar von" (docs/64,
            // Befund 15).
            'host' => 'Erreichbar von',
        ], [], [
            /*
             * **Der Name muss heissen wie das Feld auf der Seite** (`docs/66`, Befund 3).
             * Die Liste in `lang/de/validation.php` trägt den Namen, der über alle Seiten
             * passt; wo eine Seite ein anderes Wort benutzt, steht es hier. Sonst sucht der
             * Leser ein Feld, das er nicht sieht.
             *
             * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
             */
            'label' => 'Weiterer Zugang',
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
            /*
             * **Das System kommt aus der Datenbank, an der der Zugang hängt.**
             * Hier stand dieser Aufruf ohne das letzte Argument, und
             * {@see Databases::createUser()} hatte `MariaDb` als Vorgabe: Jeder
             * Zugang zu einer PostgreSQL-Datenbank entstand damit in MariaDB —
             * `db.user.create` mit dem Systembenutzer als Präfix und dem
             * PostgreSQL-Namen in der Rechteliste.
             *
             * Gefunden am 10. August 2026 in Punkt 3 der Zwischenabnahme
             * (`docs/39`), auf einem echten Server. Die Vorgabe ist deshalb
             * fort; PHP verlangt das Argument jetzt.
             */
            [$user, $password] = $this->databases->createUser(
                $subscription,
                $label,
                [$database],
                $host,
                $database->engine,
            );
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

            // **Das System, und zwar in jeder Zeile.** Ob die Liste es zeigt,
            // entscheidet sie selbst — an einem Zustand und nicht an einer
            // Einstellung: Solange keine PostgreSQL-Datenbank da ist, wäre eine
            // Spalte, in der überall dasselbe steht, nur eine Spalte weniger
            // Platz für den Namen. Und der ist hier siebzehn Zeichen länger als
            // in P5 (`docs/38 §16`).
            'engine' => $database->engine->value,
            'engine_label' => $database->engine->label(),

            /*
             * **Ob der Kunde dieses System über TCP erreicht.** In MariaDB
             * verbindet eine Anwendung auf demselben Server über den
             * Unix-Socket, in PostgreSQL nicht: `local all all peer` verlangt
             * eine Rolle, die wie der Unix-Benutzer heisst, und
             * `p1001_web` ist keiner (`docs/38 §14`). Der Kunde verbindet
             * deshalb über `127.0.0.1`.
             *
             * **Als Eigenschaft und nicht als Name des Systems.** Die
             * Oberfläche soll den Satz an dem festmachen, was er behauptet —
             * Ein Vergleich mit dem Wert des Systems im Template wäre eine
             * Zeichenkette aus dem Enum, und `DatabaseEngineTest` weist sie ab
             * — er hat diesen Satz hier auch gleich mit erwischt, weil ein
             * Kommentar mit einem Beispiel dasselbe Wort trägt wie der Code.
             */
            'over_tcp' => $database->engine !== DatabaseEngine::MariaDb,

            /*
             * **`null` für PostgreSQL, und das ist keine fehlende Angabe,
             * sondern die richtige.** Die Spalte trägt dort weiterhin
             * `utf8mb4_unicode_ci` — den Vorgabewert aus P5 —, und das ist eine
             * Behauptung über eine Datenbank, die diesen Wert nie gesehen hat.
             * Zeichensatz und Sortierung entstehen in PostgreSQL aus der
             * Vorlage und werden von diesem Panel nicht gewählt
             * (`docs/38 §5`); die Oberfläche zeigt die Zeile deshalb gar nicht.
             *
             * Die Unterscheidung steht hier und nicht im Template: Dort wäre
             * sie ein Vergleich mit dem Wert des Systems als Zeichenkette, und
             * `DatabaseEngineTest` weist den zu Recht ab.
             */
            /*
             * **Die Sortierung wird nicht mehr nach System versteckt.**
             *
             * Hier stand ein `=== MariaDb ? … : null`, und der Grund war gut:
             * Für PostgreSQL hätte in der Zeile der Vorgabewert aus P5 gestanden
             * — `utf8mb4_unicode_ci`, eine Angabe über eine Datenbank, die ihn
             * nie gesehen hat.
             *
             * **Der Grund ist mit dem 10. August 2026 weggefallen.** Seit der
             * Agent das Gebietsschema beim Cluster erfragt und in seiner Antwort
             * zurückmeldet, steht dort ein *gemessener* Wert — `de_DE.UTF-8`.
             * Ihn zu verschweigen wäre jetzt schlechter als ihn zu zeigen:
             * Sortierung ist die Frage, wegen der jemand seine Anwendung
             * umschreibt.
             *
             * Leer bleibt leer: Eine Zeile ohne Angabe soll die Oberfläche
             * weglassen und nicht als leeres Feld zeigen.
             */
            'collation' => ($database->collation ?? '') === '' ? null : $database->collation,
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

                /*
                 * **Die Netze, und für MariaDB eine leere Liste.** Nicht `null`
                 * und keine fehlende Ablage: Die Seite zeigt bei PostgreSQL
                 * eine Zeile je Netz, und „dieser Zugang hat noch keins" ist
                 * eine Auskunft, „hier gibt es das Feld nicht" eine andere. Was
                 * die beiden Systeme unterscheidet, entscheidet `over_tcp`
                 * weiter oben — im Template stünde sonst ein Vergleich mit dem
                 * Wert des Systems als Zeichenkette, und `DatabaseEngineTest`
                 * weist den zu Recht ab.
                 */
                'networks' => $user->networks
                    ->sortBy('cidr')
                    ->map(static fn (DbUserNetwork $network): array => [
                        'id' => (int) $network->id,
                        'cidr' => $network->cidr,
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Der Einstieg in die Konsole — die einzige `GET`-Route dieser Fläche.
     *
     * **Sie trägt nichts als die Datenbank.** Die Tabellenliste holt die Seite
     * nach dem Aufbau über {@see self::consoleTables()}, und das ist kein
     * Umweg: Bei zweihundert Tabellen stünde die Liste sonst in jeder Antwort
     * dieser Route, auch bei einem Zurück aus der Strukturansicht. Der Einstieg
     * ist der Rahmen, nicht der Inhalt.
     *
     * **Und deshalb steht hier kein Tabellenname in der Adresse.** Welche
     * Tabelle offen ist, hält die Seite; ein Name in der Adresse wäre eine
     * zweite Fassung dieses Zustands, und die zweite ist die, die veraltet.
     */
    public function console(Request $request, Database $database): Response
    {
        /*
         * **Wer gesehen hat, steht im Protokoll — einmal je Stunde.**
         *
         * Ohne diesen Eintrag beantwortet das Protokoll „was wurde geändert"
         * und nicht „wer hatte Zugriff" (`docs/46 §3`, Entscheidung 5, Punkt 4).
         * Ohne die Entprellung stünde er bei jedem Öffnen darin — und die
         * Konsole wird beim Arbeiten mehrfach betreten und verlassen.
         *
         * **Er entsteht hier und nicht im Agenten:** Er hält fest, *wer* gesehen
         * hat, und das weiss nur die Seite, die ein angemeldetes Konto kennt.
         *
         * Ein Wert des Kunden steht nicht darin — es gibt an dieser Stelle noch
         * keinen. Was drinsteht, ist die Datenbank, und die ist das Ziel.
         */
        $this->audit->throttled(
            'database.console.opened',
            $database,
            self::CONSOLE_AUDIT_SECONDS,
            $database->subscription_id === null ? null : (int) $database->subscription_id,
        );

        return Inertia::render('Databases/Console', [
            'database' => [
                'id' => (int) $database->id,
                'name' => (string) $database->name,
                'label' => (string) $database->label,
                'engine' => $database->engine->value,
                'engine_label' => $database->engine->label(),
                'subscription' => $database->subscription?->name,
            ],
        ]);
    }

    /**
     * Die Tabellen einer Datenbank.
     *
     * **Auch dieser Griff ist `POST`**, obwohl er nur liest und keinen Wert des
     * Kunden trägt — damit die fünf zusammenbleiben und nicht einer von ihnen
     * eine andere Bauform hat, die später jemand erklären muss.
     */
    public function consoleTables(Request $request, Database $database): JsonResponse
    {
        return $this->answer(fn (): array => [
            'tables' => $this->console->tables($database),
        ]);
    }

    /**
     * Die Struktur einer Tabelle.
     *
     * Sie kommt als eigene Anfrage und nicht mit der Tabellenliste: Bei
     * zweihundert Tabellen wären das zweihundert Katalogabfragen für eine
     * Seite, von denen der Kunde eine ansieht.
     */
    public function consoleColumns(Request $request, Database $database): JsonResponse
    {
        $table = $this->consoleTable($request);

        return $this->answer(fn (): array => [
            'columns' => $this->console->columns($database, $table),
        ]);
    }

    /**
     * Die Indexe einer Tabelle.
     *
     * **Getrennt von der Spaltenliste**, weil die Spaltenliste bei jedem
     * Blättern, Filtern und Schreiben geholt wird und die Indexe nur die
     * Strukturansicht braucht.
     */
    public function consoleIndexes(Request $request, Database $database): JsonResponse
    {
        $table = $this->consoleTable($request);

        return $this->answer(fn (): array => [
            'indexes' => $this->console->indexes($database, $table),
        ]);
    }

    /**
     * Eine Seite Zeilen.
     *
     * **Der Filterwert wird hier nicht geprüft, sondern nur weitergereicht.**
     * Der Vergleich kommt aus einer Positivliste im Agenten, der Spaltenname
     * wird dort im Katalog nachgeschlagen, und der Wert geht durch
     * `Sql::text()`. Ihn hier ein zweites Mal zu prüfen hiesse, die Regel
     * zweimal zu haben — und die zweite Fassung ist die, die veraltet.
     */
    public function consoleRows(Request $request, Database $database): JsonResponse
    {
        $validated = $request->validate([
            'table' => ['required', 'string'],
            'order' => ['required', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'filter' => ['nullable', 'array'],
            'filter.column' => ['required_with:filter', 'string'],
            'filter.operator' => ['required_with:filter', 'string'],
            'filter.value' => ['nullable', 'string'],
        ]);

        /** @var array{column: string, operator: string, value: string}|null $filter */
        $filter = isset($validated['filter']) ? [
            'column' => (string) $validated['filter']['column'],
            'operator' => (string) $validated['filter']['operator'],
            'value' => (string) ($validated['filter']['value'] ?? ''),
        ] : null;

        return $this->answer(fn (): array => $this->console->rows(
            $database,
            (string) $validated['table'],
            (string) $validated['order'],
            ($validated['direction'] ?? 'asc') === 'desc',
            (int) ($validated['offset'] ?? 0),
            $filter,
        ));
    }

    /** Der ganze Wert einer Zelle — der Ausweg aus der Kürzung (`docs/46 §12`). */
    public function consoleCell(Request $request, Database $database): JsonResponse
    {
        $validated = $request->validate([
            'table' => ['required', 'string'],
            'column' => ['required', 'string'],
            'key' => ['required', 'array', 'min:1'],
        ]);

        return $this->answer(fn (): array => $this->console->cell(
            $database,
            (string) $validated['table'],
            $this->consoleKey($validated['key']),
            (string) $validated['column'],
        ));
    }

    /**
     * Eine Zeile anlegen, ändern oder löschen.
     *
     * **`values` wird nicht nach `string` gezwungen.** `null` ist dort ein
     * eigener Zustand und keine leere Eingabe (`docs/46 §10.1`); eine Regel
     * `string` machte aus jedem `NULL` lautlos ein `''`, und zwar an der Stelle,
     * an der der Schaden an der Zeile hinterher nicht zu sehen ist.
     */
    public function consoleWrite(Request $request, Database $database): JsonResponse
    {
        $validated = $request->validate([
            'table' => ['required', 'string'],
            'mode' => ['required', 'in:insert,update,delete'],
            'key' => ['required_unless:mode,insert', 'array'],
            'values' => ['required_unless:mode,delete', 'array'],
        ], [], [
            // `mode` heisst in der gemeinsamen Liste „Rechte" — das sind die
            // Dateirechte. Hier ist es die Art der Änderung (docs/64,
            // Befund 15).
            'mode' => 'Art der Änderung',
        ]);

        $mode = (string) $validated['mode'];
        $table = (string) $validated['table'];
        $key = $mode === 'insert' ? [] : $this->consoleKey($validated['key'] ?? []);

        $answer = $this->answer(fn (): array => $this->console->write(
            $database,
            $table,
            $mode,
            $key,
            $mode === 'delete' ? [] : $this->consoleValues($validated['values'] ?? []),
        ));

        /*
         * **Protokolliert wird die gelungene Handlung, und ohne die Werte.**
         *
         * Der Eintrag steht **nach** dem Vorgang, weil ein abgewiesener
         * Schreibvorgang nichts geändert hat — die Meldung dazu bekommt der
         * Kunde, und ein Protokolleintrag über einen Versuch beantwortet keine
         * Frage, die jemand stellt.
         *
         * **Und ohne die Werte** (`docs/46 §3`, Entscheidung 4). Was im Eintrag
         * steht, ist *welche* Zeile geändert wurde, nicht *worauf*: Tabelle und
         * Schlüssel. Der Schlüssel gehört dazu — er sagt, welche Zeile es war —,
         * der Inhalt nicht.
         *
         * > **Ein Protokoll, das den Inhalt mitschreibt, ist eine Datenhaltung
         * > mit einem anderen Namen.**
         *
         * Und sie überlebte das Löschen der Zeile: Wer eine Zeile entfernt,
         * hinterliesse ihren Inhalt an einer Stelle, an der ihn niemand vermutet.
         */
        if ($answer->getStatusCode() < 400) {
            $this->audit->record(
                self::CONSOLE_WRITE_ACTIONS[$mode],
                target: $database,
                subscriptionId: $database->subscription_id === null ? null : (int) $database->subscription_id,
                context: array_filter([
                    'table' => $table,
                    'key' => $key === [] ? null : $key,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $answer;
    }

    /**
     * Die Antwort eines Konsolengriffs.
     *
     * **Ein Fehler des Agenten wird zu einer Meldung und nicht zu einem 500er.**
     * Was er sagt, ist für den Kunden brauchbar — „Diese Spalte gibt es in
     * dieser Tabelle nicht", „Der Vorgang hat 0 Zeilen getroffen" —, und es ist
     * die Auskunft, an der zwei Abnahmekriterien hängen (`docs/46 §4`,
     * Punkte 4 und 6). Eine Umschreibung nähme sie weg.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function answer(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException|AgentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
    }

    private function consoleTable(Request $request): string
    {
        /** @var array{table: string} $validated */
        $validated = $request->validate(['table' => ['required', 'string']]);

        return $validated['table'];
    }

    /**
     * Der Schlüssel einer Zeile — nur Zeichenketten.
     *
     * Was aus der Anzeige zurückkommt, ist der Text, den die Datenbank geliefert
     * hat (`docs/46 §20.3`). Ihn hier in eine Zahl zu wandeln hiesse, dass zwei
     * Stellen entscheiden, wie ein Wert aussieht — und die zweite kennt den Typ
     * der Spalte nicht.
     *
     * @param  array<array-key, mixed>  $key
     * @return array<string, string>
     */
    private function consoleKey(array $key): array
    {
        $clean = [];

        foreach ($key as $column => $value) {
            $clean[(string) $column] = is_scalar($value) ? (string) $value : '';
        }

        return $clean;
    }

    /**
     * Die zu schreibenden Spalten — `null` bleibt `null`.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string|null>
     */
    private function consoleValues(array $values): array
    {
        $clean = [];

        foreach ($values as $column => $value) {
            $clean[(string) $column] = $value === null ? null : (is_scalar($value) ? (string) $value : '');
        }

        return $clean;
    }
}
