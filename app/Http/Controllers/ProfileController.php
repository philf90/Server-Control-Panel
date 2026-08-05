<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Audit\Impersonation;
use App\Support\Passwords\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das eigene Konto — Name, Anmeldeadresse, Passwort.
 *
 * **Warum es diese Seite gibt.** Der Plan nennt sie nirgends. Angelegt wird
 * ein Adminkonto über `srvpanel:admin`, geändert werden konnte es danach
 * ausschliesslich über dasselbe Kommando — also nur von jemandem, der root auf
 * dem Server ist. Ein Panel, dessen Betreiber sein eigenes Passwort nur über
 * die Kommandozeile wechseln kann, ist an dieser Stelle kein Panel.
 *
 * **Jede Änderung verlangt das aktuelle Passwort.** Auch die des Namens. Der
 * Grund ist nicht der Name, sondern die Sitzung: Wer einen fremden Rechner
 * unbeaufsichtigt findet, soll damit nicht die Anmeldeadresse umschreiben und
 * das Konto übernehmen können. Ein Passwortfeld ist die Schranke, die genau
 * diesen Fall trifft, und sie kostet den Berechtigten fünf Sekunden.
 *
 * **Während „Anmelden als" ist die Seite gesperrt.** Das ist der wichtigste
 * Satz hier. Ein Admin in der Sicht eines Kunden könnte sonst dessen Passwort
 * setzen und sich damit einen dauerhaften Zugang zu einem fremden Konto
 * verschaffen — vorbei an jeder Protokollzeile, die den Wechsel festhält. Der
 * Wechsel ist zum Ansehen da, nicht zum Übernehmen.
 */
final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('Settings/Profile', [
            'profile' => [
                'name' => $account->name,
                'email' => $account->email,
                'type_label' => $account->type->label(),
                'two_factor' => $account->hasTwoFactor(),
                'theme' => $account->theme,
                'last_login_at' => $account->last_login_at?->toDateTimeString(),
                'last_login_ip' => $account->last_login_ip,
            ],
            'impersonating' => $this->impersonating($request),
        ]);
    }

    public function update(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);
        $this->refuseWhileImpersonating($request, $audit, 'profile.updated');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                // Über alle Konten eindeutig, nicht nur über die Kunden: Zwei
                // Konten mit derselben Adresse fänden bei der Anmeldung zwei
                // Treffer, und welcher gewinnt, wäre Zufall der Sortierung.
                Rule::unique('accounts', 'email')->ignore($account->id),
            ],
            'current_password' => ['required', 'string'],
        ]);

        $this->confirmPassword($account, $data['current_password']);

        $before = ['name' => $account->name, 'email' => $account->email];

        $account->forceFill([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
        ])->save();

        // Was sich geändert hat, gehört ins Protokoll — die neue Adresse ist
        // die Kennung, unter der sich künftig jemand anmeldet.
        $audit->success('profile.updated', $account, [
            'before' => $before,
            'after' => ['name' => $account->name, 'email' => $account->email],
        ]);

        return to_route('profile')->with('success', 'Konto gespeichert.');
    }

    /**
     * Hell, dunkel oder das, was das Betriebssystem sagt.
     *
     * **Ohne Passwortbestätigung, anders als alles andere auf dieser Seite.**
     * Die Schranke dort schützt die Sitzung: Wer einen fremden, unbeaufsichtigten
     * Rechner findet, soll die Anmeldeadresse nicht umschreiben können. Eine
     * Farbe ist kein Übernahmeweg — und eine Rückfrage nach dem Passwort für
     * einen Umschalter erzieht dazu, das Passwort beiläufig einzutippen. Genau
     * das soll die Schranke ja verhindern.
     *
     * **Während „Anmelden als" gesperrt.** Der Admin sieht die Sicht des
     * Kunden; gespeichert würde die Wahl aber am Konto des Kunden. Ein
     * Betreiber, der sein Panel hell mag, stellte damit fremde Konten um, und
     * der Kunde fände seine Oberfläche verändert vor, ohne etwas getan zu
     * haben.
     *
     * **Kein Protokolleintrag.** Das Protokoll beantwortet, wer was an diesem
     * Server verändert hat. Ein Theme verändert nichts am Server, es verändert
     * einen Bildschirm — und eine Zeile je Umschaltung machte das Protokoll
     * genau dort unübersichtlich, wo man es im Ernstfall liest.
     *
     * **`to_route('profile')` und nicht `back()` — und das ist der Fund.** Wer
     * hier die Darstellung umstellte, landete auf der Übersicht. Gespeichert
     * war richtig, nur stand man danach woanders. Es lag an drei Dingen, die
     * einzeln jedes für sich vernünftig sind:
     *
     *   1. Der Vhost des Panels schickt `Referrer-Policy: no-referrer`
     *      (`agent/src/Ops/PanelVhost.php`) — das Panel gibt nicht preis, von
     *      welcher Adresse jemand kam. Der Browser sendet damit kein `Referer`.
     *   2. `back()` fragt zuerst genau dieses `Referer`. Fehlt es, nimmt es die
     *      zuletzt in der Sitzung vermerkte Adresse.
     *   3. Vermerkt wird sie nur bei einem GET, das **kein** XHR ist
     *      (`StartSession::storeCurrentUrl`). Jede Navigation über Inertia ist
     *      eines. In der Sitzung steht deshalb der letzte vollständige
     *      Seitenaufruf — nach der Anmeldung die Übersicht.
     *
     * `back()` konnte hier also gar nicht wissen, wohin zurück ist, und fiel
     * auf `/` durch. Auffallen konnte es beim Entwickeln nicht: Ohne nginx gibt
     * es die Kopfzeile aus (1) nicht, und dann funktioniert es. Wieder eine
     * Zusage ohne Gegenprüfung — „zurück" verweist auf eine Adresse, die
     * niemand kennt. `RedirectTargetTest` lässt `back()` deshalb nirgends mehr
     * zu.
     */
    public function theme(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);
        $this->refuseWhileImpersonating($request, $audit, 'profile.theme.changed');

        $data = $request->validate([
            // `nullable` ist die Wahl „System": kein Theme, sondern die
            // Abwesenheit einer Wahl.
            'theme' => ['nullable', Rule::in(Account::THEMES)],
        ]);

        $account->forceFill(['theme' => $data['theme'] ?? null])->save();

        return to_route('profile')->with('success', 'Darstellung gespeichert.');
    }

    public function password(Request $request, Audit $audit): RedirectResponse
    {
        $account = $this->account($request);
        $this->refuseWhileImpersonating($request, $audit, 'profile.password.changed');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', ...Policy::rules()],
        ]);

        $this->confirmPassword($account, $data['current_password']);

        $account->forceFill(['password' => Hash::make($data['password'])])->save();

        /*
         * Alle anderen Sitzungen verlieren ihre Gültigkeit.
         *
         * Ein Passwortwechsel ist oft die Antwort auf einen Verdacht. Bliebe
         * eine fremde Sitzung danach angemeldet, wäre der Wechsel eine
         * Beruhigung ohne Wirkung. Die eigene Sitzung bleibt bestehen — wer
         * sein Passwort ändert, will sich nicht danach neu anmelden müssen.
         */
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        // Ohne Kontext: Ein Protokolleintrag über einen Passwortwechsel darf
        // nichts enthalten, was Rückschlüsse auf das Passwort zulässt — auch
        // nicht seine Länge.
        $audit->success('profile.password.changed', $account);

        return to_route('profile')->with('success', 'Passwort geändert. Andere Sitzungen wurden abgemeldet.');
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        return $account;
    }

    /**
     * Das aktuelle Passwort bestätigen.
     *
     * Der Fehler hängt an `current_password` und nicht an einem allgemeinen
     * Feld: So steht die Meldung dort, wo der Fehler war, statt oben über dem
     * Formular.
     */
    private function confirmPassword(Account $account, string $password): void
    {
        if (! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Das aktuelle Passwort stimmt nicht.',
            ]);
        }
    }

    private function impersonating(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->has(Impersonation::SESSION_KEY);
    }

    /**
     * Änderungen am Konto während eines „Anmelden als" abweisen — und den
     * Versuch protokollieren.
     *
     * Protokolliert, weil ein Versuch an dieser Stelle keine Ungeschicklichkeit
     * ist: Die Oberfläche zeigt in diesem Zustand kein Formular. Wer hier
     * ankommt, hat die Anfrage selbst gestellt.
     */
    private function refuseWhileImpersonating(Request $request, Audit $audit, string $action): void
    {
        if (! $this->impersonating($request)) {
            return;
        }

        $audit->denied($action, null, ['reason' => 'während „Anmelden als"']);

        abort(403, 'In fremder Sicht lässt sich kein Konto ändern.');
    }
}
