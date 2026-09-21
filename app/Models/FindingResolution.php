<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Entwarnung, die noch hinausgehen muss (B1).
 *
 * **Eine Warteschlange und kein Protokoll.** Sie entsteht, wenn ein
 * **gemeldeter** Befund verschwindet, und sie verschwindet, sobald die
 * Entwarnung angekommen ist. Wer wissen will, was war, liest das Protokoll der
 * Vorgänge; hier steht nur, was noch zu sagen ist.
 *
 * **Sie schreibt den Befund ab, statt auf ihn zu zeigen** — es gibt ihn nicht
 * mehr. Die Begründung steht in der Migration.
 *
 * @property int $id
 * @property FindingCheck $check
 * @property string $subject
 * @property string $reason
 * @property string $channel
 * @property Carbon $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FindingResolution extends Model
{
    /** @var list<string> */
    protected $fillable = ['check', 'subject', 'reason', 'channel', 'resolved_at'];

    /**
     * Eine Entwarnung vormerken.
     *
     * **`firstOrCreate` und nicht `create`**, aus demselben Grund wie bei
     * {@see FindingNotification::record()}: Der `unique`-Index hält die Zusage,
     * dieser Aufruf ist bloss höflich — und ein Schaden, der zweimal
     * verschwindet, bevor die erste Entwarnung hinausging, soll den Nachtlauf
     * nicht abbrechen.
     */
    public static function record(Finding $finding, string $channel, Carbon $at): void
    {
        self::query()->firstOrCreate(
            [
                'check' => $finding->check->value,
                'subject' => $finding->subject,
                'reason' => $finding->reason,
                'channel' => $channel,
            ],
            ['resolved_at' => $at],
        );
    }

    /**
     * Die Zeile, wie sie beim Empfänger ankommt.
     *
     * @return array{check: string, reason: string, label: string}
     */
    public function line(): array
    {
        return [
            'check' => $this->check->value,
            'reason' => $this->reason,

            /*
             * Der Satz kommt aus {@see FindingCheck} und nicht aus einer
             * zweiten Liste: Was beim Melden dastand, steht beim Entwarnen
             * wieder da — sonst muss der Leser zwei Formulierungen auf
             * dieselbe Sache beziehen.
             */
            'label' => $this->check->sentence($this->reason),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'check' => FindingCheck::class,
            'resolved_at' => 'datetime',
        ];
    }
}
