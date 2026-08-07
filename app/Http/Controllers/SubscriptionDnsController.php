<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Tls\DnsCredentialAccess;
use App\Support\Tls\DnsCredentialInput;
use App\Support\Tls\DnsProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Die DNS-Zugangsdaten eines Abonnements (`docs/34 §5`).
 *
 * **Warum es diese Seite überhaupt geben muss.** Der Betreiber hat
 * `srvpanel dns`; ein Kunde hat keine Shell. Gibt der Plan `dns_edit` frei,
 * führt der Kunde seine Zone selbst und hält den Schlüssel dazu ohnehin in den
 * Händen — ohne Oberfläche käme er ihn nur los, indem er ihn dem Betreiber
 * schickt, und damit wäre die Freigabe im Plan ein Etikett.
 *
 * **Der Profilname kommt aus dem Abonnement und nie aus der Anfrage.** Käme er
 * aus dem Formular, könnte ein Kunde `abo-7` eintragen und die Zone eines
 * anderen bearbeiten lassen. {@see DnsProfile::forSubscription()} leitet ihn
 * ab — dieselbe Haltung wie bei den Verzeichnisnamen der Systembenutzer.
 *
 * **Und es gibt keinen stillen Rückfall.** Ein Abonnement mit Freigabe, das
 * nichts hinterlegt hat, bekommt nicht ersatzweise das Token des Betreibers:
 * Das wäre ein Zugriff auf eine fremde Zone mit einem Schlüssel, der sie
 * womöglich gar nicht öffnet.
 */
final class SubscriptionDnsController extends Controller
{
    public function store(
        Request $request,
        Subscription $subscription,
        DnsCredentialAccess $access,
        DnsProfile $profiles,
        Audit $audit,
    ): RedirectResponse {
        $input = $request->all();

        $provider = DnsCredentialInput::provider($input, $access->usable());

        $stored = $access->store(
            $profiles->forSubscription($subscription),
            $provider,
            DnsCredentialInput::config($input, $provider),
        );

        // Die Zonen stehen im Eintrag, das Geheimnis nie. Ein Prüfprotokoll
        // wird gelesen, exportiert und gesichert; was hier hineingerät, liegt
        // danach an drei Orten mehr.
        $audit->success('dns.credential.stored', $subscription, [
            'profile' => $stored['profile'],
            'provider' => $stored['provider'],
            'zones' => DnsCredentialInput::zones($request->input('zones')),
        ]);

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Die Zugangsdaten sind hinterlegt.');
    }

    public function destroy(
        Subscription $subscription,
        DnsCredentialAccess $access,
        DnsProfile $profiles,
        Audit $audit,
    ): RedirectResponse {
        $profile = $profiles->forSubscription($subscription);

        $access->forget($profile);

        $audit->success('dns.credential.forgotten', $subscription, ['profile' => $profile]);

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Die Zugangsdaten sind entfernt.');
    }
}
