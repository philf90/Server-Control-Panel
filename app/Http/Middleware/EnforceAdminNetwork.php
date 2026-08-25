<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditResult;
use App\Http\Controllers\Auth\LoginController;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Authorization\AdminNetwork;
use App\Support\Settings\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Netzbeschränkung gilt auch für eine Sitzung, die schon offen ist.
 *
 * ## Warum die Prüfung bei der Anmeldung nicht genügt
 *
 * `docs/82 §2.5` nennt sie „IP-Beschränkung der Panel-**Anmeldung**", und genau
 * so gebaut wäre sie die Hälfte: Wer im Büro angemeldet war und den Rechner
 * mitnimmt, arbeitet weiter — und wer ein Sitzungskennzeichen mitnimmt, arbeitet
 * von überall weiter. Das ist die Lage, gegen die eine Netzbeschränkung
 * überhaupt gekauft wird.
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * ## Und warum sie trotzdem bei der Anmeldung *auch* steht
 *
 * Damit das Protokoll die Wahrheit sagt. Ohne die Frage im
 * {@see LoginController} stünde dort ein
 * erfolgreicher `auth.login` und unmittelbar daneben ein Rauswurf — zwei
 * Einträge über einen Vorgang, der nie stattgefunden hat.
 *
 * ## Die Reihenfolge in der Kette
 *
 * Vor {@see RequireTwoFactor}: Wer von hier nicht herein darf, soll die
 * Einrichtungsseite des zweiten Faktors nicht sehen. Und nach `ApplyTenancy`,
 * weil {@see AdminNetwork} über {@see Settings} an die
 * Datenbank geht.
 *
 * ## Ein Kunde ist nie betroffen
 *
 * Das entscheidet {@see AdminNetwork::permits()} und nicht diese Klasse — sonst
 * stünde die Regel an zwei Stellen, und die zweite wäre die, die veraltet.
 */
final class EnforceAdminNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::user();

        if (! $account instanceof Account || ! $request->hasSession()) {
            return $next($request);
        }

        if (AdminNetwork::permits($account, $request->ip())) {
            return $next($request);
        }

        /*
         * **Protokolliert wird vor dem Abmelden.** Danach gibt es kein
         * angemeldetes Konto mehr, an dem der Eintrag hinge — und ein
         * Protokoll, das den Rauswurf ohne den Hinausgeworfenen führt,
         * beantwortet die Frage nicht, für die man es aufschlägt.
         */
        app(Audit::class)->record(
            'auth.session.blocked',
            AuditResult::Failure,
            account: $account,
            context: ['ip' => $request->ip() ?? '(unbekannt)', 'reason' => 'Netz nicht mehr zugelassen'],
        );

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('notice', 'Von dieser Adresse aus ist die Anmeldung für Verwaltungskonten nicht '
                .'zugelassen. Die Sitzung wurde beendet.');
    }
}
