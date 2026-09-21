<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wer die Marke trägt — und was sie erreicht (B7, `docs/131 §4`).
 *
 * ## Warum es diese Route gibt
 *
 * Ein Klient, der eine Marke einrichtet, will als Erstes wissen, ob sie
 * überhaupt trägt — und zwar ohne dabei eine Liste abzufragen, die leer sein
 * darf. Genau daran hängt der Fall aus `docs/130` A4: **`200 []` ist von
 * „nichts erreicht" nicht zu unterscheiden.** Diese Route antwortet stattdessen
 * mit der **Zahl** der erreichbaren Abonnements, und die ist auch dann eine
 * Auskunft, wenn sie null ist.
 *
 * > **Eine leere Liste sagt nicht, ob gefragt wurde. Eine Null neben einem
 * > Namen schon.**
 *
 * ## Ohne `can:`, und das steht in `RouteGuard`
 *
 * Es gibt kein Objekt, über das eine Policy urteilen könnte: Der Gegenstand
 * **ist** das anfragende Konto. Wer hierherkommt, hat die Wache passiert, und
 * die hat den Kontozustand schon gefragt.
 */
final class MeController extends Controller
{
    public function show(Request $request, Tenancy $tenancy): JsonResponse
    {
        $account = $request->user();

        if (! $account instanceof Account) {
            abort(401);
        }

        return response()->json([
            'data' => [
                'account_id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'subscriptions' => count($tenancy->subscriptionIds()),
            ],
        ]);
    }
}
