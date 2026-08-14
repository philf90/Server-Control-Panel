<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Files\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $this->attempt(fn (): array => $this->files->write($subscription, $data['path'], $data['content']));

        $this->audit->record('file.written', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
        ]);

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
    public function upload(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'file' => ['required', 'file'],
        ]);

        $staged = $request->file('file')->storeAs(
            'uploads',
            bin2hex(random_bytes(16)),
            ['disk' => 'local'],
        );

        if ($staged === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei liess sich nicht zwischenspeichern.',
            ]);
        }

        $absolute = Storage::disk('local')->path($staged);

        try {
            $this->attempt(fn (): array => $this->files->upload($subscription, $absolute, $data['path']));
        } finally {
            // **Auch im Fehlerfall.** Ein Zwischenlager, das nur bei Erfolg
            // aufgeräumt wird, füllt sich genau mit den Dateien, deren
            // Übernahme scheiterte — und das sind die grossen.
            Storage::disk('local')->delete($staged);
        }

        $this->audit->record('file.uploaded', subscriptionId: (int) $subscription->id, context: [
            'path' => $data['path'],
        ]);

        return to_route('files.index', ['subscription' => $subscription->id, 'path' => dirname($data['path'])])
            ->with('success', 'Die Datei ist hochgeladen.');
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
