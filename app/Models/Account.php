<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Ein Anmeldekonto.
 *
 * Konto und Kunde sind absichtlich getrennt: Ein Kunde ist der
 * Vertragspartner, ein Konto ist ein Mensch, der sich anmeldet. Zu einem
 * Kunden gehören ein Kundenkonto und beliebig viele Zusatzbenutzer — und der
 * Vertragspartner kann eine Firma sein, die sich nicht anmeldet.
 *
 * Die @property-Zeilen sind kein Beiwerk für die Entwicklungsumgebung: Ohne
 * sie sieht die statische Prüfung hinter `$account->type` nur die Spalte und
 * damit eine Zeichenkette — jeder Aufruf einer Enum-Methode darauf wäre ein
 * Fehler, den sie melden muss und nicht melden sollte.
 *
 * @property int $id
 * @property AccountType $type
 * @property int|null $customer_id
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property string $locale
 * @property AccountStatus $status
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property-read Customer|null $customer
 * @property-read Collection<int, Subscription> $assignedSubscriptions
 */
class Account extends Authenticatable
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'type', 'customer_id', 'name', 'email', 'password', 'locale', 'status', 'theme',
    ];

    /**
     * Die Themes, die ein Konto wählen kann.
     *
     * `null` fehlt hier mit Absicht: Es ist kein Theme, sondern die Abwesenheit
     * einer Wahl — „dem Betriebssystem folgen". Die Liste steht an dieser einen
     * Stelle, damit Validierung, Oberfläche und Test dieselbe fragen.
     *
     * @var list<string>
     */
    public const THEMES = ['dark', 'light'];

    /** @var list<string> */
    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => AccountStatus::class,
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Die ausdrücklich zugewiesenen Abonnements eines Zusatzbenutzers.
     *
     * Für Kundenkonten ist diese Beziehung leer — die kommen über ihren
     * Kunden an ihre Abonnements, nicht über eine Zuweisung.
     *
     * @return BelongsToMany<Subscription, $this>
     */
    public function assignedSubscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class)
            ->withPivot(['permissions', 'domain_ids'])
            ->withTimestamps();
    }

    /**
     * Welche Abonnements dieses Konto sehen darf.
     *
     * **Über die Zugehörigkeitskette, nicht über einen festen Vergleich.**
     * Heute ist die Kette einen Schritt lang: Konto → Kunde → Abonnements.
     * Sobald ein Kunde Unterkunden hat (§5.4), wird sie länger, und dann ist
     * genau diese Methode die einzige Stelle, die sich ändert. Ein
     * `where customer_id = $this->customer_id` an dreißig Stellen wäre
     * derselbe Code — nur nicht mehr zu finden.
     *
     * Die Abfrage läuft ohne Mandantenklammer, denn sie stellt sie ja gerade
     * erst her.
     *
     * @return list<int>
     */
    public function accessibleSubscriptionIds(): array
    {
        if ($this->type->isAdmin()) {
            return app(Tenancy::class)->withoutRestriction(
                static fn (): array => Subscription::query()
                    ->pluck('id')->map(intval(...))->values()->all()
            );
        }

        if ($this->type === AccountType::Additional) {
            return app(Tenancy::class)->withoutRestriction(
                fn (): array => $this->assignedSubscriptions()
                    ->pluck('subscriptions.id')->map(intval(...))->values()->all()
            );
        }

        if ($this->customer_id === null) {
            return [];
        }

        return app(Tenancy::class)->withoutRestriction(function (): array {
            $customerIds = $this->customer?->descendantIdsIncludingSelf() ?? [];

            if ($customerIds === []) {
                return [];
            }

            return Subscription::query()
                ->whereIn('customer_id', $customerIds)
                ->pluck('id')
                ->map(intval(...))
                ->values()
                ->all();
        });
    }

    /**
     * Auf welche Domains dieses Konto in welchem Abonnement beschränkt ist.
     *
     * **Das Feld `domain_ids` lag seit P1 in der Verknüpfungstabelle und wurde
     * von nichts gelesen.** §6.1 verspricht „zusätzlich eine Einschränkung auf
     * einzelne Domains eines Abonnements", die Spalte war da, das Formular
     * hätte sie füllen können — und die Klammer hätte sie ignoriert. Solange
     * es keine Domains gab, war das folgenlos. Mit P3 wird daraus eine Zusage,
     * die eingelöst gehört.
     *
     * **Ein fehlender oder leerer Eintrag heißt „keine Einschränkung".** Das
     * ist der Normalfall: Die meisten Zusatzbenutzer arbeiten an allem, was
     * ihr Abonnement hat. `null` von „leere Liste" zu unterscheiden wäre hier
     * eine Unterscheidung ohne Unterschied — eine Zuweisung, die auf *keine*
     * Domain zeigt, entsteht nur durch ein Formular, das noch keine gewählt
     * hat, und die richtige Auskunft darauf ist „alle des Abonnements" und
     * nicht „gar nichts".
     *
     * @return array<int, list<int>> Abonnement-ID => erlaubte Domain-IDs
     */
    public function domainRestrictions(): array
    {
        if ($this->type !== AccountType::Additional) {
            return [];
        }

        return app(Tenancy::class)->withoutRestriction(function (): array {
            $restrictions = [];

            foreach ($this->assignedSubscriptions()->get() as $subscription) {
                $pivot = $subscription->getAttribute('pivot');
                $stored = $pivot instanceof Pivot ? $pivot->getAttribute('domain_ids') : null;

                // Wie bei den Rechten: Die Verknüpfungstabelle hat kein Modell
                // mit Casts, der Wert kann als JSON-Zeichenkette ankommen.
                if (is_string($stored)) {
                    $stored = json_decode($stored, true);
                }

                if (! is_array($stored) || $stored === []) {
                    continue;
                }

                $ids = [];

                foreach ($stored as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }

                if ($ids !== []) {
                    $restrictions[(int) $subscription->id] = array_values(array_unique($ids));
                }
            }

            return $restrictions;
        });
    }

    /** Ist der zweite Faktor eingerichtet und bestätigt? */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Die Adresse eines Kontos, das sich anmelden kann.
     *
     * **Ein zurückgezogenes Konto hat keine mehr.** Seine Adresse wird beim
     * Zurückziehen des Kunden freigegeben, damit derselbe Mensch sich wieder
     * anlegen lässt; die Kundennummer bleibt vergeben, die Adresse nicht.
     *
     * **Und hierher kommt so ein Konto nicht.** Die Anmeldung findet es nicht,
     * weil `null` auf keine getippte Adresse passt. Wer es trotzdem erreicht,
     * hat einen Weg an der Anmeldung vorbei gebaut — und dann ist eine
     * Ausnahme besser als eine leere Zeichenkette: Die stünde als Schlüssel
     * einer Ratenbegrenzung für *alle* Konten zugleich, und ein `clear()`
     * darauf setzte die Sperre für jeden zurück.
     */
    public function signInAddress(): string
    {
        $address = $this->email;

        if ($address === null || $address === '') {
            throw new RuntimeException('Dieses Konto hat keine Anmeldeadresse mehr: '.$this->id);
        }

        return $address;
    }

    public function isAdmin(): bool
    {
        return $this->type->isAdmin();
    }

    /**
     * Darf dieses Konto dieses Abonnement überhaupt sehen?
     *
     * Die Frage vor allen anderen: Ohne sie ist jede Rechteprüfung eine
     * Prüfung am falschen Objekt.
     */
    public function mayAccessSubscription(Subscription|int $subscription): bool
    {
        $id = $subscription instanceof Subscription ? (int) $subscription->id : $subscription;

        return in_array($id, $this->accessibleSubscriptionIds(), true);
    }

    /**
     * Welche Rechte dieses Konto in einem Abonnement hat.
     *
     * Für Admins und Kunden ist die Frage gegenstandslos — sie haben im
     * Rahmen ihres Abonnements alle. Der Katalog beschreibt nur, was ein Kunde
     * an einen Zusatzbenutzer weitergegeben hat.
     *
     * @return list<Permission>
     */
    public function permissionsFor(Subscription|int $subscription): array
    {
        if (! $this->mayAccessSubscription($subscription)) {
            return [];
        }

        if ($this->type !== AccountType::Additional) {
            return Permission::cases();
        }

        $id = $subscription instanceof Subscription ? (int) $subscription->id : $subscription;

        return app(Tenancy::class)->withoutRestriction(function () use ($id): array {
            $assignment = $this->assignedSubscriptions()
                ->wherePivot('subscription_id', $id)
                ->first();

            if ($assignment === null) {
                return [];
            }

            $pivot = $assignment->getAttribute('pivot');
            $stored = $pivot instanceof Pivot ? $pivot->getAttribute('permissions') : null;

            // Der Wert kann als JSON-Zeichenkette ankommen, weil die
            // Verknüpfungstabelle kein Modell mit Casts hat.
            if (is_string($stored)) {
                $stored = json_decode($stored, true);
            }

            return Permission::fromStored($stored);
        });
    }

    public function hasPermission(Subscription|int $subscription, Permission $permission): bool
    {
        return in_array($permission, $this->permissionsFor($subscription), true);
    }
}
