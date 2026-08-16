<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein öffentlicher Schlüssel für den SFTP-Zugang eines Abonnements.
 *
 * **Was hier steht, ist eine Abschrift — die Wahrheit steht in der Datei.**
 * `/etc/srvpanel/ssh/<benutzer>` schreibt der Agent bei jeder Änderung neu, aus
 * dem vollständigen Bestand dieser Tabelle. Wer nur diese Zeilen liest, liest
 * den Sollzustand; was gilt, sagt `sftp.check`.
 *
 * **Der Kunde kommt an die Datei nicht heran** (`docs/57 §4`, mit Gegenprobe):
 * Sie liegt ausserhalb jedes Chroots, und die `AuthorizedKeysFile`-Angabe im
 * verwalteten Block ersetzt die Vorgabe `.ssh/authorized_keys`, statt sie zu
 * ergänzen. Nur deshalb ist diese Liste die ganze Wahrheit und nicht die halbe.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $label
 * @property string $type
 * @property string $fingerprint
 * @property int $bits
 * @property string $public_key
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription|null $subscription
 *
 * Die Mandantenklammer kommt aus {@see BelongsToSubscription} und damit aus
 * derselben Stelle wie bei jedem anderen Modell dieser Stufe. Eine eigene wäre
 * die zweite Fassung, und die zweite ist die, die veraltet.
 */
class SshKey extends Model
{
    use BelongsToSubscription;

    /** @var list<string> */
    protected $fillable = ['subscription_id', 'label', 'type', 'fingerprint', 'bits', 'public_key', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['bits' => 'integer'];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
