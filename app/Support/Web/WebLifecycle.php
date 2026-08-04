<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\AfterOperation;
use App\Support\Operations\Lifecycles;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DocumentRoot;
use SrvPanel\Agent\DomainName;

/**
 * Der Lebenslauf einer Website — und die Argumente, die den Agenten erreichen.
 *
 * **Der Zustand folgt dem Agenten, nicht dem Klick** (CLAUDE.md, zweite
 * Grenze). Eine Domain steht auf „wird angelegt", bis `web.site.apply`
 * geantwortet hat; scheitert der Vorgang, bleibt sie darauf stehen und der
 * Fehlschlag ist sichtbar. Der naheliegende Weg — beim Absenden auf „aktiv"
 * setzen — ergäbe eine Liste, in der jede Domain aktiv aussieht, auch die, für
 * die kein Server-Block existiert.
 *
 * **Kein Wert aus der Anfrage erreicht den Agenten.** Die Argumente entstehen
 * aus der abgelegten Zeile, so wie es der Aufgabenkatalog und
 * {@see Lifecycle::payload()} vormachen. Der
 * Browser nennt eine Domain-ID; ob er sie sehen darf, entscheidet die
 * Mandantenklammer, und was der Agent bekommt, steht in der Datenbank.
 */
final class WebLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PhpSelection $php,
    ) {}

    /**
     * Die Argumente für `web.site.apply`.
     *
     * @return array<string, mixed>
     */
    public function payload(Domain $domain): array
    {
        $subscription = $this->subscription($domain);

        return [
            'subscription' => (string) $subscription?->name,
            'user' => (string) $subscription?->system_user,
            'domain' => $domain->name,
            'aliases' => $this->aliases($domain),
            'document_root' => $domain->document_root,
            'php_version' => $domain->php_version,
            'php_settings' => $domain->php_settings ?? [],
            'directives' => $domain->nginx_directives ?? [],
            'redirect_target' => $domain->redirect_target,
            'redirect_code' => $domain->redirect_kind?->statusCode(),

            // **Die Sperre des Abonnements schlägt auf jede seiner Domains
            // durch.** Sie steht am Abonnement und wird hier eingerechnet;
            // stünde sie nur am Abonnement, lieferte eine erneut angewandte
            // Domain eines gesperrten Abonnements wieder aus.
            'suspended' => $domain->status === DomainStatus::Suspended
                || $subscription?->status->usable() === false,
        ];
    }

    /**
     * Die Argumente für den FPM-Pool.
     *
     * Ein Pool je Abonnement und Version — die Zahl der Prozesse kommt aus
     * dem Kontingent des Plans.
     *
     * @return array<string, mixed>
     */
    public function poolPayload(Domain $domain): array
    {
        $subscription = $this->subscription($domain);

        return [
            'subscription' => (string) $subscription?->name,
            'user' => (string) $subscription?->system_user,
            'php_version' => (string) $domain->php_version,
            'max_children' => (int) ($subscription?->quota('fpm_processes') ?? 10),
        ];
    }

    /**
     * Einen Vorgang für eine Domain einreihen.
     *
     * `subject_type` und `subject_id` sagen, wovon er handelt — ohne sie
     * wüsste {@see self::afterSuccess()} nicht, welche Domain jetzt aktiv ist.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function dispatch(
        Domain $domain,
        string $task,
        string $message,
        ?array $payload = null,
        ?int $accountId = null,
    ): Operation {
        $operation = Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => OperationSubject::Domain->value,
            'subject_id' => $domain->id,
            // Im Arbeiter gibt es keine Anfrage. Ein Folgevorgang trägt
            // deshalb das Konto dessen, der ihn ausgelöst hat — sonst stünde
            // in der Liste „—" neben einer Sperre, die jemand angeordnet hat.
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => $payload ?? $this->payload($domain),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Den Pool und den Server-Block einreihen — in dieser Reihenfolge.
     *
     * **Zwei Vorgänge und nicht einer.** `web.site.apply` weist einen
     * Server-Block zurück, dessen FPM-Pool fehlt; der Pool muss also vorher
     * liegen. Beides in eine Operation zu packen hiesse, dass der Agent zwei
     * Dinge auf einmal tut und bei einem Fehlschlag die Hälfte davon getan
     * hat. Die Reihenfolge trägt die Warteschlange — sie hat einen Arbeiter,
     * und der arbeitet der Reihe nach.
     *
     * Steht hier und nicht im Dienst, weil beide Auslöser sie brauchen: das
     * Formular des Kunden und die Folge eines Abonnementvorgangs.
     */
    public function apply(Domain $domain, string $message, ?int $accountId = null): void
    {
        if ($domain->servesPhp() && $domain->php_version !== null) {
            $this->dispatch($domain, 'php.pool.apply', 'PHP-Pool für '.$domain->name, $this->poolPayload($domain), $accountId);
        }

        $this->dispatch($domain, 'web.site.apply', $message, null, $accountId);
    }

    /**
     * Die Hauptdomain eines Abonnements — angelegt, sobald es sie tragen kann.
     *
     * Sie entsteht nicht im Formular: Der Name des Abonnements *ist* die
     * Hauptdomain (§5.1), und ein zweites Eingabefeld dafür wäre eine
     * Gelegenheit, zwei verschiedene Namen einzutragen. Angelegt wird sie,
     * nachdem `subscription.provision` den Verzeichnisbaum gebaut hat —
     * vorher gäbe es kein `httpdocs`, in das der Server-Block zeigen könnte.
     *
     * Wiederholbar: Ein zweiter Lauf findet sie vor und legt nichts doppelt an.
     */
    public function ensureMainDomain(Subscription $subscription): ?Domain
    {
        return $this->tenancy->withoutRestriction(function () use ($subscription): ?Domain {
            $existing = Domain::query()
                ->where('subscription_id', $subscription->id)
                ->where('type', DomainType::Main->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Der Name des Abonnements ist schon durch die Prüfung des
            // Agenten gegangen — er ist der Verzeichnisname. Ist er trotzdem
            // kein Domainname (ein Abonnement aus P2, das „testabo" heisst),
            // entsteht keine Hauptdomain und niemand kommt zu Schaden.
            try {
                $name = DomainName::normalize($subscription->name);
            } catch (AgentException) {
                return null;
            }

            if (Domain::query()->where('name', $name)->exists()) {
                return null;
            }

            $domain = new Domain([
                'name' => $name,
                'type' => DomainType::Main,
                'status' => DomainStatus::Provisioning,
                'document_root' => DocumentRoot::forDomain($name, true),
                'php_version' => $this->php->defaultFor($subscription),
            ]);

            $domain->subscription_id = (int) $subscription->id;
            $domain->save();

            return $domain;
        });
    }

    public function afterSuccess(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if (str_starts_with($task, 'php.version')) {
            $this->rememberVersions($operation);

            return;
        }

        if (str_starts_with($task, 'subscription.')) {
            $this->afterSubscription($operation, $task);

            return;
        }

        if (! str_starts_with($task, 'web.site.')) {
            return;
        }

        // Der Arbeiter hat keinen Mandanten — ohne die Ausnahme fände er die
        // Domain nicht, deren Zustand er setzen soll.
        $this->tenancy->withoutRestriction(function () use ($operation, $task): void {
            $domain = Domain::query()->find($operation->subject_id);

            if ($domain === null) {
                return;
            }

            match ($task) {
                'web.site.apply' => $domain->forceFill([
                    'status' => $this->appliedStatus($operation),
                ])->save(),

                // **Jetzt erst verschwindet die Zeile.** Der Server-Block ist
                // weg, das Verzeichnis ist weg, die Protokolle sind weg — erst
                // danach gibt der Bestand den Namen wieder frei. Andersherum
                // wäre der Name frei, während die Dateien noch liegen.
                'web.site.remove' => $domain->delete(),

                default => null,
            };
        });
    }

    /**
     * Was ein Abonnementvorgang für die Websites bedeutet.
     *
     * **Die Reihenfolge in {@see Lifecycles::HANDLERS}
     * ist die Voraussetzung dafür, dass das hier stimmt.** Der Lebenslauf des
     * Abonnements läuft zuerst und hat den Zustand schon gesetzt; die
     * Argumente, die hier entstehen, lesen ihn frisch aus der Datenbank. Liefe
     * es umgekehrt, trüge jeder Server-Block noch den Zustand von vorher — die
     * Sperre stünde im Panel und die Website antwortete weiter.
     */
    private function afterSubscription(Operation $operation, string $task): void
    {
        if (! in_array($task, ['subscription.provision', 'subscription.suspend', 'subscription.resume'], true)) {
            return;
        }

        $subscription = $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($operation->subscription_id)
        );

        if ($subscription === null) {
            return;
        }

        if ($task === 'subscription.provision') {
            $main = $this->ensureMainDomain($subscription);

            if ($main !== null) {
                $this->apply($main, 'Website '.$main->name.' wird eingerichtet', $operation->account_id);
            }

            return;
        }

        // **Sperren und Entsperren schlagen auf jede Website durch.** Bis
        // hierher setzte `subscription.suspend` nur die Rechte des
        // Verzeichnisses; ein Besucher sah daraufhin einen nackten „403
        // Forbidden" von nginx. Jetzt wird jeder Server-Block neu geschrieben
        // — mit 503 und einer Erklärung, und beim Entsperren zurück.
        $domains = $this->tenancy->withoutRestriction(
            fn (): array => Domain::query()
                ->where('subscription_id', $subscription->id)
                ->orderBy('id')
                ->get()
                ->all()
        );

        foreach ($domains as $domain) {
            if ($domain->status->pending()) {
                continue;
            }

            $this->apply(
                $domain,
                sprintf('Website %s wird %s', $domain->name, $task === 'subscription.suspend' ? 'gesperrt' : 'freigegeben'),
                $operation->account_id,
            );
        }
    }

    /**
     * Was nach `web.site.apply` in der Zeile steht.
     *
     * Der Agent sagt es selbst: Er hat entweder einen ausliefernden oder einen
     * sperrenden Server-Block geschrieben, und `suspended` in seiner Antwort
     * ist die Auskunft darüber. Sie noch einmal aus dem Abonnement abzuleiten
     * hiesse, dieselbe Frage zweimal zu beantworten — und in dem Moment, in
     * dem sich das Abonnement zwischen Absenden und Antwort ändert, mit zwei
     * verschiedenen Ergebnissen.
     */
    private function appliedStatus(Operation $operation): DomainStatus
    {
        $suspended = ($operation->result['suspended'] ?? null) === true;

        return $suspended ? DomainStatus::Suspended : DomainStatus::Active;
    }

    /**
     * Den Zwischenspeicher der installierten Versionen nachziehen.
     *
     * Er wird nach jeder Installation und jedem Entfernen geschrieben. Bis
     * dahin stünde im Auswahlfeld einer Domain eine Version, die es seit fünf
     * Minuten gibt, noch nicht — oder eine, die es nicht mehr gibt, noch
     * immer.
     */
    private function rememberVersions(Operation $operation): void
    {
        $versions = $operation->result['available'] ?? null;

        if (is_array($versions)) {
            $this->php->remember(array_values(array_filter($versions, is_string(...))));
        }
    }

    /**
     * Das Abonnement der Domain — **ohne Mandantenklammer**.
     *
     * Die Begründung ist die Richtung: Die Domain ist bereits durch beide
     * Klammern gekommen; wer sie in der Hand hat, durfte auch ihr Abonnement
     * sehen. Ohne die Ausnahme hinge der Inhalt der Argumente daran, wer
     * gerade angemeldet ist — und im Grundzustand der Klammer stünde im
     * Namensfeld eine leere Zeichenkette. Der Agent wiese sie ab, aber die
     * Meldung führte an eine Stelle, an der niemand nach der Mandantenklammer
     * sucht. Aufgefallen im Test des Lebenslaufs, der so läuft wie der
     * Arbeiter: ohne angemeldetes Konto.
     */
    private function subscription(Domain $domain): ?Subscription
    {
        return $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($domain->subscription_id)
        );
    }

    /**
     * Die Aliasse einer Domain — als Namen für den `server_name`.
     *
     * @return list<string>
     */
    private function aliases(Domain $domain): array
    {
        return $this->tenancy->withoutRestriction(
            fn (): array => $domain->children()
                ->where('type', DomainType::Alias->value)
                ->orderBy('name')
                ->pluck('name')
                ->map(strval(...))
                ->values()
                ->all()
        );
    }
}
