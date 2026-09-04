<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\SubscriptionStatus;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\Client;

/**
 * Der Wartungsmodus — schalten, festhalten, und die Endzeit nachziehen
 * (A12, `docs/101`).
 *
 * ## Zwei Dinge, die nicht dasselbe sind
 *
 * **Schalten ist eine Datei.** `web.maintenance.set` legt sie an oder entfernt
 * sie; nginx liest sie bei jeder Anfrage. Das ist unmittelbar, unteilbar und
 * braucht keinen Reload — gemessen in `docs/81 §2.3p`.
 *
 * **Beschriften ist die Vorlage.** Die voraussichtliche Endzeit steht im
 * Server-Block jeder Domain und kommt über `web.site.apply` dorthin. Sie ändert
 * sich selten, das Schalten oft.
 *
 * Wären beide dasselbe, müsste jedes Umschalten alle Blöcke neu schreiben — und
 * A12 wäre wieder ein Rundlauf mit halb umgestellten Domains.
 *
 * ## Was das an der Reihenfolge kostet, und es steht hier statt in einer Fussnote
 *
 * Ändert sich die Endzeit **und** wird im selben Zug eingeschaltet, ist die
 * Datei sofort da und die Blöcke tragen noch die alte Zeit: Der Rundlauf läuft
 * über die Warteschlange. Für ein paar Sekunden liest ein Besucher die
 * Wartungsseite ohne die neue Angabe.
 *
 * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den
 * > Ausgang dessen, was er abgesetzt hat, nichts** — Form A aus `docs/86 §5`.
 *
 * Das ist bewusst so und keine Nachlässigkeit: Die Alternative wäre, das
 * Einschalten an die Warteschlange zu hängen, und dann dauerte der Griff, der
 * sofort wirken soll, so lange wie der Arbeiter braucht. Eine Wartungsseite mit
 * einer Zeitangabe von vorhin ist der kleinere Schaden als ein Schalter, der
 * hängt.
 */
final class MaintenanceMode
{
    public function __construct(
        private readonly Client $agent,
        private readonly Settings $settings,
        private readonly WebLifecycle $web,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Einschalten oder ausschalten, und die Endzeit dabei setzen.
     *
     * **Der Agent kommt vor dem Festhalten.** Schlägt er fehl, bleibt die
     * Einstellung, wie sie war — sonst behauptete das Panel einen Zustand, den
     * der Server nicht hat. Dieselbe Richtung wie `Lifecycle::afterSuccess()`:
     * Der Zustand folgt dem Agenten und nicht dem Klick.
     *
     * @return array{enabled: bool, resweep: int}
     */
    public function set(bool $enabled, ?string $until): array
    {
        $vorher = $this->settings->maintenance();

        $result = $this->agent->call('web.maintenance.set', ['enabled' => $enabled]);

        // Gelesen wird, was der Agent **gemessen** hat, und nicht, was gefragt
        // war. Er sieht nach dem Schalten an der Datei nach.
        $ist = ($result['enabled'] ?? null) === true;

        $this->settings->saveMaintenance($ist, $until);

        // Die Blöcke tragen die Endzeit, also müssen sie neu geschrieben
        // werden, wenn sie sich ändert — und nur dann.
        $resweep = $vorher['until'] === $until ? 0 : $this->rewrite();

        return ['enabled' => $ist, 'resweep' => $resweep];
    }

    /**
     * Jede lebende Domain neu schreiben, damit sie die Endzeit trägt.
     *
     * **Ohne Mandantenklammer, begründet:** Der Wartungsmodus gilt für den
     * ganzen Server, und der Betreiber hat kein Abonnement. Dieselben Abfragen
     * wie {@see Certificates::rows()}.
     *
     * Aliasse stehen nicht darunter: Sie sind Namen ihrer Eltern und haben
     * keinen eigenen Block.
     */
    private function rewrite(): int
    {
        $gezählt = 0;

        $this->tenancy->withoutRestriction(function () use (&$gezählt): void {
            $nutzbar = array_fill_keys(
                Subscription::query()->whereIn('status', SubscriptionStatus::usableValues())->pluck('id')->all(),
                true,
            );

            $domains = Domain::query()
                ->withoutGlobalScopes()
                ->whereIn('status', [DomainStatus::Active->value, DomainStatus::Suspended->value])
                ->where('type', '!=', DomainType::Alias->value)
                ->orderBy('id')
                ->get();

            /** @var Domain $domain */
            foreach ($domains as $domain) {
                if (! isset($nutzbar[(int) $domain->subscription_id])) {
                    continue;
                }

                // `dispatch` und nicht `apply`: Geändert hat sich eine Textzeile
                // im Server-Block. `apply` schriebe zusätzlich den PHP-Pool neu
                // — ein Neustart je Domain für etwas, das ihn nicht berührt.
                $this->web->dispatch($domain, 'web.site.apply', 'Wartungshinweis für '.$domain->name);
                $gezählt++;
            }
        });

        return $gezählt;
    }
}
