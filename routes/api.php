<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DomainsController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\SpecController;
use App\Http\Controllers\Api\SubscriptionsController;
use App\Http\Middleware\AuthenticateToken;
use App\Models\Subscription;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — lesend (B7, `docs/131`)
|--------------------------------------------------------------------------
|
| **Die Reihenfolge der Mittelschichten steht in `bootstrap/app.php`**, in
| derselben Form und mit derselben Begründung wie beim `web`-Block: Die
| Mandantenklammer vor der Modellbindung. Die Vorgabegruppe `api` trägt genau
| einen Eintrag — `SubstituteBindings` und sonst nichts (`docs/130` A1) —,
| und eine Route, die dort landet, bindet, bevor irgendetwas klammert.
|
| **Jede Route trägt `can:`**, wie die 143 Routen des Panels. Eine API bekommt
| keinen eigenen Rechteweg; was ein Token darf, darf sein Konto.
|
| **Und v1 liest nur.** Was hier nicht steht, steht in `docs/131 §9` als
| Entscheidung und nicht als Lücke.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    /*
     * **Die Beschreibung ist offen**, und das ist keine Auskunft über den
     * Bestand: Sie sagt, welche Felder es gibt, und nicht, welche Abonnements.
     * Ein Klient, der sie erst nach einer Anmeldung bekäme, könnte seinen
     * Zugang nicht einrichten, bevor er ihn hat.
     *
     * `withoutMiddleware` und kein zweiter Zweig in der Wache: Eine Wache, die
     * selbst entscheidet, wann sie nicht gilt, ist die Stelle, an der später
     * eine zweite Ausnahme dazukommt, die niemand sieht.
     */
    Route::get('/openapi.yaml', [SpecController::class, 'show'])
        ->withoutMiddleware(AuthenticateToken::class)
        ->name('spec');

    Route::get('/me', [MeController::class, 'show'])->name('me');

    Route::get('/subscriptions', [SubscriptionsController::class, 'index'])
        ->middleware('can:viewAny,'.Subscription::class)
        ->name('subscriptions.index');

    Route::get('/subscriptions/{subscription}', [SubscriptionsController::class, 'show'])
        ->middleware('can:view,subscription')
        ->name('subscriptions.show');

    Route::get('/subscriptions/{subscription}/domains', [SubscriptionsController::class, 'domains'])
        ->middleware('can:view,subscription')
        ->name('subscriptions.domains');

    Route::get('/subscriptions/{subscription}/metrics', [SubscriptionsController::class, 'metrics'])
        ->middleware('can:view,subscription')
        ->name('subscriptions.metrics');

    Route::get('/domains/{domain}', [DomainsController::class, 'show'])
        ->middleware('can:view,domain')
        ->name('domains.show');
});
