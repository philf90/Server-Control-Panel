<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStatus;
use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Vorgang: jede Systemänderung, die länger als eine Sekunde dauern kann.
 *
 * Mandantengebunden — und damit der erste echte Anwender der Klammer aus
 * §6.2.1. Vorgänge des Betreibers tragen kein Abonnement und sind für Kunden
 * deshalb unsichtbar, ohne dass irgendwo eine zusätzliche Bedingung stehen
 * müsste.
 */
class Operation extends Model
{
    use BelongsToSubscription, HasFactory;

    protected $fillable = [
        'subscription_id', 'account_id', 'type', 'status', 'progress',
        'message', 'payload', 'result', 'output',
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationStatus::class,
            'progress' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function open(): bool
    {
        return $this->status->open();
    }
}
