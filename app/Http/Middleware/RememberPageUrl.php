<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diese Anfrage **ist** eine Seite — sie setzt das „Zurück".
 *
 * ## Der Fehler, den das behebt
 *
 * Laravel merkt sich die vorige Seite in {@see StartSession::storeCurrentUrl()},
 * und `ValidationException` leitet genau dorthin zurück. Gespeichert wird aber
 * nur, was **nicht** als XHR gilt — und jede Inertia-Navigation ist XHR. In
 * diesem Panel wird `_previous.url` damit nach dem Anmelden **nie wieder
 * gesetzt**: Es steht auf der letzten vollständigen Seitenladung, und das ist
 * `/login`.
 *
 * Die Folge trifft **jedes Formular des Panels**: Ein Eingabefehler leitet auf
 * `/login`, dort schickt die `guest`-Middleware den angemeldeten Benutzer
 * weiter auf die Übersicht, und die Fehlermeldung — die es gibt, die in der
 * Sitzung liegt und korrekt formuliert ist — zeigt niemand mehr an. Was ein
 * Kunde sieht, ist ein Formular, das ihn wortlos wegschickt.
 *
 * **Gefunden am 11. August 2026** im Abnahmelauf des Fernzugriffs (`docs/43`
 * Punkt 6), und zwar erst nach drei falschen Spuren: abgelaufene Sitzung,
 * absolute Sitzungsdauer, fehlende Anzeige im Formular. Entschieden hat es
 * nicht das Lesen, sondern ein Vergleich zweier Antworten — ein gültiges Netz
 * ging auf `302 → /databases/22`, ein abgewiesenes auf `302 → /login`. Der
 * Unterschied ist, dass der Erfolgsweg sein Ziel nennt und der Fehlerweg nicht.
 *
 * ## Warum hier und nicht am Referer
 *
 * `back()` liest zuerst den `Referer`, und der Vhost des Panels schickt
 * `Referrer-Policy: no-referrer` — bewusst. Ihn aufzuweichen, damit eine
 * Weiterleitung funktioniert, hiesse eine Sicherheitsentscheidung gegen eine
 * Bequemlichkeit zu tauschen. Die Antwort ist stattdessen, den Zustand zu
 * führen, statt ihn aus einer Kopfzeile zu erraten.
 *
 * ## Das Gegenstück
 *
 * {@see KeepPreviousUrl} macht das Umgekehrte: Der Vorgangskanal ist eine
 * GET-Anfrage, die *keine* Seite ist, und wird darum ausgenommen. Beide
 * zusammen sagen dasselbe — **was das „Zurück" setzt, ist eine Entscheidung und
 * kein Nebeneffekt der Übertragungsart.**
 *
 * > **Ein Wächter über `back()` im eigenen Code sagt nichts über das `back()`,
 * > das das Framework macht.**
 *
 * `RedirectTargetTest` besteht seit P4 darauf, dass jede Weiterleitung ihr Ziel
 * nennt. Die Weiterleitung einer `ValidationException` nennt es nicht — sie
 * fragt die Sitzung. Ab hier steht dort etwas Richtiges.
 */
final class RememberPageUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * **Nur echte Seitenaufrufe.** `X-Inertia` unterscheidet die Navigation
         * des Panels von allem anderen, was per XHR hereinkommt — der
         * Vorgangskanal, ein Abruf im Hintergrund, ein Werkzeug. Eine
         * Weiterleitung merkt sich niemand: Sie ist kein Ort, an dem jemand
         * war.
         */
        if ($request->isMethod('GET')
            && $request->route() !== null
            && $request->hasSession()
            && $request->header('X-Inertia') === 'true'
            && $response->getStatusCode() < 300
        ) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }
}
