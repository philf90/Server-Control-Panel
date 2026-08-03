<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditResult;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anmeldung und Abmeldung.
 *
 * Drei Dinge, die hier bewusst so und nicht anders sind:
 *
 * 1. **Eine einzige Fehlermeldung.** Ob die Adresse unbekannt ist, das
 *    Passwort falsch oder das Konto deaktiviert — die Antwort lautet immer
 *    gleich. Wer unterscheidet, verrät, welche Adressen existieren, und macht
 *    aus dem Anmeldeformular ein Werkzeug zum Sammeln von Kontonamen.
 *
 * 2. **Auch für unbekannte Adressen wird gerechnet.** Ohne das wäre die
 *    Antwortzeit der Verräter, den Punkt 1 gerade verhindern soll: Eine
 *    unbekannte Adresse käme in Millisekunden zurück, eine bekannte erst nach
 *    dem Argon2-Durchlauf.
 *
 * 3. **Die Sitzung wird nach der Anmeldung erneuert.** Sonst könnte jemand,
 *    der dem Opfer vorher eine Sitzungskennung untergeschoben hat, sie danach
 *    weiterbenutzen (Session Fixation).
 */
final class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, LoginThrottle $throttle, Audit $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $ip = (string) $request->ip();
        $email = Str::lower(trim($credentials['email']));

        if ($throttle->tooManyAttempts($ip, $email)) {
            $seconds = $throttle->secondsUntilAllowed($ip, $email);

            $audit->record(
                'auth.login.throttled',
                AuditResult::Denied,
                context: ['email' => $email, 'seconds' => $seconds],
            );

            throw ValidationException::withMessages([
                'email' => $this->waitMessage($seconds),
            ]);
        }

        $account = Account::query()->where('email', $email)->first();

        // Auch ohne Konto wird gerechnet — siehe Punkt 2 oben. Der Vergleich
        // läuft gegen einen festen Hash, damit der Zeitaufwand derselbe ist.
        $passwordMatches = $account !== null
            ? Hash::check($credentials['password'], $account->password)
            : Hash::check($credentials['password'], $this->dummyHash());

        if ($account === null || ! $passwordMatches || ! $account->status->canSignIn()) {
            $throttle->recordFailure($ip, $email);

            $audit->record(
                'auth.login.failed',
                AuditResult::Failure,
                account: $account,
                context: [
                    'email' => $email,
                    // Warum es scheiterte, steht im Protokoll — nur nicht in
                    // der Antwort an den Browser.
                    'reason' => match (true) {
                        $account === null => 'unbekannte Adresse',
                        ! $passwordMatches => 'falsches Passwort',
                        default => 'Konto deaktiviert',
                    },
                ],
            );

            throw ValidationException::withMessages([
                'email' => 'Diese Zugangsdaten passen nicht zu einem aktiven Konto.',
            ]);
        }

        $throttle->clear($ip, $email);

        // Zweiter Faktor: Ab hier ist das Passwort richtig — angemeldet ist
        // trotzdem noch niemand. Das wartende Konto steht in der Sitzung, und
        // erst der zweite Schritt meldet an. Wer hier schon `Auth::login`
        // aufruft und danach umleitet, hat den zweiten Faktor zu einer Seite
        // gemacht, die man durch Eintippen einer anderen Adresse überspringt.
        if ($account->hasTwoFactor()) {
            TwoFactorChallengeController::await($request, $account, (bool) ($credentials['remember'] ?? false));

            $audit->record('auth.two_factor.required', account: $account);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($account, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        // Der Startpunkt der absoluten Sitzungsdauer. Siehe
        // App\Http\Middleware\EnforceSessionLifetime.
        $request->session()->put('authenticated_at', time());

        $account->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $audit->success('auth.login');

        return redirect()->intended(route('overview'));
    }

    public function destroy(Request $request, Audit $audit): RedirectResponse
    {
        $audit->success('auth.logout');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function waitMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $seconds < 60
            ? "Zu viele Versuche. Bitte {$seconds} Sekunden warten."
            : "Zu viele Versuche. Bitte {$minutes} Minuten warten.";
    }

    /**
     * Ein gültiger Argon2id-Hash, gegen den bei unbekannter Adresse gerechnet
     * wird. Er wird einmal je Anfrage erzeugt und passt zu keinem Passwort.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(64));
    }
}
