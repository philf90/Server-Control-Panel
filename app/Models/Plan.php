<?php

declare(strict_types=1);

namespace App\Models;

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
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_customer_id', 'name', 'description', 'quotas', 'features', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'quotas' => 'array',
            'features' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
