<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyMetric;
use App\Models\Concerns\BelongsToSubscription;
use Database\Factories\SubscriptionMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Kennzahl eines Abonnements an einem Tag (B3, `docs/129 §6`).
 *
 * **Eine Zeile je Abonnement, Kennzahl und Tag.** Die lange Form ist gemessen
 * teurer als die breite (32 MiB gegen 16 bei 2000 Abonnements über 30 Tage) und
 * nimmt dafür eine sechste Kennzahl ohne Migration auf — die Begründung steht
 * in der Migration und nicht hier.
 *
 * **Geschrieben wird überschreibend und nicht addierend.** Der Nachtlauf sieht
 * denselben Tag mehrfach: `web.access.count` liest `access.log` **und**
 * `access.log.1`, weil `logrotate` in einem Fenster läuft und nicht zu einer
 * Uhrzeit (gemessen, `CLAUDE.md`). Wer hier addierte, zählte jeden Tag so oft,
 * wie der Lauf ihn zu Gesicht bekommt.
 *
 * > **Ein Lauf, der denselben Tag mehrfach sieht, darf ihn nicht mehrfach
 * > zählen.**
 *
 * **Keine Zeitstempel.** Der Tag ist der Zeitpunkt; wann die Zeile geschrieben
 * wurde, sagt über die Zahl darin nichts.
 *
 * @property int $id
 * @property int $subscription_id
 * @property Carbon $day
 * @property DailyMetric $metric
 * @property int $value
 * @property-read Subscription|null $subscription
 */
final class SubscriptionMetric extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<SubscriptionMetricFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['subscription_id', 'day', 'metric', 'value'];

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
