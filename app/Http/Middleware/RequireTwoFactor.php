<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Für Administratoren ist der zweite Faktor verpflichtend (§6.4).
 *
 * Ein Adminkonto ohne ihn kommt nicht weiter als bis zur Einrichtungsseite.
 * Das ist unbequem und der Punkt: Ein Konto, das als root auf einem Server
 * arbeitet, hinter einem einzelnen Passwort — das ist genau die Lage, die ein
 * Hosting-Panel für andere nicht schaffen sollte.
 *
 * Die Einrichtungsseite selbst und das Abmelden bleiben erreichbar. Ohne diese
 * Ausnahmen wäre die Umleitung eine Schleife.
 */
final class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Account || ! $account->isAdmin() || $account->hasTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.setup', 'two-factor.setup.store', 'logout')) {
            return $next($request);
        }

        return redirect()->route('two-factor.setup');
    }
}
