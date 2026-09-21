<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

/**
 * Eine Domain — lesend (B7, `docs/131 §4`).
 *
 * **Die Form einer Domainzeile steht hier und nicht zweimal.**
 * {@see SubscriptionsController::domains()} gibt dieselbe Form heraus und ruft
 * dieselbe Stelle; zwei Abschriften liefen beim nächsten Feld auseinander, und
 * die Beschreibung in `docs/openapi-v1.yaml` nennt genau **eine** Form.
 *
 * **Was hier fehlt, fehlt mit Grund.** Kein `nginx_directives`, keine
 * `php_settings`: Das sind Angaben über die Konfiguration des Servers, und
 * eine lesende Schnittstelle, die sie herausgibt, beantwortet einem Angreifer
 * die Frage, womit er es zu tun hat. Wer sie ändern will, tut es auf der
 * Seite — v1 schreibt nicht.
 */
final class DomainsController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        return response()->json(['data' => self::row($domain)]);
    }

    /** @return array<string, mixed> */
    public static function row(Domain $domain): array
    {
        return [
            'id' => $domain->id,
            'subscription_id' => $domain->subscription_id,
            'name' => $domain->name,
            'type' => $domain->type->value,
            'status' => $domain->status->value,
            'php_version' => $domain->php_version,
            'created_at' => $domain->created_at?->toIso8601String(),
        ];
    }
}
