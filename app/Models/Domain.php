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
 * @property-read Domain|null $parent
 * @property-read Collection<int, Domain> $children
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
