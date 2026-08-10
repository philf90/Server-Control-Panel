<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DbUserStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\DbUserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Ein Datenbankbenutzer.
 *
 * **Name und Wirt zusammen sind die Kennung.** `'p1001_web'@'localhost'` und
 * `'p1001_web'@'203.0.113.5'` sind in MariaDB **zwei verschiedene Benutzer** —
 * mit zwei Passwörtern und zwei Rechtelisten. Deshalb steht `host` als Spalte
 * und der eindeutige Index über beiden; ein Kennzeichen „darf von aussen" wäre
 * die Sorte Vereinfachung, die beim ersten Zurücksetzen eines Passworts
 * auffliegt.
 *
 * **Es gibt keine Passwortspalte, und das ist die Entscheidung und nicht ihr
 * Nebeneffekt** (`docs/36 §4`, Entscheidung 3 des Betreibers). Das Panel
 * erzeugt das Passwort, schickt es in einem unmittelbaren Aufruf an den
 * Agenten, zeigt es genau einmal und vergisst es. Der Massstab dafür steht im
 * Abnahmelauf von P4: *„und das DNS-Token steht nirgends."* Wer seines
 * verliert, setzt ein neues — es gibt kein Nachschlagen, und genau das ist der
 * Punkt.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string|null $subscription_name
 * @property string $name
 * @property string $label
 * @property string $host
 * @property DatabaseEngine $engine
 * @property DbUserStatus $status
 * @property Carbon|null $locked_at
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Database> $databases
 */
class DbUser extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<DbUserFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['subscription_id', 'name', 'label', 'engine', 'host', 'status', 'locked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'status' => DbUserStatus::class,
            'locked_at' => 'datetime',
        ];
    }

    /** Der Name des Abonnements wird beim Anlegen abgeschrieben — siehe {@see Database::booted()}. */
    protected static function booted(): void
    {
        static::creating(function (DbUser $user): void {
            if ($user->subscription_id === null || $user->subscription_name !== null) {
                return;
            }

            $name = app(Tenancy::class)->withoutRestriction(
                static fn (): mixed => Subscription::query()
                    ->whereKey($user->subscription_id)
                    ->value('name'),
            );

            $user->subscription_name = is_string($name) ? $name : null;
        });
    }

    /** @return BelongsToMany<Database, $this> */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class, 'database_db_user');
    }

    /** `'p1001_web'@'localhost'` — die Kennung, wie MariaDB sie schreibt. */
    public function account(): string
    {
        return sprintf("'%s'@'%s'", $this->name, $this->host);
    }

    /**
     * Erreicht dieser Zugang den Server von aussen?
     *
     * `localhost` heisst über den Unix-Socket oder über die Loopback-Adresse —
     * also von einer Anwendung auf demselben Server. Alles andere ist
     * Fernzugriff (`docs/36 §12`) und setzt voraus, dass der Betreiber ihn
     * freigeschaltet hat.
     */
    public function remote(): bool
    {
        return $this->host !== 'localhost';
    }
}
