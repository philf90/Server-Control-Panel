<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Authorization\AdminNetwork;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;

/**
 * Der Zugang zum Panel — aus welchen Netzen sich ein Verwaltungskonto anmelden
 * darf.
 *
 * **`can:operate-server`, entschieden beim Bauen.** Wer diese Liste ändert,
 * entscheidet, wer überhaupt an den Server kommt — das ist Merkmal 2 aus
 * `docs/20 §6.1` („es nimmt alle Kunden mit"), und zwar in seiner schärfsten
 * Form: Eine falsche Zeile hier sperrt jeden Administrator aus, und zurück
 * kommt man nur über SSH.
 *
 * **Nur Adminkonten sind betroffen** (`docs/82 §2.5`). Ein Kunde, der sich aus
 * dem Urlaub nicht anmelden kann, ist ein Ausfall; ein Betreiber, der sein
 * Panel auf sein Büronetz beschränkt, weiss, was er tut.
 */
final class AccessSettingsController extends Controller
{
    public function show(Request $request, Settings $settings): Response
    {
        $networks = $settings->adminNetworks();

        return Inertia::render('Settings/Access', [
            'networks' => $networks,

            /*
             * **Die eigene Adresse steht auf der Seite**, und das ist kein
             * Beiwerk: Ohne sie schreibt der Betreiber ein Netz hin und erfährt
             * erst beim Speichern, dass es ihn nicht enthält. Mit ihr sieht er
             * vorher, was er treffen muss.
             */
            'address' => $request->ip(),

            // Deckt die gespeicherte Liste den Betrachter gerade? Bei leerer
            // Liste ist die Frage gegenstandslos — dann gilt keine Schranke.
            'covered' => $networks === [] || AdminNetwork::covers($networks, $request->ip()),
        ]);
    }

    public function update(Request $request, Settings $settings, Audit $audit): RedirectResponse
    {
        /*
         * **Leere Zeilen sind erlaubt, und das ist Befund 10 aus `docs/84`.**
         *
         * Bis zum 25. August 2026 warf die Oberfläche die leeren Zeilen vor dem
         * Absenden weg, und der Fehlerschlüssel `networks.<n>` zählte über die
         * **gefilterte** Liste. Sobald irgendwo davor eine leere Zeile stand,
         * zeigte der rote Rand auf eine andere Zeile als die, die falsch war.
         *
         * > **Eine Kennung, die auf eine Liste zeigt, die der Browser nicht
         * > hat, zeigt auf die falsche Zeile.**
         *
         * Jetzt schickt das Formular seine Zeilen, wie sie dastehen — auch die
         * leere zum Tippen —, und der Index bedeutet auf beiden Seiten
         * dasselbe. `max:64` bezieht sich damit auf **Zeilen** und nicht mehr
         * auf Netze; es war nie ein Produktversprechen, sondern der Deckel für
         * eine zu grosse Anfrage.
         */
        $data = $request->validate([
            'networks' => ['present', 'array', 'max:64'],
            'networks.*' => ['nullable', 'string', 'max:64'],
        ]);

        $networks = [];
        $refusals = [];

        foreach ($data['networks'] as $index => $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            try {
                /*
                 * **Prüfung und Politik stehen in {@see AdminNetwork}** und
                 * nicht hier: `srvpanel access --add` stellt dieselbe Frage,
                 * und zwei Fassungen liefen auseinander.
                 *
                 * > **Zwei Eingänge zu derselben Einstellung teilen ihre
                 * > Prüfung, oder die Einstellung hat zwei Bedeutungen.**
                 */
                $networks[] = AdminNetwork::normalize($entry);
            } catch (AgentException $error) {
                /*
                 * **Gesammelt und nicht geworfen.** Hier stand ein `throw` im
                 * Schleifenrumpf: Der erste schlechte Eintrag beendete die
                 * Prüfung, alles darunter wurde nie angesehen — und der Kunde
                 * bekam seine Liste in so vielen Runden zurück, wie sie Fehler
                 * hatte.
                 *
                 * `srvpanel access` steigt weiterhin beim ersten aus, und das
                 * ist dort richtig: An einer Kommandozeile steht ein Argument.
                 *
                 * > **Zwei Eingänge, die dieselbe Prüfung teilen, teilen darum
                 * > noch nicht dieselbe Meldung — eine Liste hat mehr Fehler
                 * > als eine Kommandozeile.**
                 */
                $refusals['networks.'.$index] = $error->getMessage();
            }
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        $networks = array_values(array_unique($networks));

        /*
         * **Der Aussperrschutz** (`docs/82 §7`, Schritt 7): *eine
         * IP-Beschränkung, die ihren eigenen Urheber nicht aussperrt.*
         *
         * Gefragt wird mit der Liste, die gespeichert werden **soll** — nicht
         * mit der, die gespeichert ist. Eine leere Liste hebt die Beschränkung
         * auf und kann niemanden aussperren.
         */
        if ($networks !== [] && ! AdminNetwork::covers($networks, $request->ip())) {
            throw ValidationException::withMessages([
                'networks' => AdminNetwork::refusal($request->ip()),
            ]);
        }

        $settings->saveAdminNetworks($networks);

        $audit->success('settings.access', null, [
            'networks' => $networks === [] ? 'keine Beschränkung' : implode(', ', $networks),
        ]);

        return redirect()->route('settings.access')->with('success', $networks === []
            ? 'Die Anmeldung ist wieder von überall möglich.'
            : 'Die Netze sind gespeichert.');
    }
}
