<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DumpKind;
use App\Enums\DumpStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\DatabaseDumpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Db\Dump;

/**
 * Eine Sicherung — die dritte Sache, die P5 auf dem System hinterlässt.
 *
 * **Sie überlebt ihre Datenbank.** `database_id` steht auf `nullOnDelete`, und
 * der Name ist abgeschrieben: Eine Sicherung ist gerade das, was man nach einem
 * Versehen noch hat. Eine, die mit der Datenbank verschwindet, ist keine.
 *
 * **Und `storage_name` ist ein Name und kein Pfad.** Derselbe Zuschnitt wie bei
 * {@see Certificate::$storage_name}: Die Anwendung nennt ihn, {@see Dump::path()}
 * im Agenten macht daraus den Ablageort. Ein Prozess mit Systemrechten nimmt
 * keinen Pfad entgegen.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string|null $subscription_name
 * @property int|null $database_id
 * @property string $database_name
 * @property string $storage_name
 * @property DatabaseEngine $engine
 * @property DumpKind $kind
 * @property DumpStatus $status
 * @property int|null $bytes
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property-read Subscription|null $subscription
 * @property-read Database|null $database
 */
class DatabaseDump extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<DatabaseDumpFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'database_id', 'database_name', 'storage_name',
        'engine', 'kind', 'status', 'bytes', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['engine' => DatabaseEngine::class, 'status' => DumpStatus::class, 'kind' => DumpKind::class];
    }

    /** Der Name des Abonnements wird beim Anlegen abgeschrieben — siehe {@see Database::booted()}. */
    protected static function booted(): void
    {
        static::creating(function (DatabaseDump $dump): void {
            if ($dump->subscription_id === null || $dump->subscription_name !== null) {
                return;
            }

            $name = app(Tenancy::class)->withoutRestriction(
                static fn (): mixed => Subscription::query()
                    ->whereKey($dump->subscription_id)
                    ->value('name'),
            );

            $dump->subscription_name = is_string($name) ? $name : null;
        });
    }

    /** @return BelongsTo<Database, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * Der Ablageort — gefragt wird der Agent, nicht diese Klasse.
     *
     * Das Panel liest die Datei (sie gehört `root:srvpanel 0640`), und dafür
     * braucht es den Pfad. Er entsteht trotzdem in {@see Dump::path()} und
     * nicht hier: Zwei Stellen, die einen Pfad zusammensetzen, setzen ihn
     * irgendwann verschieden zusammen — und die eine davon ist die, die als
     * root schreibt.
     */
    public function path(): ?string
    {
        // `->` und nicht `?->`: Der Null-Zusammenführungsoperator hat
        // isset-Semantik und fängt das fehlende Abonnement selbst ab — dieselbe
        // Zeile wie in `Subscription::quota()`, mit demselben Kommentar.
        $subscription = $this->subscription->name ?? $this->subscription_name;

        return $subscription === null ? null : Dump::path($subscription, $this->storage_name);
    }

    /** Eine Sicherung, deren Abonnement zurückgebaut wurde — die Datei liegt noch. */
    public function orphaned(): bool
    {
        return $this->subscription_id === null;
    }
}
