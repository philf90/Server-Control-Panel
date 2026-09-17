<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Backups\Backups;
use App\Support\Backups\Restore;
use App\Support\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Packer;
use SrvPanel\Agent\Ops\SubscriptionProvision;
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
        private readonly Restore $restore,
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

        /*
         * **Die Sicherungen ohne Abonnement, und die gibt es nur für den
         * Betreiber.** Sie gehören keinem Kunden mehr — das Abonnement ist
         * zurückgebaut, und `backups.subscription_id` steht auf `nullOnDelete`,
         * damit die Sicherung ihn überlebt.
         */
        $verwaist = Gate::allows('create', Subscription::class)
            ? $this->backups->orphaned()
            : collect();

        /*
         * **Der kurze Weg nur, wenn es nichts anderes zu wählen gibt.**
         *
         * Hier stand `if ($erreichbar->count() === 1)` allein, und das war eine
         * Sackgasse: Bei genau einem Abonnement sprang die Seite weiter, und
         * die verwaisten Sicherungen bekam niemand zu sehen — sie stehen in
         * keiner anderen Liste dieses Panels.
         *
         * > **Eine Weiterleitung, die den Sonderfall überspringt, macht ihn
         * > unerreichbar und sieht dabei aus wie Bequemlichkeit.**
         */
        if ($erreichbar->count() === 1 && $verwaist->isEmpty()) {
            return to_route('backups.show', ['subscription' => $erreichbar->first()?->id]);
        }

        return Inertia::render('Subscriptions/BackupPick', [
            'subscriptions' => $erreichbar
                ->map(static fn (Subscription $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])
                ->all(),

            'orphaned' => $verwaist
                ->map(static fn (Backup $backup): array => [
                    'id' => (int) $backup->id,
                    'subscription_name' => (string) $backup->subscription_name,
                    'storage_name' => (string) $backup->storage_name,
                    'created_at' => Clock::display($backup->created_at),
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
    /**
     * Die Zeilen der Liste — als eigene Methode, damit die Angabe ein
     * Verschluss sein kann.
     *
     * **Inertia siebt die Angaben, bevor es sie auflöst.** Die Seite fragt
     * `backups` alle drei Sekunden nach, solange eine Sicherung läuft; stünde
     * im Steuerungscode ein fertiger Wert, wäre diese Abfrage schon gelaufen,
     * wenn das Sieb sie sieht. Jede Nachfrage kostete dann die ganze Seite und
     * lieferte einen Teil.
     *
     * > **Ein fertiger Wert läuft bei jeder Anfrage, auch bei einer, die ihn
     * > gar nicht mitschickt.**
     *
     * `PartialReloadTest` hält das und hat den ersten Wurf der Nachfrage genau
     * hier angehalten — noch bevor sie einen Server gesehen hat.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(Subscription $subscription): array
    {
        return Backup::query()
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
    }

    public function show(Subscription $subscription): Response
    {
        return Inertia::render('Subscriptions/Backups', [
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
            ],
            'backups' => fn (): array => $this->rows($subscription),

            /*
             * **Was eine Sicherung nicht enthält, steht auf der Seite und nicht
             * in einer Fussnote.** Die drei Verzeichnisse kommen aus dem Agenten
             * und nicht aus einer Liste hier — eine zweite Aufzählung wäre die
             * Fassung, die veraltet, sobald jemand `Packer::SKIPPED` ändert.
             *
             * > **Was ein Archiv nicht enthält, muss es sagen.**
             */
            'skipped' => Packer::SKIPPED,

            /*
             * **Wer zurückspielen darf, entscheidet dieselbe Policy, die die
             * Route bewacht** — und nicht der Kontotyp. Eine zweite Fassung
             * wäre die, die veraltet.
             *
             * Der Knopf gehört in ein `v-if` darauf und wird nicht bloss
             * abgeblendet: `AbilityReachTest` besteht darauf, dass ein Knopf,
             * den der Betrachter nicht drücken darf, gar nicht gezeigt wird.
             * Diese Seite gehört dem **Kunden** (`manageBackups`), die
             * Wiederherstellung dem Betreiber — sie legt ein Abonnement an.
             *
             * Eigenes `can` und nicht die geteilte Ablage `abilities`: Die
             * führt die Adminfähigkeiten aus `AdminAbility` und keine Policy
             * über ein Modell.
             */
            'can' => [
                'restore' => Gate::allows('create', Subscription::class),

                /*
                 * **Enger als das Verwalten, seit die Sicherung ein Geheimnis
                 * trägt.** Ein Administrator sieht die Liste und darf anlegen
                 * und entfernen — die fertige Datei bekommt der Betreiber und
                 * der Kunde ({@see \App\Policies\SubscriptionPolicy::downloadBackup()}).
                 *
                 * Der Knopf hängt daran und nicht am Kontotyp: Ein Knopf, der
                 * einen 403 gibt, ist schlimmer als keiner.
                 */
                'download' => Gate::allows('downloadBackup', $subscription),
            ],
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
    /**
     * Das Formular der Wiederherstellung (`docs/117 §6` Schritt 7).
     *
     * ## Warum die Adresse an der **Sicherung** hängt und nicht am Abonnement
     *
     * Der häufigste Fall ist der, für den es Sicherungen gibt: Das Abonnement
     * ist fort. Eine Adresse `/subscriptions/{id}/backups/{backup}/restore`
     * verlangte genau das, was fehlt — und wäre ausgerechnet dann nicht
     * erreichbar, wenn man sie braucht.
     *
     * > **Ein Weg, den es nur gibt, solange man ihn nicht braucht, ist
     * > keiner.**
     *
     * ## Und warum der Betreiber und nicht der Kunde
     *
     * Eine Wiederherstellung **legt ein Abonnement an**, und das tut in diesem
     * Panel nur der Betreiber (`can:create,Subscription`). Sie braucht
     * ausserdem zwei Angaben, die in keiner Sicherung stehen und auch nicht
     * hineingehören: den Kunden und den Plan. Der Kunde ist eine Kennung dieses
     * Panels, der Plan kann auf dem Zielserver ein anderer sein.
     */
    public function restoreForm(Backup $backup): Response
    {
        $manifest = $this->restore->manifest($backup);

        return Inertia::render('Subscriptions/BackupRestore', [
            'backup' => [
                'id' => (int) $backup->id,
                'storage_name' => $backup->storage_name,
                'bytes' => $backup->bytes,
                'created_at' => Clock::display($backup->created_at),
            ],

            // Was darin steht — Zahlen und keine Zusage. Ob der gewählte Plan
            // sie trägt, entscheidet beim Anlegen die Kontingentprüfung.
            'contents' => $this->restore->preview($manifest),

            /*
             * **Der alte Name, solange er frei ist** — dann bleiben Pfad und
             * Verzeichnisname gleich, und der Kunde merkt von beidem nichts
             * (`docs/117 §3`). Ist er vergeben, steht hier `null` und der
             * Betreiber wählt: Einen zu erfinden hiesse, ihm eine Entscheidung
             * abzunehmen, die er sehen soll.
             */
            'suggested_name' => $this->restore->suggestedName($manifest),

            'customers' => Customer::query()->orderBy('last_name')->get()
                ->map(static fn (Customer $c): array => [
                    'id' => (int) $c->id,
                    'label' => $c->number.' · '.$c->displayName(),
                    'suspended' => $c->status === CustomerStatus::Suspended,
                ])->all(),

            'plans' => Plan::query()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(static fn (Plan $p): array => [
                    'id' => (int) $p->id,
                    'label' => $p->name,
                    'is_default' => (bool) $p->is_default,
                ])->all(),
        ]);
    }

    /**
     * Und sie starten.
     *
     * **Die Prüfung des Namens ist dieselbe wie beim Anlegen** und keine zweite
     * Formulierung davon: `Rule::unique` für die Zeile, `SubscriptionProvision`
     * für die Form. Ein Name, der hier durchginge und dort scheiterte, ergäbe
     * ein Abonnement, das ewig „wird angelegt" bliebe.
     */
    public function restore(Request $request, Backup $backup): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'name' => ['required', 'string', 'max:63', Rule::unique('subscriptions', 'name')],
        ]);

        try {
            SubscriptionProvision::subscriptionName($data['name']);
        } catch (AgentException) {
            throw ValidationException::withMessages([
                'name' => 'Kleinbuchstaben, Ziffern, Punkt und Bindestrich; Anfang und Ende alphanumerisch.',
            ]);
        }

        try {
            $operation = $this->restore->start(
                $backup,
                (int) $data['customer_id'],
                (int) $data['plan_id'],
                $data['name'],
                $request->user()?->getAuthIdentifier(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        $this->audit->record(
            'backup.restored',
            target: $backup,
            context: ['storage' => $backup->storage_name, 'name' => $data['name']],
        );

        return redirect()->route('operations.show', $operation);
    }

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
