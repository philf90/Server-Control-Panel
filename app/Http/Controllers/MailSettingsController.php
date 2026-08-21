<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\TestMessage;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Der Mailversand des Panels — ein Relay, zentral hinterlegt.
 *
 * **Warum das Panel überhaupt Mail braucht.** Der Einmal-Link zum Setzen eines
 * Passworts, die Warnung bei erreichtem Kontingent, später die Meldung über
 * einen fehlgeschlagenen Sicherungslauf. Alles davon ist wertlos, solange es
 * niemanden erreicht.
 *
 * **Warum ein Relay und kein eigener Versand.** Ein Panel, das selbst
 * zustellt, braucht einen MTA auf demselben Server, einen sauberen PTR, SPF,
 * DKIM und eine IP mit Ruf. Fehlt eines davon, landet die Mail im Spam — ohne
 * Rückmeldung, und das ist der Fall, den man nicht bemerkt. Über ein Relay
 * geht sie über einen Absender, der das alles schon hat.
 *
 * **Das Passwort verlässt den Server nicht wieder.** Es steht in keiner
 * Antwort; das Formular zeigt nur, *ob* eines hinterlegt ist. Ein Feld, das
 * den gespeicherten Wert zurückschickt, damit es „vollständig" aussieht, legt
 * ihn im Quelltext jeder Seite ab, die es zeigt.
 */
final class MailSettingsController extends Controller
{
    public function show(Settings $settings): Response
    {
        return Inertia::render('Settings/Mail', [
            'mail' => $settings->mail()->forDisplay(),
            'encryptions' => [
                ['value' => 'tls', 'label' => 'STARTTLS (üblich, Port 587)'],
                ['value' => 'ssl', 'label' => 'TLS von Anfang an (Port 465)'],
                ['value' => 'none', 'label' => 'ohne Verschlüsselung'],
            ],
            'usable' => $settings->mail()->usable(),
        ]);
    }

    public function update(Request $request, Settings $settings, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(MailSettings::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],

            // Leer heisst „unverändert" und nicht „gelöscht". Ein Formular,
            // das das Passwortfeld leer anzeigt — und es zeigt es leer an,
            // siehe oben —, würde sonst bei jedem Speichern des Ports die
            // Anmeldung am Relay abräumen.
            'password' => ['nullable', 'string', 'max:255'],
            'password_clear' => ['required', 'boolean'],

            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ], [], [
            /*
             * **Der Name muss heissen wie das Feld auf der Seite** (`docs/66`, Befund 3).
             * Die Liste in `lang/de/validation.php` trägt den Namen, der über alle Seiten
             * passt; wo eine Seite ein anderes Wort benutzt, steht es hier. Sonst sucht der
             * Leser ein Feld, das er nicht sieht.
             *
             * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
             */
            'from_address' => 'Adresse',
            'password_clear' => 'Hinterlegtes Passwort entfernen',
        ]);

        $current = $settings->mail();

        $password = match (true) {
            (bool) $data['password_clear'] => '',
            ($data['password'] ?? '') !== '' => (string) $data['password'],
            default => $current->password,
        };

        $settings->saveMail(new MailSettings(
            host: $data['host'],
            port: (int) $data['port'],
            encryption: $data['encryption'],
            username: (string) ($data['username'] ?? ''),
            password: $password,
            from_address: $data['from_address'],
            from_name: $data['from_name'],
        ));

        // Im Protokoll steht, wohin verschickt wird — nicht, womit. Weder das
        // Passwort noch seine Länge.
        $audit->success('settings.mail.updated', context: [
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'from' => $data['from_address'],
        ]);

        return redirect()->route('settings.mail')->with('success', 'Einstellungen gespeichert.');
    }

    /**
     * Eine Testmail an die eigene Adresse.
     *
     * **An die eigene und an keine andere.** Ein Feld für den Empfänger machte
     * aus dieser Seite ein Formular, mit dem sich über das Relay des
     * Betreibers an beliebige Adressen schreiben lässt — mit seinem Absender
     * und auf seinen Ruf.
     *
     * **Der Fehler wird gezeigt, nicht nur „hat nicht geklappt".** Was hier
     * schiefgeht, ist fast immer eine Auskunft des Relays: falsches Passwort,
     * Port zu, Zertifikat abgelehnt. Genau die braucht der Betreiber, und sie
     * steht sonst nur im Protokoll der Anwendung, an das er auf einem Server
     * ohne Shell nicht herankommt.
     */
    public function test(Request $request, Settings $settings, Audit $audit): RedirectResponse
    {
        $account = $request->user();

        if (! $account instanceof Account) {
            abort(403);
        }

        /*
         * Das Ziel wird genannt und nicht `back()` überlassen. Der Vhost des
         * Panels schickt `Referrer-Policy: no-referrer`, und Inertia navigiert
         * über XHR — damit kennt `back()` weder ein `Referer` noch eine in der
         * Sitzung vermerkte Adresse und fällt auf die Übersicht durch. Die
         * ganze Begründung steht bei `ProfileController::theme()`.
         */
        if (! $settings->mail()->usable()) {
            return to_route('settings.mail')->with('error', 'Erst Server und Absenderadresse eintragen und speichern.');
        }

        try {
            Mail::to($account->signInAddress())->send(new TestMessage($account->name, (string) Clock::display(now())));
        } catch (Throwable $error) {
            $audit->failure('settings.mail.tested', ['error' => mb_substr($error->getMessage(), 0, 500)]);

            return to_route('settings.mail')->with('error', 'Der Versand ist gescheitert: '.mb_substr($error->getMessage(), 0, 500));
        }

        $audit->success('settings.mail.tested', context: ['to' => $account->signInAddress()]);

        return to_route('settings.mail')->with('success', 'Testmail an '.$account->signInAddress().' verschickt.');
    }
}
