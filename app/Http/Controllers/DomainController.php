<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificateSource;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Plans\Quota;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificateOrder;
use App\Support\Tls\CertificateRecord;
use App\Support\Tls\WildcardOrder;
use App\Support\Web\Domains;
use App\Support\Web\Page;
use App\Support\Web\PhpLimits;
use App\Support\Web\PhpSelection;
use App\Support\Web\WebLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\Acme\Bundle;
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
        private readonly CertificateChoice $choice,
        private readonly WildcardOrder $wildcards,
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

            // Die Auswahl: wovon gewählt werden kann, was gewählt ist, und ob
            // die Wahl gerade übergangen wird (`docs/34 §8`).
            'choice' => [
                'pinned' => $domain->certificate_pinned_at !== null ? (int) $domain->certificate_id : null,
                'overridden' => $this->choice->overridden($domain),
                'options' => array_map(
                    static fn (Certificate $c): array => [
                        'id' => (int) $c->id,
                        'label' => $c->source->label(),
                        'not_after' => $c->not_after?->getTimestamp(),

                        // **Der Unterschied, wegen dem jemand hier wählt.** Ohne
                        // diese Angabe steht in der Liste zweimal „Let’s
                        // Encrypt" mit zwei Daten, und ob ein Eintrag eine
                        // Domain deckt oder jede Unterdomain der Zone, muss man
                        // am Datum erraten. Im Abnahmelauf genau so passiert.
                        'wildcard' => $c->isWildcard(),
                    ],
                    $this->choice->candidates($domain),
                ),
            ],
            // Der Platzhalter: ob er erlaubt ist, ob er gerade geht, und was
            // er nicht deckt (`docs/34 §3`).
            'wildcard' => [
                'possible' => $this->wildcards->possible($domain),
                'obstacle' => $this->wildcards->obstacle($domain),
                'names' => WildcardOrder::names($domain),
                'uncovered' => WildcardOrder::uncovered($domain),

                // **Liegt er schon?** Danach richtet sich, ob das Kästchen
                // überhaupt gezeigt wird — nicht danach, ob es irgendein
                // Zertifikat gibt. Der Unterschied ist der Weg von
                // Einzelzertifikaten zu einem Platzhalter, und den gab es bis
                // zum 7. August 2026 über die Oberfläche nicht.
                'covered' => $this->wildcards->covered($domain),
            ],

            // **`can` und nicht `may`.** Diese Seite hiess als einzige anders
            // und war damit von `AbilityReachTest` in beide Richtungen nicht
            // erfasst — weder „kommt an" noch „wird benutzt". Eine zweite
            // Schreibweise für dieselbe Sache ist genau die Zeichenkette, die
            // auf nichts zeigt.
            'can' => [
                'update' => $request->user()?->can('update', $domain) ?? false,
                'update_php' => $request->user()?->can('updatePhp', $domain) ?? false,
                'delete' => $request->user()?->can('delete', $domain) ?? false,
                'view_logs' => $request->user()?->can('viewLogs', $domain) ?? false,
                'upload_certificate' => $request->user()?->can('uploadCertificate', $domain) ?? false,
                'order_wildcard' => $request->user()?->can('orderWildcard', $domain) ?? false,
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

    /**
     * Was für ein Zertifikat diese Domain ausliefert.
     *
     * **Gelesen wird der Bestand und nicht der Ablageort.** Was dort liegt, weiss
     * der Agent; was gilt, steht in `certificates`. Die Seite fragt den
     * Agenten hier bewusst nicht: Eine Domainseite, die bei jedem Aufruf über
     * den Socket geht, ist eine Seite, die bei einem stehenden Agenten nicht
     * mehr aufgeht — und die Frage „habe ich eines?" beantwortet der Bestand.
     *
     * @return array<string, mixed>|null
     */
    private function certificateOf(Domain $domain): ?array
    {
        // **Gezeigt wird, was ausgeliefert wird — nicht, was zugeordnet ist.**
        // Eine abgelaufene Wahl wird übergangen; stünde hier trotzdem sie,
        // zeigte die Seite ein Zertifikat, das kein Besucher zu sehen bekommt.
        $certificate = $this->choice->effective($domain);

        if (! $certificate instanceof Certificate) {
            return null;
        }

        return [
            'id' => (int) $certificate->id,
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
        $wildcard = $request->boolean('wildcard');

        if ($wildcard && ($refusal = $this->refuseWildcard($domain, $request, $audit)) !== null) {
            return $refusal;
        }

        $operation = $order->place($domain, wildcard: $wildcard);

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

        $audit->success('domain.certificate.ordered', $domain, [
            'operation' => (int) $operation->id,
            'wildcard' => $wildcard,
        ]);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Warum ein Platzhalter jetzt nicht bestellt wird.
     *
     * **Zwei verschiedene Neins, und sie gehören auseinandergehalten.** „Darfst
     * du nicht" beantwortet die Policy und endet mit 403 — die Fähigkeit steht
     * an der Route und wird hier nur für den Zusatzfall nachgefragt, weil
     * dieselbe Route auch die gewöhnliche Bestellung fährt. „Geht gerade
     * nicht" ist eine Auskunft: keine Basisdomain, keine Zugangsdaten. Wer
     * beides gleich behandelt, sagt dem Betreiber „keine Berechtigung", wo
     * „kein Token hinterlegt" gemeint ist.
     */
    private function refuseWildcard(Domain $domain, Request $request, Audit $audit): ?RedirectResponse
    {
        if ($request->user()?->can('orderWildcard', $domain) !== true) {
            abort(403);
        }

        $obstacle = $this->wildcards->obstacle($domain);

        if ($obstacle === null) {
            return null;
        }

        $audit->failure('domain.certificate.ordered', [
            'domain' => $domain->name,
            'reason' => $obstacle,
        ]);

        return redirect()->route('domains.show', $domain)->with('error', $obstacle);
    }

    /**
     * Auswählen, welches Zertifikat diese Domain ausliefert.
     *
     * **Gewählt wird aus dem, was ohnehin gilt.** Die Nummer aus der Anfrage
     * muss unter den Kandidaten stehen — also dem eigenen Abonnement gehören,
     * gültig sein und alle Namen des Blocks decken. Damit ist keine
     * Zusatzprüfung nötig, die man vergessen könnte: Was nicht in der Liste
     * steht, gibt es für diese Domain nicht.
     *
     * **`null` nimmt die Wahl zurück.** Dann entscheidet wieder die Automatik,
     * und das ist ausdrücklich ein Weg zurück: Eine Einstellung, die man nur
     * einmal treffen kann, ist eine Falle.
     */
    public function chooseCertificate(
        Domain $domain,
        Request $request,
        WebLifecycle $web,
        Audit $audit,
    ): RedirectResponse {
        $wanted = $request->input('certificate');

        if ($wanted === null || $wanted === '') {
            $domain->certificate_pinned_at = null;
            $domain->save();

            $audit->success('domain.certificate.chosen', $domain, ['certificate' => null]);
            $web->apply($domain, 'Server-Block nach der Wahl für '.$domain->name);

            return redirect()
                ->route('domains.show', $domain)
                ->with('success', 'Es entscheidet wieder die Automatik.');
        }

        $chosen = null;

        foreach ($this->choice->candidates($domain) as $candidate) {
            if ((int) $candidate->id === (int) $wanted) {
                $chosen = $candidate;
            }
        }

        if (! $chosen instanceof Certificate) {
            return redirect()
                ->route('domains.show', $domain)
                ->with('error', 'Dieses Zertifikat steht für diese Domain nicht zur Wahl.');
        }

        $domain->certificate_id = (int) $chosen->id;
        $domain->certificate_pinned_at = now();
        $domain->save();

        $audit->success('domain.certificate.chosen', $domain, ['certificate' => (int) $chosen->id]);

        // Ohne diesen Vorgang gilt die Wahl und nginx kennt sie nicht.
        $web->apply($domain, 'Server-Block nach der Wahl für '.$domain->name);

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', 'Die Wahl ist gespeichert. Der Server-Block wird neu geschrieben.');
    }

    /**
     * Ein eigenes Zertifikat hinterlegen.
     *
     * **Kein Vorgang, sondern ein unmittelbarer Aufruf — und das ist keine
     * Bequemlichkeit.** Ein eingereihter Vorgang legt seine Argumente in
     * `operations.payload` ab; der private Schlüssel läge damit im Klartext in
     * der Datenbank, dauerhaft und für jeden lesbar, der sie liest. Er darf
     * den Socket genau einmal überqueren und nirgends sonst stehen. Dieselbe
     * Entscheidung wie bei `srvpanel tls --upload` (`docs/34 §7`).
     *
     * **Die Meldung des Agenten wird wörtlich durchgereicht.** Sie ist das
     * Wertvollste an einem Fehlschlag: „Die Kette ist nicht in der richtigen
     * Reihenfolge" ist eine Auskunft, mit der jemand weiterkommt; „ungültig"
     * ist keine. Ein Betreiber liest sonst das Protokoll — ein Kunde liest
     * diese Seite und sonst nichts.
     *
     * **Und danach wird der Server-Block neu geschrieben.** Ohne das gilt das
     * Zertifikat und nginx kennt es nicht: Welches ausgeliefert wird, steht
     * seit dem zweiten Wurf von P4 in den Argumenten des Blocks.
     */
    public function uploadCertificate(
        Domain $domain,
        Request $request,
        Client $agent,
        CertificateRecord $record,
        WebLifecycle $web,
        Audit $audit,
    ): RedirectResponse {
        $eingaben = $request->validate([
            'certificate' => ['required', 'string', 'max:'.Bundle::MAX_CHAIN_BYTES],
            'private_key' => ['required', 'string', 'max:'.Bundle::MAX_KEY_BYTES],
        ]);

        try {
            $result = $agent->call('tls.certificate.upload', [
                'certificate' => $eingaben['certificate'],
                'private_key' => $eingaben['private_key'],
            ], ['source' => 'web', 'account' => $request->user()?->id]);
        } catch (AgentException $error) {
            $audit->failure('domain.certificate.uploaded', [
                'domain' => $domain->name,
                'reason' => $error->getMessage(),
            ]);

            return redirect()
                ->route('domains.show', $domain)
                ->with('error', 'Zertifikat abgewiesen: '.$error->getMessage());
        }

        $certificate = $record->store($domain, $result, CertificateSource::Uploaded);

        $audit->success('domain.certificate.uploaded', $domain, [
            'certificate' => (int) $certificate->id,
            'names' => $certificate->coveredNames(),
        ]);

        $web->apply($domain, 'Server-Block mit hochgeladenem Zertifikat für '.$domain->name);

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', 'Das Zertifikat ist hinterlegt. Der Server-Block wird neu geschrieben.');
    }

    /** @return array<string, mixed> */
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
