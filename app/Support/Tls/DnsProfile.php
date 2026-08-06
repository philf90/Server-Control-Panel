<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Feature;

/**
 * Unter welchem Profil die Zugangsdaten für eine Zone liegen.
 *
 * **Der Name wird abgeleitet und nicht entgegengenommen.** Käme er aus einer
 * Anfrage, könnte ein Kunde das Profil eines anderen nennen — und damit dessen
 * Zone bearbeiten lassen. Dieselbe Haltung wie bei den Verzeichnisnamen der
 * Systembenutzer: Was zu einem Pfad wird, entsteht aus dem Bestand.
 *
 * **Die Unterscheidung gibt es in diesem Panel schon.** `Feature::DnsEdit`
 * trägt seit den Plänen den Hinweistext „Ohne diese Freigabe verwaltet der
 * Betreiber die Zone; das Abonnement sieht sie nur." Genau diese Frage ist es
 * (`docs/34 §5`):
 *
 * - **Plan mit der Freigabe:** Das Abonnement führt seine Zone selbst und hält
 *   den Schlüssel dazu ohnehin in den Händen — es hinterlegt sein eigenes
 *   Profil.
 * - **Plan ohne:** Es gilt das Profil des Betreibers. Der Kunde hinterlegt
 *   nichts; das Token gehört dem, der die Zone führt.
 *
 * **Und keinen stillen Rückfall.** Ein Abonnement mit Freigabe, das noch nichts
 * hinterlegt hat, bekommt nicht ersatzweise das Token des Betreibers: Das wäre
 * ein Zugriff auf eine fremde Zone mit einem Schlüssel, der sie womöglich gar
 * nicht öffnet — und die Fehlermeldung dazu käme vom Anbieter und nicht von
 * hier. Der Agent sagt dann „für dieses Profil sind keine Zugangsdaten
 * hinterlegt", und das ist die Auskunft, die weiterhilft.
 */
final class DnsProfile
{
    /** Das Profil des Betreibers — für alles, was er selbst führt. */
    public const OPERATOR = 'betrieb';

    /** Der Vorsatz für ein Abonnement, das seine Zone selbst verwaltet. */
    public const SUBSCRIPTION_PREFIX = 'abo-';

    public function forDomain(Domain $domain): string
    {
        return $this->forSubscription($domain->subscription);
    }

    public function forSubscription(?Subscription $subscription): string
    {
        if (! $subscription instanceof Subscription) {
            return self::OPERATOR;
        }

        if (! $subscription->feature(Feature::DnsEdit->value)) {
            return self::OPERATOR;
        }

        return self::SUBSCRIPTION_PREFIX.$subscription->id;
    }
}
