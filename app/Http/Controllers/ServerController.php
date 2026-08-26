<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Operations\Operations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Ops\SystemReboot;

/**
 * Die Handgriffe an der Maschine selbst.
 *
 * **Vorerst einer: der Neustart.** `docs/81 §11` führt ihn unter A11 zusammen
 * mit der Zeitzone des Servers und NTP und ordnet sie „mit A1, Schritt 7" ein —
 * die Nachbarn landen hier, wenn sie gebaut werden.
 *
 * ## Warum nicht auf der Updates-Seite
 *
 * Weil der Anlass an zwei Stellen steht und die Handlung nur einmal. Die
 * Updates-Seite meldet „Ein Neustart steht aus" (aus `/run/reboot-required`),
 * die Übersicht „ein neuerer Kernel ist installiert" (aus `/boot`) — zwei
 * verschiedene Fragen an zwei verschiedene Quellen, und beide enden bei
 * derselben Handlung. Ein Griff, der nach einer der beiden Seiten benannt wäre,
 * hiesse auf der anderen falsch.
 *
 * > **Ein Knopf, den man sucht, wenn man ihn braucht, steht am falschen Ort.**
 * > (`docs/81 §6`)
 *
 * ## Warum `can:operate-server`
 *
 * `docs/20 §6.1`, erstes Merkmal: Der Neustart betrifft die Maschine und damit
 * jedes Abonnement darauf. Er ist Sache des Betreibers — dieselbe Fähigkeit,
 * unter der PHP-Versionen, Datenbankserver und die Protokolle des Servers
 * stehen.
 */
final class ServerController extends Controller
{
    /**
     * Was eine Seite braucht, um den Neustart anzubieten.
     *
     * **Eine Stelle für zwei Seiten.** Übersicht und Updates zeigen denselben
     * Knopf an zwei verschiedenen Anlässen; bauten beide ihre Angaben selbst
     * zusammen, gäbe es zwei Fassungen — und die zweite ist die, die beim
     * Ändern stehenbleibt.
     *
     * **Und die Wartezeit kommt aus der Operation und nicht aus dem Text.** Sie
     * ist eine Zusage des Agenten; eine Zahl in der deutschen Rückfrage wäre
     * ihre zweite Fassung, und der Betreiber läse „eine Minute", während er
     * zehn Sekunden hat.
     *
     * **Nicht geteilt über `App\Http\Middleware\HandleInertiaRequests`.**
     * {@see Names::host()} liest im ungünstigsten Fall `/etc/hosts` und löst
     * eine Adresse rückwärts auf — das auf **jeder** Anfrage zu tun wäre ein
     * Namensdienst im Weg jeder Seite, für eine Angabe, die zwei Seiten
     * brauchen.
     *
     * @return array{hostname: string, delay: int}
     */
    public static function prompt(): array
    {
        return [
            'hostname' => Names::host(),
            'delay' => SystemReboot::DELAY_SECONDS,
        ];
    }

    /**
     * Den Server neu starten — nach Eingabe seines Namens.
     *
     * ## Warum der Name auch hier geprüft wird
     *
     * Im Browser ist der Knopf abgeschaltet, solange der Name nicht stimmt
     * (`resources/js/Components/Confirmation.vue`). Das ist die **Anzeige**:
     * Sie sagt dem Betreiber, dass er noch nicht fertig ist. Eine Schranke ist
     * sie nicht — wer die Anfrage selbst schickt, sieht keinen Knopf.
     *
     * > **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke,
     * > sondern eine Voreinstellung.**
     *
     * ## Und warum {@see Names::host()} und nicht `php_uname`
     *
     * Weil es genau eine Stelle geben darf, die diese Frage beantwortet —
     * `Names::fqdn()` ist in diesem Projekt viermal neu erfunden worden, und
     * `HostnameSourceTest` gibt es seit dem vierten Mal. `Names::host()`
     * antwortet ausserdem nie leer: Ein Vergleich gegen die leere Zeichenkette
     * wäre eine Bestätigung, die jeder besteht, der nichts eingibt.
     */
    public function reboot(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        $host = Names::host();

        $request->validate(
            ['hostname' => ['required', 'string', Rule::in([$host])]],
            ['hostname.in' => 'Der eingegebene Name ist nicht der Name dieses Servers.'],
        );

        $account = $request->user();

        $operation = $operations->dispatch(
            'system.reboot',
            [],
            account: $account instanceof Account ? $account : null,
            message: 'Server neu starten: '.$host,
        );

        /*
         * **Das Protokoll nennt den Gegenstand und nicht nur die Handlung**
         * (`docs/66`). Beim Neustart ist der Gegenstand der Server selbst — und
         * die Zeile ist die einzige Spur, die den Ausfall später erklärt: Wer
         * hinterher wissen will, warum dieser Server um 03:12 Uhr zwei Minuten
         * fort war, findet hier den Namen des Kontos und die Minute.
         *
         * **Geschrieben wird beim Absetzen und nicht nach dem Erfolg** — wie
         * bei jeder anderen Handlung dieses Panels, die einen Vorgang
         * auslöst. Die Zeile hält damit fest, was jemand angewiesen hat; ob es
         * geklappt hat, steht am Vorgang, dessen Nummer sie mitführt. Gemessen
         * am 26. August 2026 in diesem Container: `server.rebooted` mit
         * `operation: 1`, und Vorgang 1 steht auf `failed`, weil hier kein
         * systemd läuft. Die beiden Auskünfte widersprechen sich nicht — sie
         * beantworten verschiedene Fragen.
         */
        $audit->success('server.rebooted', context: [
            'hostname' => $host,
            'delay' => SystemReboot::DELAY_SECONDS,
            'operation' => (int) $operation->id,
        ]);

        return redirect()->route('operations.show', $operation);
    }
}
