<?php

declare(strict_types=1);

use App\Http\Controllers\UebersichtController;
use CloudSrv\Agent\Client;
use CloudSrv\Agent\Version;
use Illuminate\Support\Facades\Route;

Route::get('/', UebersichtController::class)->name('uebersicht');

/*
 * Bereitschaftsprüfung.
 *
 * Sie ist die Bedingung, unter der ein Update übernommen wird: Antwortet sie
 * nach dem Umschalten nicht mit „bereit", geht das Paket auf die vorige
 * Fassung zurück. Deshalb prüft sie den Agenten mit — eine Anwendung, die
 * läuft, aber nicht ins System kommt, ist kein brauchbarer Zustand.
 */
Route::get('/gesundheit', function (Client $agent) {
    $agentLaeuft = $agent->erreichbar();

    return response()->json([
        'bereit' => $agentLaeuft,
        'anwendung' => config('app.version'),
        'protokoll' => Version::PROTOKOLL,
        'agent' => $agentLaeuft ? 'erreichbar' : 'nicht erreichbar',
    ], $agentLaeuft ? 200 : 503);
})->name('gesundheit');
