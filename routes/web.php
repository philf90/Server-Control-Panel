<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\OperationStreamController;
use App\Http\Controllers\OverviewController;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Operation;
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

/*
 * Der zweite Schritt der Anmeldung.
 *
 * Ohne `auth`: Zwischen Passwort und zweitem Faktor ist niemand angemeldet —
 * das wartende Konto steht in der Sitzung. Wäre hier `auth` verlangt, käme
 * niemand je an diese Seite.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/two-factor', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorChallengeController::class, 'store']);
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
     * Vorgänge.
     *
     * `viewAny` lässt jedes angemeldete Konto auf die Liste; was darauf steht,
     * entscheidet die Mandantenklammer. `create` ist enger — und die Prüfung,
     * welche Aufgabe jemand auslösen darf, sitzt noch einmal dahinter im
     * Katalog (App\Support\Operations\Task).
     */
    Route::get('/operations', [OperationController::class, 'index'])
        ->middleware('can:viewAny,'.Operation::class)
        ->name('operations.index');

    Route::post('/operations', [OperationController::class, 'store'])
        ->middleware('can:create,'.Operation::class)
        ->name('operations.store');

    Route::get('/operations/{operation}', [OperationController::class, 'show'])
        ->middleware('can:view,operation')
        ->name('operations.show');

    /*
     * Abbrechen darf, wer den Vorgang sieht — er hat ihn in aller Regel selbst
     * ausgelöst. Die Policy prüft zusätzlich, dass er überhaupt noch offen ist.
     */
    Route::post('/operations/{operation}/cancel', [OperationController::class, 'cancel'])
        ->middleware('can:cancel,operation')
        ->name('operations.cancel');

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

    /*
     * Kunden — die Betreiberseite.
     */
    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('can:viewAny,'.Customer::class)
        ->name('customers.index');

    Route::get('/customers/create', [CustomerController::class, 'create'])
        ->middleware('can:create,'.Customer::class)
        ->name('customers.create');

    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('can:create,'.Customer::class)
        ->name('customers.store');

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('can:view,customer')
        ->name('customers.show');

    /*
     * „Anmelden als" (§6.3).
     *
     * Der Beginn braucht die Fähigkeit `impersonate` am Kunden. Der Rückweg
     * nicht: Wer schon in fremder Sicht ist, ist in diesem Moment ein
     * Kundenkonto und hätte sie nicht mehr — die Prüfung stünde ihm dann
     * ausgerechnet beim Zurückkommen im Weg.
     */
    Route::post('/customers/{customer}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('can:impersonate,customer')
        ->name('impersonation.start');

    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');

    /*
     * Den zweiten Faktor einrichten oder abschalten.
     */
    Route::get('/settings/two-factor', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor.setup');
    Route::post('/settings/two-factor', [TwoFactorSetupController::class, 'store'])
        ->name('two-factor.setup.store');
    Route::delete('/settings/two-factor', [TwoFactorSetupController::class, 'destroy'])
        ->name('two-factor.setup.destroy');
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
