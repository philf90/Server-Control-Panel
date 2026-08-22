<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Was der letzte DNS-Abgleich einer Domain ergeben hat.
 *
 * **Eine Messung mit ihrem Zeitpunkt und kein Spiegel der Zone.** Das Panel
 * führt keine Einträge (`docs/72 §1`); was hier steht, ist die Antwort auf
 * „zeigt die Domain jetzt hierher", und sie gilt für den Augenblick, in dem
 * sie entstanden ist.
 *
 * **`checked_at` ist nicht `updated_at`.** Ein späterer Umbau, der die Zeile
 * aus einem anderen Grund anfasst, verschöbe sonst die Auskunft „zuletzt
 * geprüft" — und die Anzeige behauptete eine Frische, die es nicht gibt.
 *
 * @property int $id
 * @property int $domain_id
 * @property int $subscription_id
 * @property Carbon $checked_at
 * @property array<string, mixed> $findings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Domain|null $domain
 */
final class DomainDnsCheck extends Model
{
    use BelongsToSubscription;

    protected $fillable = ['domain_id', 'subscription_id', 'checked_at', 'findings'];

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'findings' => 'array',
        ];
    }
}
