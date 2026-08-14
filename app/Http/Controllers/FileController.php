<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Files\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;

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
            'entries' => $listing['entries'] ?? [],
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
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'content' => ['present', 'string'],
        ]);

        $result = $this->attempt(fn (): array => $this->files->write($subscription, $data['path'], $data['content']));

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

    public function remove(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'recursive' => ['boolean'],
        ]);

        $this->attempt(fn (): array => $this->files->remove(
            $subscription,
            $data['path'],
            (bool) ($data['recursive'] ?? false),
        ));

        $this->audit->record('file.removed', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
            'recursive' => (bool) ($data['recursive'] ?? false),
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['path'])])
            ->with('success', 'Der Eintrag ist entfernt.');
    }

    public function move(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:4096'],
            'to' => ['required', 'string', 'max:4096'],
        ]);

        $this->attempt(fn (): array => $this->files->move($subscription, $data['from'], $data['to']));

        $this->audit->record('file.moved', subscriptionId: (int) $subscription->id, context: [
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['to'])])
            ->with('success', 'Der Eintrag ist verschoben.');
    }

    public function copy(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:4096'],
            'to' => ['required', 'string', 'max:4096'],
        ]);

        $this->attempt(fn (): array => $this->files->copy($subscription, $data['from'], $data['to']));

        $this->audit->record('file.copied', subscriptionId: (int) $subscription->id, context: [
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['to'])])
            ->with('success', 'Der Eintrag ist kopiert.');
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

    public function compress(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'target' => ['required', 'string', 'max:4096'],
        ]);

        $result = $this->attempt(fn (): array => $this->files->compress($subscription, $data['path'], $data['target']));

        $this->audit->record('file.compressed', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
            'target' => $data['target'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['target'])])
            ->with('success', sprintf('Das Archiv ist gepackt — %d Einträge.', $result['entries'] ?? 0));
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
