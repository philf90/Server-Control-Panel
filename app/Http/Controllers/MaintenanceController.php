<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use App\Support\Web\MaintenanceMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Wartungsmodus — A12, `docs/101 §6`.
 *
 * ## Warum eine eigene Seite und kein Bereich auf einer anderen
 *
 * `docs/101 §6` sah einen Bereich „auf der Serverseite" vor. Eine solche Seite
 * gibt es nicht: `ServerController` trägt genau eine Handlung, den Neustart,
 * und der sitzt als Knopf auf der Übersicht und auf „Updates".
 *
 * Der Schalter, der **jede Kundenwebsite** vom Netz nimmt, gehört nicht als
 * dritter Abschnitt unter etwas anderes. Dieses Projekt hat den Ort eines
 * Menüpunkts dreimal falsch gehabt — Dateimanager, SFTP-Zugang, „Job anlegen" —
 * und jedes Mal hat es der Betreiber gemeldet und kein Test.
 *
 * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
 * > dort?**
 *
 * ## Warum „Betrieb" und nicht „Einstellungen"
 *
 * Die Gruppe entscheidet die Adresse, und `NavGroupTest` hält es: Was unter
 * `/settings/…` liegt, ist eine Einstellung. Der Wartungsmodus ist keine — er
 * sagt, was **jetzt** auf diesem Server geschieht, wie „Dienste" daneben.
 */
final class MaintenanceController extends Controller
{
    public function index(Settings $settings): Response
    {
        $stand = $settings->maintenance();

        /*
         * **Die Anzeige rechnet um, die Ablage nicht.** Gespeichert ist UTC;
         * was hier hinausgeht, ist Ortszeit — und zwar über {@see Clock} und
         * nicht über eine zweite Umrechnung daneben. `docs/40` hat einmal
         * gekostet, dass die Hälfte, die still bricht, die mitrechnende Grenze
         * ist.
         *
         * **Zerlegt wird der fertige Anzeigewert und nicht der abgelegte.** Die
         * Umrechnung bleibt damit an einer Stelle; hier steht nur noch ein
         * Schnitt an dem Leerzeichen, das `Clock::minute()` selbst setzt.
         */
        $lokal = Clock::minute($stand['until']);
        $teile = $lokal === null ? ['', ''] : explode(' ', $lokal, 2);

        return Inertia::render('Maintenance/Index', [
            'maintenance' => [
                'enabled' => $stand['enabled'],
                'until_date' => $teile[0],
                'until_time' => $teile[1] ?? '',
                'zone' => Clock::label(),
            ],
        ]);
    }

    /**
     * Schalten.
     *
     * ## Kein Vorgang, und das ist der Zuschnitt
     *
     * Der Neustart legt einen Vorgang an, weil er dauert und weil sein Ausgang
     * später jemanden interessiert. Das Schalten dauert nicht: Es legt eine
     * Datei an oder entfernt sie, nginx liest sie bei der nächsten Anfrage, und
     * der Agent sieht unmittelbar nach. Ein Vorgang mit „wartet · läuft ·
     * fertig" für zwei Millisekunden wäre eine Anzeige über nichts.
     */
    public function update(Request $request, MaintenanceMode $mode, Audit $audit): RedirectResponse
    {
        $daten = $request->validate([
            'enabled' => ['required', 'boolean'],

            /*
             * **Zwei Felder und nicht eines** — gemeldet vom Betreiber am
             * 4. September 2026, auf dem Telefon.
             *
             * Der erste Wurf war ein Textfeld für `Y-m-d H:i` mit
             * `inputmode="numeric"`. Die Zifferntastatur von iOS gibt weder
             * Bindestrich noch Doppelpunkt noch Leerzeichen her: Das Feld war
             * dort nicht ausfüllbar, und zwar gar nicht — nicht bloss
             * umständlich.
             *
             * > **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon
             * > nicht tippbar** — und `datetime-local` gibt es nicht her, denn
             * > sein Wert trägt ein `T` statt des Leerzeichens.
             *
             * `date` und `time` sind die beiden Typen, die es hergeben, und
             * Safari zeigt das Datum von sich aus deutsch. Auf `/audit` steht
             * dasselbe Paar seit P2 richtig da — **die Vermeidung war nur nie
             * die Regel geworden**; jetzt hält es {@see \Tests\Unit\DateInputTest}.
             *
             * **`date_format` und nicht `date`.** Die Angabe reist bis in eine
             * nginx-Zeichenkette; der Agent prüft sie dort ein zweites Mal
             * gegen dieselbe Form. Zwei Prüfungen sind keine Verdopplung,
             * sondern die beiden Seiten einer Grenze — die zweite steht im
             * Code, der als root läuft, und der verlässt sich auf niemanden.
             *
             * **Beide oder keines**, über `required_with` in beide Richtungen:
             * Ein Datum ohne Uhrzeit ergäbe „ab 2026-09-04 Uhr".
             *
             * **Eine Zeit in der Vergangenheit ist erlaubt.** Sie ist eine
             * Auskunft und keine Steuerung; wer sich verschätzt hat, soll sie
             * nicht erst korrigieren müssen. Dass sie überschritten ist, meldet
             * die Bestandsdiagnose.
             */
            'until_date' => ['nullable', 'required_with:until_time', 'date_format:Y-m-d'],
            'until_time' => ['nullable', 'required_with:until_date', 'date_format:H:i'],
        ], [
            'until_date.date_format' => 'Das Datum muss die Form JJJJ-MM-TT haben.',
            'until_time.date_format' => 'Die Uhrzeit muss die Form HH:MM haben.',
        ]);

        $enabled = (bool) $daten['enabled'];
        $datum = $daten['until_date'] ?? null;
        $zeit = $daten['until_time'] ?? null;

        // Zusammengesetzt wird hier und nicht in der Seite: Das Format, das
        // hinausgeht, ist eine Eigenschaft dieser Grenze, und die Seite hätte
        // sonst eine zweite Fassung davon.
        $until = is_string($datum) && is_string($zeit) && $datum !== '' && $zeit !== ''
            ? Clock::minuteToUtc($datum.' '.$zeit)
            : null;

        $ergebnis = $mode->set($enabled, $until);

        $audit->success('server.maintenance', context: [
            'enabled' => $ergebnis['enabled'],
            'until' => $until,
            'domains' => $ergebnis['resweep'],
        ]);

        return redirect()->route('maintenance')->with('success', $ergebnis['enabled']
            ? 'Der Wartungsmodus ist eingeschaltet. Alle Kundenwebsites antworten mit 503.'
            : 'Der Wartungsmodus ist ausgeschaltet.');
    }
}
