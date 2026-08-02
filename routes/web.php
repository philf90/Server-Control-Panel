<?php

declare(strict_types=1);

use App\Http\Controllers\OverviewController;
use CloudSrv\Agent\Client;
use CloudSrv\Agent\Version;
use Illuminate\Support\Facades\Route;

Route::get('/', OverviewController::class)->name('uebersicht');

/*
 * Bereitschaftsprüfung.
 *
 * Sie ist die Bedingung, unter der ein Update übernommen wird: Antwortet sie
 * nach dem Umschalten nicht mit „bereit", geht das Paket auf die vorige
 * Fassung zurück. Deshalb prüft sie den Agenten mit — eine Anwendung, die
 * läuft, aber nicht ins System kommt, ist kein brauchbarer Zustand.
 */
Route::get('/gesundheit', function (Client $agent) {
    $agentUp = $agent->reachable();

    return response()->json([
        'ready' => $agentUp,
        'app' => config('app.version'),
        'protocol' => Version::PROTOCOL,
        'agent' => $agentUp ? 'reachable' : 'nicht erreichbar',
    ], $agentUp ? 200 : 503);
})->name('gesundheit');
