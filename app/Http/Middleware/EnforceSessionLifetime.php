<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditResult;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die absolute Sitzungsdauer (§6.4).
 *
 * Laravel bringt die gleitende Dauer mit: Wer zwei Stunden nichts tut, fliegt
 * raus. Was fehlt, ist die absolute — eine Sitzung, in der jemand alle zehn
 * Minuten etwas anklickt, läuft sonst wochenlang weiter.
 *
 * Für ein Panel, das als root auf einem Server arbeitet, ist das der
 * Unterschied zwischen „ein vergessener Browser im Büro ist bis Feierabend
 * offen" und „bis jemand ihn schließt". Nach Ablauf wird abgemeldet und im
 * Protokoll vermerkt, damit der Betroffene später nachvollziehen kann, warum
 * er plötzlich vor dem Anmeldeformular stand.
 */
final class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $maximum = (int) config('srvpanel.session.absolute_lifetime', 43200);

        if ($maximum <= 0) {
            return $next($request);
        }

        $startedAt = $request->session()->get('authenticated_at');

        // Fehlt der Zeitstempel, ist die Sitzung älter als diese Prüfung oder
        // von Hand entstanden. Nachtragen statt abmelden: Ein fehlender Wert
        // ist kein Grund, jemanden hinauszuwerfen — beim nächsten Mal greift
        // die Grenze dann regulär.
        if (! is_numeric($startedAt)) {
            $request->session()->put('authenticated_at', time());

            return $next($request);
        }

        if (time() - (int) $startedAt < $maximum) {
            return $next($request);
        }

        app(Audit::class)->record(
            'auth.session.expired',
            AuditResult::Success,
            context: ['after_seconds' => time() - (int) $startedAt],
        );

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('anmeldung')
            ->with('hinweis', 'Die Sitzung hat ihre Höchstdauer erreicht. Bitte erneut anmelden.');
    }
}
