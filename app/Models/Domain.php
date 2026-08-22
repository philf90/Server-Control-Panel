<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\RedirectKind;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use RuntimeException;
use SrvPanel\Agent\DocumentRoot;
use SrvPanel\Agent\DomainName;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Eine Domain — der erste Gegenstand, der unter dem Abonnement hängt (§5.1).
 *
 * **Zwei Klammern, nicht eine.** Die erste kommt aus
 * {@see BelongsToSubscription} und filtert auf das Abonnement. Die zweite
 * steht in {@see self::booted()} und filtert auf einzelne Domains — sie ist
 * die Einlösung des Versprechens aus §6.1, dass ein Zusatzbenutzer auf
 * einzelne Domains eines Abonnements beschränkt werden kann. Ohne sie hätte
 * das Formular ein Feld gehabt, das nichts bewirkt.
 *
 * **Der Name ist die Normalform des Agenten.** Nicht „auch", sondern
 * ausschliesslich: {@see DomainName::normalize()} entscheidet, was ein
 * Domainname ist, und das Panel fragt dieselbe Regel. Zwei Schreibweisen
 * desselben Namens — mit und ohne Punkt am Ende, gross und klein — wären zwei
 * Zeilen in der Datenbank und zwei `server_name` in der Konfiguration.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int|null $certificate_id
 * @property Carbon|null $certificate_pinned_at
 * @property int|null $parent_domain_id
 * @property string $name
 * @property DomainType $type
 * @property DomainStatus $status
 * @property string|null $document_root
 * @property string|null $php_version
 * @property array<string, mixed>|null $php_settings
 * @property list<string>|null $nginx_directives
 * @property string|null $redirect_target
 * @property RedirectKind|null $redirect_kind
 * @property-read Subscription|null $subscription
 * @property-read Certificate|null $certificate
 * @property-read Domain|null $parent
 * @property-read Collection<int, Domain> $children
 * @property-read DomainDnsCheck|null $dnsCheck
 */
class Domain extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'parent_domain_id', 'name', 'type', 'status',
        'document_root', 'php_version', 'php_settings', 'nginx_directives',
        'redirect_target', 'redirect_kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'status' => DomainStatus::class,
            'redirect_kind' => RedirectKind::class,
            'php_settings' => 'array',
            'nginx_directives' => 'array',
            'certificate_pinned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('domain-restriction', static function (Builder $builder): void {
            $model = $builder->getModel();

            app(Tenancy::class)->applyDomainRestriction(
                $builder,
                $model->qualifyColumn('subscription_id'),
                $model->qualifyColumn('id'),
            );
        });

        /*
         * **`subscriptions.main_domain` ist eine Abschrift, keine zweite
         * Wahrheit.**
         *
         * Die Spalte gibt es seit P1 und sie wurde nie beschrieben — die
         * Kundenübersicht zeigt deshalb bis heute „noch keine Domain". Ab hier
         * hat die Frage eine Antwort, und damit die Gefahr: Derselbe Name
         * stünde an zwei Stellen, und die eine, die beim nächsten Umbau
         * nachgezogen wird, ist erfahrungsgemäss nicht beide.
         *
         * Deshalb wird die Abschrift nicht von einem Dienst gepflegt, der
         * daran denken muss, sondern vom Modell selbst — an dem einen Ereignis,
         * nach dem sie falsch sein könnte. Ein Test bricht die Regel
         * absichtlich und sieht nach, ob es auffällt.
         */
        static::saved(static function (Domain $domain): void {
            $domain->projectMainDomain($domain->name);
        });

        static::deleted(static function (Domain $domain): void {
            $domain->projectMainDomain(null);
        });

        /*
         * **Ein Zertifikat, das diese Domain nicht deckt, wird ihr nicht
         * zugeordnet.**
         *
         * Die Regel steht hier und nicht beim Aufrufer, weil es davon mehrere
         * geben wird: das Einspielen nach einer Bestellung, die Erneuerung,
         * später das Hochladen. Drei Stellen mit derselben Prüfung heisst zwei
         * Gelegenheiten, sie zu vergessen — und was dann entsteht, ist kein
         * Fehler, den jemand meldet: Die Seite lädt, der Browser zeigt eine
         * Namenswarnung, und der Betreiber sieht sie nie, weil er die eigene
         * Domain im Zertifikatsspeicher stehen hat.
         *
         * Geprüft wird nur beim Setzen — ein Speichern, das den Verweis nicht
         * anfasst, kostet keine Abfrage.
         */
        static::saving(static function (Domain $domain): void {
            $domain->guardCertificateCoverage();
        });
    }

    /**
     * Das Zertifikat, das nginx für diese Domain ausliefert.
     *
     * **Nicht in `$fillable`.** Der Verweis kommt aus einem Vorgang und nie aus
     * einem Formular; stünde er dort, könnte ein Kunde beim Bearbeiten seiner
     * Domain eine fremde Zertifikatsnummer mitschicken. Die Deckungsprüfung
     * unten finge das meistens ab — aber nicht bei einem Wildcard, das den
     * Namen zufällig deckt.
     *
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * Die Prüfung zur Zuordnung — siehe den Kommentar in {@see self::booted()}.
     *
     * **Ohne die Mandantenklammer, und das will begründet sein.** Sie steht im
     * Grundzustand auf „nichts", und in einem Kommando oder einem Job ist das
     * der Normalfall. Ein `Certificate::find()` lieferte dort `null`, und diese
     * Prüfung wiese eine Zuordnung ab, die richtig ist — der Wächter würde beim
     * Arbeiten zubeissen, statt beim Fehler. Gelesen wird hier nichts, was
     * jemand zu sehen bekommt: Die Antwort ist ein Ja oder Nein über eine
     * Nummer, die der Aufrufer schon hat.
     */
    private function guardCertificateCoverage(): void
    {
        if ($this->certificate_id === null || ! $this->isDirty('certificate_id')) {
            return;
        }

        $certificate = Certificate::query()->withoutGlobalScopes()->find($this->certificate_id);

        if ($certificate === null) {
            throw new RuntimeException(sprintf(
                'Zertifikat %d gibt es nicht — %s bekommt es nicht zugeordnet.',
                $this->certificate_id,
                $this->name,
            ));
        }

        if (! $certificate->covers($this->name)) {
            throw new RuntimeException(sprintf(
                'Das Zertifikat deckt %s nicht ab. Gedeckt sind: %s.',
                $this->name,
                implode(', ', $certificate->coveredNames()) ?: 'keine Namen',
            ));
        }

        /*
         * **Und es muss demselben Abonnement gehören.**
         *
         * Der Kommentar an {@see self::certificate()} sagt seit P4 voraus,
         * wann die Deckungsprüfung allein nicht genügt: „nicht bei einem
         * Wildcard, das den Namen zufällig deckt". Genau das kommt mit dem
         * zweiten Wurf — `*.example.de` deckt jede Unterdomain der Zone, auch
         * die eines fremden Kunden, und ab da ist die Zuordnung keine
         * Sorgfaltsfrage mehr, sondern die Grenze zwischen zwei Abonnements
         * (`docs/34 §3`).
         *
         * Das Zertifikat der Oberfläche (`subscription_id === null`) fällt
         * damit ebenfalls heraus, und das ist richtig: Es gehört keinem
         * Kunden und keiner Kundendomain.
         */
        if ($certificate->subscription_id !== $this->subscription_id) {
            throw new RuntimeException(sprintf(
                'Das Zertifikat gehört nicht zum Abonnement von %s.',
                $this->name,
            ));
        }
    }

    /** @return BelongsTo<Domain, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_domain_id');
    }

    /** @return HasMany<Domain, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_domain_id');
    }

    /**
     * Der letzte DNS-Abgleich dieser Domain — oder keiner.
     *
     * **Es gibt je Domain genau eine Zeile**, `Dns::store()` schreibt sie über
     * `updateOrCreate()`. Deshalb `hasOne` und nicht `hasMany`: Die Beziehung
     * bildet ab, was in der Tabelle steht, statt eine Liste zu versprechen,
     * die nie länger als eins wird.
     *
     * **Sie ist für die Listen da**, nicht für die Domainseite — dort fragt
     * `Dns::last()`, weil es dabei auch den Zeitpunkt über `Clock` schickt.
     * Hier geht es um zwanzig Zeilen auf einmal: Ohne `with('dnsCheck')`
     * stellte jede Zeile ihre eigene Abfrage.
     *
     * @return HasOne<DomainDnsCheck, $this>
     */
    public function dnsCheck(): HasOne
    {
        return $this->hasOne(DomainDnsCheck::class);
    }

    /**
     * Alle Namen, unter denen diese Domain antwortet.
     *
     * **Dieselbe Liste wie im `server_name` des Agenten**, und aus demselben
     * Grund an einer Stelle: Ein Zertifikat muss jeden Namen decken, unter dem
     * der Block antwortet. Deckt es nur den ersten, warnt der Browser bei jedem
     * Alias — und die Seite lädt trotzdem, weshalb es niemand meldet.
     *
     * Aliasse sind Kinder ohne eigenen Block; Subdomains und Zusatzdomains
     * haben einen eigenen und stehen deshalb nicht hier.
     *
     * @return list<string>
     */
    public function serverNames(): array
    {
        $names = [$this->name];

        foreach ($this->children as $child) {
            if ($child->type === DomainType::Alias) {
                $names[] = $child->name;
            }
        }

        return $names;
    }

    /** Leitet diese Domain weiter, statt eigene Dateien auszuliefern? */
    public function isRedirect(): bool
    {
        return $this->redirect_target !== null;
    }

    /**
     * Läuft unter dieser Domain PHP?
     *
     * Nein bei einem Alias — er steht im `server_name` seiner Elterndomain und
     * hat keinen eigenen Block, in dem ein Handler stünde. Nein bei einer
     * Weiterleitung: Dort antwortet nginx selbst, ohne je eine Datei zu suchen.
     * Ein FPM-Pool für einen dieser beiden Fälle wäre ein Pool, den nie ein
     * Prozess betritt — und ein Eintrag im Kontingent, der nichts belegt.
     */
    public function servesPhp(): bool
    {
        return $this->type->servesOwnContent() && ! $this->isRedirect();
    }

    /**
     * Der absolute Pfad des DocumentRoot — **zum Anzeigen**.
     *
     * Er geht nicht an den Agenten. Der baut denselben Pfad selbst aus dem
     * Namen des Abonnements und dem relativen Teil; das ist die Regel aus
     * `subscription.provision`, und sie ist der Grund, warum es dort keinen
     * Pfadausbruch gibt. Hier steht der Pfad, weil ein Kunde wissen muss,
     * wohin er seine Dateien legt.
     */
    public function absoluteDocumentRoot(): ?string
    {
        $subscription = $this->subscription;

        if ($subscription === null || $this->document_root === null) {
            return null;
        }

        return SubscriptionProvision::VHOSTS.'/'.$subscription->name.'/'.$this->document_root;
    }

    /**
     * Ist das ein zulässiger relativer DocumentRoot?
     *
     * **Die Regel steht im Agenten.** Hier stand zuerst ein eigener Ausdruck
     * mit derselben Absicht — und das war dieselbe Doppelung, die dieses
     * Projekt schon mehrfach eingeholt hat: zwei Formulierungen einer Regel,
     * von denen beim nächsten Mal eine nachgezogen wird. Der Agent baut aus
     * dem Wert einen Pfad; also gehört ihm die Regel, und das Panel fragt sie.
     * So ist es bei {@see DomainName} und beim Namen des Abonnements auch.
     *
     * Dass der Agent beim Aufruf noch einmal prüft, bleibt richtig: Er glaubt
     * seinem Aufrufer nicht. Es ist dann aber dieselbe Prüfung und nicht eine
     * zweite, die davon abweichen kann.
     */
    public static function isValidDocumentRoot(string $value): bool
    {
        return DocumentRoot::valid($value);
    }

    /**
     * Der Vorschlag für ein neues DocumentRoot.
     *
     * Ein Alias bekommt keines, weil er keine eigenen Dateien hat; alles
     * andere entscheidet {@see DocumentRoot::forDomain()}.
     */
    public static function defaultDocumentRoot(DomainType $type, string $name): ?string
    {
        if (! $type->servesOwnContent()) {
            return null;
        }

        return DocumentRoot::forDomain($name, $type === DomainType::Main);
    }

    /**
     * Die Abschrift in `subscriptions.main_domain` nachziehen.
     *
     * `withoutRestriction`, und die Begründung ist die Richtung: Die Domain
     * selbst ist bereits durch beide Klammern gekommen — wer sie speichern
     * durfte, durfte auch ihr Abonnement sehen. Ohne die Ausnahme hinge die
     * Abschrift daran, welcher Mandant gerade gesetzt ist: Im Arbeiter der
     * Warteschlange und in jedem Konsolenkommando ist das der Grundzustand,
     * die Aktualisierung träfe keine Zeile und niemand bekäme davon etwas mit.
     * Eine Abschrift, die still danebenliegt, ist schlimmer als keine.
     */
    private function projectMainDomain(?string $name): void
    {
        if ($this->type !== DomainType::Main) {
            return;
        }

        app(Tenancy::class)->withoutRestriction(function () use ($name): void {
            Subscription::query()
                ->whereKey($this->subscription_id)
                ->update(['main_domain' => $name]);
        });
    }
}
