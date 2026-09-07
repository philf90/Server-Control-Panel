<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSubscription;
use Database\Factories\CronJobFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Ein Cronjob eines Abonnements.
 *
 * **Was hier steht, ist der Sollzustand — was läuft, steht in der Datei.**
 * `/etc/cron.d/srvpanel-<benutzer>` schreibt der Agent bei jeder Änderung neu,
 * aus dem vollständigen Bestand dieser Tabelle. Dieselbe Aufteilung wie bei
 * {@see SshKey}, und mit einem Zusatz, den `docs/60 §4` gemessen hat: Zwischen
 * dem Speichern und dem ersten möglichen Lauf liegen **bis zu 60 Sekunden**.
 *
 * > **„Gespeichert" ist nicht „gilt".**
 *
 * ## Der Zeitplan ist Serverzeit
 *
 * Die fünf Felder werden von cron in der Zeit der **Maschine** gedeutet, nicht
 * in der Anzeigezeitzone des Panels (`docs/60 §11`, gemessen — und `CRON_TZ`
 * gibt es in diesem cron nicht). Die **nächste Fälligkeit** wird deshalb aus der
 * Serverzeit gerechnet und über `Clock` angezeigt wie jeder andere Zeitstempel.
 *
 * **Sie stand bis zum 7. September 2026 als Spalte hier** und wurde nur beim
 * Anlegen und Ändern geschrieben. Als die Rechnung berichtigt wurde, blieben
 * die alten Werte stehen (`docs/107 §0d`).
 *
 * > **Ein Wert, der aus „jetzt" folgt und abgelegt wird, ist ab dem nächsten
 * > Augenblick falsch — die Frage ist nur, wie schnell es auffällt.**
 *
 * Gerechnet wird sie jetzt beim Lesen, in `CronController::job()`.
 *
 * **Zeitplan und Zeitstempel sind zwei verschiedene Dinge**, und sie dürfen
 * nicht durch dieselbe Umrechnung: Wer die fünf Felder in die Anzeigezone
 * umrechnete, zeigte eine Zeile und fände sie nicht.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $label
 * @property string $command
 * @property string $minute
 * @property string $hour
 * @property string $day_of_month
 * @property string $month
 * @property string $day_of_week
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, CronRun> $runs
 */
class CronJob extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<CronJobFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'label', 'command',
        'minute', 'hour', 'day_of_month', 'month', 'day_of_week',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<CronRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CronRun::class);
    }

    /**
     * Die fünf Felder in der Form, in der der Agent sie erwartet.
     *
     * **Die Reihenfolge und die Namen kommen aus {@see Schedule::FIELDS}** und
     * nicht aus einer Liste hier. Zwei Listen, die dasselbe meinen, laufen
     * auseinander — und keine von beiden ist der Ort, an dem man nachsieht
     * (`docs/47`, derselbe Satz über zwei Argumentlisten für `mysql`).
     *
     * @return array<string,string>
     */
    public function schedule(): array
    {
        $schedule = [];

        foreach (Schedule::FIELDS as $field) {
            $schedule[$field] = (string) $this->getAttribute($field);
        }

        return $schedule;
    }
}
