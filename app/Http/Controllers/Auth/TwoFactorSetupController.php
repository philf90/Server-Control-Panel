<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Auth\RecoveryCodes;
use App\Support\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Den zweiten Faktor einrichten.
 *
 * **Das Geheimnis gilt erst, wenn ein Code daraus stimmt.** Zwischen „erzeugt"
 * und „bestätigt" liegt der häufigste Weg, sich selbst auszusperren: Wer das
 * Geheimnis speichert und sofort verlangt, hat ein Konto, an das niemand mehr
 * kommt, sobald der QR-Code falsch abfotografiert wurde. Deshalb steht das
 * neue Geheimnis in der Sitzung, bis ein Code aus der App es belegt.
 *
 * **Die Wiederherstellungscodes werden einmal gezeigt.** Danach liegen nur
 * ihre Hashes in der Datenbank — auch das Panel kann sie nicht mehr anzeigen.
 * Das ist der Sinn: Wer die Datenbank liest, soll sie nicht benutzen können.
 */
final class TwoFactorSetupController extends Controller
{
    private const PENDING_SECRET = 'two_factor_setup_secret';

    public function show(Request $request): Response
    {
        $account = $this->account($request);

        if ($account->hasTwoFactor()) {
            return Inertia::render('Auth/TwoFactorSetup', [
                'active' => true,
                'remainingRecoveryCodes' => count($account->two_factor_recovery_codes ?? []),
            ]);
        }

        // Ein einmal begonnener Vorgang behält sein Geheimnis: Sonst zeigt ein
        // Neuladen der Seite einen neuen QR-Code, und der Code aus der App
        // passt plötzlich nicht mehr.
        $secret = $request->session()->get(self::PENDING_SECRET);

        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            $request->session()->put(self::PENDING_SECRET, $secret);
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'active' => false,
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, $account->email, config('app.name', 'SrvPanel')),
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);

        if ($account->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $secret = $request->session()->get(self::PENDING_SECRET);

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('two-factor.setup');
        }

        $step = Totp::verify($secret, $data['code']);

        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => 'Dieser Code stimmt nicht. Bitte die Uhrzeit des Geräts prüfen.',
            ]);
        }

        $codes = RecoveryCodes::generate();

        $account->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();

        $request->session()->forget(self::PENDING_SECRET);
        $audit->success('auth.two_factor.enabled');

        // Die Klartextcodes gehen einmal über die Kurzmeldung an die nächste
        // Seite und werden nirgends abgelegt.
        return redirect()->route('two-factor.setup')->with('recoveryCodes', $codes);
    }

    /**
     * Den zweiten Faktor abschalten.
     *
     * Nur mit gültigem Code — sonst genügte eine offene Sitzung an einem
     * unbeaufsichtigten Rechner, um den zweiten Faktor loszuwerden, und er
     * schützte genau den Fall nicht mehr, für den es ihn gibt.
     */
    public function destroy(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);

        if ($account->isAdmin()) {
            // Für Betreiber ist er verpflichtend (§6.4).
            throw ValidationException::withMessages([
                'code' => 'Für Administratoren ist der zweite Faktor verpflichtend.',
            ]);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $secret = $account->two_factor_secret;

        if (! is_string($secret) || Totp::verify($secret, $data['code']) === null) {
            throw ValidationException::withMessages(['code' => 'Dieser Code stimmt nicht.']);
        }

        $account->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $audit->success('auth.two_factor.disabled');

        return redirect()->route('two-factor.setup');
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        return $account;
    }
}
