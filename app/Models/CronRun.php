<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CronRunStatus;
use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein aufgezeichneter Lauf eines Cronjobs.
 *
 * **Diese Zeilen sind die einzige Quelle über den Lauf.** Gemessen
 * (`docs/60 §10`): Ohne MTA geht die Ausgabe eines Cronjobs nirgendwohin — kein
 * Fehler, keine Meldung, keine Datei —, und einen MTA hat ein frisch
 * aufgesetzter Server nicht. Was `cron-run` nicht aufschreibt, ist fort.
 *
 * **`truncated` ist eine Angabe und keine Rechnung.** Wer sie aus der Länge
 * erschlösse, läge bei einer Ausgabe falsch, die zufällig genau 64 KB lang ist:
 *
 * > **Eine Anzeige, die eine abgeschnittene Ausgabe wie eine vollständige
 * > aussehen lässt, behauptet etwas, das sie nicht weiss.**
 *
 * **`subscription_id` steht mit dabei**, obwohl der Job ihn kennt — sonst
 * griffe die Mandantenklammer hier nicht, und die Voreinstellung dieser Tabelle
 * wäre „alles sichtbar" statt „nichts". Siehe die Migration.
 *
 * @property int $id
 * @property int $cron_job_id
 * @property int $subscription_id
 * @property Carbon $started_at
 * @property int $duration_ms
 * @property int|null $exit_code
 * @property CronRunStatus $status
 * @property string|null $output
 * @property bool $truncated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CronJob|null $job
 */
class CronRun extends Model
{
    use BelongsToSubscription;

    /**
     * Wie viele Läufe je Job aufgehoben werden.
     *
     * Entscheidung 4 des Betreibers (`docs/51 §3`). Beschnitten wird **beim
     * Einpflegen** und nicht in einem Tageslauf: Ein Job, der jede Minute läuft,
     * soll die Tabelle nicht bis zum nächsten Aufräumen füllen dürfen.
     */
    public const KEEP_PER_JOB = 50;

    /**
     * Wie viel Ausgabe je Lauf aufgehoben wird.
     *
     * Dieselbe Zahl wie in `cron-run`, und sie steht an beiden Stellen, weil der
     * Wrapper ohne das Panel läuft. Hier ist sie die zweite Wand: Was der
     * Wrapper aus irgendeinem Grund nicht gekappt hat, wird spätestens hier
     * gekappt — und dann auch als gekappt vermerkt.
     */
    public const OUTPUT_MAX = 65536;

    /** @var list<string> */
    protected $fillable = [
        'cron_job_id', 'subscription_id', 'started_at', 'duration_ms',
        'exit_code', 'status', 'output', 'truncated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
            'status' => CronRunStatus::class,
            'truncated' => 'boolean',
        ];
    }

    /** @return BelongsTo<CronJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(CronJob::class, 'cron_job_id');
    }
}
