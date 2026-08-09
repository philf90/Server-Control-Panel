<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\DatabaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\DomainName;

/**
 * Eine Datenbank — das Erste, was P5 unter dem Abonnement anlegt (§5.1).
 *
 * **Der Name trägt das Präfix und ist serverweit eindeutig.** `p1001_shop`,
 * nicht `shop`: Ein Schema in MariaDB gehört keinem Abonnement, es gehört dem
 * Server. Die Zusammensetzung steht in {@see Names} und nicht hier — das Panel
 * fragt dieselbe Regel, die der Agent erzwingt, so wie {@see Domain} für den
 * Domainnamen {@see DomainName} fragt.
 *
 * **`label` ist eine Abschrift für die Anzeige, kein zweiter Name.** Der
 * vollständige Name wird nicht aus `subscription->system_user` und `label`
 * zusammengesetzt, wenn er gebraucht wird — er steht in `name`. Zwei Stellen,
 * die entscheiden, wie eine Datenbank heisst, geben irgendwann zwei Antworten,
 * und die falsche steht dann in einem `DROP DATABASE`.
 *
 * **Sie überlebt ihr Abonnement, wenn etwas schiefgeht.** `subscription_id`
 * steht auf `nullOnDelete`, und der Name wird beim Anlegen abgeschrieben —
 * dieselbe Form wie bei {@see Certificate} und {@see Operation}, aus einem
 * schärferen Grund: Ein Schema liegt in `/var/lib/mysql` und damit ausserhalb
 * von allem, was `subscription.remove` anfasst. Kaskadierte die Zeile, wäre
 * nach einem gescheiterten `db.database.remove` das Schema da und die Zeile
 * fort.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string|null $subscription_name
 * @property string $name
 * @property string $label
 * @property DatabaseStatus $status
 * @property string $charset
 * @property string $collation
 * @property int|null $size_bytes
 * @property Carbon|null $size_measured_at
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, DbUser> $users
 */
class Database extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<DatabaseFactory> */
    use HasFactory;

    /*
     * `subscription_name` steht mit Absicht **nicht** darin — es ist eine
     * Abschrift und keine Eingabe, dieselbe Regel wie bei {@see Certificate}.
     *
     * Und **kein `password`**: Es gibt die Spalte nicht (docs/36 §4).
     */

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'name', 'label', 'engine', 'status', 'charset', 'collation',
        'size_bytes', 'size_measured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'status' => DatabaseStatus::class,
            'size_measured_at' => 'datetime',
        ];
    }

    /**
     * Der Name des Abonnements wird beim Anlegen abgeschrieben.
     *
     * **Die dritte Stelle mit dieser Schleife** — nach {@see Operation} und
     * {@see Certificate}, und {@see DbUser} ist die vierte. Dass eine Regel an
     * vier Orten steht, ist in diesem Projekt sonst der Anlass, sie
     * einzusammeln; hier steht sie trotzdem viermal, und der Grund ist der
     * Kommentar in `Operation::booted()`: Der Filter aus
     * {@see BelongsToSubscription} setzt `subscription_id` selbst, wenn genau
     * ein Mandant aktiv ist, und das muss vorher geschehen sein. Ein Trait
     * hinge damit an der Reihenfolge zweier `creating`-Zuhörer — an einer
     * Eigenschaft also, die niemand beim Lesen sieht und die ein umsortiertes
     * `use` still kippt.
     *
     * **Aufgeschrieben statt behoben** (`docs/36 §20`): Der Weg wäre ein Trait,
     * dessen Reihenfolge ein Wächter festhält. Das ist eine eigene Änderung an
     * vier Modellen und gehört nicht in denselben Beitrag wie die Datenbanken.
     *
     * Ohne Mandantenklammer nachgeschlagen, aus demselben Grund wie dort: Ein
     * Vorgang des Betreibers entsteht ohne gesetzten Mandanten, und die Klammer
     * fände das Abonnement nicht, dessen Namen sie abschreiben soll.
     */
    protected static function booted(): void
    {
        static::creating(function (Database $database): void {
            if ($database->subscription_id === null || $database->subscription_name !== null) {
                return;
            }

            $name = app(Tenancy::class)->withoutRestriction(
                static fn (): mixed => Subscription::query()
                    ->whereKey($database->subscription_id)
                    ->value('name'),
            );

            $database->subscription_name = is_string($name) ? $name : null;
        });
    }

    /** @return BelongsToMany<DbUser, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(DbUser::class, 'database_db_user');
    }

    /**
     * Eine Datenbank, deren Abonnement zurückgebaut wurde — das Schema liegt
     * noch.
     *
     * Die Gegenfrage zu {@see Certificate::orphaned()}, mit einem Unterschied:
     * Es gibt hier kein Gegenstück zum „Zertifikat der Oberfläche". Eine
     * Datenbank ohne Abonnement **und** ohne Abschrift ist kein zulässiger
     * Zustand, sondern ein Fehler — und `srvpanel db prune` meldet ihn, statt
     * ihn wegzuräumen.
     */
    public function orphaned(): bool
    {
        return $this->subscription_id === null;
    }

    /**
     * Der Zusatz aus dem vollständigen Namen — für den Fall, dass `label`
     * fehlt.
     *
     * Gebraucht wird das beim Aufräumen: Eine Zeile aus einer alten Fassung
     * oder aus einer Datenmigration hat den Namen und vielleicht keine
     * Abschrift. Der vollständige Name bleibt die Wahrheit.
     */
    public function suffix(): string
    {
        $parts = explode('_', $this->name, 2);

        return $parts[1] ?? $this->name;
    }
}
