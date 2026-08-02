<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\OperationStreamController;
use App\Http\Controllers\OverviewController;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Route;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Version;

/*
 * Anmeldung.
 *
 * Die einzigen Routen, die ohne Konto erreichbar sind — neben der
 * Bereitschaftsprüfung, die das Paket beim Update braucht.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Alles Weitere setzt ein Konto voraus.
 *
 * Die Gruppe ist kein Komfort, sondern die Voreinstellung: Eine neue Route
 * entsteht innerhalb dieser Klammer und ist damit von sich aus geschützt. Wer
 * eine öffentliche Route braucht, muss sie ausdrücklich außerhalb anlegen —
 * und das fällt beim Lesen auf.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');

    /*
     * Die Live-Ausgabe eines Vorgangs.
     *
     * Die erste Route mit einer Policy an der Aktion statt einer Eintragung
     * in der Registratur — `can:view,operation`. Die Modellbindung läuft
     * bereits unter der Mandantenklammer (siehe bootstrap/app.php), ein
     * fremder Vorgang ist deshalb schon „nicht gefunden" und erreicht die
     * Policy gar nicht erst. Sie steht trotzdem da: zwei Schichten, und
     * wenn eine ausfällt, hält die andere.
     */
    Route::get('/operations/{operation}/stream', OperationStreamController::class)
        ->middleware('can:view,operation')
        ->name('operations.stream');

    /*
     * Das Protokoll.
     *
     * `viewAny` gibt jedem angemeldeten Konto Zugang — was es dann sieht,
     * entscheidet AuditQuery::visibleTo. Die Policy an der Route ersetzt
     * diese Prüfung nicht, sie steht davor: Ohne Konto gar nichts, mit Konto
     * genau das Eigene.
     */
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('can:viewAny,'.AuditEvent::class)
        ->name('audit');

    Route::get('/audit/export', [AuditController::class, 'export'])
        ->middleware('can:viewAny,'.AuditEvent::class)
        ->name('audit.export');
});

/*
 * Bereitschaftsprüfung.
 *
 * Sie ist die Bedingung, unter der ein Update übernommen wird: Antwortet sie
 * nach dem Umschalten nicht mit „bereit", geht das Paket auf die vorige
 * Fassung zurück. Deshalb prüft sie den Agenten mit — eine Anwendung, die
 * läuft, aber nicht ins System kommt, ist kein brauchbarer Zustand.
 *
 * Ohne Anmeldung erreichbar, und das mit Absicht: Sie läuft, während das
 * Paket umschaltet, und es gibt in diesem Moment niemanden, der angemeldet
 * wäre. Sie gibt nur Fassungsnummern und einen Bereitschaftszustand heraus.
 */
Route::get('/health', function (Client $agent) {
    $agentUp = $agent->reachable();

    return response()->json([
        'ready' => $agentUp,
        'app' => config('app.version'),
        'protocol' => Version::PROTOCOL,
        'agent' => $agentUp ? 'reachable' : 'nicht erreichbar',
    ], $agentUp ? 200 : 503);
})->name('health');
