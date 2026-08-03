<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Subscription;

/**
 * Die eine Stelle, an der Vorgänge entstehen.
 *
 * Sie ist schmal, damit niemand versehentlich einen Vorgang anlegt, ohne ihn
 * einzureihen — oder umgekehrt einen Auftrag einreiht, zu dem es keinen
 * sichtbaren Vorgang gibt. Beides führt zu derselben unangenehmen Lage: Der
 * Betreiber sieht etwas anderes, als das System tut.
 */
final class Operations
{
    /**
     * Einen Vorgang anlegen und einreihen.
     *
     * @param  array<string,mixed>  $payload
     */
    public function dispatch(
        string $type,
        array $payload = [],
        ?Subscription $subscription = null,
        ?Account $account = null,
        ?string $message = null,
    ): Operation {
        $operation = new Operation([
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'subscription_id' => $subscription?->id,
            'account_id' => $account?->id,
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        // Ausdrücklich gesetzt statt der Klammer überlassen: Ein Vorgang des
        // Betreibers trägt kein Abonnement, und das darf nicht davon abhängen,
        // wie viele Mandanten gerade aktiv sind.
        $operation->subscription_id = $subscription?->id;
        $operation->save();

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }
}
