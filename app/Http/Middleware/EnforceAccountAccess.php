<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditResult;
use App\Http\Controllers\ImpersonationController;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Audit\Impersonation;
use App\Support\Authorization\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein gesperrtes Konto verliert seine offene Sitzung — sofort und nicht in
 * dreissig Tagen.
 *
 * ## Der Befund
 *
 * Befund 6 aus `docs/84`. Keine der sieben Mittelschichten fragte den
 * Kontozustand: `status` wurde beim **Anmelden** gefragt, beim zweiten Faktor
 * und bei der Rückkehr aus einer fremden Sicht — bei einer laufenden Anfrage
 * nie. Der Leerlauf einer Sitzung setzt sich bei jedem Klick zurück, die
 * absolute Obergrenze liegt bei 30 Tagen. **So lange behielt ein gesperrtes
 * Adminkonto seine Rechte, solange jemand die Sitzung benutzte.**
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * Gemeldet hat es der Betreiber während des Abnahmelaufs, weil er hingesehen
 * hat: Punkt 7 der Vorschrift liess zwei Ausgänge zu und wäre grün geblieben.
 *
 * ## Warum zwei Konten gefragt werden
 *
 * In einer fremden Sicht ist `Auth::user()` der **Kunde**, und der Handelnde
 * steht im Sitzungsschlüssel {@see Impersonation::SESSION_KEY}. Fragte diese
 * Klasse nur den ersten, bliebe ein gesperrter Administrator so lange
 * unbehelligt, wie er in fremder Sicht weiterarbeitet — also genau so lange,
 * wie es am meisten wehtut.
 *
 * Der {@see ImpersonationController} kennt den Fall
 * längst („Der Rückweg ist versperrt — das Konto wurde inzwischen gelöscht oder
 * gesperrt"), aber nur für den, der auf „zurück" drückt.
 *
 * > **Ein Zustand, der beim Verlassen geprüft wird, ist beim Bleiben ungeprüft.**
 *
 * ## Die Reihenfolge in der Kette
 *
 * **Vor {@see EnforceAdminNetwork}:** Ein Konto, das gar nicht mehr da sein
 * darf, wird nicht zuerst nach seiner Adresse gefragt. Und vor
 * {@see RequireTwoFactor}, aus demselben Grund wie dort — wer nicht herein
 * darf, soll die Einrichtungsseite des zweiten Faktors nicht sehen.
 *
 * Nach `ApplyTenancy`, weil {@see AccountAccess} für die Frage nach dem
 * zurückgezogenen Kunden an die Datenbank geht.
 *
 * ## Ein Kunde ist genauso betroffen
 *
 * Anders als bei der Netzbeschränkung, und mit Absicht: Ein gekündigter Kunde,
 * der weiterarbeitet, ist derselbe Fehler wie ein gesperrter Administrator. Die
 * Unterscheidung träfe {@see AccountAccess} und nicht diese Klasse — sonst
 * stünde die Regel an zwei Stellen, und die zweite wäre die, die veraltet.
 */
final class EnforceAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::user();

        if (! $account instanceof Account || ! $request->hasSession()) {
            return $next($request);
        }

        $refusal = AccountAccess::refusal($account);

        /*
         * **Erst der Handelnde, dann der hinter ihm.** Andersherum stünde im
         * Protokoll der Grund des Hintermanns über einem Konto, das selbst
         * schon gesperrt ist — und der genauere Grund ginge verloren.
         */
        if ($refusal === null) {
            $behind = $this->impersonator($request);

            if ($behind !== null) {
                $refusal = AccountAccess::refusal($behind);
                $account = $refusal === null ? $account : $behind;
            }
        }

        if ($refusal === null) {
            return $next($request);
        }

        /*
         * **Derselbe Ereignisname wie bei der Netzbeschränkung**, und der
         * Grund steht daneben. Aus der Sicht dessen, der das Protokoll
         * aufschlägt, ist es dasselbe Ereignis — „eine offene Sitzung wurde
         * vom Panel beendet" —, und `auth.login.failed` führt seit P1 ebenso
         * mehrere Gründe unter einem Namen. Ein zweiter Name teilte eine Frage
         * auf zwei Filter auf, die man beide kennen müsste.
         *
         * **Protokolliert wird vor dem Abmelden.** Danach gibt es kein
         * angemeldetes Konto mehr, an dem der Eintrag hinge — und ein
         * Protokoll, das den Rauswurf ohne den Hinausgeworfenen führt,
         * beantwortet die Frage nicht, für die man es aufschlägt.
         */
        app(Audit::class)->record(
            'auth.session.blocked',
            AuditResult::Failure,
            account: $account,
            context: ['reason' => $refusal],
        );

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('notice', 'Dieses Konto ist nicht mehr zugelassen. Die Sitzung wurde beendet.');
    }

    /**
     * Wer steht hinter dieser Sitzung — oder `null`, wenn niemand.
     *
     * **`Account::query()` ohne weitere Umstände:** Das Modell trägt keine
     * globale Mandantenklammer, sie wird in seinen Methoden einzeln
     * aufgehoben. Nachgesehen und nicht dem Kommentar im
     * {@see ImpersonationController} geglaubt.
     */
    private function impersonator(Request $request): ?Account
    {
        $id = $request->session()->get(Impersonation::SESSION_KEY);

        return is_numeric($id) ? Account::query()->find((int) $id) : null;
    }
}
