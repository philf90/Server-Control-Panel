<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupStatus;
use App\Models\Concerns\BelongsToSubscription;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Sicherung eines ganzen Abonnements (P8).
 *
 * **Sie überlebt ihr Abonnement.** `subscription_id` steht auf `nullOnDelete`,
 * und `subscription_name` ist abgeschrieben: Eine Sicherung ist gerade das, was
 * man nach einem Rückbau noch hat. Dieselbe Entscheidung wie bei
 * {@see DatabaseDump} und bei `audit_events.account_name` seit `docs/901`.
 *
 * > **Löschen und Vergessen sind zwei Dinge.**
 *
 * **Und `storage_name` ist ein Name und kein Pfad.** Die Anwendung nennt ihn,
 * `SrvPanel\Agent\Backup\Store::path()` macht daraus den Ablageort. Ein Prozess
 * mit Systemrechten nimmt keinen Pfad entgegen.
 *
 * **`system_user` und `db_prefix` sind eine Auskunft und keine Anweisung.**
 * Nach Form A (`docs/117 §3`) bekommt eine Wiederherstellung beide **neu**;
 * die alten stehen hier, damit die Seite hinterher die Zuordnung alt → neu
 * zeigen kann. Wer sie beim Zurückspielen als Vorgabe läse, hätte Form B
 * gebaut — und die hat der Betreiber nicht entschieden.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string $subscription_name
 * @property string $storage_name
 * @property BackupStatus $status
 * @property int|null $bytes
 * @property int|null $files
 * @property int|null $entries
 * @property int|null $databases
 * @property int|null $system_user
 * @property string|null $db_prefix
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription|null $subscription
 */
class Backup extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'subscription_name', 'storage_name', 'status',
        'bytes', 'files', 'entries', 'databases', 'system_user', 'db_prefix',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'bytes' => 'integer',
            'files' => 'integer',
            'entries' => 'integer',
            'databases' => 'integer',
            'system_user' => 'integer',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
