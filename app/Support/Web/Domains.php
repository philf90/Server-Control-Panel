<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\RedirectKind;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\ValidationException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Directives;
use SrvPanel\Agent\DocumentRoot;
use SrvPanel\Agent\DomainName;

/**
 * Domains anlegen, ändern und entfernen — mit Kontingent und Plan.
 *
 * **§6.2.3: die Prüfung sitzt im Dienst, nicht im Formular.** Wer das Formular
 * umgeht, trifft auf dieselbe Schranke wie wer es benutzt. Diese Klasse ist
 * diese Schranke; die Oberfläche zeigt den Stand nur an.
 *
 * **Die Regeln kommen aus dem Agenten, wo es sie schon gibt.** Was ein
 * Domainname ist, entscheidet {@see DomainName}; was ein DocumentRoot sein
 * darf, {@see DocumentRoot}; welche eigenen Direktiven zulässig sind,
 * {@see Directives}. Das Panel formuliert keine dieser Regeln neu — es fragt
 * sie und übersetzt die Ablehnung in eine Meldung, die im Formular steht. Ein
 * Name, der hier durchginge und dort scheiterte, ergäbe eine Domain, die ewig
 * „wird angelegt" bliebe; dieser Fehler ist in P2 schon einmal gemacht und
 * behoben worden.
 *
 * **Der Zustand folgt dem Agenten.** Angelegt wird die Zeile mit
 * `provisioning`; „aktiv" setzt {@see WebLifecycle} erst, wenn der Server-Block
 * steht.
 */
final class Domains
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PhpSelection $php,
        private readonly PhpLimits $limits,
        private readonly WebLifecycle $lifecycle,
    ) {}

    /**
     * Eine Domain anlegen und ihren Server-Block einreihen.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Subscription $subscription, array $data): Domain
    {
        $type = $this->type($data['type'] ?? null);
        $name = $this->name($data['name'] ?? null);
        $parent = $this->parent($subscription, $type, $data['parent_domain_id'] ?? null, $name);

        $this->assertUsable($subscription);
        $this->assertNameIsFree($name);
        $this->assertWithinQuota($subscription, $type);

        $redirect = $this->redirect($data);

        $domain = new Domain([
            'subscription_id' => $subscription->id,
            'parent_domain_id' => $parent?->id,
            'name' => $name,
            'type' => $type,
            'status' => DomainStatus::Provisioning,
            'document_root' => $this->documentRoot($type, $name, $data['document_root'] ?? null, $redirect !== null),
            'php_version' => $this->phpVersion($subscription, $type, $data['php_version'] ?? null, $redirect !== null),
            'php_settings' => $this->limits->check($subscription, $this->settingsFrom($data)),
            'nginx_directives' => $this->directives($data['nginx_directives'] ?? null),
            'redirect_target' => $redirect?->target,
            'redirect_kind' => $redirect?->kind,
        ]);

        $domain->subscription_id = (int) $subscription->id;
        $domain->save();

        $this->lifecycle->apply($domain, 'Domain '.$domain->name.' wird angelegt');

        return $domain;
    }

    /**
     * Eine Domain ändern und den Server-Block neu schreiben.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Domain $domain, array $data): Domain
    {
        $subscription = $domain->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages(['domain' => 'Diese Domain hat kein Abonnement.']);
        }

        $this->assertUsable($subscription);
        $this->assertNotPending($domain);

        $redirect = $this->redirect($data);

        // **Der Name ändert sich nicht.** Er ist der Verzeichnisname, der
        // `server_name`, der Name der Protokolldatei und der Schlüssel jeder
        // Zuordnung. Ein Umbenennen wäre ein Anlegen und ein Entfernen in
        // einem Vorgang — und wenn der zweite Teil scheitert, hat der Kunde
        // zwei Websites, von denen eine niemandem gehört.
        $domain->fill([
            'document_root' => $this->documentRoot(
                $domain->type,
                $domain->name,
                $data['document_root'] ?? $domain->document_root,
                $redirect !== null,
            ),
            'php_version' => $this->phpVersion(
                $subscription,
                $domain->type,
                array_key_exists('php_version', $data) ? $data['php_version'] : $domain->php_version,
                $redirect !== null,
            ),
            'php_settings' => $this->limits->check($subscription, $this->settingsFrom($data)),
            'nginx_directives' => $this->directives($data['nginx_directives'] ?? null),
            'redirect_target' => $redirect?->target,
            'redirect_kind' => $redirect?->kind,
        ]);

        $domain->status = DomainStatus::Provisioning;
        $domain->save();

        $this->lifecycle->apply($domain, 'Domain '.$domain->name.' wird geändert');

        return $domain;
    }

    /**
     * Eine Domain entfernen — mit ihrem Verzeichnis.
     *
     * **Das Verzeichnis geht immer mit**, so hat der Betreiber es festgelegt:
     * Ein Name, der belegt bleibt, weil irgendwo noch Dateien liegen, ist
     * schlechter als ein klarer Schnitt. Die Rückfrage in der Oberfläche nennt
     * den Pfad, bevor jemand bestätigt.
     *
     * **Es sei denn, eine zweite Domain liefert daraus aus.** Zwei Domains
     * dürfen auf dasselbe DocumentRoot zeigen; das Entfernen der einen nähme
     * der anderen dann die Dateien weg. Diese Frage beantwortet der Bestand
     * und nicht das Dateisystem — deshalb wird sie hier entschieden und dem
     * Agenten als Argument mitgegeben.
     *
     * @throws ValidationException
     */
    public function remove(Domain $domain): Operation
    {
        if (! $domain->type->removable()) {
            throw ValidationException::withMessages([
                'domain' => 'Die Hauptdomain gehört zum Abonnement und wird mit ihm entfernt.',
            ]);
        }

        $this->assertNotPending($domain);

        $children = $this->childCount($domain);

        if ($children > 0) {
            throw ValidationException::withMessages([
                'domain' => sprintf(
                    'An dieser Domain hängen noch %d Subdomains oder Aliasse. Erst die entfernen.',
                    $children,
                ),
            ]);
        }

        $payload = $this->lifecycle->payload($domain);
        $payload['remove_document_root'] = $this->documentRootIsExclusive($domain);

        $domain->forceFill(['status' => DomainStatus::Removing])->save();

        return $this->lifecycle->dispatch(
            $domain,
            'web.site.remove',
            'Domain '.$domain->name.' wird entfernt',
            $payload,
        );
    }

    /**
     * Wie viele Domains welcher Sorte ein Abonnement hat — für die Oberfläche
     * und für die Prüfung darunter.
     *
     * **Gezählt werden auch die, die gerade entstehen.** Zwei gleichzeitige
     * Anlagen kämen sonst beide durch, weil jede die andere noch nicht sieht.
     *
     * @return array<string, int>
     */
    public function counts(Subscription $subscription): array
    {
        $counts = [];

        foreach ([Quota::Domains, Quota::Subdomains] as $quota) {
            $types = array_values(array_filter(
                DomainType::cases(),
                static fn (DomainType $type): bool => $type->countsTowards() === $quota,
            ));

            $counts[$quota->value] = $this->tenancy->withoutRestriction(
                fn (): int => Domain::query()
                    ->where('subscription_id', $subscription->id)
                    ->whereIn('type', array_map(static fn (DomainType $t): string => $t->value, $types))
                    ->count()
            );
        }

        return $counts;
    }

    private function type(mixed $value): DomainType
    {
        $type = is_string($value) ? DomainType::tryFrom($value) : null;

        if ($type === null || ! in_array($type, DomainType::creatable(), true)) {
            throw ValidationException::withMessages([
                'type' => 'Diese Sorte Domain lässt sich nicht anlegen.',
            ]);
        }

        return $type;
    }

    /** Der Name — in der Normalform des Agenten. */
    private function name(mixed $value): string
    {
        try {
            return DomainName::normalize($value);
        } catch (AgentException) {
            throw ValidationException::withMessages([
                'name' => 'Kein gültiger Domainname. Erwartet wird etwa beispiel.de; Umlautdomains in Punycode.',
            ]);
        }
    }

    /**
     * Die Elterndomain — und die Regeln, die daran hängen.
     *
     * Eine Subdomain muss **unterhalb** ihrer Elterndomain liegen; der
     * Vergleich läuft über {@see DomainName::isBelow()} und nicht über
     * `str_ends_with`, sonst ginge `boesebeispiel.de` als Subdomain von
     * `beispiel.de` durch. Ein Alias darf dagegen jeden Namen tragen — genau
     * dafür gibt es ihn.
     */
    private function parent(Subscription $subscription, DomainType $type, mixed $parentId, string $name): ?Domain
    {
        if (! $type->requiresParent()) {
            return null;
        }

        $parent = is_numeric($parentId)
            ? Domain::query()->where('subscription_id', $subscription->id)->find((int) $parentId)
            : null;

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_domain_id' => 'Diese Sorte braucht eine Domain, unter der sie hängt.',
            ]);
        }

        if (! $parent->type->servesOwnContent()) {
            throw ValidationException::withMessages([
                'parent_domain_id' => 'Ein Alias kann selbst keine Subdomains und keine Aliasse tragen.',
            ]);
        }

        if ($type->requiresNameBelowParent() && ! DomainName::isBelow($name, $parent->name)) {
            throw ValidationException::withMessages([
                'name' => sprintf('Eine Subdomain von %s endet auf .%s', $parent->name, $parent->name),
            ]);
        }

        return $parent;
    }

    /**
     * Ist der Name auf diesem Server noch frei?
     *
     * **Ohne Mandantenklammer**, und das ist der Punkt: Die Eindeutigkeit gilt
     * serverweit. Mit der Klammer sähe ein Kunde die fremde Domain nicht,
     * bekäme hier ein „frei" und liefe eine Zeile später in einen
     * Datenbankfehler — mit einer Meldung, die niemandem hilft.
     */
    private function assertNameIsFree(string $name): void
    {
        $taken = $this->tenancy->withoutRestriction(
            fn (): bool => Domain::query()->where('name', $name)->exists()
        );

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'Diese Domain ist auf diesem Server bereits eingerichtet.',
            ]);
        }
    }

    private function assertUsable(Subscription $subscription): void
    {
        if (! $subscription->usable()) {
            throw ValidationException::withMessages([
                'domain' => sprintf('Das Abonnement ist %s — daran lässt sich nichts ändern.', $subscription->status->label()),
            ]);
        }
    }

    /** An einer Domain, an der ein Vorgang läuft, ändert niemand etwas. */
    private function assertNotPending(Domain $domain): void
    {
        if ($domain->status->pending()) {
            throw ValidationException::withMessages([
                'domain' => 'An dieser Domain läuft gerade ein Vorgang.',
            ]);
        }
    }

    /**
     * Das Kontingent — nach den Regeln, die im Formular des Betreibers stehen.
     *
     * `null` heisst unbegrenzt (docs/23 §2), `0` heisst keine. Ein Alias zählt
     * auf nichts; welche Sorte auf welches Kontingent zählt, beantwortet
     * {@see DomainType::countsTowards()} und nicht eine Bedingung hier.
     */
    private function assertWithinQuota(Subscription $subscription, DomainType $type): void
    {
        $quota = $type->countsTowards();

        if ($quota === null) {
            return;
        }

        $limit = $subscription->quota($quota->value);

        if (! is_numeric($limit)) {
            return;
        }

        $used = $this->counts($subscription)[$quota->value] ?? 0;

        if ($used >= (int) $limit) {
            throw ValidationException::withMessages([
                'type' => sprintf(
                    '%s: Das Kontingent von %d ist erreicht.',
                    $quota->label(),
                    (int) $limit,
                ),
            ]);
        }
    }

    private function documentRoot(DomainType $type, string $name, mixed $value, bool $isRedirect): ?string
    {
        if ($isRedirect || ! $type->servesOwnContent()) {
            return null;
        }

        if ($value === null || $value === '') {
            return DocumentRoot::forDomain($name, $type === DomainType::Main);
        }

        if (! is_string($value) || ! DocumentRoot::valid($value)) {
            throw ValidationException::withMessages([
                'document_root' => 'Ein Verzeichnis innerhalb des Abonnements, ohne führenden Schrägstrich und ohne „..".',
            ]);
        }

        return $value;
    }

    private function phpVersion(Subscription $subscription, DomainType $type, mixed $value, bool $isRedirect): ?string
    {
        if ($isRedirect || ! $type->servesOwnContent()) {
            return null;
        }

        if ($value === null || $value === '') {
            return $this->php->defaultFor($subscription);
        }

        if (! is_string($value) || ! $this->php->isSelectable($subscription, $value)) {
            throw ValidationException::withMessages([
                'php_version' => 'Diese PHP-Version ist für dieses Abonnement nicht verfügbar.',
            ]);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function settingsFrom(array $data): array
    {
        $settings = $data['php_settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @return list<string> */
    private function directives(mixed $value): array
    {
        try {
            return Directives::check($value);
        } catch (AgentException $error) {
            throw ValidationException::withMessages([
                'nginx_directives' => $error->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function redirect(array $data): ?object
    {
        $target = $data['redirect_target'] ?? null;

        if ($target === null || $target === '') {
            return null;
        }

        if (! is_string($target)) {
            throw ValidationException::withMessages(['redirect_target' => 'Kein gültiges Ziel.']);
        }

        $kind = is_string($data['redirect_kind'] ?? null)
            ? RedirectKind::tryFrom($data['redirect_kind'])
            : RedirectKind::Temporary;

        return (object) ['target' => $target, 'kind' => $kind ?? RedirectKind::Temporary];
    }

    private function childCount(Domain $domain): int
    {
        return $this->tenancy->withoutRestriction(
            fn (): int => Domain::query()->where('parent_domain_id', $domain->id)->count()
        );
    }

    /** Liefert nur diese eine Domain aus diesem Verzeichnis aus? */
    private function documentRootIsExclusive(Domain $domain): bool
    {
        if ($domain->document_root === null) {
            return false;
        }

        return $this->tenancy->withoutRestriction(
            fn (): bool => Domain::query()
                ->where('subscription_id', $domain->subscription_id)
                ->where('document_root', $domain->document_root)
                ->whereKeyNot($domain->id)
                ->doesntExist()
        );
    }
}
