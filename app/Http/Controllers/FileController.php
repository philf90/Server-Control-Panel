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
