<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Dns\Dns;
use App\Support\Dns\ServerAddresses;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function show(Dns $dns): Response
    {
        return Inertia::render('Settings/General', [
            /*
             * **Beide Listen, und das ist keine Bequemlichkeit**
             * (`docs/72 §2.1a`). Eine übersteuerte Adresse ist eine im Panel
             * gemerkte Fassung eines Serverzustands und kann veralten; wer nur
             * das Ergebnis zeigt, macht aus einer alten Eintragung eine
             * falsche Auskunft über jede Domain.
             *
             * Gefragt wird {@see Dns::addresses()} und nicht `Names` und
             * `Settings` einzeln — sonst stünde die Zusammenführung an zwei
             * Stellen, und die zweite ist die, die veraltet.
             */
            'addresses' => $dns->addresses(),

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

    public function update(Request $request, Audit $audit, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],

            /*
             * **Ein Textfeld und keine Liste im Rumpf.** Der Betreiber tippt
             * hier ein bis zwei Adressen ab; ein Feld je Adresse mit einem
             * Knopf zum Hinzufügen wäre mehr Oberfläche als Sache.
             *
             * Geprüft wird jede Zeile einzeln über {@see ServerAddresses::rejected()}
             * — dieselbe Stelle, die auch die abgeleiteten Adressen siebt. Zwei
             * Fassungen derselben Frage wären zwei Antworten, sobald jemand
             * eine der beiden ändert.
             */
            'dns_addresses' => ['nullable', 'string', 'max:1000'],
        ]);

        $adressen = self::adressen($data['dns_addresses'] ?? '');

        if ($adressen instanceof MessageBag) {
            throw ValidationException::withMessages(['dns_addresses' => $adressen->first()]);
        }

        Clock::store($data['timezone']);

        /*
         * **Leer räumt die Übersteuerung ab** und ist damit kein Sonderfall,
         * sondern der Normalfall: „nimm die abgeleiteten" (`docs/72 §2.1a`).
         */
        $settings->saveDnsAddresses($adressen);

        // Die Zone steht im Protokoll, weil sie ändert, wie jeder andere
        // Eintrag darin gelesen wird — auch die von gestern.
        $audit->success('settings.timezone', null, ['timezone' => $data['timezone']]);

        /*
         * **Die Adressen stehen mit im Protokoll**, und zwar die eingetragenen.
         * Sie entscheiden, was der Abgleich für jede Domain als Sollwert
         * ausgibt — wer später eine falsche Meldung untersucht, will wissen,
         * seit wann sie so lautet.
         */
        $audit->success('settings.dns_addresses', null, ['addresses' => $adressen]);

        return redirect()->route('settings.general')->with('status', 'Die Anzeigezone ist jetzt '.Clock::label().'.');
    }

    /**
     * Die eingetippten Adressen — oder die Beanstandung dazu.
     *
     * **Eine Zeile je Adresse, und Leerraum trennt.** Wer sie mit Komma
     * trennt, bekommt sie trotzdem auseinander; wer eine leere Zeile stehen
     * lässt, bekommt keine Beanstandung über eine leere Adresse.
     *
     * @return list<string>|MessageBag
     */
    private static function adressen(string $eingabe): array|MessageBag
    {
        $adressen = [];

        foreach (preg_split('/[\s,]+/', trim($eingabe)) ?: [] as $zeile) {
            if ($zeile === '') {
                continue;
            }

            $grund = ServerAddresses::rejected($zeile);

            if ($grund !== null) {
                return new MessageBag(['dns_addresses' => [$zeile.': '.$grund]]);
            }

            $adressen[] = $zeile;
        }

        return $adressen;
    }
}
