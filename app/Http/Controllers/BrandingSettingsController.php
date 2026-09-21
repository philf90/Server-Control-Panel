<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Brand\Logo;
use App\Support\Design\Contrast;
use App\Support\Settings\BrandSettings;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Das Aussehen, das der Betreiber vorgibt — B6, `docs/129 §9`.
 *
 * **Die Absenderadresse steht hier nicht.** Sie gibt es seit P2 auf der Seite
 * „Mailversand"; ein zweites Feld dafür wäre ein zweiter Ort für einen Wert,
 * und der zweite ist der, der veraltet. Diese Seite verweist darauf.
 */
final class BrandingSettingsController extends Controller
{
    public function update(Request $request, Settings $settings, Logo $logo, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'accent_light' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'accent_dark' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],

            /*
             * **Reiner Text und kein Markup.** Die Fusszeile steht auf der
             * Anmeldeseite — der einen Seite ohne angemeldetes Konto. Wäre
             * HTML erlaubt, trüge der Betreiber die Verantwortung dafür, dass
             * dort kein Skript landet; er soll eine Zeile schreiben dürfen und
             * keine Sicherheitsentscheidung treffen müssen.
             */
            'footer' => ['nullable', 'string', 'max:200'],

            'logo' => ['nullable', 'file'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $this->refuseUnreadable('accent_light', $data['accent_light'], BrandSettings::SURFACES_LIGHT);
        $this->refuseUnreadable('accent_dark', $data['accent_dark'], BrandSettings::SURFACES_DARK);

        $marke = $settings->brand();
        $name = $marke->logo;

        if (($data['remove_logo'] ?? false) === true) {
            $logo->forget();
            $name = null;
        }

        if ($request->hasFile('logo')) {
            $datei = $request->file('logo');

            if (($grund = $logo->refusal($datei)) !== null) {
                throw ValidationException::withMessages(['logo' => $grund]);
            }

            $name = $logo->store($datei);
        }

        $settings->saveBrand(new BrandSettings(
            name: trim($data['name']),
            accent_light: strtolower($data['accent_light']),
            accent_dark: strtolower($data['accent_dark']),
            footer: trim((string) ($data['footer'] ?? '')),
            logo: $name,
        ));

        $audit->success('settings.branding', null, ['name' => trim($data['name'])]);

        /*
         * **Zurück auf die Seite, auf der das Formular steht — und das ist
         * `/settings/general`.** Hier stand `settings.branding`, ein Name, den
         * es nie gab: Die Markenfelder sind in die allgemeine Seite gefaltet
         * worden, weil die Navigationsgruppe an der Route trennt, und die
         * Weiterleitung ist mit dem alten Namen stehengeblieben.
         *
         * `to_route()` wirft dafür `RouteNotFoundException` — **jedes**
         * gelungene Speichern gab also 500, während die Marke gespeichert war.
         * Gefunden hat es kein Wächter, sondern der Blick in
         * `Route::getRoutes()`; `RedirectTargetTest` hält es seitdem für jeden
         * Namen, den ein Controller nennt.
         */
        return to_route('settings.general')->with('success', 'Die Marke ist gespeichert.');
    }

    /**
     * Das Logo ausliefern — **ohne angemeldetes Konto**.
     *
     * Die Anmeldeseite hat keines, und ein Logo hinter der Anmeldung wäre auf
     * genau der Seite unsichtbar, für die es das Abnahmekriterium gibt. Der
     * Typ kommt aus der Positivliste in {@see Logo} und nicht aus der Datei:
     * Was ausgeliefert wird, entscheidet diese Anwendung.
     */
    public function logo(Settings $settings, Logo $logo): BinaryFileResponse
    {
        $name = $settings->brand()->logo;
        $pfad = $logo->path($name);

        abort_if($pfad === null || $name === null, 404);

        return response()->file($pfad, [
            'Content-Type' => (string) Logo::typeOf($name),

            // Der Browser soll nicht raten, was er bekommen hat — und aus einem
            // Bild kein Dokument machen.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Eine Farbe abweisen, unter der die Schrift nicht mehr lesbar ist.
     *
     * Die Meldung nennt den **gemessenen** Wert und die Fläche, an der er
     * entsteht. „Zu wenig Kontrast" allein liesse den Betreiber raten, um wie
     * viel er danebenliegt und wo.
     *
     * @param  list<string>  $surfaces
     */
    private function refuseUnreadable(string $field, string $colour, array $surfaces): void
    {
        $urteil = BrandSettings::verdict($colour, $surfaces);

        if ($urteil['passes']) {
            return;
        }

        throw ValidationException::withMessages([$field => sprintf(
            'Diese Farbe erreicht auf %s nur %s:1. Der Akzent trägt auch Schrift; verlangt sind %s:1.',
            $urteil['surface'],
            number_format($urteil['ratio'], 2, ',', '.'),
            number_format(Contrast::TEXT, 1, ',', '.'),
        )]);
    }
}
