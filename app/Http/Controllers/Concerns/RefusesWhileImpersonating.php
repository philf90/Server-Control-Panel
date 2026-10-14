<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\Audit\Audit;
use App\Support\Audit\Impersonation;
use Illuminate\Http\Request;

/**
 * Änderungen am eigenen Konto während eines „Anmelden als" abweisen — und den
 * Versuch protokollieren.
 *
 * In fremder Sicht ist das angemeldete Konto das des Kunden. Was hier
 * abgewiesen wird, änderte also sein Konto: Name, Adresse, Passwort, die
 * Darstellung, seine API-Tokens und seinen zweiten Faktor. Der Wechsel ist
 * zum Ansehen da, nicht zum Übernehmen.
 *
 * Protokolliert, weil ein Versuch an dieser Stelle keine Ungeschicklichkeit
 * ist: Die Oberfläche bietet in diesem Zustand keine dieser Handlungen an.
 * Wer hier ankommt, hat die Anfrage selbst gestellt.
 *
 * **Bis zum 11. Oktober 2026 stimmte der Satz für die API-Tokens nicht**
 * (`docs/144 §0` Befund 2). Formular und „Entfernen" standen in fremder
 * Sicht da, und ein Klick darauf wurde hier als selbst gestellter Versuch
 * gebucht. Geschrieben war der Satz für die Formulare, die es gab, als er
 * entstand; die API-Tokens kamen mit B7 danach. `ImpersonationControlTest`
 * hält ihn seitdem für jede Handlung, die hier abgewiesen wird.
 *
 * > **Eine Begründung, die ein Formular für abwesend erklärt, gilt für die
 * > Formulare, die es gab, als sie geschrieben wurde.**
 *
 * **Und bis zum 14. Oktober 2026 stand hier ein Controller nicht**, der das
 * Konto ebenfalls ändert: der des zweiten Faktors (`docs/144 §8`). In fremder
 * Sicht liess er sich am Konto des Kunden einschalten, mit einem Geheimnis,
 * das danach beim Betreiber lag. Die Abweisung war eine private Methode von
 * `ProfileController`, und der zweite Controller hätte sie abschreiben
 * müssen. Seitdem steht sie hier, und `ImpersonationDoorTest` fragt von der
 * Seite der Tür, ob jede Route, die ein Konto ohne Fähigkeit erreicht und die
 * schreibt, sie ruft.
 *
 * > **Ein Wächter, der die Abweisungen aus ihren Aufrufen liest, sieht keine
 * > Tür, die gar nicht abweist.**
 */
trait RefusesWhileImpersonating
{
    private function impersonating(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->has(Impersonation::SESSION_KEY);
    }

    private function refuseWhileImpersonating(Request $request, Audit $audit, string $action): void
    {
        if (! $this->impersonating($request)) {
            return;
        }

        $audit->denied($action, null, ['reason' => 'während „Anmelden als"']);

        abort(403, 'In fremder Sicht lässt sich kein Konto ändern.');
    }
}
