<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Service-Paket: Kontingente und Funktionsfreigaben als Vorlage.
 *
 * Änderungen wirken auf alle daran gebundenen Abonnements — das ist der Sinn
 * einer Vorlage und zugleich ihre Gefahr. Ein Abonnement kann einzelne Werte
 * übersteuern; welche das sind, steht am Abonnement und wird in der
 * Oberfläche als „abweichend vom Plan" markiert.
 *
 * @property int $id
 * @property int|null $owner_customer_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $quotas
 * @property array<string, mixed> $features
 * @property bool $is_default
 * @property-read Customer|null $owner
 * @property-read Collection<int, Subscription> $subscriptions
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'owner_customer_id', 'name', 'description', 'quotas', 'features', 'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quotas' => 'array',
            'features' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
