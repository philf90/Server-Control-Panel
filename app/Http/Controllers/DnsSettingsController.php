<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Tls\DnsCredentialAccess;
use App\Support\Tls\DnsCredentialInput;
use App\Support\Tls\DnsProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die DNS-Zugangsdaten des Betreibers — ansehen, hinterlegen, entfernen.
 *
 * **Warum es diese Seite gibt.** Bis hierher ging das nur über
 * `srvpanel dns` (`docs/34`, Schritt 6b). Für den Betreiber ist die
 * Kommandozeile oft der bequemere Weg — wer einen Server einrichtet, hat den
 * Schlüssel gerade im Terminal —, aber es gibt keinen Ort, an dem man
 * *nachsieht*, was hinterlegt ist. „Es müsste eigentlich liegen" ist keine
 * Auskunft, und die Frage stellt sich genau dann, wenn eine Bestellung
 * gescheitert ist.
 *
 * **Und die Zonen stehen hier, weil sie die häufigste Ursache sind.** Ein
 * TSIG-Schlüssel ist im Nameserver ohnehin auf Zonen eingegrenzt, und die
 * Liste in den Zugangsdaten ist eine Positivliste: Was nicht daraufsteht, wird
 * gar nicht erst versucht. Fehlt eine Zone, sagt der Agent das beim Bestellen —
 * und dann ist ein Fehlversuch verbraucht, der bei Let's Encrypt für **jeden**
 * Kunden dieses Servers zählt. Hier steht sie vorher.
 *
 * **Das Geheimnis kommt nie zurück.** Weder ganz noch als letzte vier Zeichen:
 * Bei einem kurzen Token ist das ein spürbarer Teil davon, und der Gewinn wäre
 * eine Bequemlichkeit beim Wiedererkennen. Wer wissen will, ob das richtige
 * hinterlegt ist, hinterlegt es neu (`docs/34 §5`).
 */
final class DnsSettingsController extends Controller
{
    public function show(DnsCredentialAccess $access): Response
    {
        return Inertia::render('Settings/Dns', [
            'profile' => DnsProfile::OPERATOR,
            'credential' => $access->describe(DnsProfile::OPERATOR),
            'providers' => $access->providers(),
        ]);
    }

    /**
     * Zugangsdaten hinterlegen.
     *
     * Welche Felder gelten und was davon der Agent prüft, steht in
     * {@see DnsCredentialInput} — dieselben Regeln gelten am Abonnement.
     */
    public function store(Request $request, DnsCredentialAccess $access, Audit $audit): RedirectResponse
    {
        $input = $request->all();

        $stored = $access->store(
            DnsProfile::OPERATOR,
            DnsCredentialInput::provider($input, $access->usable()),
            DnsCredentialInput::config($input),
        );

        // **Der Eintrag nennt die Zonen und nie das Geheimnis.** Ein
        // Prüfprotokoll wird gelesen, exportiert und gesichert; was hier
        // hineingerät, ist danach an drei Orten mehr.
        $audit->success('dns.credential.stored', context: [
            'profile' => $stored['profile'],
            'provider' => $stored['provider'],
            'zones' => DnsCredentialInput::zones($request->input('zones')),
        ]);

        return redirect()->route('settings.dns')
            ->with('success', 'Die Zugangsdaten sind hinterlegt.');
    }

    public function destroy(DnsCredentialAccess $access, Audit $audit): RedirectResponse
    {
        $access->forget(DnsProfile::OPERATOR);

        $audit->success('dns.credential.forgotten', context: ['profile' => DnsProfile::OPERATOR]);

        return redirect()->route('settings.dns')
            ->with('success', 'Die Zugangsdaten sind entfernt.');
    }
}
