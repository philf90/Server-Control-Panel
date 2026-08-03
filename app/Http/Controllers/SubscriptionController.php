<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use App\Support\Subscriptions\Lifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Abonnements — die Bedienung zu den vier Operationen des Agenten.
 *
 * **Jede Systemänderung ist ein Vorgang, keine Anfrage.** Anlegen, sperren,
 * entsperren und zurückbauen dauern länger als eine HTTP-Anfrage und ändern
 * den Server. Sie laufen deshalb über die Warteschlange, mit sichtbarem
 * Verlauf — und der Zustand in der Datenbank folgt erst, wenn der Agent
 * geantwortet hat (siehe {@see Lifecycle}).
 *
 * **Kein Wert aus der Anfrage erreicht den Agenten.** Beim Anlegen prüft der
 * Name die Regel des Agenten selbst — dieselbe Funktion, nicht eine zweite
 * Formulierung derselben Regel —, und danach steht er in der Datenbank. Alles
 * Weitere liest {@see Lifecycle::payload()} aus der abgelegten Zeile. Der
 * Browser nennt ein Abonnement; ob er es überhaupt sehen darf, entscheidet die
 * Mandantenklammer, bevor der Controller es in der Hand hat.
 */
final class SubscriptionController extends Controller
{
    public function index(): Response
    {
        $subscriptions = Subscription::query()
            ->with(['customer', 'plan'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions->map(static fn (Subscription $s): array => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'customer' => $s->customer?->displayName(),
                'plan' => $s->plan?->name,
                'system_user' => $s->system_user,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'used_mb' => $s->disk_used_mb,
                'percent' => $s->diskUsagePercent(),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Subscriptions/Create', [
            'customers' => Customer::query()->orderBy('last_name')->get()
                ->map(static fn (Customer $c): array => [
                    'id' => (int) $c->id,
                    'label' => $c->number.' · '.$c->displayName(),
                ])->all(),
            'plans' => Plan::query()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(static fn (Plan $p): array => [
                    'id' => (int) $p->id,
                    'label' => $p->name,
                    'is_default' => (bool) $p->is_default,
                ])->all(),
            'nextUser' => app(Lifecycle::class)->nextSystemUser(),
        ]);
    }

    public function store(Request $request, Lifecycle $lifecycle, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', Rule::exists('plans', 'id')],

            // Der Name ist der Verzeichnisname unter /var/www/vhosts. Die
            // Form prüft gleich darunter der Agent selbst; hier steht nur,
            // dass er einmalig sein muss.
            'name' => ['required', 'string', 'max:63', Rule::unique('subscriptions', 'name')->withoutTrashed()],
        ]);

        // **Die Regel des Agenten, nicht eine zweite Formulierung davon.**
        // Ein eigener Ausdruck im Controller wäre dieselbe Regel an zwei
        // Orten — und der eine, der beim nächsten Mal nachgezogen wird, ist
        // erfahrungsgemäss nicht der im Panel. Ein Name, der hier durchginge
        // und dort scheiterte, ergäbe ein Abonnement, das ewig „wird
        // angelegt" bliebe.
        try {
            SubscriptionProvision::subscriptionName($data['name']);
        } catch (AgentException $error) {
            throw ValidationException::withMessages([
                'name' => 'Kleinbuchstaben, Ziffern, Punkt und Bindestrich; Anfang und Ende alphanumerisch.',
            ]);
        }

        $subscription = DB::transaction(function () use ($data, $lifecycle): Subscription {
            return Subscription::query()->create([
                'customer_id' => (int) $data['customer_id'],
                'plan_id' => (int) $data['plan_id'],
                'name' => $data['name'],
                'system_user' => $lifecycle->nextSystemUser(),
                'status' => SubscriptionStatus::Provisioning,
            ]);
        });

        $operation = $this->start($subscription, 'subscription.provision', 'Abonnement anlegen', $audit, $lifecycle);

        return redirect()->route('operations.show', $operation);
    }

    public function show(Subscription $subscription): Response
    {
        $subscription->loadMissing(['customer', 'plan']);

        return Inertia::render('Subscriptions/Show', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'customer' => $subscription->customer?->displayName(),
                'customer_id' => (int) $subscription->customer_id,
                'plan' => $subscription->plan?->name,
                'system_user' => $subscription->system_user,
                'root' => '/var/www/vhosts/'.$subscription->name,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'suspended_at' => $subscription->suspended_at?->toDateTimeString(),
            ],

            /*
             * Der belegte Speicher — gemessen, nicht vereinbart.
             *
             * Der Zeitstempel geht mit, und zwar immer: Ohne ihn sähe eine
             * Messung von vor drei Tagen genauso aus wie eine von vor einer
             * Minute. `measured_at: null` heisst „noch nie gemessen" und ist
             * etwas anderes als „0 MB".
             */
            'usage' => [
                'used_mb' => $subscription->disk_used_mb,
                'limit_mb' => is_numeric($limit = $subscription->quota(Quota::DiskMb->value)) ? (int) $limit : null,
                'percent' => $subscription->diskUsagePercent(),
                'measured_at' => $subscription->disk_usage_measured_at?->toDateTimeString(),
            ],

            // Der Stand, nicht die Vorlage: Was am Abonnement abweicht, ist
            // markiert. Ein Kunde sieht hier seine Grenzen und nicht den Plan,
            // aus dem sie kommen.
            'quotas' => array_map(static fn (Quota $quota): array => [
                'key' => $quota->value,
                'label' => $quota->label(),
                'value' => Quotas::format($quota, $subscription->quota($quota->value)),
                'differs' => $subscription->quotaDiffersFromPlan($quota->value),
            ], Quota::cases()),

            'features' => array_map(static fn (Feature $feature): array => [
                'label' => $feature->label(),
                'granted' => $subscription->feature($feature->value),
            ], Feature::cases()),

            'operations' => Operation::query()
                ->where('subscription_id', $subscription->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(static fn (Operation $o): array => [
                    'id' => (int) $o->id,
                    'task' => $o->task,
                    'status_label' => $o->status->label(),
                    'created_at' => $o->created_at?->toDateTimeString(),
                ])->all(),
        ]);
    }

    /**
     * Plan und Kontingente eines bestehenden Abonnements.
     *
     * **Was hier nicht steht, ist die Hälfte der Entscheidung.** Der Name
     * fehlt: Er ist der Verzeichnisname unter /var/www/vhosts, und ihn zu
     * ändern hiesse, einen Baum zu verschieben, auf den ein laufender
     * Webserver, eine Chroot-Wurzel und der Heimatpfad eines Systembenutzers
     * zeigen. Der Systembenutzer fehlt aus demselben Grund — er trägt eine
     * UID, an der auf dem Dateisystem Eigentum hängt. Der Kunde fehlt, weil
     * ein Abonnement umzuhängen eine Vertragsfrage ist und keine
     * Formularzeile. Und der Zustand fehlt, weil er seine eigenen Aktionen hat.
     */
    public function edit(Subscription $subscription): Response
    {
        $subscription->loadMissing('plan');

        $overrides = $subscription->quota_overrides ?? [];

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'plan_id' => (int) $subscription->plan_id,
                'status' => $subscription->status->value,
            ],

            'plans' => Plan::query()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(static fn (Plan $p): array => [
                    'id' => (int) $p->id,
                    'label' => $p->name,
                ])->all(),

            /*
             * Je Kontingent drei Dinge: was der Katalog darüber weiss, was der
             * Plan sagt, und ob dieses Abonnement abweicht. Die Oberfläche
             * kennt kein Kontingent beim Namen — sie baut ihre Felder aus
             * dieser Liste, genau wie das Formular der Pläne.
             */
            'quotas' => array_map(static fn (Quota $quota): array => [
                'key' => $quota->value,
                'label' => $quota->label(),
                'hint' => $quota->hint(),
                'unit' => $quota->unit(),
                'selection' => $quota->isSelection(),
                'minimum' => $quota->minimum(),
                'maximum' => $quota->maximum(),
                'plan_value' => Quotas::format($quota, ($subscription->plan->quotas ?? [])[$quota->value] ?? null),
                'override' => $overrides[$quota->value] ?? null,
            ], Quota::cases()),

            'phpVersions' => Quota::PHP_VERSIONS,
        ]);
    }

    /**
     * Speichern — und die Speichergrenze anwenden, wenn sie sich ändert.
     *
     * **Nur `disk_mb` erreicht das System.** Von allen Kontingenten ist es das
     * einzige, das gerade schon durchgesetzt wird: als Dateisystem-Quota des
     * Systembenutzers. Domains, Datenbanken und FTP-Konten werden beim Anlegen
     * gezählt (P3 und später), PHP-Versionen wählt eine vhost-Vorlage aus, und
     * Traffic wird gemessen. Für sie gibt es nichts auszuführen — der neue
     * Wert steht in der Datenbank, und das ist die ganze Wirkung.
     *
     * **Und nur, wenn er sich wirklich ändert.** Der Vergleich läuft über den
     * *wirksamen* Wert und nicht über die Übersteuerung: Wer eine
     * Übersteuerung von 5120 MB entfernt, während der Plan ebenfalls 5120 MB
     * sagt, hat nichts geändert — ein Vorgang dafür wäre eine Zeile im
     * Protokoll über ein System, das gleich bleibt.
     */
    public function update(Request $request, Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            ...Quotas::overrideRules(),
        ]);

        $before = $subscription->quota(Quota::DiskMb->value);

        $subscription->update([
            'plan_id' => (int) $data['plan_id'],
            // `?? []`: Ein Formular ohne eine einzige Übersteuerung schickt
            // den Schlüssel gar nicht mit — siehe Quotas::overrideRules().
            'quota_overrides' => Quotas::overrides($data['overrides'] ?? []),
        ]);

        $after = $subscription->quota(Quota::DiskMb->value);

        $audit->success('subscription.updated', $subscription, [
            'name' => $subscription->name,
            'plan' => (int) $subscription->plan_id,
            'overrides' => array_keys($subscription->quota_overrides ?? []),
            'disk_mb' => $after,
        ]);

        /*
         * **Nicht `usable()`, und das ist Absicht.** `usable()` heisst „aktiv"
         * — ein gesperrtes Abonnement hätte damit keinen Vorgang bekommen. Es
         * hat aber weiterhin einen Systembenutzer und eine Dateisystem-Quota,
         * und `subscription.quota` fasst nichts an, was die Sperre trägt. Ohne
         * diese Zeile stünde die neue Grenze in der Datenbank und würde nie
         * angewandt: Das Entsperren setzt keine Quota, und einen zweiten
         * Anlass gäbe es nicht.
         *
         * Kein Konto gibt es nur in zwei Zuständen: während des Anlegens (dort
         * setzt `subscription.provision` die Grenze gleich mit) und nach dem
         * Rückbau.
         */
        $hasAccount = in_array(
            $subscription->status,
            [SubscriptionStatus::Active, SubscriptionStatus::Suspended],
            true,
        );

        if ($before === $after || ! $hasAccount) {
            return redirect()->route('subscriptions.show', $subscription)
                ->with('success', 'Abonnement gespeichert.');
        }

        return redirect()->route('operations.show', $this->start(
            $subscription, 'subscription.quota', 'Speichergrenze anwenden', $audit, $lifecycle,
        ));
    }

    public function suspend(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        if ($subscription->status === SubscriptionStatus::Provisioning) {
            throw ValidationException::withMessages([
                'subscription' => 'Das Abonnement wird gerade angelegt. Erst danach lässt es sich sperren.',
            ]);
        }

        // Einzeln gesperrt heisst: gehört nicht zur Kundensperre. Ohne diese
        // Zeile bliebe eine Kennzeichnung von früher stehen, und die nächste
        // Freigabe des Kunden holte ein Abonnement zurück, das der Betreiber
        // aus einem eigenen Grund gesperrt hat.
        $subscription->forceFill(['suspended_with_customer' => false])->save();

        return redirect()->route('operations.show', $this->start(
            $subscription, 'subscription.suspend', 'Abonnement sperren', $audit, $lifecycle,
        ));
    }

    public function resume(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        /*
         * **Nicht, solange der Kunde gesperrt ist.** Sonst liesse sich die
         * Kundensperre von unten aushebeln: Ein Abonnement käme zurück,
         * während im Panel weiter „Kunde gesperrt" steht — und die Freigabe
         * des Kunden später wüsste nicht mehr, was zu ihr gehört. Wer eines
         * herausnehmen will, gibt den Kunden frei und sperrt danach dieses
         * eine.
         */
        if ($subscription->customer?->status === CustomerStatus::Suspended) {
            throw ValidationException::withMessages([
                'subscription' => 'Der Kunde ist gesperrt. Erst seine Freigabe, dann das Abonnement.',
            ]);
        }

        return redirect()->route('operations.show', $this->start(
            $subscription, 'subscription.resume', 'Abonnement entsperren', $audit, $lifecycle,
        ));
    }

    /**
     * Zurückbauen — Verzeichnis, Systembenutzer, Quota.
     *
     * **Ohne Sicherung, und das steht auch so da.** Der Plan verlangt „löschen
     * mit Sicherung davor"; die gehört vor den Aufruf und nicht in ihn. Eine
     * Operation, die sichert *und* löscht, sichert im Fehlerfall vielleicht
     * nicht und löscht trotzdem. Solange es keine Sicherungen gibt, ist der
     * Rückbau endgültig — und die Rückfrage in der Oberfläche sagt das.
     */
    public function destroy(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        return redirect()->route('operations.show', $this->start(
            $subscription, 'subscription.remove', 'Abonnement zurückbauen', $audit, $lifecycle,
        ));
    }

    /**
     * Einen Vorgang für dieses Abonnement einreihen.
     *
     * Die Argumente entstehen hier aus der abgelegten Zeile — nicht aus der
     * Anfrage. Der Vorgang trägt das Abonnement, damit ihn der Kunde in seiner
     * eigenen Liste sieht und die Mandantenklammer ihn dort hält.
     */
    private function start(
        Subscription $subscription,
        string $task,
        string $message,
        Audit $audit,
        Lifecycle $lifecycle,
    ): Operation {
        $operation = $lifecycle->dispatch($subscription, $task, $message);

        $audit->success($task, $subscription, [
            'name' => $subscription->name,
            'user' => $subscription->system_user,
            'operation' => (int) $operation->id,
        ]);

        return $operation;
    }
}
