<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Operation;
use App\Support\Audit\Audit;
use App\Support\Operations\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vorgänge ansehen und auslösen.
 *
 * **Was der Browser schicken darf, ist ein Schlüssel aus dem Katalog.** Die
 * Argumente für den Agenten entstehen in App\Support\Operations\Task und nicht
 * aus der Anfrage — die Begründung steht dort. Hier ist nur wichtig, was
 * dieser Steuerungscode deshalb *nicht* tut: Er nimmt keine Unit, keinen Pfad
 * und keine Aktion entgegen.
 *
 * **Der Vorgang wird angelegt, nicht ausgeführt.** Die Anfrage endet, sobald
 * die Zeile steht und der Auftrag in der Warteschlange liegt. Alles Weitere
 * macht der Arbeiter, und die Oberfläche sieht ihm über den Ereignisstrom zu.
 * Ein Neustart des Webservers, der in einer HTTP-Anfrage abliefe, wäre eine
 * Anfrage, die ihren eigenen Server neu startet.
 */
final class OperationController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        // Ohne `with('account')` je Zeile eine Abfrage — bei fünfzig Zeilen
        // einundfünfzig. Die Mandantenklammer schränkt die Liste bereits ein;
        // hier steht deshalb keine weitere Bedingung.
        $operations = Operation::query()
            ->with('account')
            ->orderByDesc('id')
            ->paginate(50);

        return Inertia::render('Operations/Index', [
            'operations' => [
                'data' => collect($operations->items())
                    ->map(fn (Operation $operation): array => $this->row($operation))
                    ->all(),
                'total' => $operations->total(),
            ],
            'tasks' => collect(Task::for($account))->map(static fn (Task $task): array => [
                'key' => $task->value,
                'label' => $task->label(),
                'description' => $task->description(),
                'mutating' => $task->mutating(),
            ])->all(),
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'task' => ['required', 'string', 'max:64'],
        ]);

        $key = (string) $data['task'];
        $task = Task::tryFrom($key);

        // Ein unbekannter Schlüssel und ein Schlüssel, den dieses Konto nicht
        // haben darf, führen zur selben Antwort. Wer den Katalog abklopft,
        // soll daraus nicht ablesen können, welche Aufgaben es gibt.
        if ($task === null || ! $task->allowedFor($account)) {
            $audit->denied('operation.started', context: ['task' => $key]);

            throw ValidationException::withMessages([
                'task' => 'Diese Aufgabe gibt es nicht oder sie steht Ihnen nicht offen.',
            ]);
        }

        $operation = Operation::query()->create([
            // Betreibervorgänge tragen kein Abonnement — sie betreffen den
            // Server und nicht einen Kunden. Für Kunden bleibt die Klammer
            // damit von sich aus geschlossen; es braucht keine zusätzliche
            // Bedingung, damit sie diesen Vorgang nicht sehen.
            'subscription_id' => null,
            'account_id' => $account->id,
            'type' => $task->operation(),
            'task' => $task->value,
            'payload' => $task->payload(),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $task->label(),
        ]);

        $audit->success('operation.started', $operation, [
            'task' => $task->value,
            'operation' => $task->operation(),
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Einen Vorgang abbrechen.
     *
     * **Hier wird gebeten, nicht vollstreckt.** Der Vorgang läuft im Arbeiter,
     * das Programm auf dem Server läuft im Agenten — beides andere Prozesse,
     * die diese Anfrage nicht anhalten kann. Sie vermerkt den Wunsch; der
     * Arbeiter sieht ihn beim nächsten Warten auf den Agenten, schließt die
     * Verbindung, und der Agent beendet daraufhin das Programm.
     *
     * Der Zustand „abgebrochen" wird deshalb *nicht* hier gesetzt. Er stünde
     * in der Datenbank, während das Programm weiterläuft — eine Auskunft, die
     * zum Zeitpunkt ihrer Anzeige nicht stimmt. Wer den Knopf drückt, sieht
     * „Abbruch angefordert", bis es zutrifft.
     *
     * Ein Vorgang, der noch wartet, ist die Ausnahme: Da gibt es nichts zu
     * beenden, und der Arbeiter macht daraus einen sofortigen Abbruch, sobald
     * er den Auftrag anfasst.
     */
    public function cancel(Request $request, Operation $operation, Audit $audit): RedirectResponse
    {
        if (! $operation->open()) {
            // Zwischen dem Anzeigen der Seite und dem Klick können Sekunden
            // liegen. Ein fertiger Vorgang ist kein Fehler des Benutzers.
            return redirect()->route('operations.show', $operation);
        }

        if ($operation->cancel_requested_at === null) {
            $operation->forceFill([
                'cancel_requested_at' => now(),
                'cancelled_by' => $this->account($request)->id,
            ])->save();

            $audit->success('operation.cancelled', $operation);
        }

        return redirect()->route('operations.show', $operation);
    }

    public function show(Operation $operation): Response
    {
        return Inertia::render('Operations/Show', [
            'operation' => $this->row($operation) + [
                'payload' => $operation->payload,
                'result' => $operation->result,
                'output' => $operation->output ?? '',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Operation $operation): array
    {
        $task = $operation->task === null ? null : Task::tryFrom($operation->task);

        return [
            'id' => (int) $operation->id,
            'type' => $operation->type,
            // Fällt auf die Operation des Agenten zurück, wenn die Aufgabe
            // unbekannt ist. Das ist kein hypothetischer Fall: Ein Vorgang
            // bleibt stehen, ein Katalogeintrag kann verschwinden — und ein
            // alter Vorgang, der die Liste zum Absturz brächte, wäre die
            // schlechtere Antwort auf „diese Aufgabe kennen wir nicht mehr".
            'label' => $task?->label() ?? $operation->type,
            'status' => $operation->status->value,
            'status_label' => $operation->status->label(),
            'open' => $operation->open(),
            'progress' => $operation->progress,
            'message' => $operation->message,
            'account' => $operation->account?->name,
            'started_at' => $operation->started_at?->toDateTimeString(),
            'finished_at' => $operation->finished_at?->toDateTimeString(),
            // Angefordert, nicht vollzogen — solange der Vorgang noch offen
            // ist, ist das der ehrliche Zwischenzustand.
            'cancel_requested' => $operation->cancel_requested_at !== null,
        ];
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        return $account;
    }
}
