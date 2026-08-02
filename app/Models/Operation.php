<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStatus;
use App\Models\Concerns\BelongsToSubscription;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Vorgang: jede Systemänderung, die länger als eine Sekunde dauern kann.
 *
 * Mandantengebunden — und damit der erste echte Anwender der Klammer aus
 * §6.2.1. Vorgänge des Betreibers tragen kein Abonnement und sind für Kunden
 * deshalb unsichtbar, ohne dass irgendwo eine zusätzliche Bedingung stehen
 * müsste.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property int|null $account_id
 * @property string $type
 * @property OperationStatus $status
 * @property int $progress
 * @property string|null $message
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read Account|null $account
 * @property-read Subscription|null $subscription
 */
class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use BelongsToSubscription, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'account_id', 'type', 'status', 'progress',
        'message', 'payload', 'result', 'output',
    ];

    /** @return array<string, string> */
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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function open(): bool
    {
        return $this->status->open();
    }
}
