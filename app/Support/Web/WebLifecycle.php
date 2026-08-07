<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Enums\AuditResult;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Operations\AfterOperation;
use App\Support\Operations\Lifecycles;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateChoice;
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
        private readonly AcmeSettings $tls,
        private readonly CertificateChoice $choice,
        private readonly Audit $audit,
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

            // **Eine Erlaubnis, keine Anweisung.** Ob HSTS gewollt ist, weiss
            // nur das Panel — es kennt den Testbetrieb, dessen Wurzel kein
            // Browser kennt. Ob das Zertifikat es hergibt, sieht der Agent
            // selbst nach; erst beide zusammen schreiben den Header.
            'hsts' => $this->tls->hsts($this->certificate($domain)),

            // **Welches Zertifikat ausgeliefert wird, sagt das Panel.** Bis
            // zum zweiten Wurf von P4 sah der Agent unter dem Namen der Domain
            // nach — damit entschied das Dateisystem, was nginx vorweist, und
            // die Zuordnung in der Datenbank war die zweite Wahrheit daneben.
            // Was hier hinausgeht, ist ein Name und kein Pfad; den Ablageort
            // baut der Agent (`docs/34 §2.1`).
            'certificate' => $this->certificate($domain)?->storage_name,
        ];
    }

    /**
     * Welches Zertifikat dieser Block ausliefert.
     *
     * **Gefragt wird {@see CertificateChoice} und nicht die Spalte.** Sie trägt
     * die Zuordnung; ob die gerade gilt, entscheidet die Auswahl — eine
     * abgelaufene Wahl wird übergangen, und dann steht hier das Zertifikat, das
     * einspringt. Zwei Stellen, die diese Frage beantworten, geben irgendwann
     * zwei Antworten, und die falsche fällt erst im Browser auf.
     */
    private function certificate(Domain $domain): ?Certificate
    {
        return $this->choice->effective($domain);
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

    /**
     * Die Aufgaben, nach denen sich an einer Website etwas ändert.
     *
     * Die drei Abonnementaufgaben stehen mit darin, und das ist keine
     * Doppelung: Der Lebenslauf des Abonnements setzt dessen Zustand, dieser
     * schreibt die Server-Blöcke nach. Beide beantworten dieselbe Aufgabe mit
     * verschiedenen Folgen.
     *
     * `php.pool.apply`, `web.logs.tail` und `web.logrotate.apply` fehlen: Sie
     * ändern am Bestand des Panels nichts.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return [
            'web.site.apply',
            'web.site.remove',
            'php.versions',
            'php.version.install',
            'php.version.remove',
            'subscription.provision',
            'subscription.suspend',
            'subscription.resume',
        ];
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
                'web.site.apply' => $this->applied($domain, $operation),

                // **Jetzt erst verschwindet die Zeile.** Der Server-Block ist
                // weg, das Verzeichnis ist weg, die Protokolle sind weg — erst
                // danach gibt der Bestand den Namen wieder frei. Andersherum
                // wäre der Name frei, während die Dateien noch liegen.
                'web.site.remove' => $this->removed($domain, $operation),

                default => null,
            };
        });
    }

    /**
     * Der Block steht — und mit ihm die Antwort auf „was liefert er aus?".
     *
     * **Der Zustand folgt dem Agenten, nicht dem Klick** (CLAUDE.md, zweite
     * Grenze). Deshalb steht der Protokolleintrag hier und nicht beim
     * Einreihen: Erst jetzt liefert dieser Block wirklich etwas anderes aus,
     * als jemand eingestellt hat. Ein Eintrag beim Absenden behauptete es
     * schon, bevor es stimmt — und bliebe stehen, wenn der Vorgang scheitert.
     *
     * **Und er steht hier für beide Wege.** `web.site.apply` wird an zwei
     * Stellen eingereiht: von {@see self::apply()} und von
     * `CertificateLifecycle::install()` nach jeder Erneuerung. Ein Haken am
     * Einreihen müsste an beiden hängen, und der zweite ist der, den jemand
     * vergisst. Hier laufen sie zusammen, weil jeder abgeschlossene Vorgang
     * durch `Lifecycles::afterSuccess()` geht.
     */
    private function applied(Domain $domain, Operation $operation): void
    {
        $domain->forceFill(['status' => $this->appliedStatus($operation)])->save();

        $this->recordOverride($domain);
    }

    /**
     * Der laute Rückfall: Die Wahl gilt nicht mehr, und das steht im Protokoll.
     *
     * **Beschlossen am 6. August 2026** (`docs/34 §8`, umgesetzt am 7. August):
     * Läuft die Wahl ab und liegt ein gültiges, deckendes Zertifikat daneben,
     * liefert der Block dieses aus — sonst nähme ein hochgeladenes Zertifikat,
     * das niemand erneuert, die Website vom Netz. **Übergangen heisst aber
     * nicht verschwiegen.** Die Domainseite sagt es dem, der hinsieht; das
     * Protokoll sagt es dem, der später fragt, seit wann.
     *
     * **Gefragt wird {@see CertificateChoice::overridden()} und nicht die
     * Spalte.** Dieselbe Stelle beantwortet die Frage für den Server-Block und
     * für die Domainseite; eine zweite Rechnung hier wäre eine zweite Antwort,
     * und die falsche fiele erst auf, wenn jemand das Protokoll gegen die Seite
     * hält.
     *
     * **Der Eintrag wiederholt sich, und das ist Absicht.** Jeder geschriebene
     * Block, der die Wahl übergeht, ist ein eigener Vorgang; wer nur den ersten
     * protokollierte, bräuchte einen Vermerk „schon gesagt" — also einen
     * zweiten Zustand neben der Wahl, der veraltet. So steht im Protokoll die
     * Spanne und nicht ein Punkt.
     *
     * **Als Fehlschlag und nicht als Erfolg.** Jemand durfte wählen, und die
     * Wahl liess sich nicht einlösen — genau der Fall, für den es diesen
     * Ausgang gibt. „Erfolgreich" stünde neben einem Ereignis, das für den, der
     * es eingestellt hat, das Gegenteil bedeutet, und niemand fände es beim
     * Filtern nach dem, was Aufmerksamkeit braucht.
     */
    private function recordOverride(Domain $domain): void
    {
        if (! $this->choice->overridden($domain)) {
            return;
        }

        $delivered = $this->choice->effective($domain);

        $this->audit->record(
            'domain.certificate.overridden',
            AuditResult::Failure,
            target: $domain,
            subscriptionId: (int) $domain->subscription_id,
            context: [
                // Beide als `int` und nicht so, wie der Treiber sie liefert:
                // Das Protokoll wird als JSON abgelegt, und eine Kennung, die
                // einmal `7` und einmal `"7"` heisst, lässt sich später nicht
                // vergleichen, ohne dass jemand daran denkt.
                'chosen' => (int) $domain->certificate_id,
                'delivered' => $delivered === null ? null : (int) $delivered->id,
            ],
        );
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
            /*
             * **Die Rotation gehört zum Abonnement und nicht zur Domain.** Der
             * Ausdruck in der logrotate-Datei deckt `logs/*&#47;*.log` ab —
             * jede Domain, auch die von morgen. Eine Datei je Domain hiesse,
             * dass eine vergessene nicht rotiert, und das fiele erst auf, wenn
             * die Quota des Kunden voll ist.
             *
             * Ohne diese Zeilen war `web.logrotate.apply` in P3 gebaut und von
             * nichts aufgerufen — gefunden hat das `AgentOperationReachTest`.
             */
            $this->dispatchForSubscription(
                $subscription,
                'web.logrotate.apply',
                'Protokollrotation für '.$subscription->name,
                $operation->account_id,
            );

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
     * Die Zeile geht — und mit ihr der Pool, den niemand mehr braucht.
     *
     * **Ein Pool ohne Domain ist kein leerer Ordner, sondern eine Sperre.**
     * `php.version.remove` weist ab, solange ein Abonnement einen Pool in
     * dieser Version hat; bliebe der Pool einer entfernten Domain stehen,
     * liesse sich die Version nie wieder entfernen, und der Betreiber suchte
     * nach einem Abonnement, das es nicht mehr gibt.
     *
     * Gefunden hat diese Lücke `AgentOperationReachTest`: `php.pool.remove`
     * war gebaut und wurde von nichts aufgerufen.
     */
    private function removed(Domain $domain, Operation $operation): void
    {
        $version = $domain->php_version;
        $subscriptionId = $domain->subscription_id;

        $domain->delete();

        if ($version === null) {
            return;
        }

        $weitere = Domain::query()
            ->where('subscription_id', $subscriptionId)
            ->where('php_version', $version)
            ->exists();

        if ($weitere) {
            return;
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null) {
            return;
        }

        $this->dispatchForSubscription(
            $subscription,
            'php.pool.remove',
            sprintf('PHP-Pool %s für %s wird entfernt', $version, $subscription->name),
            $operation->account_id,
            ['php_version' => $version],
        );
    }

    /**
     * Ein Vorgang, der zum Abonnement gehört und zu keiner einzelnen Domain.
     *
     * Er trägt deshalb keinen Gegenstand: Es gibt keine Domain, deren Zustand
     * danach ein anderer wäre.
     *
     * @param  array<string, mixed>  $extra
     */
    private function dispatchForSubscription(
        Subscription $subscription,
        string $task,
        string $message,
        ?int $accountId,
        array $extra = [],
    ): void {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => $accountId,
            'type' => $task,
            'task' => $task,
            'payload' => array_merge([
                'subscription' => (string) $subscription->name,
                'user' => (string) $subscription->system_user,
            ], $extra),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);
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
