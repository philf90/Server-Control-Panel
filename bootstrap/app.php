<?php

use App\Http\Middleware\ApplyTenancy;
use App\Http\Middleware\EnforceAccountAccess;
use App\Http\Middleware\EnforceAdminNetwork;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RememberPageUrl;
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
        // Mandantenklammer.
        //
        // **Was das anrichtet, ist am 21. September 2026 gemessen worden**
        // (`docs/130` A3), und es ist nicht das, was hier stand. Der Satz
        // lautete, aus „nicht gefunden" würde „verboten", und damit liesse
        // sich abzählen, welche IDs es gibt. Gemessen gibt die umgedrehte
        // Reihenfolge **404 für das fremde und 404 für das eigene**
        // Abonnement: Die Klammer steht beim Binden im Grundzustand, und der
        // verweigert alles. Der Schaden ist kein Leck, sondern ein Panel, in
        // dem kein Kunde mehr seine eigene Seite sieht.
        //
        //   Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist
        //   auch dann falsch, wenn der Handgriff daneben richtig ist — und er
        //   hält länger als der Handgriff, weil ihn der Nächste liest und
        //   glaubt.
        //
        // Deshalb wird SubstituteBindings aus seiner Position genommen und
        // hinter ApplyTenancy wieder eingesetzt.
        //
        // **Und der Test, den diese Zeile bis zum 21. September behauptet hat,
        // gab es nicht.** Ausgezählt nannte keine Datei unter `tests/` diese
        // beiden Mittelschichten zusammen; gehalten war nur das Paar
        // `EnforceAccountAccess` vor `EnforceAdminNetwork`
        // (`AccountAccessReachTest`).
        //
        //   Eine Zeile, die einen Wächter behauptet, ist teurer als keine —
        //   der Nächste baut ihn nicht, weil er ihn für gebaut hält.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                EnforceSessionLifetime::class,
                ApplyTenancy::class,
                SubstituteBindings::class,

                // Der Kontozustand vor allem anderen: Ein Konto, das gar
                // nicht mehr da sein darf, wird nicht zuerst nach seiner
                // Adresse gefragt. Bis zum 25. August 2026 stand hier gar
                // nichts — `status` wurde ausschliesslich beim Anmelden
                // gefragt, und ein gesperrtes Konto behielt seine offene
                // Sitzung bis zu 30 Tage (Befund 6 aus `docs/84`).
                EnforceAccountAccess::class,

                // Die Netzbeschränkung vor dem zweiten Faktor: Wer von hier
                // nicht herein darf, soll dessen Einrichtungsseite nicht sehen.
                // Und nach ApplyTenancy, weil sie über Settings an die
                // Datenbank geht.
                EnforceAdminNetwork::class,

                RequireTwoFactor::class,

                // RememberPageUrl setzt das „Zurück" für Inertia-Navigationen.
                // Ohne sie steht es nach dem Anmelden dauerhaft auf /login, und
                // jeder Formularfehler dieses Panels landet dort statt am
                // Formular — siehe den Klassenkopf.
                RememberPageUrl::class,
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
