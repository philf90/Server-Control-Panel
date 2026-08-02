<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Die tragende Einheit — und der Anker der Mandantentrennung.
 *
 * Genau ein Systembenutzer, genau eine Hauptdomain, ein Plan, ein Satz
 * Kontingente, ein Zustand. Alles Weitere hängt hier dran; wer ein Abonnement
 * sehen darf, darf alles darunter sehen, und wer es nicht darf, kommt an
 * nichts darunter.
 *
 * Dieses Modell trägt selbst **keine** Mandantenklammer über
 * {@see BelongsToSubscription} — es wäre eine Klammer um
 * sich selbst. Die Sichtbarkeit von Abonnements regelt die Policy; die Klammer
 * regelt alles, was daran hängt.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'plan_id', 'name', 'system_user', 'main_domain',
        'status', 'quota_overrides',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'quota_overrides' => 'array',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function additionalAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)
            ->withPivot(['permissions', 'domain_ids'])
            ->withTimestamps();
    }

    /**
     * Ein Kontingent, mit der Übersteuerung des Abonnements vor dem Plan.
     *
     * `array_key_exists` statt `??`, und das ist der ganze Punkt: Eine
     * Übersteuerung auf `0` oder `null` ist eine Aussage („keine Datenbanken")
     * und darf nicht stillschweigend auf den Planwert zurückfallen.
     */
    public function quota(string $key): mixed
    {
        $overrides = $this->quota_overrides ?? [];

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return ($this->plan?->quotas ?? [])[$key] ?? null;
    }

    /** Weicht dieses Kontingent vom Plan ab? Die Oberfläche markiert das. */
    public function quotaDiffersFromPlan(string $key): bool
    {
        return array_key_exists($key, $this->quota_overrides ?? []);
    }

    public function feature(string $key): bool
    {
        return (bool) (($this->plan?->features ?? [])[$key] ?? false);
    }

    public function usable(): bool
    {
        return $this->status->usable();
    }
}
