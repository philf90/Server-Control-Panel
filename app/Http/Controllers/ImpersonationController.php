<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Audit\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * „Anmelden als Kunde" (§6.3).
 *
 * Vier Zusagen stehen im Plan, und drei davon sind leicht zu übersehen:
 *
 * 1. **Kein Passwortzugriff.** Der Wechsel läuft über die Sitzung, nicht über
 *    die Zugangsdaten des Kunden. Niemand liest, ändert oder umgeht sie.
 * 2. **Kein stiller Wechsel.** Beide Richtungen stehen im Protokoll, und zwar
 *    mit dem Admin als handelnder Person — auch die Aktionen dazwischen.
 * 3. **Sichtbares Band.** Solange der Wechsel läuft, sagt die Oberfläche es
 *    auf jeder Seite. Ein Admin, der vergisst, in wessen Sicht er ist, tut
 *    sonst im Namen eines Kunden Dinge, die er für seine eigenen hält.
 * 4. **Rückweg jederzeit.** Auch dann, wenn das Kundenkonto inzwischen
 *    gesperrt wurde — sonst säße der Admin in einer Sitzung fest, aus der er
 *    sich nur durch Abmelden befreien kann.
 *
 * **Kein Wechsel aus einem Wechsel heraus.** Wer schon in fremder Sicht ist,
 * kann nicht in eine dritte springen. Sonst wäre der Rückweg mehrdeutig, und
 * im Protokoll stünde eine Kette, die niemand mehr auflöst.
 */
final class ImpersonationController extends Controller
{
    public function start(Request $request, Customer $customer, Audit $audit): RedirectResponse
    {
        if ($request->session()->has(Impersonation::SESSION_KEY)) {
            throw ValidationException::withMessages([
                'impersonation' => 'Es läuft bereits ein Wechsel. Bitte zuerst zurückkehren.',
            ]);
        }

        $admin = $request->user();

        if (! $admin instanceof Account) {
            abort(403);
        }

        $target = $customer->accounts()
            ->where('type', AccountType::Customer->value)
            ->where('status', AccountStatus::Active->value)
            ->orderBy('id')
            ->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'impersonation' => 'Dieser Kunde hat kein aktives Konto, in das gewechselt werden könnte.',
            ]);
        }

        $audit->record(
            'impersonation.start',
            account: $admin,
            target: $customer,
            context: ['target_account_id' => (int) $target->id],
        );

        // Erst anmelden, dann den Schlüssel setzen: `Auth::login` erneuert die
        // Sitzungskennung, und alles, was vorher hineingeschrieben wurde,
        // ginge dabei verloren.
        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, (int) $admin->id);
        $request->session()->put('authenticated_at', time());

        return redirect()->route('overview');
    }

    public function stop(Request $request, Audit $audit): RedirectResponse
    {
        $adminId = $request->session()->get(Impersonation::SESSION_KEY);

        if (! is_numeric($adminId)) {
            return redirect()->route('overview');
        }

        // Ohne Mandantenklammer: Das Adminkonto hängt an keinem Abonnement,
        // und die Klammer steht in diesem Moment auf der Sicht des Kunden.
        $admin = Account::query()->find((int) $adminId);

        if ($admin === null || ! $admin->isAdmin() || ! $admin->status->canSignIn()) {
            // Der Rückweg ist versperrt — das Konto wurde inzwischen gelöscht
            // oder gesperrt. Dann bleibt nur das Abmelden, und zwar sofort:
            // In fremder Sicht weiterzuarbeiten wäre der schlechtere Zustand.
            $audit->record('impersonation.stop.failed');

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('notice', 'Die Rückkehr in das eigene Konto ist nicht möglich. Bitte neu anmelden.');
        }

        // Noch im Kontext protokollieren, damit der Eintrag die Zuordnung
        // trägt — danach ist der Sitzungsschlüssel weg.
        $audit->record('impersonation.stop');

        $request->session()->forget(Impersonation::SESSION_KEY);
        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());

        return redirect()->route('customers.index');
    }
}
