<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditResult;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginThrottle;
use App\Support\Auth\RecoveryCodes;
use App\Support\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der zweite Schritt der Anmeldung.
 *
 * **Zwischen Passwort und zweitem Faktor ist niemand angemeldet.** Das
 * Kennzeichen des wartenden Kontos steht in der Sitzung, nicht im
 * Anmeldezustand — sonst wäre das Panel nach dem Passwort allein bereits
 * offen, und der zweite Faktor nur eine Seite, die man überspringt, indem man
 * eine andere Adresse eintippt.
 *
 * **Ein verbrauchter Code ist verbraucht.** Das Fenster ist neunzig Sekunden
 * breit, weil es ungenaue Uhren abfangen muss; ohne diesen Vermerk hätte
 * jemand, der einen Code mitliest, anderthalb Minuten Zeit. Der zuletzt
 * angenommene Zeitschritt steht deshalb am Konto.
 */
final class TwoFactorChallengeController extends Controller
{
    private const SESSION_KEY = 'two_factor_pending_account_id';

    private const REMEMBER_KEY = 'two_factor_pending_remember';

    /**
     * Das Konto in den Wartezustand versetzen — aufgerufen aus der Anmeldung.
     */
    public static function await(Request $request, Account $account, bool $remember): void
    {
        $request->session()->put(self::SESSION_KEY, (int) $account->id);
        $request->session()->put(self::REMEMBER_KEY, $remember);
    }

    public static function isAwaiting(Request $request): bool
    {
        return $request->session()->has(self::SESSION_KEY);
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $account = $this->pending($request);

        if ($account === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, LoginThrottle $throttle, Audit $audit): RedirectResponse
    {
        $account = $this->pending($request);

        if ($account === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $ip = (string) $request->ip();

        // Derselbe Zähler wie beim Passwort. Ohne ihn wäre der zweite Faktor
        // sechs Stellen, die sich in Ruhe durchprobieren lassen — eine Million
        // Möglichkeiten sind für ein Programm kein Hindernis.
        if ($throttle->tooManyAttempts($ip, $account->email)) {
            throw ValidationException::withMessages([
                'code' => 'Zu viele Versuche. Bitte einen Moment warten.',
            ]);
        }

        $accepted = $this->accept($account, $data['code']);

        if ($accepted === null) {
            $throttle->recordFailure($ip, $account->email);
            $audit->record('auth.two_factor.failed', AuditResult::Failure, account: $account);

            throw ValidationException::withMessages([
                'code' => 'Dieser Code stimmt nicht.',
            ]);
        }

        $throttle->clear($ip, $account->email);

        $remember = (bool) $request->session()->get(self::REMEMBER_KEY, false);
        $request->session()->forget([self::SESSION_KEY, self::REMEMBER_KEY]);

        Auth::login($account, $remember);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());

        $account->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $audit->success('auth.login', context: ['method' => $accepted]);

        return redirect()->intended(route('overview'));
    }

    /**
     * Prüft den Code und verbucht ihn. Gibt zurück, womit er angenommen wurde.
     */
    private function accept(Account $account, string $input): ?string
    {
        $secret = $account->two_factor_secret;

        if (is_string($secret) && $secret !== '') {
            $step = Totp::verify($secret, $input);

            // Der Vergleich mit dem zuletzt verbrauchten Schritt ist die
            // Wiederholungssperre. `<=` und nicht `===`: Ein älterer Schritt
            // aus dem Fenster darf ebenso wenig noch einmal gelten.
            if ($step !== null && $step > (int) ($account->two_factor_last_step ?? 0)) {
                $account->forceFill(['two_factor_last_step' => $step])->save();

                return 'totp';
            }

            if ($step !== null) {
                return null;
            }
        }

        $stored = $account->two_factor_recovery_codes;

        if (is_array($stored) && $stored !== []) {
            /** @var list<string> $stored */
            $remaining = RecoveryCodes::consume($stored, $input);

            if ($remaining !== null) {
                $account->forceFill(['two_factor_recovery_codes' => $remaining])->save();

                return 'recovery';
            }
        }

        return null;
    }

    private function pending(Request $request): ?Account
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! is_numeric($id)) {
            return null;
        }

        $account = Account::query()->find((int) $id);

        return $account !== null && $account->status->canSignIn() ? $account : null;
    }
}
