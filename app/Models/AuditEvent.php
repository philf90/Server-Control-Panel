<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
class AuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'acting_as_account_id', 'subscription_id', 'action',
        'target_type', 'target_id', 'result', 'ip_address', 'user_agent', 'context',
    ];

    protected function casts(): array
    {
        return [
            'result' => AuditResult::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Bei „Anmelden als": das Konto, in dessen Kontext gehandelt wurde. */
    public function actingAs(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'acting_as_account_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
