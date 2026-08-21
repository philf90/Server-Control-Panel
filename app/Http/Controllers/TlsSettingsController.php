<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Operations\Operations;
use App\Support\Tls\AcmeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\Acme\Directories;
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
 * **Seit P4 gibt es zwei Zertifikate und nur eines gilt.** Das selbstsignierte
 * ist die Notlösung aus P0 und bleibt als Rückweg liegen; sobald eines von
 * Let's Encrypt daneben liegt, liefert nginx dieses aus. Welches, entscheidet
 * der Agent an einer Stelle, und `panel.tls.info` gibt genau das zurück —
 * `acme` sagt es, `self_signed` beschreibt die Datei. Eine Seite, die statt
 * dessen die Notlösung anzeigt, während der Browser das echte bekommt, schickt
 * den Betreiber auf die Suche nach einem Fehler, den es nicht gibt.
 *
 * **Und hier stehen die beiden Angaben, ohne die nichts bestellt wird.** Bis
 * hierher gab es sie nur auf der Kommandozeile ({@see AcmeSettings}).
 */
final class TlsSettingsController extends Controller
{
    public function show(Client $agent, AcmeSettings $settings): Response
    {
        try {
            $certificate = $agent->call('panel.tls.info');
        } catch (AgentException $error) {
            // Ein nicht erreichbarer Agent ist kein Fehler dieser Seite. Sie
            // sagt, dass sie nichts weiss, statt eine Fehlerseite zu zeigen —
            // dieselbe Haltung wie in der Übersicht.
            $certificate = ['present' => false, 'reason' => 'Der Agent antwortet nicht: '.$error->getMessage()];
        }

        return Inertia::render('Settings/Tls', [
            'certificate' => $certificate,
            'acme' => [
                'contact' => $settings->contact(),
                'directory' => $settings->directory(),
                'staging' => $settings->staging(),
                'configured' => $settings->configured(),
            ],
            // **Kurz genug für ein Auswahlfeld auf dem Telefon.** Ein
            // `<select>` bricht seine Einträge nicht um und zeigt keinen
            // Hinweis darauf, dass etwas fehlt — es schneidet ab. „Produktiv —
            // gültige Zertifikate von Let's Encrypt" endete bei 390px als
            // „… von Let's Encry". Was darüber hinaus zu sagen ist, steht im
            // Hinweis unter dem Feld; der bricht um.
            'directories' => [
                ['value' => Directories::STAGING, 'label' => 'Testbetrieb — kein Browser traut ihnen'],
                ['value' => Directories::PRODUCTION, 'label' => 'Produktiv — gültige Zertifikate'],
            ],
        ]);
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

    /**
     * Die beiden ACME-Angaben setzen.
     *
     * **Die Kontaktadresse wird nicht geraten.** An sie schreibt die
     * Zertifizierungsstelle, wenn ein Zertifikat abzulaufen droht; sie aus dem
     * ersten Adminkonto abzuleiten wäre bequem und falsch. Solange sie fehlt,
     * bestellt das Panel nichts — deshalb ist dieses Formular der Schalter,
     * mit dem TLS überhaupt anfängt.
     *
     * **Geprüft wird gegen dieselbe Positivliste, die der Agent befragt.** Was
     * hier durchkommt, kann dort keine unbekannte Adresse mehr werden
     * ({@see Directories}).
     */
    public function acme(Request $request, AcmeSettings $settings, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'contact' => ['required', 'email', 'max:255'],
            'directory' => ['required', Rule::in(Directories::keys())],
        ], [], [
            /*
             * **Der Name muss heissen wie das Feld auf der Seite** (`docs/66`,
             * Befund 3). „Das Feld Verzeichnis ist ungültig" schickte den Leser
             * in den Dateimanager; auf der Seite heisst dieses Feld
             * „Zertifizierungsstelle", und das ist es auch.
             */
            'directory' => 'Zertifizierungsstelle',
        ]);

        $settings->update([
            'contact' => (string) $data['contact'],
            'directory' => (string) $data['directory'],
        ]);

        // Die Adresse steht im Protokoll, das Verzeichnis auch: Beides
        // entscheidet darüber, was auf diesem Server ausgestellt wird, und wer
        // es geändert hat, gehört dazu.
        $audit->success('panel.acme.settings', context: $data);

        return redirect()->route('settings.tls');
    }
}
