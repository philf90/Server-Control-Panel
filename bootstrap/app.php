<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
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
