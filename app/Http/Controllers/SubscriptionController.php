<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Databases\Databases;
use App\Support\Databases\Dumps;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Time\Clock;
use App\Support\Tls\DnsCredentialAccess;
use App\Support\Tls\DnsProfile;
use App\Support\Web\Page;
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
    /**
     * Das Verzeichnis — und was der Betrachter darin tun darf.
     *
     * **Warum die Fähigkeiten mitkommen.** Ein Kunde sah hier „Abonnement
     * anlegen" und in jeder Zeile „Bearbeiten". Beides ist ihm verwehrt, beides
     * endete mit einem 403 — die Autorisierung war richtig, die Auskunft
     * falsch. Ein Knopf ist ein Angebot; einer, der nur ablehnen kann, ist eine
     * Falle.
     *
     * Die Entscheidung fällt hier und nicht im Browser: Dieselbe Regel wie bei
     * den Kacheln (§7.2, Regel 2) — der Server schickt, was gilt, die Seite
     * zeichnet es. Ein `v-if="istAdmin"` im Template wäre eine zweite Fassung
     * der Policy, und die zweite Fassung ist die, die veraltet.
     *
     * **Je Zeile und nicht je Seite.** `SubscriptionPolicy::update()` fragt
     * heute nur nach dem Kontotyp und fiele für alle Zeilen gleich aus. Das ist
     * eine Eigenschaft von heute und keine Zusage: Sobald ein Zusatzbenutzer
     * eines seiner Abonnements bearbeiten darf, ist die Antwort je Zeile eine
     * andere. Ein Aufruf je Zeile kostet hier nichts.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        $subscriptions = Subscription::query()
            ->with(['customer', 'plan'])
            ->orderBy('name')
            ->paginate(Page::SIZE)
            ->withQueryString();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => Page::from($subscriptions, static fn (Subscription $s): array => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'customer' => $s->customer?->displayName(),
                'plan' => $s->plan?->name,
                'system_user' => $s->system_user,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'used_mb' => $s->disk_used_mb,
                'percent' => $s->diskUsagePercent(),
                'can' => ['update' => $account?->can('update', $s) ?? false],
            ]),
            'can' => ['create' => $account?->can('create', Subscription::class) ?? false],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Subscriptions/Create', [
            /*
             * Gesperrte Kunden stehen in der Liste — abgeblendet.
             *
             * Sie herauszufiltern wäre der kürzere Weg und die schlechtere
             * Auskunft: Wer einen Kunden sucht, den er gestern angelegt hat,
             * und ihn nicht findet, sucht den Fehler bei sich. So steht er da,
             * lässt sich nicht wählen, und daneben steht der Grund.
             */
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
            //
            // Ohne `withoutTrashed()`, seit `subscriptions` kein `deleted_at`
            // mehr hat (docs/35): Die Regel hängt genau eine Bedingung auf
            // diese Spalte an, und ohne sie wäre das ab der Migration ein
            // SQL-Fehler auf jedem Anlegen. Sie fällt nicht auf, weil ein
            // Aufruf sie verlangt, sondern weil eine Spalte verschwand.
            'name' => ['required', 'string', 'max:63', Rule::unique('subscriptions', 'name')],
        ]);

        /*
         * **Kein Abonnement für einen gesperrten Kunden.**
         *
         * Es käme aktiv aus dem Anlegen heraus, während der Kunde gesperrt
         * ist: Die Kaskade der Kundensperre (docs/26 §11) sperrt, was es beim
         * Klick gab, und ein Abonnement im Zustand „wird angelegt" hat noch
         * keinen Systembenutzer, den man sperren könnte. Danach stünde beim
         * Kunden „gesperrt" und darunter eine laufende Webseite.
         *
         * Die Prüfung gilt auch für den Betreiber, und das ist kein Versehen:
         * Anlegen kann ohnehin nur er. Wer für einen gesperrten Kunden etwas
         * anlegen will, gibt ihn vorher frei — dann ist die Freigabe eine
         * Entscheidung und kein Nebeneffekt.
         *
         * Die Regel steht hier und nicht als `Rule::exists(...)->where(...)`:
         * Eine Prüfregel könnte nur „gibt es nicht" sagen, und der Betreiber
         * sähe „Der gewählte Kunde ist ungültig" für einen Kunden, der vor ihm
         * auf dem Bildschirm steht.
         */
        $customer = Customer::query()->findOrFail($data['customer_id']);

        if ($customer->status === CustomerStatus::Suspended) {
            throw ValidationException::withMessages([
                'customer_id' => "Kunde {$customer->number} ist gesperrt. Erst freigeben, dann anlegen.",
            ]);
        }

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

        // **`claim()` steht innerhalb der Transaktion und nicht davor.** Es
        // verbraucht den Namen — schreibt also eine Zeile ins Verzeichnis, die
        // nie wieder verschwindet. Scheitert das Anlegen danach, soll auch die
        // Reservierung zurückgerollt werden; sonst frisst jeder Fehlversuch
        // eine Nummer, und die Lücke im Zähler ist nicht mehr zu erklären.
        $subscription = DB::transaction(function () use ($data, $lifecycle): Subscription {
            return Subscription::query()->create([
                'customer_id' => (int) $data['customer_id'],
                'plan_id' => (int) $data['plan_id'],
                'name' => $data['name'],
                'system_user' => $lifecycle->claim($data['name']),
                'status' => SubscriptionStatus::Provisioning,
            ]);
        });

        $operation = $this->start($subscription, 'subscription.provision', 'Abonnement anlegen', $audit, $lifecycle);

        return redirect()->route('operations.show', $operation);
    }

    public function show(
        Request $request,
        Subscription $subscription,
        DnsCredentialAccess $credentials,
        DnsProfile $profiles,
    ): Response {
        $subscription->loadMissing(['customer', 'plan']);
        $account = $request->user();

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
                'suspended_at' => Clock::display($subscription->suspended_at),
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
                'measured_at' => Clock::display($subscription->disk_usage_measured_at),

                /*
                 * **Ob die Grenze überhaupt gilt** — drei Werte, und `null`
                 * heisst „nicht nachgesehen". Ein Abonnement aus der Zeit vor
                 * dieser Spalte hat keine Auskunft, und dann schweigt die Seite
                 * statt Entwarnung zu geben.
                 *
                 * Auf `cloudsrv24` stand hier bis zum 10. August 2026 eine
                 * Grenze von 15360 MB, die nichts begrenzte: `setquota` war
                 * gescheitert, der Agent hatte es gemeldet, und niemand las es.
                 */
                'enforced' => is_bool($subscription->disk_quota_enforced)
                    ? $subscription->disk_quota_enforced
                    : null,
                'note' => $subscription->disk_quota_note,
            ],

            /*
             * Und der Platz der Datenbanken — daneben und nicht darin.
             *
             * **Zwei Zahlen und kein Balken mit zwei Farben.** Sie messen
             * verschiedene Datenträgerbereiche: `disk_mb` das Abo-Verzeichnis
             * unter `/var/www/vhosts`, `database_mb` die Schemata unter
             * `/var/lib/mysql`. Sie zu addieren ergäbe eine Zahl, die gegen
             * keine Grenze steht.
             *
             * **`enforced: false` geht mit und ist keine Nebensache.** Der
             * Speicher ist eine Quota, die zuschlägt; die Datenbankgrösse wird
             * gemessen und nicht erzwungen (docs/36 §9). Ein Balken, der beides
             * gleich zeichnet, verspricht eine Grenze, die es nicht gibt — und
             * genau das wäre die Sorte Zusage, die ein Betreiber erst im
             * Ernstfall als falsch erkennt.
             */
            'database_usage' => [
                /*
                 * **Drei Zustände und nicht zwei.** „Noch nicht gemessen" und
                 * „keine Datenbanken" sehen in einer Zahl gleich aus und
                 * bedeuten Verschiedenes: Das eine ist ein ausstehender Lauf,
                 * das andere ein fertiger Befund. Ohne `count` stünde bei jedem
                 * frischen Abonnement „braucht einen erreichbaren
                 * Datenbankserver" — ein Satz, der nach einem Defekt klingt, wo
                 * schlicht nichts anzulegen war. Dieselbe Unterscheidung wie
                 * zwischen `null` und `0` bei `size_bytes`, nur eine Ebene höher.
                 */
                'count' => $subscription->databases()->count(),
                'used_mb' => $subscription->databaseUsedMb(),
                'limit_mb' => is_numeric($dbLimit = $subscription->quota(Quota::DatabaseMb->value)) ? (int) $dbLimit : null,
                'percent' => $subscription->databaseUsagePercent(),
                'enforced' => false,
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

            /*
             * Die Domains des Abonnements.
             *
             * Sie stehen an dieser Seite und nicht auf einer eigenen: Ein Kunde
             * kommt über sein Abonnement zu seinen Websites, und ein zweiter
             * Menüpunkt wäre ein zweiter Weg zum selben Ort. Der Betreiber hat
             * zusätzlich die serverweite Liste unter /domains.
             */
            'domains' => $subscription->domains()
                ->orderByRaw("case when type = 'main' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(static fn (Domain $domain): array => [
                    'id' => (int) $domain->id,
                    'name' => $domain->name,
                    'type_label' => $domain->type->label(),
                    'status' => $domain->status->value,
                    'status_label' => $domain->status->label(),
                    'php_version' => $domain->php_version,
                    'is_redirect' => $domain->isRedirect(),
                ])->all(),

            /*
             * Was der Betrachter an diesem Abonnement tun darf.
             *
             * **Eine Ablage und nicht fünf einzelne Fahnen.** Hier stand
             * `mayAddDomain` allein — richtig gedacht und nur für eine der
             * sechs Aktionen dieser Seite gemacht. Bearbeiten, Sperren,
             * Entsperren und Zurückbauen standen ungefragt da, und ein Kunde
             * bekam auf jeden Klick einen 403.
             *
             * `addDomain` heisst deshalb jetzt `can.addDomain`: eine Form für
             * dieselbe Sache, damit `AbilityReachTest` sie überhaupt
             * gegenprüfen kann.
             */
            'can' => [
                'update' => $account?->can('update', $subscription) ?? false,
                'suspend' => $account?->can('suspend', $subscription) ?? false,
                'delete' => $account?->can('delete', $subscription) ?? false,
                'addDomain' => $account?->can('create', [Domain::class, $subscription]) ?? false,
                'viewCustomer' => $subscription->customer !== null
                    && ($account?->can('view', $subscription->customer) ?? false),
                'manageDns' => $account?->can('manageDns', $subscription) ?? false,
            ],

            /*
             * Die eigenen DNS-Zugangsdaten (docs/34 §5, Schritt 6b).
             *
             * **Nur wenn der Plan `dns_edit` freigibt.** Ohne die Freigabe gilt
             * das Profil des Betreibers, und dann gibt es hier nichts zu
             * hinterlegen — der Agent würde nach einem Profil gefragt, das
             * niemand je liest. Die Frage stellt dieselbe Policy, die den
             * Aufruf später abweist; ein `v-if` auf den Kontotyp wäre eine
             * zweite Fassung davon.
             *
             * **Und das Geheimnis steht hier nicht.** `describe()` gibt
             * Anbieter, Zeitpunkt und Zonen — mehr gibt der Agent nicht heraus,
             * auch nicht die letzten vier Zeichen.
             */
            'dns' => $account?->can('manageDns', $subscription) === true
                ? [
                    'profile' => $profiles->forSubscription($subscription),
                    'credential' => $credentials->describe($profiles->forSubscription($subscription)),
                    'providers' => $credentials->providers(),
                ]
                : null,

            'operations' => Operation::query()
                ->where('subscription_id', $subscription->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(static fn (Operation $o): array => [
                    'id' => (int) $o->id,
                    'task' => $o->task,
                    'status_label' => $o->status->label(),
                    'created_at' => Clock::display($o->created_at),
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

    /**
     * Die Speichergrenze noch einmal anwenden — ohne sie zu ändern.
     *
     * **Der Anlass steht in `docs/41`.** Auf `cloudsrv24` war die
     * Dateisystem-Quota nicht eingeschaltet; beide Abonnements bekamen ihre
     * Grenze nie. Nach dem Einschalten gab es keinen Weg, sie anzuwenden:
     * {@see self::update()} reiht `subscription.quota` nur ein, wenn sich der
     * Wert *unterscheidet* — und er unterschied sich nicht. Der Betreiber hätte
     * die Grenze umstellen und zurückstellen müssen.
     *
     * > **Eine Einstellung, die sich nur durch eine Änderung anwenden lässt,
     * > hat keinen Weg zurück in einen Zustand, den jemand anderes verändert
     * > hat.**
     *
     * **Kein Knopf für alle Abonnements auf einmal.** Der wäre bequemer und
     * hiesse: ein Klick, hundert Vorgänge. Wer die Quota gerade eingeschaltet
     * hat, hat auch die Kommandozeile — `srvpanel usage` misst danach ohnehin
     * neu, und was fehlt, sieht er in der Übersicht.
     */
    public function reapplyQuota(Subscription $subscription, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        /*
         * **Nur wo es ein Konto gibt.** Dieselbe Bedingung wie in
         * {@see self::update()}: Während des Anlegens setzt
         * `subscription.provision` die Grenze gleich mit, und nach dem Rückbau
         * gibt es niemanden mehr, dem sie gälte.
         */
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Suspended], true)) {
            throw ValidationException::withMessages([
                'subscription' => 'Dieses Abonnement hat keinen Systembenutzer, dem eine Grenze gälte.',
            ]);
        }

        $audit->success('subscription.quota_reapplied', $subscription, [
            'name' => $subscription->name,
            'disk_mb' => $subscription->quota(Quota::DiskMb->value),
        ]);

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
     *
     * **Die Datenbanken gehen zuerst, und das ist keine Kosmetik** (`docs/36
     * §5`). Ein Schema liegt in `/var/lib/mysql` und damit ausserhalb von
     * allem, was `subscription.remove` anfasst — genau wie ein
     * Zertifikatsverzeichnis unter `/etc/srvpanel/tls/certs`, und genau dieser
     * Fall hat den Abnahmelauf von `docs/35` angehalten: zwölf private
     * Schlüssel ohne Zeile, die auf sie zeigt. Hier wären es die Daten eines
     * Kunden.
     *
     * Die Reihenfolge trägt die Warteschlange: Sie hat einen Arbeiter, und der
     * arbeitet der Reihe nach — dasselbe Mittel wie in `WebLifecycle::apply()`,
     * wo der FPM-Pool vor dem Server-Block liegen muss.
     *
     * **Scheitert einer der Vorgänge, bleibt seine Zeile auffindbar.**
     * `databases.subscription_id` steht auf `nullOnDelete` und der Name ist
     * abgeschrieben; `srvpanel db prune` findet, was liegengeblieben ist. Die
     * Alternative wäre gewesen, `subscription.remove` selbst alles mit dem
     * Präfix wegwerfen zu lassen — und das ist die eine Stelle, an der ein
     * Fehler in der Präfixbildung die Daten eines fremden Kunden kostet.
     */
    public function destroy(
        Subscription $subscription,
        Audit $audit,
        Lifecycle $lifecycle,
        Databases $databases,
        Dumps $dumps,
    ): RedirectResponse {
        $databases->removeAllFor($subscription);

        // **Und das Verzeichnis der Sicherungen.** Es liegt unter
        // `/var/lib/srvpanel/dumps/<abo>` und damit ausserhalb von allem, was
        // `subscription.remove` anfasst — dieselbe Lage wie bei den
        // Zertifikatsverzeichnissen, die docs/35 zutage gebracht hat. Ein Dump
        // ist die vollständige Datenbank eines Kunden; einer, auf den nichts
        // mehr zeigt, ist genau der Rest, den P5 nicht hinterlassen darf.
        $dumps->removeAllFor($subscription);

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
