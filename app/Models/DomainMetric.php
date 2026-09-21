<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyMetric;
use App\Models\Concerns\BelongsToSubscription;
use Database\Factories\DomainMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dieselbe Zeile für eine einzelne Domain (B3, `docs/129 §6`).
 *
 * **Drei Kennzahlen und nicht sechs** — Traffic, Zugriffe, Fehler. Platz und
 * Datenbanken gehören dem Abonnement und nicht einer seiner Domains; welche
 * Kennzahl wo hingehört, sagt {@see DailyMetric::ofADomain()} und keine Liste
 * an dieser Stelle.
 *
 * **`subscription_id` steht mit dabei**, obwohl die Domain ihn kennt — sonst
 * griffe {@see BelongsToSubscription} hier nicht, und die Voreinstellung dieser
 * Tabelle wäre „alles sichtbar" statt „nichts". Derselbe Grund wie bei
 * {@see CronRun}.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $domain_id
 * @property Carbon $day
 * @property DailyMetric $metric
 * @property int $value
 * @property-read Domain|null $domain
 * @property-read Subscription|null $subscription
 */
final class DomainMetric extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<DomainMetricFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['subscription_id', 'domain_id', 'day', 'metric', 'value'];

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'metric' => DailyMetric::class,
            'value' => 'integer',
        ];
    }
}
