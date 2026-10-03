<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Brand\Logo;
use App\Support\Design\Contrast;
use App\Support\Settings\BrandSettings;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Das Aussehen, das der Betreiber vorgibt — B6, `docs/129 §9`.
 *
 * **Die Absenderadresse steht hier nicht.** Sie gibt es seit P2 auf der Seite
 * „Mailversand"; ein zweites Feld dafür wäre ein zweiter Ort für einen Wert,
 * und der zweite ist der, der veraltet. Diese Seite verweist darauf.
 */
final class BrandingSettingsController extends Controller
{
    public function update(Request $request, Settings $settings, Logo $logo, Audit $audit): Response
    {
        $data = $request->validate([
            /*
             * **Leer ist erlaubt und heisst „die Vorgabe"**, entschieden vom
             * Betreiber am 3. Oktober 2026 (`docs/140 §6d`). Hier stand
             * `required`; wer zurück zur Vorgabe wollte, musste sie abschreiben.
             * Was ein leeres Feld bedeutet, sagt {@see BrandSettings::fromForm()}.
             */
            'name' => ['nullable', 'string', 'max:40'],
            'accent_light' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'accent_dark' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],

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

        /*
         * **Gerechnet wird die Farbe, die gelten wird** — bei einem leeren
         * Feld also die Vorgabe. Die trägt; die Prüfung bleibt trotzdem für
         * jeden Fall dieselbe und kennt keinen, den sie auslässt.
         */
        $eingabe = BrandSettings::fromForm($data, $settings->brand()->logo);

        $this->refuseUnreadable('accent_light', BrandSettings::verdictLight($eingabe->accent_light));
        $this->refuseUnreadable('accent_dark', BrandSettings::verdictDark($eingabe->accent_dark));

        $name = $eingabe->logo;

        /*
         * **Wahr ist, was der Browser als wahr schickt.** Die Seite schickt das
         * Formular als Formulardaten, weil ein Bild dabei sein kann, und darin
         * reist ein Wahrheitswert als Zeichenkette: Inertia 3.6.1 schreibt
         * `"1"`. Die Regel `boolean` lässt das durch, und in `$data` steht
         * danach die Zeichenkette. Hier stand bis zum 1. Oktober 2026
         * `=== true`, und das traf nur einen Prüfstand, der `true` schickt:
         * „Logo entfernen" meldete Erfolg und liess das Logo liegen
         * (`docs/140 §0` Punkt 3). `boolean()` nimmt `"1"` und `true` gleich.
         *
         * > **Dieselbe Regel über einem Wert, der einmal als JSON und einmal als
         * > Zeichenkette reist, gilt nur einmal.**
         */
        if ($request->boolean('remove_logo')) {
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

        $settings->saveBrand(BrandSettings::fromForm($data, $name));

        // Der Name, der gilt — bei einem leeren Feld also die Vorgabe.
        $audit->success('settings.branding', null, ['name' => $eingabe->name]);

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
        /*
         * **Und danach lädt die Seite vollständig neu.** Die Farbe steht im
         * Markenblock im Kopf des Dokuments, und eine Inertia-Antwort tauscht
         * die Seite und lässt den Kopf, wie er war. Bis zum 1. Oktober 2026
         * blieb die Farbe deshalb bis F5 die alte, während die Hinweise darunter
         * schon die neue nannten (`docs/140 §0` Punkt 2).
         *
         * `Inertia::location()` antwortet einer Inertia-Anfrage mit 409 und der
         * Adresse, und der Browser lädt sie ganz. Die Meldung liegt dann schon
         * in der Sitzung und kommt mit dem neuen Laden an. Einer gewöhnlichen
         * Anfrage gibt es die Weiterleitung unverändert zurück.
         *
         * > **Was im Kopf des Dokuments steht, erneuert nur ein volles Laden.**
         */
        return Inertia::location(to_route('settings.general')->with('success', 'Die Marke ist gespeichert.'));
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
     * Die Meldung nennt den **gemessenen** Wert und den Grund, an dem er
     * entsteht. „Zu wenig Kontrast" allein liesse den Betreiber raten, um wie
     * viel er danebenliegt und wo.
     *
     * **Und bei einer Tönung auch den Ort.** Seit dem 3. Oktober 2026 rechnet
     * die Prüfung die getönten Flächen mit (`docs/140 §6c`). Deren Hexwert
     * steht nirgends im Stylesheet — der Browser mischt ihn erst —, und ohne
     * „der Tönung einer Warnung" daneben suchte der Betreiber eine Farbe, die
     * es nur auf dem Bildschirm gibt.
     *
     * @param  array{ratio: float, passes: bool, surface: string, place: string|null}  $urteil
     */
    private function refuseUnreadable(string $field, array $urteil): void
    {
        if ($urteil['passes']) {
            return;
        }

        throw ValidationException::withMessages([$field => sprintf(
            'Diese Farbe erreicht auf %s nur %s:1. Der Akzent trägt auch Schrift; verlangt sind %s:1.',
            $urteil['surface'].($urteil['place'] === null ? '' : ' ('.$urteil['place'].')'),
            number_format($urteil['ratio'], 2, ',', '.'),
            number_format(Contrast::TEXT, 1, ',', '.'),
        )]);
    }
}
