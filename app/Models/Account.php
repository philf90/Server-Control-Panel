<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Ein Anmeldekonto.
 *
 * Konto und Kunde sind absichtlich getrennt: Ein Kunde ist der
 * Vertragspartner, ein Konto ist ein Mensch, der sich anmeldet. Zu einem
 * Kunden gehören ein Kundenkonto und beliebig viele Zusatzbenutzer — und der
 * Vertragspartner kann eine Firma sein, die sich nicht anmeldet.
 */
class Account extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'type', 'customer_id', 'name', 'email', 'password', 'locale', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Die ausdrücklich zugewiesenen Abonnements eines Zusatzbenutzers.
     *
     * Für Kundenkonten ist diese Beziehung leer — die kommen über ihren
     * Kunden an ihre Abonnements, nicht über eine Zuweisung.
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
                static fn (): array => Subscription::query()->pluck('id')->all()
            );
        }

        if ($this->type === AccountType::Additional) {
            return app(Tenancy::class)->withoutRestriction(
                fn (): array => $this->assignedSubscriptions()->pluck('subscriptions.id')->all()
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
                ->all();
        });
    }

    public function isAdmin(): bool
    {
        return $this->type->isAdmin();
    }
}
