<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
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

    public function suspend(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        if ($subscription->status === SubscriptionStatus::Provisioning) {
            throw ValidationException::withMessages([
                'subscription' => 'Das Abonnement wird gerade angelegt. Erst danach lässt es sich sperren.',
            ]);
        }

        return redirect()->route('operations.show', $this->start(
            $subscription, 'subscription.suspend', 'Abonnement sperren', $audit, $lifecycle,
        ));
    }

    public function resume(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
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
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => $lifecycle->payload($subscription),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        $audit->success($task, $subscription, [
            'name' => $subscription->name,
            'user' => $subscription->system_user,
            'operation' => (int) $operation->id,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }
}
