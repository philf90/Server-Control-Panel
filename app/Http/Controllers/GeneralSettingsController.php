<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Time\Clock;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Was für den ganzen Server gilt und zu keinem Dienst gehört.
 *
 * **Warum es diese Seite gibt.** `docs/40` verlangt „ein Feld in
 * Einstellungen", und es gab keinen Ort dafür: Die fünf vorhandenen Seiten sind
 * themengebunden — PHP, Datenbankserver, Mailversand, Zertifikat, DNS —, und
 * das Profil gehört einem Konto. Die Anzeigezone ist serverweit
 * (`docs/40 §3.1`) und hat mit keinem Dienst zu tun.
 *
 * **Eine Seite mit einem Feld ist wenig, und das ist in Ordnung.** Der Ort für
 * serverweite Anzeigeeinstellungen fehlte; ihn beim ersten Bedarf anzulegen ist
 * billiger, als das Feld irgendwo unterzubringen, wo es niemand sucht.
 */
final class GeneralSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/General', [
            'timezone' => Clock::zone(),
            'label' => Clock::label(),

            /*
             * **Die Liste kommt vom Server und nicht aus einem Freitextfeld.**
             * Der Wert geht in `CarbonInterface::setTimezone()`, und ein
             * unbekannter Name wirft dort — mitten im Aufbau einer Seite. Die
             * Auswahl verhindert den Tippfehler, statt ihn zu behandeln
             * (`docs/40 §4`).
             */
            'zones' => DateTimeZone::listIdentifiers(),

            'example' => [
                'utc' => now()->format('Y-m-d H:i:s'),
                'display' => Clock::display(now()),
            ],
        ]);
    }

    public function update(Request $request, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        Clock::store($data['timezone']);

        // Die Zone steht im Protokoll, weil sie ändert, wie jeder andere
        // Eintrag darin gelesen wird — auch die von gestern.
        $audit->success('settings.timezone', null, ['timezone' => $data['timezone']]);

        return redirect()->route('settings.general')->with('status', 'Die Anzeigezone ist jetzt '.Clock::label().'.');
    }
}
