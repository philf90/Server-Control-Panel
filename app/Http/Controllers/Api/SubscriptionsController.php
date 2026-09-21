<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApplyTenancy;
use App\Models\Concerns\BelongsToSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Metrics\Daily;
use Illuminate\Http\JsonResponse;

/**
 * Die Abonnements eines Tokens — lesend (B7, `docs/131 §4`).
 *
 * ## Was diese Klasse **nicht** tut
 *
 * Sie fragt die Mandantenklammer nicht. `Subscription` klammert über
 * {@see BelongsToSubscription} als globalen Scope, und
 * gesetzt hat ihn {@see ApplyTenancy} — dieselbe Stelle
 * wie für jede Seite des Panels. Ein `where customer_id = …` hier wäre die
 * zweite Fassung derselben Entscheidung.
 *
 * ## Warum die Liste trotzdem einen Wächter braucht
 *
 * Eine Route, die den Mandanten zu setzen vergisst, antwortet **`200 []`** und
 * nicht mit einem Fehler (gemessen, `docs/130` A4). Ein Kunde ohne Abonnements
 * sieht von aussen genauso aus.
 *
 * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
 * > leeren Liste und nicht mit einem Fehler — und `200 []` ist die Antwort,
 * > die niemand meldet.**
 *
 * Deshalb hält `ApiEmptyListTest` die Naht und nicht dieser Kopf.
 */
final class SubscriptionsController extends Controller
{
    public function index(): JsonResponse
    {
        $abos = Subscription::query()
            ->with('plan')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $abos->map(fn (Subscription $abo): array => $this->row($abo))->all(),
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->loadMissing('plan');

        return response()->json(['data' => $this->row($subscription)]);
    }

    public function domains(Subscription $subscription): JsonResponse
    {
        return response()->json([
            'data' => $subscription->domains()
                ->orderBy('name')
                ->get()
                ->map(fn ($domain): array => DomainsController::row($domain))
                ->all(),
        ]);
    }

    /**
     * Die Tageswerte aus B3 — roh und nicht als Kurve.
     *
     * **Die Kachel rechnet, die Schnittstelle nicht.** `Points` baut
     * Stützstellen für ein `viewBox` von 100 × 32; das ist eine Angabe über
     * eine Zeichnung und keine über den Bestand. Ein Klient, der eigene Bilder
     * malt, braucht die Zahlen und nicht unsere Geometrie.
     */
    public function metrics(Subscription $subscription): JsonResponse
    {
        $zeilen = SubscriptionMetric::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('day')
            ->orderBy('metric')
            ->get();

        return response()->json([
            'retention_days' => Daily::RETENTION_DAYS,
            'data' => $zeilen->map(fn (SubscriptionMetric $zeile): array => [
                'day' => $zeile->day->toDateString(),
                'metric' => $zeile->metric->value,
                'value' => $zeile->value,
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Subscription $abo): array
    {
        return [
            'id' => $abo->id,
            'name' => $abo->name,
            'status' => $abo->status->value,
            'plan' => $abo->plan?->name,
            'main_domain' => $abo->main_domain,
            'disk_used_mb' => $abo->disk_used_mb,
            'created_at' => $abo->created_at?->toIso8601String(),
        ];
    }
}
