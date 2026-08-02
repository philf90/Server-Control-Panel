<?php

use App\Http\Middleware\ApplyTenancy;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Die Reihenfolge trägt Bedeutung, und eine davon ist nicht
        // offensichtlich.
        //
        // EnforceSessionLifetime steht vorn: Eine abgelaufene Sitzung soll
        // beendet sein, bevor irgendetwas anderes sie als angemeldet ansieht.
        //
        // **ApplyTenancy muss vor SubstituteBindings stehen.** In der
        // Standardgruppe steht SubstituteBindings weiter vorn, und angehängte
        // Middleware liefe danach — die Modellbindung suchte dann ohne
        // Mandantenklammer. Ein Kunde, der eine fremde ID in die Adresse
        // schreibt, bekäme das Objekt gebunden; erst die Policy wiese ihn ab.
        // Das ist eine Schicht zu spät: Aus „nicht gefunden" würde
        // „verboten", und damit ließe sich abzählen, welche IDs es gibt.
        //
        // Deshalb wird SubstituteBindings aus seiner Position genommen und
        // hinter ApplyTenancy wieder eingesetzt. Ein Test hält die Reihenfolge
        // fest, damit sie nicht beim nächsten Umbau still zurückfällt.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                EnforceSessionLifetime::class,
                ApplyTenancy::class,
                SubstituteBindings::class,
                RequireTwoFactor::class,
                HandleInertiaRequests::class,
            ],
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

/*
 * Schlüssel und Zugangsdaten liegen unter /etc/srvpanel/panel.env und nicht
 * im Auslieferungsverzeichnis.
 *
 * Der Grund ist das Update: /opt/srvpanel/releases/<version>/ wird dabei
 * ersetzt. Eine .env darin wäre nach dem ersten Update weg — samt APP_KEY,
 * mit dem alle verschlüsselten Werte in der Datenbank lesbar sind. Für die
 * Entwicklung bleibt die .env im Projektverzeichnis; die Datei unter /etc
 * gibt es dort nicht.
 */
if (is_readable('/etc/srvpanel/panel.env')) {
    $app->useEnvironmentPath('/etc/srvpanel')->loadEnvironmentFrom('panel.env');
}

return $app;
