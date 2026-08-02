<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt die Mandantenklammer für die Dauer einer Anfrage.
 *
 * **Genau eine Stelle.** Kein Controller und kein Dienst setzt den Mandanten
 * selbst; wer wissen will, wie die Klammer entsteht, liest diese Datei. Und
 * wer sie umgehen will, muss {@see Tenancy::withoutRestriction()} aufrufen —
 * ein Name, der beim Lesen auffällt.
 *
 * **Ohne Anmeldung bleibt der Grundzustand.** Die Klammer wird dann nicht
 * gesetzt, und der Grundzustand ist „nichts". Eine unangemeldete Anfrage, die
 * es trotzdem bis an ein mandantengebundenes Modell schafft, sieht damit
 * keine Daten — auch wenn die Autorisierung darüber versagt hat.
 */
final class ApplyTenancy
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if ($account instanceof Account) {
            $this->tenancy->forAccount($account);
        }

        return $next($request);
    }
}
