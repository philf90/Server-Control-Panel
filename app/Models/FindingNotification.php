<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Notify\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dass dieser Befund über diesen Kanal zugestellt wurde (B1, `docs/129 §4`).
 *
 * **Eine Zeile entsteht nur bei einer gelungenen Zustellung.** Ein Fehlschlag
 * schreibt hier nichts — sonst stünde eine Zustellung da, die es nicht gab, und
 * die Zeile wäre für immer nicht mehr fällig. Derselbe Grund, aus dem
 * `Settings::saveNoticeSent()` nur die gelungene festhält.
 *
 * > **Ein Vermerk über eine Zustellung, die nicht stattfand, ist teurer als
 * > keiner: Er nimmt der Meldung ihre Fälligkeit und nicht bloss ihre
 * > Spur.**
 *
 * **Was hier nicht steht: warum etwas *nicht* zugestellt wurde.** Ob ein Kanal
 * überhaupt durchkommt, ist eine Frage je Kanal und keine je Befund — sie wird
 * als „zuletzt erfolgreich zugestellt" in den Einstellungen beantwortet
 * (`docs/80`). Eine Fehlerspalte hier ergäbe je Nacht eine Zeile je Befund und
 * Kanal, also ein Protokoll, das niemand bestellt hat.
 *
 * @property int $id
 * @property int $finding_id
 * @property string $channel
 * @property Carbon $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FindingNotification extends Model
{
    /** @var list<string> */
    protected $fillable = ['finding_id', 'channel', 'notified_at'];

    /**
     * Der Befund, der gemeldet wurde.
     *
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * Festhalten, dass ein Kanal diesen Befund zugestellt hat.
     *
     * **`firstOrCreate` und nicht `create`**, obwohl der `unique`-Index das
     * Doppel ohnehin abwiese: Ein zweiter Lauf in derselben Nacht wäre sonst
     * ein Ausfall des ganzen Kommandos statt einer Zeile, die schon dasteht.
     * Der Index bleibt trotzdem — er hält die Zusage, dieser Aufruf ist bloss
     * höflich.
     */
    public static function record(Finding $finding, Channel $channel, Carbon $at): void
    {
        self::query()->firstOrCreate(
            ['finding_id' => $finding->id, 'channel' => $channel->key()],
            ['notified_at' => $at],
        );
    }
}
