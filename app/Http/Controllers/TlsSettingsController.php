<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Operations\Operations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Das Zertifikat der Oberfläche — ansehen und neu ausstellen.
 *
 * **Warum es diese Seite gibt.** Ein Zertifikat, das man nirgends ansehen
 * kann, läuft ab, ohne dass jemand hinsieht. Der Timer erneuert es zwar von
 * selbst, aber „es müsste eigentlich laufen" ist keine Auskunft — die Seite
 * beantwortet die Frage, die man vor dem Nachsehen im Terminal hat: Welches
 * Zertifikat liefert der Server gerade aus, wie lange noch, und deckt es die
 * Adresse ab, unter der ich gerade hier bin?
 *
 * **Ansehen ist kein Vorgang, Neuausstellen schon.** Das Lesen fragt den
 * Agenten unmittelbar — eine Seite, die bei jedem Aufruf einen verändernden
 * Vorgang ins Protokoll schreibt, öffnet man nicht gern. Das Neuausstellen
 * tauscht das Zertifikat des laufenden Webservers und lädt nginx neu; das
 * gehört in die Warteschlange, mit sichtbarem Verlauf und einer Zeile im
 * Protokoll.
 *
 * Ab P4 kommt Let's Encrypt dazu. Diese Seite ist dann der Ort, an dem steht,
 * welches der beiden gerade gilt — die Angabe `self_signed` beantwortet das
 * schon heute.
 */
final class TlsSettingsController extends Controller
{
    public function show(Client $agent): Response
    {
        try {
            $certificate = $agent->call('panel.tls.info');
        } catch (AgentException $error) {
            // Ein nicht erreichbarer Agent ist kein Fehler dieser Seite. Sie
            // sagt, dass sie nichts weiss, statt eine Fehlerseite zu zeigen —
            // dieselbe Haltung wie in der Übersicht.
            $certificate = ['present' => false, 'reason' => 'Der Agent antwortet nicht: '.$error->getMessage()];
        }

        return Inertia::render('Settings/Tls', ['certificate' => $certificate]);
    }

    /**
     * Neu ausstellen.
     *
     * **Immer mit `force`.** Wer diesen Knopf drückt, hat einen Grund, den das
     * Panel nicht kennt — meistens eine neue Adresse, die im Zertifikat fehlt.
     * Die Prüfung „gilt ja noch" würde genau diesen Fall abweisen.
     */
    public function store(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        $account = $request->user();

        $operation = $operations->dispatch(
            'panel.tls.ensure',
            ['force' => true],
            account: $account instanceof Account ? $account : null,
            message: 'Zertifikat neu ausstellen',
        );

        $audit->success('panel.tls.reissued', context: ['operation' => (int) $operation->id]);

        return redirect()->route('operations.show', $operation);
    }
}
