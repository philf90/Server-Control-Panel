<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diese Anfrage ist keine Seite — sie überschreibt das „Zurück" nicht.
 *
 * ## Der Fehler, den das behebt
 *
 * Laravel merkt sich bei jeder GET-Anfrage, wo der Benutzer gerade war
 * ({@see StartSession::storeCurrentUrl()}), und `ValidationException` leitet
 * genau dorthin zurück. **Der Vorgangskanal `/operations/{id}/stream` ist eine
 * GET-Anfrage**: `EventSource` schickt kein `X-Requested-With`, gilt damit nicht
 * als XHR und wurde als „vorige Seite" gemerkt.
 *
 * Damit landete **jeder Formularfehler dieses Panels** auf einem Ereigniskanal
 * statt auf dem Formular — sobald irgendwo ein Vorgang lief. Der Benutzer sah:
 * nichts. Keine Meldung, keine rote Zeile, kein Hinweis. Gehörte der Vorgang
 * einem anderen Konto, sah er stattdessen eine 403 ohne erkennbaren Auslöser
 * und eine Flut von `stream`-Anfragen, weil `EventSource` unermüdlich neu
 * verbindet.
 *
 * **Gefunden am 10. August 2026**, und zwar erst nach einer Stunde: Die
 * Zwischenabnahme (`docs/39`, Punkt 3) blieb an einer Fehlermeldung hängen, die
 * es gab und die niemand zu sehen bekam. Sichtbar wurde sie erst in einem
 * frischen Reiter, in dem keine Vorgangsseite offen gewesen war.
 *
 * ## Warum es kein Einzelfall war
 *
 * `CLAUDE.md` warnt seit P4: *„`back()` weiss in diesem Panel nicht, wohin
 * zurück ist"* — der Vhost schickt `Referrer-Policy: no-referrer`, und Inertia
 * navigiert über XHR. `RedirectTargetTest` setzt das durch. **Er sieht aber nur
 * `back()`-Aufrufe im eigenen Code**, und die Weiterleitung einer
 * `ValidationException` macht das Framework.
 *
 * > **Eine Regel mit Wächter, und daneben eine Tür, durch die dieselbe Regel
 * > gebrochen wird.**
 *
 * ## Wie
 *
 * `storeCurrentUrl()` überspringt Anfragen, die sich als XHR ausweisen — das ist
 * das Signal, das Laravel selbst dafür liest. Die Kennzeichnung steht hier und
 * nicht im Browser, weil `EventSource` keine Kopfzeilen setzen kann; sie ist
 * auch keine Notlüge, sondern die Wahrheit über diese Anfrage: Ein Textstrom ist
 * keine Seite, zu der jemand zurückkehren könnte.
 *
 * **Und sie steht vor `can:`.** Wird der Zugriff abgewiesen, läuft
 * `storeCurrentUrl()` trotzdem — eine 403 auf dem Kanal würde sonst weiterhin
 * das „Zurück" der nächsten Formularseite kapern.
 */
final class KeepPreviousUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return $next($request);
    }
}
