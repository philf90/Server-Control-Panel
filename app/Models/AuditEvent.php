<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditResult;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Protokolleintrag: wer, was, woran, mit welchem Ergebnis.
 *
 * **Kein `updated_at`, und keine Methode zum Ändern.** Ein Protokoll, das sich
 * bearbeiten lässt, ist als Nachweis wertlos. Einträge entstehen und bleiben.
 *
 * **Keine Mandantenklammer.** Das ist Absicht und die Begründung gehört
 * hierher: Ein Protokoll muss auch Ereignisse aufnehmen können, die zu keinem
 * Abonnement gehören — fehlgeschlagene Anmeldungen etwa, bei denen noch gar
 * nicht feststeht, wer da klopft. Die Sichtbarkeit für Kunden stellt die
 * Abfrage in der Oberfläche her, nicht ein globaler Filter; sie ist damit an
 * einer Stelle sichtbar statt unsichtbar überall.
 *
 * @property int $id
 * @property int|null $account_id
 * @property int|null $acting_as_account_id
 * @property int|null $subscription_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property AuditResult $result
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 * @property-read Account|null $account
 * @property-read Account|null $actingAs
 * @property-read Subscription|null $subscription
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'acting_as_account_id', 'subscription_id', 'action',
        'target_type', 'target_id', 'result', 'ip_address', 'user_agent', 'context',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => AuditResult::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Bei „Anmelden als": das Konto, in dessen Kontext gehandelt wurde.
     *
     * @return BelongsTo<Account, $this>
     */
    public function actingAs(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'acting_as_account_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
