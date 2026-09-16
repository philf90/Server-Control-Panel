<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Backups\Backups;
use App\Support\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Packer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Die Sicherungen eines Abonnements — ansehen, anlegen, herunterladen, entfernen.
 *
 * ## Warum `/backups` und nicht `/subscriptions/{id}/backups` im Menü
 *
 * Das ist das **vierte** Merkmal mit derselben Frage, und die Antwort ist in
 * diesem Repo längst die Regel und keine Entdeckung: `/files` (`docs/55`
 * Befund 8), `/sftp` (`docs/59` Befund 19), `/cron` (`docs/64` Befund 13) —
 * jedes lag drei Klicks tief, jedes hat der Betreiber gemeldet.
 *
 * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
 * > dort?**
 *
 * Die Adresse beantwortet die Frage „welches Abonnement" selbst: Bei genau
 * einem erreichbaren führt sie hinein, bei mehreren zur Auswahl.
 *
 * ## Und warum das Herunterladen `response()->download()` benutzt
 *
 * Gemessen (`docs/116` M3): Es strömt — 8 MiB Spitze bei einer Datei von
 * 512 MiB. Der erste Anlauf jener Messung war keine: `ob_start()` ohne
 * Stückgrösse sammelte die Antwort im Speicher und starb bei 1 GiB.
 *
 * > **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert, meldet den
 * > Unterschied als Fehler des Gemessenen.**
 *
 * Was daran auf einem echten Server noch offen ist — ob nginx die
 * FastCGI-Antwort auf die Platte puffert —, steht in `docs/117 §9` Punkt 2 und
 * nicht hier als Zusage.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly Backups $backups,
        private readonly Audit $audit,
    ) {}

    /**
     * Welches Abonnement? — bei genau einem ohne Zwischenfrage.
     *
     * **Diese Route trägt kein `can:`**, und das ist begründet: Sie hat kein
     * Objekt, an dem eine Policy ansetzen könnte. Sie sucht aus den
     * Abonnements, die die Mandantenklammer ohnehin sichtbar macht, diejenigen
     * mit `manageBackups` heraus — wortgleich die Überlegung zu `GET /sftp` und
     * `GET /cron`, und ein Eintrag in `RouteGuard` sagt es.
     */
    public function pick(Request $request): RedirectResponse|Response
    {
        $account = $request->user();

        $erreichbar = Subscription::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Subscription $s): bool => $account?->can('manageBackups', $s) ?? false)
            ->values();

        if ($erreichbar->count() === 1) {
            return to_route('backups.show', ['subscription' => $erreichbar->first()?->id]);
        }

        return Inertia::render('Subscriptions/BackupPick', [
            'subscriptions' => $erreichbar
                ->map(static fn (Subscription $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])
                ->all(),
        ]);
    }

    /**
     * Die Liste der Sicherungen eines Abonnements, neueste zuerst.
     *
     * **Die Zeiten kommen aus {@see Clock} und nicht aus `->format()`.** Das
     * Panel legt in UTC ab, und der Betreiber liest auf einer Uhr, die zwei
     * Stunden weiter ist — `docs/40` hat dafür die eine Stelle gebaut, über die
     * achtzehn Lesestellen gehen.
     */
    public function show(Subscription $subscription): Response
    {
        $backups = Backup::query()
            ->where('subscription_id', (int) $subscription->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Backup $backup): array => [
                'id' => $backup->id,
                'storage_name' => $backup->storage_name,
                'status' => $backup->status->value,
                'status_label' => $backup->status->label(),
                'usable' => $backup->status->usable(),
                'bytes' => $backup->bytes,
                'files' => $backup->files,
                'databases' => $backup->databases,
                'system_user' => $backup->system_user,
                'last_error' => $backup->last_error,
                'created_at' => Clock::display($backup->created_at),
            ])
            ->all();

        return Inertia::render('Subscriptions/Backups', [
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
            ],
            'backups' => $backups,

            /*
             * **Was eine Sicherung nicht enthält, steht auf der Seite und nicht
             * in einer Fussnote.** Die drei Verzeichnisse kommen aus dem Agenten
             * und nicht aus einer Liste hier — eine zweite Aufzählung wäre die
             * Fassung, die veraltet, sobald jemand `Packer::SKIPPED` ändert.
             *
             * > **Was ein Archiv nicht enthält, muss es sagen.**
             */
            'skipped' => Packer::SKIPPED,
        ]);
    }

    /**
     * Eine Sicherung anlegen.
     *
     * Der Weg führt auf die Vorgangsseite, weil er Minuten dauern kann und der
     * Fortschritt dort steht. Die Herkunft trägt der Vorgang selbst —
     * `Operation::booted()` setzt sie seit `docs/94` an einer Stelle.
     */
    public function store(Subscription $subscription): RedirectResponse
    {
        try {
            $backup = $this->backups->create($subscription);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        $this->audit->record(
            'backup.created',
            target: $backup,
            subscriptionId: (int) $subscription->id,
            context: ['storage' => $backup->storage_name],
        );

        return to_route('backups.show', ['subscription' => $subscription->id])
            ->with('status', 'Die Sicherung wird erstellt. Ihr Fortschritt steht unter „Vorgänge".');
    }

    /**
     * Herunterladen.
     *
     * **Drei Prüfungen, und jede fängt einen anderen Zustand.** Die Zeile
     * gehört zu diesem Abonnement; die Sicherung ist fertig; die Datei liegt
     * wirklich. Die dritte ist die, die man vergisst — eine Zeile überlebt das
     * Entfernen ihrer Datei, wenn jemand sie von Hand wegnimmt, und dann führte
     * der Knopf in einen Fehler statt in ein `404`.
     */
    public function download(Subscription $subscription, Backup $backup): BinaryFileResponse
    {
        abort_unless($backup->subscription_id === $subscription->id, 404);
        abort_unless($backup->status->usable(), 404);

        $path = $backup->path();

        abort_if($path === null || ! is_file($path), 404);

        $this->audit->record(
            'backup.downloaded',
            target: $backup,
            subscriptionId: (int) $subscription->id,
            context: ['storage' => $backup->storage_name],
        );

        return response()->download($path, $backup->storage_name.'.zip');
    }

    /**
     * Entfernen.
     *
     * **Die Zeile bleibt, bis der Agent geantwortet hat** — die zweite Grenze
     * (`docs/20 §4`). Sie hier zu löschen und die Datei später nicht
     * wegzubekommen hiesse, eine Datei zu hinterlassen, die in keiner Liste
     * steht.
     */
    public function destroy(Subscription $subscription, Backup $backup): RedirectResponse
    {
        abort_unless($backup->subscription_id === $subscription->id, 404);

        $storage = (string) $backup->storage_name;

        try {
            $this->backups->remove($backup);
        } catch (RuntimeException|AgentException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        $this->audit->record(
            'backup.removed',
            target: $backup,
            subscriptionId: (int) $subscription->id,
            context: ['storage' => $storage],
        );

        return to_route('backups.show', ['subscription' => $subscription->id])
            ->with('status', 'Die Sicherung wird entfernt.');
    }
}
