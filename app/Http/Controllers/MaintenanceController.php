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

        return Inertia::render('Maintenance/Index', [
            'maintenance' => [
                'enabled' => $stand['enabled'],

                /*
                 * **Die Anzeige rechnet um, die Ablage nicht.** Gespeichert ist
                 * UTC; was hier hinausgeht, ist Ortszeit — und zwar über
                 * {@see Clock} und nicht über eine zweite Umrechnung daneben.
                 * `docs/40` hat einmal gekostet, dass die Hälfte, die still
                 * bricht, die mitrechnende Grenze ist.
                 */
                'until' => Clock::minute($stand['until']),
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
             * **`date_format` und nicht `date`.** Die Angabe reist bis in eine
             * nginx-Zeichenkette; der Agent prüft sie dort ein zweites Mal
             * gegen dieselbe Form. Zwei Prüfungen sind keine Verdopplung,
             * sondern die beiden Seiten einer Grenze — die zweite steht im
             * Code, der als root läuft, und der verlässt sich auf niemanden.
             *
             * **Eine Zeit in der Vergangenheit ist erlaubt.** Sie ist eine
             * Auskunft und keine Steuerung; wer sich verschätzt hat, soll sie
             * nicht erst korrigieren müssen. Dass sie überschritten ist, meldet
             * die Bestandsdiagnose.
             */
            'until' => ['nullable', 'date_format:Y-m-d H:i'],
        ], [
            'until.date_format' => 'Die voraussichtliche Endzeit muss die Form JJJJ-MM-TT HH:MM haben.',
        ]);

        $enabled = (bool) $daten['enabled'];
        $eingabe = $daten['until'] ?? null;
        $until = is_string($eingabe) && $eingabe !== '' ? Clock::minuteToUtc($eingabe) : null;

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
