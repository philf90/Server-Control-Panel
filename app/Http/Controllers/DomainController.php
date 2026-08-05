<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Plans\Quota;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateOrder;
use App\Support\Web\Domains;
use App\Support\Web\Page;
use App\Support\Web\PhpLimits;
use App\Support\Web\PhpSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Directives;
use SrvPanel\Agent\Ops\WebLogsTail;
use SrvPanel\Agent\PhpSettings;

/**
 * Domains ansehen, anlegen, ändern, entfernen.
 *
 * **Der Steuerungscode prüft nichts Fachliches.** Er nimmt die Anfrage
 * entgegen, gibt sie an {@see Domains} und übersetzt das Ergebnis in eine
 * Antwort. Jede Grenze — Kontingent, Plan, Name, Pfad — sitzt im Dienst, weil
 * sie dort auch für Aufrufer gilt, die kein Formular benutzen (§6.2.3).
 *
 * **Was hier steht, ist die Zusammenstellung für die Anzeige.** Und die
 * unterscheidet zwei Dinge, die leicht zusammenfallen: was ein Konto *sehen*
 * darf und was es *ändern* darf. Die Knöpfe der Oberfläche richten sich nach
 * dem zweiten; die Prüfung, die zählt, steht an der Route.
 */
final class DomainController extends Controller
{
    public function __construct(
        private readonly Domains $domains,
        private readonly AcmeSettings $tls,
        private readonly PhpSelection $php,
        private readonly PhpLimits $limits,
    ) {}

    /**
     * Die serverweite Liste — für den Betreiber.
     *
     * Für einen Kunden zeigt dieselbe Route nur seine Domains: Die
     * Mandantenklammer entscheidet, nicht eine Bedingung hier.
     */
    /**
     * Die Domains — und, für den Kunden, der kurze Weg zu einer neuen.
     *
     * **Der Befund kam vom Betreiber.** Ein Kunde erreichte „Domain anlegen"
     * nur über Abonnements → Name des Abonnements → einen kleinen Knopf rechts
     * im Bereich „Domains". Drei Klicks für die Sache, wegen der er das Panel
     * überhaupt öffnet, und der letzte davon versteckt. Die Liste selbst gab es
     * für ihn schon: `viewAny` lässt jedes Konto durch, und was darauf steht,
     * entscheidet die Mandantenklammer. Gefehlt hat der Menüpunkt — und ein
     * Knopf an der Stelle, an der man ihn sucht.
     *
     * **Warum die Abkürzung nur Kunden bekommen.** Sie führt in ein bestimmtes
     * Abonnement, und der Betreiber hat davon Hunderte: Eine Auswahlliste über
     * alle wäre kein kurzer Weg, sondern ein langer mit Suchfeld. Seine Wege
     * bleiben unverändert — er legt eine Domain dort an, wo sie hingehört, am
     * Abonnement.
     */
    public function index(Request $request): Response
    {
        $domains = Domain::query()
            ->with('subscription')
            ->orderBy('name')
            ->paginate(Page::SIZE)
            ->withQueryString();

        return Inertia::render('Domains/Index', [
            'domains' => Page::from($domains, fn (Domain $domain): array => $this->row($domain)),
            'creatable' => $this->creatable($request->user()),
        ]);
    }

    /**
     * Die Abonnements, in denen dieses Konto eine Domain anlegen darf.
     *
     * Gefragt wird dieselbe Policy, die die Route später prüft — nicht der
     * Kontotyp. Ein Zusatzbenutzer ohne `files.write` darf es nämlich nicht,
     * und ein gesperrtes Abonnement ist kein Ort für eine neue Domain
     * ({@see DomainPolicy::create()}).
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
            ->filter(static fn (Subscription $s): bool => $account->can('create', [Domain::class, $s]))
            ->map(static fn (Subscription $s): array => ['id' => (int) $s->id, 'name' => $s->name])
            ->values()
            ->all();
    }

    public function create(Subscription $subscription): Response
    {
        return Inertia::render('Domains/Create', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
            ],
            'parents' => $this->parents($subscription),
            'php' => $this->php->optionsFor($subscription),
            'counts' => $this->countsFor($subscription),
        ]);
    }

    public function store(Request $request, Subscription $subscription, Audit $audit): RedirectResponse
    {
        // **Keine Prüfregeln für die Fachwerte.** `type`, `name`,
        // `document_root` und die PHP-Angaben gehen ungefiltert an den Dienst;
        // er weist sie mit einer Meldung am Feld ab. Eine zweite Prüfung hier
        // wäre eine zweite Formulierung derselben Regel — und die im
        // Steuerungscode ist die, die beim nächsten Umbau vergessen wird.
        $domain = $this->domains->create($subscription, $request->all());

        $audit->success('domain.created', $domain, [
            'domain' => $domain->name,
            'type' => $domain->type->value,
        ]);

        return redirect()->route('domains.show', $domain);
    }

    public function show(Domain $domain, Request $request): Response
    {
        $domain->loadMissing(['subscription', 'parent']);

        $subscription = $domain->subscription;

        return Inertia::render('Domains/Show', [
            'domain' => $this->row($domain) + [
                'document_root_path' => $domain->absoluteDocumentRoot(),
                'php_settings' => $domain->php_settings ?? new \stdClass,
                'nginx_directives' => $domain->nginx_directives ?? [],
                'redirect_target' => $domain->redirect_target,
                'redirect_kind' => $domain->redirect_kind?->value,
                'parent' => $domain->parent?->name,
                'log_dir' => $subscription === null ? null : '/var/www/vhosts/'.$subscription->name.'/logs/'.$domain->name,
            ],
            'php' => $subscription === null ? [] : $this->php->optionsFor($subscription),
            'caps' => $subscription === null ? [] : $this->limits->capsFor($subscription),
            'settings' => array_keys(PhpSettings::ALLOWED),
            'directives' => Directives::ALLOWED,
            'certificate' => $this->certificateOf($domain),
            'acme' => ['configured' => $this->tls->configured(), 'staging' => $this->tls->staging()],
            'may' => [
                'update' => $request->user()?->can('update', $domain) ?? false,
                'update_php' => $request->user()?->can('updatePhp', $domain) ?? false,
                'delete' => $request->user()?->can('delete', $domain) ?? false,
                'view_logs' => $request->user()?->can('viewLogs', $domain) ?? false,
            ],
            'operations' => Operation::query()
                ->where('subject_type', 'domain')
                ->where('subject_id', $domain->id)
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

    public function update(Request $request, Domain $domain, Audit $audit): RedirectResponse
    {
        // Die PHP-Einstellungen hängen an einer eigenen Fähigkeit — sie
        // brauchen zusätzlich die Freigabe des Plans. Wer sie nicht hat,
        // ändert die Domain ohne sie, statt eine Fehlermeldung auf eine
        // Änderung zu bekommen, die er gar nicht vorhatte.
        $data = $request->all();

        if (($request->user()?->can('updatePhp', $domain) ?? false) === false) {
            unset($data['php_settings']);
        }

        $this->domains->update($domain, $data);

        $audit->success('domain.updated', $domain, ['domain' => $domain->name]);

        return redirect()->route('domains.show', $domain);
    }

    public function destroy(Domain $domain, Audit $audit): RedirectResponse
    {
        $subscription = $domain->subscription;
        $name = $domain->name;

        $operation = $this->domains->remove($domain);

        $audit->success('domain.removed', $domain, ['domain' => $name]);

        return redirect()->route(
            $subscription === null ? 'domains.index' : 'subscriptions.show',
            $subscription === null ? [] : $subscription,
        )->with('operation', (int) $operation->id);
    }

    /**
     * Das Protokoll einer Domain — gelesen, während die Anfrage läuft.
     *
     * **Der Agent wird hier unmittelbar gefragt und nicht über einen Vorgang.**
     * Ein Vorgang ist für alles da, was länger als eine Sekunde dauern kann
     * (§5.3); die letzten hundert Zeilen einer Datei zu lesen gehört nicht
     * dazu, und ein Protokoll, das man erst als Vorgang anstossen und dann in
     * einer zweiten Ansicht nachlesen muss, liest niemand.
     *
     * Antwortet der Agent nicht, steht das da — und nicht eine leere Liste,
     * die aussähe wie „keine Einträge".
     */
    public function logs(Request $request, Domain $domain, Client $agent): Response
    {
        $kind = $request->string('kind')->toString() === 'error' ? 'error' : 'access';
        $lines = min(max((int) $request->integer('lines', 100), 10), WebLogsTail::MAX_LINES);

        $subscription = $domain->subscription;

        $result = ['lines' => [], 'exists' => false, 'error' => null, 'path' => null];

        try {
            $answer = $agent->call('web.logs.tail', [
                'subscription' => (string) $subscription?->name,
                'user' => (string) $subscription?->system_user,
                'domain' => $domain->name,
                'kind' => $kind,
                'lines' => $lines,
            ]);

            $result['lines'] = is_array($answer['lines'] ?? null) ? $answer['lines'] : [];
            $result['exists'] = ($answer['exists'] ?? false) === true;
            $result['path'] = is_string($answer['path'] ?? null) ? $answer['path'] : null;
        } catch (AgentException $error) {
            $result['error'] = $error->getMessage();
        }

        return Inertia::render('Domains/Logs', [
            'domain' => ['id' => (int) $domain->id, 'name' => $domain->name],
            'kind' => $kind,
            'lines' => $lines,
            'log' => $result,
        ]);
    }

    /**
     * Die Domains, unter denen eine Subdomain oder ein Alias hängen kann.
     *
     * Nur die, die eigene Dateien ausliefern: Ein Alias kann selbst keine
     * tragen, weil er kein eigenes Verzeichnis hat.
     *
     * @return list<array{id: int, name: string}>
     */
    private function parents(Subscription $subscription): array
    {
        return Domain::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('name')
            ->get()
            ->filter(static fn (Domain $domain): bool => $domain->type->servesOwnContent())
            ->map(static fn (Domain $domain): array => [
                'id' => (int) $domain->id,
                'name' => $domain->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Stand und Grenze der beiden Kontingente, die Domains verbrauchen.
     *
     * @return array<string, array{label: string, used: int, limit: int|null}>
     */
    private function countsFor(Subscription $subscription): array
    {
        $counts = $this->domains->counts($subscription);
        $out = [];

        foreach ([Quota::Domains, Quota::Subdomains] as $quota) {
            $limit = $subscription->quota($quota->value);

            $out[$quota->value] = [
                'label' => $quota->label(),
                'used' => $counts[$quota->value] ?? 0,
                'limit' => is_numeric($limit) ? (int) $limit : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    /**
     * Was für ein Zertifikat diese Domain ausliefert.
     *
     * **Gelesen wird der Bestand und nicht die Platte.** Was dort liegt, weiss
     * der Agent; was gilt, steht in `certificates`. Die Seite fragt den
     * Agenten hier bewusst nicht: Eine Domainseite, die bei jedem Aufruf über
     * den Socket geht, ist eine Seite, die bei einem stehenden Agenten nicht
     * mehr aufgeht — und die Frage „habe ich eines?" beantwortet der Bestand.
     *
     * @return array<string, mixed>|null
     */
    private function certificateOf(Domain $domain): ?array
    {
        $certificate = $domain->certificate_id === null
            ? null
            : Certificate::query()->find($domain->certificate_id);

        if (! $certificate instanceof Certificate) {
            return null;
        }

        return [
            'names' => $certificate->coveredNames(),
            'issuer' => $certificate->issuer,
            'source' => $certificate->source->value,
            'source_label' => $certificate->source->label(),
            'trusted' => $certificate->source->trusted(),
            'not_after' => $certificate->not_after?->getTimestamp(),
            'renew_after' => $certificate->renew_after?->getTimestamp(),

            // Deckt es wirklich alles ab, was im `server_name` steht? Ein
            // Alias, der nach der Ausstellung dazukam, ist genau der Fall, in
            // dem der Browser warnt und im Panel alles grün aussieht.
            'covers_all' => $certificate->coversAll($domain->serverNames()),
        ];
    }

    /**
     * Für diese Domain ein Zertifikat bestellen.
     *
     * Wie bestellt wird, steht an einer Stelle ({@see CertificateOrder}) —
     * hier wird nur entschieden, *ob*. Ohne Kontaktadresse passiert dort
     * nichts, und dann sagt es die Meldung statt einer leeren Vorgangsliste.
     */
    public function certificate(Domain $domain, Request $request, CertificateOrder $order, Audit $audit): RedirectResponse
    {
        $operation = $order->place($domain);

        if ($operation === null) {
            // `failure()` kennt kein Ziel — die Domain steht deshalb im
            // Zusammenhang. Ohne sie wäre der Eintrag die halbe Auskunft.
            $audit->failure('domain.certificate.ordered', [
                'domain' => $domain->name,
                'reason' => 'keine Kontaktadresse',
            ]);

            return redirect()
                ->route('domains.show', $domain)
                ->with('error', 'Es ist keine Kontaktadresse für Let’s Encrypt eingetragen — ohne sie wird nichts bestellt.');
        }

        $audit->success('domain.certificate.ordered', $domain, ['operation' => (int) $operation->id]);

        return redirect()->route('operations.show', $operation);
    }

    private function row(Domain $domain): array
    {
        return [
            'id' => (int) $domain->id,
            'name' => $domain->name,
            'type' => $domain->type->value,
            'type_label' => $domain->type->label(),
            'status' => $domain->status->value,
            'status_label' => $domain->status->label(),
            'pending' => $domain->status->pending(),
            'document_root' => $domain->document_root,
            'php_version' => $domain->php_version,
            'is_redirect' => $domain->isRedirect(),
            'subscription' => $domain->subscription?->name,
            'subscription_id' => (int) $domain->subscription_id,
            'removable' => $domain->type->removable(),
        ];
    }
}
