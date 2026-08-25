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
        $data = $request->validate([
            'networks' => ['present', 'array', 'max:64'],
            'networks.*' => ['required', 'string', 'max:64'],
        ]);

        /** @var list<string> $raw */
        $raw = array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $data['networks']),
            static fn (string $v): bool => $v !== '',
        ));

        $networks = [];

        foreach ($raw as $index => $entry) {
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
                throw ValidationException::withMessages([
                    'networks.'.$index => $error->getMessage(),
                ]);
            }
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
