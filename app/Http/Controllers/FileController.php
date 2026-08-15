<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Files\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Ops\FilesList;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Der Dateimanager.
 *
 * **Hier steht keine Pfadprüfung**, und das ist so gewollt. Die Grenze hält die
 * Sandbox im Agenten (`docs/51 §5`); eine zweite Prüfung hier sähe aus wie die
 * Schranke, wäre keine, und der nächste Umbau verliesse sich auf sie.
 * Validiert wird, was eine Validierung ist: dass ein Pfad überhaupt geschickt
 * wurde und wie lang er sein darf.
 *
 * **Jede Änderung steht im Protokoll, jedes Nachsehen nicht.** Ein
 * Dateimanager erzeugt beim Blättern Dutzende Abfragen je Minute; ein Protokoll,
 * das sie alle aufnimmt, ist nach einem Tag unlesbar und verdeckt genau die
 * Zeile, für die man es liest.
 *
 * **Ein Fehler des Agenten wird zur Feldmeldung.** `docs/19 §6` verlangt, dass
 * der Satz oben in der Zusammenfassung steht; eine `AgentException` mit
 * `denied` oder `not_found` ist für den Kunden kein Serverfehler, sondern eine
 * Auskunft über das, was er gerade versucht hat.
 */
final class FileController extends Controller
{
    public function __construct(
        private readonly Files $files,
        private readonly Audit $audit,
    ) {}

    /**
     * Der Weg in den Dateimanager, ohne dass jemand eine Abo-Kennung kennt.
     *
     * ## Warum es diesen Griff gibt
     *
     * `Domains` und `Datenbanken` stehen im Menü, sobald ein aktives Abonnement
     * da ist. Der Dateimanager stand dort nicht — er war über
     * `Abonnements → Name → Dateien` erreichbar, also drei Klicks tief, und das
     * hat der Betreiber im Prüflauf gemeldet (`docs/55`, Befund 8).
     *
     * **Er kann aber nicht wie die beiden anderen aussehen.** Domains und
     * Datenbanken sind mandantengeklammerte **Listen** unter einer festen
     * Adresse; Dateien hängen an *einem* Abonnement, weil jedes sein eigenes
     * Chroot hat. Ein Menüpunkt braucht deshalb eine Antwort auf „welches" —
     * und die ist bei einem Kunden mit einem Abonnement eine andere als bei
     * einem mit dreien.
     *
     * **Bei genau einem geht es hinein, bei mehreren zur Auswahl.** Eine
     * Auswahlseite auch für den Normalfall wäre ein Klick, der nie eine Frage
     * beantwortet; ein Untermenü je Abonnement wächst mit deren Zahl ins
     * Unlesbare.
     *
     * ## Was hier **nicht** steht
     *
     * Keine Fähigkeitsprüfung an der Route, und das ist begründet: Sie hätte
     * kein Objekt. Gefiltert wird je Abonnement über dieselbe Policy, die die
     * Zielseite später anwendet — nicht über eine zweite Fassung der Regel.
     */
    public function pick(Request $request): RedirectResponse|Response
    {
        $account = $request->user();

        /*
         * **Die Mandantenklammer hat schon gefiltert**, bevor diese Zeile
         * läuft; `browseFiles` ist die zweite Frage und nicht die erste. Ein
         * Admin sieht damit alle Abonnements — für ihn steht der Punkt aber
         * nicht im Menü, weil „welches" bei tausend Kunden keine Auswahlliste
         * mehr ist, sondern die Abonnementsliste, die es schon gibt.
         */
        $erreichbar = Subscription::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Subscription $s): bool => $account?->can('browseFiles', $s) ?? false)
            ->values();

        if ($erreichbar->count() === 1) {
            return to_route('files.index', ['subscription' => $erreichbar->first()?->id]);
        }

        return Inertia::render('Files/Pick', [
            'subscriptions' => $erreichbar
                ->map(static fn (Subscription $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])
                ->all(),
        ]);
    }

    /**
     * Jeder Eintrag sagt, ob er zum Gerüst des Abonnements gehört.
     *
     * ## Warum das in die Liste gehört
     *
     * `Scheme::protect()` weist Umbenennen, Rechte und Entfernen für die sechs
     * Verzeichnisse des Schemas **immer** ab — für jeden Kunden, in jedem
     * Zustand. Die Liste zeigte die drei Griffe trotzdem: Sie entscheidet über
     * `writable`, und `.ssh` gehört dem Kunden mit `0700`.
     *
     * Gemessen im Browser am 15. August 2026 (`docs/55`, Befund 18). Zwei Zeilen
     * über dem `v-if` in der Vorlage steht der Satz, den das verletzt: Der Knopf
     * erscheint nur, wenn der Betrachter ihn drücken darf, und die Antwort
     * darauf kommt aus derselben Stelle, die ihn später abweist.
     *
     * > **Ein Knopf, der nie funktioniert, ist keine Auskunft — er ist eine
     * > Zusage, die das System nicht einlöst.**
     *
     * Bei „Entfernen" ist die Absage noch lehrreich; bei „Rechte" nicht: Der
     * Kunde öffnet den Editor, setzt neun Kästchen, drückt Speichern — und
     * erfährt erst dann, dass es nie ging.
     *
     * ## Warum `Scheme` gefragt wird und keine Liste hier steht
     *
     * Agent-Klassen sind aus der Anwendung autoladbar. Eine zweite Aufzählung
     * hier wäre die Fassung, die beim nächsten Zuwachs des Schemas veraltet —
     * derselbe Grund, aus dem `Scheme` seine Liste aus
     * {@see SubscriptionProvision} holt statt sie
     * abzutippen.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function marked(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => [
                ...$entry,
                'fixed' => Scheme::isFixed((string) ($entry['path'] ?? '')),
            ],
            $entries,
        );
    }

    /**
     * Der Baum und die Liste.
     */
    public function index(Request $request, Subscription $subscription): Response
    {
        $path = $this->path($request->query('path', '/'));

        $listing = $this->attempt(fn (): array => $this->files->list($subscription, $path));

        return Inertia::render('Files/Index', [
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
            ],
            'path' => $listing['path'] ?? $path,
            'entries' => $this->marked($listing['entries'] ?? []),
            'truncated' => $listing['truncated'] ?? false,

            /*
             * **Welche Verzeichnisse der Webserver ausliefert.**
             *
             * Die Rechteanzeige sagt dem Kunden, was seine Einstellung
             * bewirkt, und der wichtigste Satz davon lautet „Der Webserver
             * kann diese Datei ausliefern". Er stimmt nur unterhalb eines
             * DocumentRoots — und welche das sind, weiss die Seite nicht.
             *
             * `httpdocs` fest hineinzuschreiben wäre die naheliegende Zeile
             * und für jede zweite Domain falsch: Ihr DocumentRoot heisst wie
             * sie selbst. Es käme dann eine Auskunft heraus, die für den einen
             * Ordner stimmt und für den daneben nicht — und zwar wortgleich.
             *
             * > **Ein Satz, der an einer Stelle stimmt und an der nächsten
             * > nicht, ist schlechter als kein Satz.**
             */
            'documentRoots' => $subscription->domains()
                ->whereNotNull('document_root')
                ->pluck('document_root')
                ->map(static fn (string $root): string => '/'.$root)
                ->unique()
                ->values()
                ->all(),

            // **Die Antwort auf „darf ich" kommt aus derselben Policy, die es
            // später abweist** — nicht aus einem `v-if` auf den Kontotyp. Eine
            // zweite Fassung der Regel wäre die, die veraltet
            // (`AbilityReachTest`).
            'can' => [
                'edit' => $request->user()?->can('editFiles', $subscription) ?? false,
            ],
        ]);
    }

    /**
     * Die Unterverzeichnisse eines Verzeichnisses — für den Baum.
     *
     * **Der zweite Griff dieses Panels, der JSON zurückgibt** (der erste ist
     * die Datenbankkonsole, `docs/46 §20.9`). Der Grund ist hier ein anderer:
     * Ein Baum klappt einen Ast auf, ohne die Seite zu wechseln — eine
     * Inertia-Antwort täte genau das und nähme dabei jeden anderen geöffneten
     * Ast mit.
     *
     * **`POST`, obwohl er nur liest**, aus demselben Grund wie dort: damit alle
     * Griffe dieser Bauart zusammenbleiben und keiner eine eigene hat, die
     * später jemand erklären muss.
     */
    public function tree(Request $request, Subscription $subscription): JsonResponse
    {
        $path = $this->path($request->input('path', '/'));

        try {
            return response()->json($this->files->tree($subscription, $path));
        } catch (AgentException $exception) {
            /*
             * **422 und nicht 500.** „Das Verzeichnis gibt es nicht" ist für
             * den Betrachter keine Störung, sondern eine Auskunft über den Ast,
             * den er gerade aufklappen wollte — meistens, weil ihn jemand
             * anderes inzwischen entfernt hat.
             */
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Eine Datei für den Editor.
     */
    public function read(Request $request, Subscription $subscription): Response
    {
        $path = $this->path($request->query('path'));

        $file = $this->attempt(fn (): array => $this->files->read($subscription, $path));

        return Inertia::render('Files/Edit', [
            'subscription' => ['id' => $subscription->id, 'name' => $subscription->name],
            'path' => $path,
            'entry' => $file['entry'] ?? null,
            'content' => $file['content'] ?? null,

            // Beide getrennt, weil sie verschiedene Sätze verlangen: „zu gross
            // zum Öffnen" und „keine Textdatei" sind für den Kunden zwei
            // verschiedene Auskünfte und zwei verschiedene nächste Schritte.
            'binary' => $file['binary'] ?? false,
            'tooLarge' => $file['too_large'] ?? false,
            'can' => [
                'edit' => $request->user()?->can('editFiles', $subscription) ?? false,
            ],
        ]);
    }

    public function write(Request $request, Subscription $subscription): RedirectResponse
    {
        /*
         * **`nullable`, und ohne das war „Datei anlegen" kaputt.**
         *
         * Laravels globaler Stapel enthält `ConvertEmptyStringsToNull`. Eine
         * leere Datei schickt `content: ''`, und daraus wird `null`, **bevor**
         * die Prüfung läuft. `present` ist damit erfüllt (der Schlüssel ist da),
         * `string` nicht — und der Kunde liest „The content field must be a
         * string." über einem Formular, das nur nach einem Namen gefragt hat.
         *
         * Gefunden im Browser am 15. August 2026 (`docs/55`, Befund 6), beim
         * ersten Anlegen einer Datei auf einem echten Server. Es traf **beide**
         * Wege: das Anlegen aus der Liste und das Speichern einer geleerten
         * Datei aus dem Editor.
         *
         * > **Eine Regel, die den leeren Wert verbietet, verbietet genau den
         * > Fall, für den der Griff gebaut ist.**
         */
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'content' => ['present', 'nullable', 'string'],
        ]);

        $result = $this->attempt(
            fn (): array => $this->files->write($subscription, $data['path'], $data['content'] ?? ''),
        );

        /*
         * **Angelegt oder gespeichert — entschieden am Zustand und nicht an
         * der Absicht.**
         *
         * Der Agent sagt, ob es die Datei vorher gab (`created`). Dieselbe
         * Route bedient damit beides: das Speichern aus dem Editor und das
         * Anlegen aus der Liste, ohne dass ein Feld im Formular mitteilt, was
         * gemeint war.
         *
         * Ein Flag wäre die naheliegende Zeile und der Fehler aus P4: eine
         * Bedingung, die an einer **Absicht** hängt statt an einem **Zustand**
         * — und beim nächsten Aufrufer stimmt sie nicht mehr.
         */
        $created = ($result['created'] ?? false) === true;

        $this->audit->record('file.written', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
            'created' => $created,
        ]);

        // Wer eine Datei anlegt, will etwas hineinschreiben. Der Editor ist
        // deshalb das Ziel — und nur dann, denn beim Speichern *aus* dem Editor
        // wäre eine Weiterleitung dorthin ein Kreis.
        if ($created) {
            return to_route('files.edit', ['subscription' => $subscription->id, 'path' => $data['path']])
                ->with('success', 'Die Datei ist angelegt.');
        }

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['path'])])
            ->with('success', 'Die Datei ist gespeichert.');
    }

    public function makeDirectory(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:4096']]);

        $this->attempt(fn (): array => $this->files->makeDirectory($subscription, $data['path']));

        $this->audit->record('file.directory.created', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['path'])])
            ->with('success', 'Das Verzeichnis ist angelegt.');
    }

    /**
     * Entfernen — einen Eintrag oder viele.
     *
     * **Die Einzahl ist eine Auswahl aus einem und kein eigener Weg.** Bis
     * Schritt 5h hatte diese Methode ein `path`; die Mehrfachauswahl hätte sie
     * daneben um ein `paths` ergänzen können, und dann gäbe es zwei Fassungen
     * derselben Regel — die seltener benutzte veraltet.
     */
    public function remove(Request $request, Subscription $subscription): RedirectResponse
    {
        $paths = $this->selection($request, ['recursive' => ['boolean']]);
        $recursive = $request->boolean('recursive');

        $result = $this->each($paths, function (string $path) use ($subscription, $recursive): void {
            $this->files->remove($subscription, $path, $recursive);

            $this->audit->record('file.removed', subscriptionId: (int) $subscription->id, context: [
                'path' => $path,
                'recursive' => $recursive,
            ]);
        });

        $this->report($paths, $result, 'entfernt');

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($paths[0] ?? '/')])
            ->with('success', $result['done'] === 1
                ? 'Der Eintrag ist entfernt.'
                : sprintf('%d Einträge sind entfernt.', $result['done']));
    }

    /**
     * Umbenennen.
     *
     * **Ein eigener Griff, seit `move` eine Auswahl verschiebt.** Beide bewegen
     * denselben Eintrag mit demselben `rename()`, aber sie beantworten
     * verschiedene Fragen: Umbenennen nennt einen **Namen**, Verschieben ein
     * **Verzeichnis**. Solange beide dasselbe Feld `to` benutzten, musste der
     * Aufrufer wissen, welche der beiden Bedeutungen gerade gilt — und die Seite
     * hat den Pfad dafür selbst zusammengesetzt.
     *
     * > **Ein Feld mit zwei Bedeutungen hat keine.**
     *
     * Der neue Name ist deshalb ein Name: `basename()` nimmt ihm jeden
     * Verzeichnisteil ab. Das ist **keine** Schranke — die hält das Chroot —,
     * sondern die Antwort auf die Frage, wonach gefragt wurde.
     */
    public function rename(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $leaf = basename(str_replace('\\', '/', $data['name']));

        if ($leaf === '' || $leaf === '.' || $leaf === '..') {
            throw ValidationException::withMessages(['name' => 'Das ist kein brauchbarer Name.']);
        }

        $to = rtrim(dirname($data['path']), '/').'/'.$leaf;

        $this->attempt(fn (): array => $this->files->move($subscription, $data['path'], $to));

        $this->audit->record('file.moved', subscriptionId: (int) $subscription->id, context: [
            'from' => $data['path'],
            'to' => $to,
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($to)])
            ->with('success', 'Der Eintrag ist umbenannt.');
    }

    public function move(Request $request, Subscription $subscription): RedirectResponse
    {
        $paths = $this->selection($request, ['to' => ['required', 'string', 'max:4096']]);
        $ziel = $request->string('to')->toString();

        $result = $this->each($paths, function (string $path) use ($subscription, $ziel): void {
            $to = $this->into($ziel, $path);

            $this->files->move($subscription, $path, $to);

            $this->audit->record('file.moved', subscriptionId: (int) $subscription->id, context: [
                'from' => $path,
                'to' => $to,
            ]);
        });

        $this->report($paths, $result, 'verschoben');

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => $ziel])
            ->with('success', $result['done'] === 1
                ? 'Der Eintrag ist verschoben.'
                : sprintf('%d Einträge sind verschoben.', $result['done']));
    }

    public function copy(Request $request, Subscription $subscription): RedirectResponse
    {
        $paths = $this->selection($request, ['to' => ['required', 'string', 'max:4096']]);
        $ziel = $request->string('to')->toString();

        $result = $this->each($paths, function (string $path) use ($subscription, $ziel): void {
            $to = $this->into($ziel, $path);

            $this->files->copy($subscription, $path, $to);

            $this->audit->record('file.copied', subscriptionId: (int) $subscription->id, context: [
                'from' => $path,
                'to' => $to,
            ]);
        });

        $this->report($paths, $result, 'kopiert');

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => $ziel])
            ->with('success', $result['done'] === 1
                ? 'Der Eintrag ist kopiert.'
                : sprintf('%d Einträge sind kopiert.', $result['done']));
    }

    public function chmod(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],

            // Die Obergrenze ist dieselbe wie im Agenten, und sie steht hier,
            // damit der Kunde eine Feldmeldung bekommt statt einer Absage aus
            // der Tiefe. Sie ist nicht die Schranke — die steht dort.
            'mode' => ['required', 'integer', 'min:0', 'max:511'],
        ]);

        $this->attempt(fn (): array => $this->files->chmod($subscription, $data['path'], (int) $data['mode']));

        $this->audit->record('file.chmod', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
            'mode' => (int) $data['mode'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['path'])])
            ->with('success', 'Die Rechte sind gesetzt.');
    }

    /**
     * Hochladen.
     *
     * **Die Datei geht über das Zwischenlager und nicht über den Aufruf.**
     * Laravel legt sie beim Empfang ohnehin im Dateisystem ab; sie von dort zu
     * lesen und als Zeichenkette an den Agenten zu reichen hiesse, eine
     * 500-MB-Datei zweimal durch den Arbeitsspeicher zu schicken.
     *
     * **Der Name im Zwischenlager kommt nicht vom Kunden.** Er wird gewürfelt;
     * der gewünschte Name gilt erst am Ziel, und dort deutet ihn das Chroot.
     * Ein kundengewählter Name im Schreibbereich des Panels wäre ein Pfad, den
     * der Agent später als root liest.
     */
    /**
     * Hochladen — eine Datei oder viele.
     *
     * ## Der eigentliche Gegenstand ist die Rückmeldung
     *
     * Mehrere Dateien anzunehmen ist eine Schleife. Was daran Arbeit macht,
     * ist der Fall, der hier der **Normalfall** ist: Datei 7 von 20 reisst die
     * Quota, und die anderen neunzehn liegen schon da.
     *
     * Eine Erfolgsmeldung über einem Verzeichnis, in dem neunzehn statt zwanzig
     * Dateien liegen, ist genau der Fehler, den `docs/48` an anderer Stelle
     * gefunden hat:
     *
     * > **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen,
     * > als wäre sie durchgelaufen.**
     *
     * Deshalb wird **je Datei** übernommen und **je Datei** gemeldet, und die
     * Zahl derer, die durch sind, steht im ersten Satz. Ein Abbruch beim ersten
     * Fehler wäre die kürzere Fassung und die schlechtere: Der Kunde wüsste
     * dann nicht, ob die restlichen an derselben Sache scheitern oder nie
     * versucht wurden.
     *
     * ## Warum nicht alles oder nichts
     *
     * Ein Rückbau des schon Übernommenen wäre die dritte Möglichkeit, und sie
     * ist hier falsch: Er löschte Dateien im Verzeichnis des Kunden, die
     * genauso gut vorher dort gelegen haben könnten. Ein Vorgang, der beim
     * Aufräumen fremde Dateien mitnimmt, ist schlimmer als einer, der die
     * Hälfte schafft und es sagt.
     */
    public function upload(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],
        ]);

        /** @var list<UploadedFile> $incoming */
        $incoming = $request->file('files');

        $done = 0;
        $failed = [];

        foreach ($incoming as $file) {
            $name = $file->getClientOriginalName();

            /*
             * **Der Zielpfad entsteht hier und nicht im Browser.**
             *
             * Bis zum Mehrfach-Upload schickte die Seite den vollständigen Pfad
             * mitsamt Dateinamen; bei mehreren Dateien wäre das **ein** Pfad für
             * alle gewesen — zwanzig Dateien unter demselben Namen, neunzehnmal
             * überschrieben, und der Vorgang hätte Erfolg gemeldet.
             *
             * `basename()` nimmt dem Namen jeden Verzeichnisteil ab, den ein
             * Browser mitschicken könnte. Das ist **keine** Schranke — die hält
             * das Chroot —, sondern die Antwort auf die Frage, wie die Datei
             * heissen soll.
             */
            $leaf = basename(str_replace('\\', '/', $name));

            if ($leaf === '' || $leaf === '.' || $leaf === '..') {
                $failed[$name] = 'hat keinen brauchbaren Dateinamen';

                continue;
            }

            $target = rtrim($data['path'], '/').'/'.$leaf;

            $staged = $file->storeAs('uploads', bin2hex(random_bytes(16)), ['disk' => 'local']);

            if ($staged === false) {
                $failed[$name] = 'liess sich nicht zwischenspeichern';

                continue;
            }

            try {
                $this->files->upload($subscription, Storage::disk('local')->path($staged), $target);

                $done++;

                $this->audit->record('file.uploaded', subscriptionId: (int) $subscription->id, context: [
                    'path' => $target,
                ]);
            } catch (AgentException $exception) {
                $failed[$name] = $exception->getMessage();
            } finally {
                // **Auch im Fehlerfall.** Ein Zwischenlager, das nur bei Erfolg
                // aufgeräumt wird, füllt sich genau mit den Dateien, deren
                // Übernahme scheiterte — und das sind die grossen.
                Storage::disk('local')->delete($staged);
            }
        }

        if ($failed !== []) {
            /*
             * **Die Zahl steht vor der Liste**, und zwar auch dann, wenn sie
             * null ist. Ohne sie liest der Kunde drei Fehlermeldungen und weiss
             * nicht, ob die anderen siebzehn durchgekommen sind.
             */
            $messages = [sprintf(
                'Von %d Dateien %s %d hochgeladen.',
                count($incoming),
                $done === 1 ? 'ist' : 'sind',
                $done,
            )];

            foreach ($failed as $name => $reason) {
                $messages[] = sprintf('%s: %s', $name, $reason);
            }

            throw ValidationException::withMessages(['files' => $messages]);
        }

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => $data['path']])
            ->with('success', $done === 1
                ? 'Die Datei ist hochgeladen.'
                : sprintf('%d Dateien sind hochgeladen.', $done));
    }

    /**
     * Ein Archiv entpacken.
     *
     * **Übersprungene und verlegte Einträge gehen als Meldung zurück.** Ein
     * Archiv, von dem die Hälfte fehlt, ohne dass es jemand sagt, ist
     * schlimmer als eines, das gar nicht entpackt.
     */
    public function extract(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'target' => ['required', 'string', 'max:4096'],
        ]);

        $result = $this->attempt(fn (): array => $this->files->extract($subscription, $data['path'], $data['target']));

        $this->audit->record('file.extracted', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
            'written' => $result['written'] ?? 0,
            'skipped' => count($result['skipped'] ?? []),
        ]);

        $uebergangen = count($result['skipped'] ?? []) + count($result['redirected'] ?? []);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => $data['target']])
            ->with('success', $uebergangen === 0
                ? sprintf('Das Archiv ist entpackt — %d Einträge.', $result['written'] ?? 0)
                : sprintf(
                    'Das Archiv ist entpackt — %d Einträge, %d übergangen, weil sie aus dem Zielverzeichnis herausführen.',
                    $result['written'] ?? 0,
                    $uebergangen,
                ));
    }

    /**
     * Packen — eine Auswahl in **ein** Archiv.
     *
     * **Hier gibt es keine Schleife**, und das ist der Unterschied zu Entfernen,
     * Kopieren und Verschieben. Die drei tun je Eintrag dasselbe und können den
     * einen schaffen und den nächsten nicht; Packen tut **einmal** etwas über
     * alle. Ein Archiv, das die Hälfte der Auswahl enthält und Erfolg meldet,
     * wäre die schlechtere Antwort — deshalb entscheidet der Agent, und zwar für
     * alle zusammen.
     */
    public function compress(Request $request, Subscription $subscription): RedirectResponse
    {
        $paths = $this->selection($request, ['target' => ['required', 'string', 'max:4096']]);
        $target = $request->string('target')->toString();

        $result = $this->attempt(
            fn (): array => $this->files->compress($subscription, $paths, $target),
        );

        $this->audit->record('file.compressed', subscriptionId: (int) $subscription->id, context: [
            'paths' => $paths,
            'target' => $target,
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($target)])
            ->with('success', ($result['entries'] ?? 0) === 1
                ? 'Das Archiv ist gepackt — ein Eintrag.'
                : sprintf('Das Archiv ist gepackt — %d Einträge.', $result['entries'] ?? 0));
    }

    /**
     * Suchen.
     *
     * Sie führt auf dieselbe Liste wie das Blättern und nicht auf eine eigene
     * Seite: Ein Treffer ist eine Datei, und was man damit tun will, steht
     * dort. Eine zweite Fläche mit halben Griffen wäre eine zweite Fassung
     * derselben Liste.
     */
    public function search(Request $request, Subscription $subscription): Response
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:4096'],
            'content' => ['boolean'],
        ]);

        $result = $this->attempt(fn (): array => $this->files->search(
            $subscription,
            $data['path'] ?? '/',
            $data['query'],
            (bool) ($data['content'] ?? false),
        ));

        return Inertia::render('Files/Search', [
            'subscription' => ['id' => $subscription->id, 'name' => $subscription->name],
            'path' => $data['path'] ?? '/',
            'query' => $data['query'],
            'inContent' => (bool) ($data['content'] ?? false),
            'hits' => $result['hits'] ?? [],
            'visited' => $result['visited'] ?? 0,

            // Ohne diese Angabe behauptet eine leere Liste, es gebe nichts —
            // wo „nicht zu Ende gesucht" richtig wäre.
            'truncated' => $result['truncated'] ?? false,

            // **Kein `can` hier.** Die Trefferliste hat keine Griffe, die etwas
            // ändern — sie führt auf die Datei, und dort steht, was man mit ihr
            // tun kann. Eine Fahne, die niemand abfragt, ist eine Zusage ins
            // Leere; `AbilityReachTest` prüft beide Richtungen.
        ]);
    }

    /**
     * Ein Pfad aus der Anfrage — als Zeichenkette, nicht als Prüfung.
     *
     * Die Länge ist begrenzt, damit nicht ein Megabyte Pfad durch die
     * Warteschlange des Agenten geht. Was er *bezeichnet*, entscheidet der
     * Agent im Chroot.
     */
    private function path(mixed $value): string
    {
        $path = is_string($value) && $value !== '' ? $value : '/';

        if (strlen($path) > 4096) {
            throw ValidationException::withMessages([
                'path' => 'Der Pfad ist zu lang.',
            ]);
        }

        return $path;
    }

    /**
     * Die Auswahl aus der Anfrage — und was der jeweilige Griff sonst braucht.
     *
     * **Eine Stelle und nicht vier.** Die vier Griffe der Mehrfachauswahl
     * unterscheiden sich in einem Feld (`recursive`, `to`, `target`) und sind im
     * Rest gleich. Viermal abgeschrieben wäre es viermal die Gelegenheit, die
     * Obergrenze oder das `min:1` in einem davon zu vergessen.
     *
     * **Zurück kommen nur die Pfade.** Das zusätzliche Feld wird hier zwar
     * mitgeprüft, aber vom Griff selbst gelesen — mit `string()` oder
     * `boolean()`, die einen Typ zurückgeben. Ein gemeinsamer Rückgabewert mit
     * drei wahlweisen Schlüsseln wäre an jeder Lesestelle ein „gibt es
     * vielleicht nicht".
     *
     * @param  array<string, list<string>>  $extra
     * @return list<string>
     */
    private function selection(Request $request, array $extra = []): array
    {
        /*
         * **Die Obergrenze kommt aus dem Agenten und wird hier nicht
         * abgeschrieben.** Sie ist die der Liste: Mehr Einträge, als eine Liste
         * zeigt, kann niemand angehakt haben. Eine zweite Zahl an dieser Stelle
         * wäre die, die beim nächsten Zuwachs stehenbleibt — und die Absage käme
         * dann aus der Tiefe statt als Feldmeldung.
         */
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:'.FilesList::MAX_ENTRIES],
            'paths.*' => ['required', 'string', 'max:4096'],
        ] + $extra);

        /** @var array<int, string> $paths */
        $paths = $data['paths'];

        /*
         * **`array_values`, weil die Reihenfolge trägt.** Die erste Zeile der
         * Rückmeldung nennt die Zahl, und die Weiterleitung nach dem Entfernen
         * nimmt den ersten Pfad. Ein Schlüssel `1` ohne `0` — den ein Formular
         * durchaus schicken kann — machte daraus einen undefinierten Zugriff.
         *
         * Und `array_unique`, weil derselbe Eintrag in zwei Schreibweisen
         * zweimal angefasst würde: Beim zweiten Mal meldet der Agent „gibt es
         * nicht", mitten in einer Rückmeldung über siebzehn Erfolge.
         */
        return array_values(array_unique($paths));
    }

    /**
     * Wohin ein Eintrag beim Kopieren und Verschieben kommt.
     *
     * **Das Ziel ist ein Verzeichnis, der Name kommt von der Quelle.** Dieselbe
     * Regel wie beim Hochladen, und aus demselben Grund: Bei mehreren Quellen
     * wäre ein vollständiger Zielpfad **ein** Pfad für alle — der letzte Eintrag
     * gewönne, die anderen wären fort, und der Vorgang meldete Erfolg.
     */
    private function into(string $directory, string $path): string
    {
        return rtrim($directory, '/').'/'.basename($path);
    }

    /**
     * Jeden Eintrag einzeln, und was dabei schiefging festhalten.
     *
     * **Kein Abbruch beim ersten Fehler.** Wer zwanzig Dateien anhakt und bei der
     * siebten in die Quota läuft, will wissen, ob die restlichen dreizehn an
     * derselben Sache scheitern oder nie versucht wurden — und ob sie jetzt am
     * Ziel liegen oder nicht.
     *
     * **Und kein Rückbau des schon Getanen.** Er löschte Einträge im Verzeichnis
     * des Kunden, die genauso gut vorher dort gelegen haben könnten. Ein Vorgang,
     * der beim Aufräumen fremde Dateien mitnimmt, ist schlimmer als einer, der
     * die Hälfte schafft und es sagt.
     *
     * @param  list<string>  $paths
     * @param  callable(string): void  $do
     * @return array{done: int, failed: array<string, string>}
     */
    private function each(array $paths, callable $do): array
    {
        $done = 0;
        $failed = [];

        foreach ($paths as $path) {
            try {
                $do($path);

                $done++;
            } catch (AgentException $exception) {
                $failed[$path] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Was schiefging — mit der Zahl im ersten Satz.
     *
     * > **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als
     * > wäre sie durchgelaufen.** (`docs/48 §3.5`)
     *
     * Bei genau einem Eintrag steht sein Grund allein da. „Von 1 Einträgen ist 0
     * entfernt." wäre die Zahl ohne die Auskunft — und die Auskunft ist bei einem
     * Eintrag alles, was es zu sagen gibt.
     *
     * @param  list<string>  $paths
     * @param  array{done: int, failed: array<string, string>}  $result
     */
    private function report(array $paths, array $result, string $verb): void
    {
        if ($result['failed'] === []) {
            return;
        }

        if (count($paths) === 1) {
            throw ValidationException::withMessages(['path' => array_values($result['failed'])[0]]);
        }

        $messages = [sprintf(
            'Von %d Einträgen %s %d %s.',
            count($paths),
            $result['done'] === 1 ? 'ist' : 'sind',
            $result['done'],
            $verb,
        )];

        foreach ($result['failed'] as $path => $reason) {
            $messages[] = sprintf('%s: %s', $path, $reason);
        }

        throw ValidationException::withMessages(['path' => $messages]);
    }

    /**
     * Einen Agentenaufruf machen und seine Absagen als Feldmeldung ausgeben.
     *
     * **`denied` und `not_found` sind keine Serverfehler.** Sie sind die
     * Antwort auf das, was der Kunde gerade versucht hat — „in dieses
     * Verzeichnis darf das Abonnement nicht schreiben" ist eine Auskunft und
     * gehört als Satz nach oben in die Zusammenfassung (`docs/19 §6`), nicht in
     * eine Fehlerseite mit fünfhundert.
     *
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function attempt(callable $call): array
    {
        try {
            return $call();
        } catch (AgentException $exception) {
            throw ValidationException::withMessages([
                'path' => $exception->getMessage(),
            ]);
        }
    }
}
