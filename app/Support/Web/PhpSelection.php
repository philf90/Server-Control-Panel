<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use SrvPanel\Agent\PhpVersions;

/**
 * Welche PHP-Version darf ein Abonnement wählen?
 *
 * **Drei Mengen, und sie werden auseinandergehalten:**
 *
 * | Menge | Wo sie steht | Wer sie ändert |
 * |---|---|---|
 * | Katalog | {@see PhpVersions::CATALOG} | eine neue Version des Panels |
 * | installiert | auf dem Server, gemessen über `php.versions` | der Betreiber, über einen Vorgang |
 * | vom Plan erlaubt | `Quota::PhpVersions` | der Betreiber, im Plan |
 *
 * Wählbar ist der **Schnitt aus allen dreien**, und diese Rechnung steht
 * genau hier. Stünde sie zusätzlich im Formular, gäbe es zwei Antworten auf
 * dieselbe Frage — und die im Formular wäre die freundlichere.
 *
 * **Der Kunde stellt keine Anforderung.** Installiert wird von einem Admin,
 * über `/settings/php`. Was der Kunde sieht, ist ein Zustand: seine wählbaren
 * Versionen, und daneben die, die sein Plan zwar hergibt, die es auf dem
 * Server aber nicht gibt — abgeblendet, mit dem Grund. Er sieht damit, dass
 * die Lücke am Server liegt und nicht an seinem Vertrag. Ein Knopf
 * „anfordern" wäre ein halber Ticketkanal: Der Kunde drückt, sichtbar
 * passiert nichts, und niemand ist zuständig.
 *
 * **Warum die installierten Versionen aus dem Zwischenspeicher kommen.** Die
 * Frage stellt sich bei jedem Domainformular. Sie jedes Mal über den Socket zu
 * stellen hiesse, dass eine Seite des Panels nicht mehr lädt, wenn der Agent
 * steht — für eine Auskunft, die sich ändert, wenn ein Admin etwas
 * installiert, und sonst nie. Geschrieben wird der Zwischenspeicher nach jedem
 * Lauf von `php.versions` und nach jeder Installation.
 */
final class PhpSelection
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Was auf diesem Server liegt — aus dem Zwischenspeicher.
     *
     * **Ein leerer Zwischenspeicher heisst „nichts installiert" und nicht
     * „alles erlaubt".** Das ist die sichere Richtung: Vor dem ersten Lauf von
     * `php.versions` weiss das Panel es nicht, und eine Domain mit einer
     * Version anzulegen, die es nicht gibt, endet in einem Server-Block, den
     * der Agent zurückweist. Die Oberfläche sagt dann, dass der Betreiber
     * zuerst nachsehen muss.
     *
     * @return list<string>
     */
    public function installed(): array
    {
        return $this->settings->phpVersions();
    }

    /**
     * Was der Plan hergibt — mit den Übersteuerungen des Abonnements.
     *
     * @return list<string>
     */
    public function allowedByPlan(Subscription $subscription): array
    {
        $allowed = $subscription->quota(Quota::PhpVersions->value);

        if (! is_array($allowed)) {
            return [];
        }

        return array_values(array_filter(
            PhpVersions::CATALOG,
            static fn (string $version): bool => in_array($version, $allowed, true),
        ));
    }

    /**
     * Der Schnitt: was dieses Abonnement wirklich wählen kann.
     *
     * @return list<string>
     */
    public function selectableFor(Subscription $subscription): array
    {
        $installed = $this->installed();

        return array_values(array_filter(
            $this->allowedByPlan($subscription),
            static fn (string $version): bool => in_array($version, $installed, true),
        ));
    }

    /**
     * Die Vorgabe für eine neue Domain: die neueste wählbare Version.
     *
     * `null`, wenn es keine gibt — dann liefert die Domain nichts aus, und das
     * ist eine Auskunft, die der Dienst weitergeben muss statt sie mit einer
     * beliebigen Version zu überdecken.
     */
    public function defaultFor(Subscription $subscription): ?string
    {
        $selectable = $this->selectableFor($subscription);

        return $selectable === [] ? null : $selectable[count($selectable) - 1];
    }

    public function isSelectable(Subscription $subscription, string $version): bool
    {
        return in_array($version, $this->selectableFor($subscription), true);
    }

    /**
     * Was die Oberfläche anzeigt — samt Grund, warum etwas nicht geht.
     *
     * Versionen, die der Plan nicht hergibt, stehen **nicht** darin: Das ist
     * Vertragssache und gehört auf die Planseite, nicht in ein technisches
     * Auswahlfeld.
     *
     * @return list<array{version: string, selectable: bool, reason: string|null}>
     */
    public function optionsFor(Subscription $subscription): array
    {
        $installed = $this->installed();
        $options = [];

        foreach ($this->allowedByPlan($subscription) as $version) {
            $selectable = in_array($version, $installed, true);

            $options[] = [
                'version' => $version,
                'selectable' => $selectable,
                'reason' => $selectable ? null : 'auf diesem Server nicht installiert',
            ];
        }

        return $options;
    }

    /**
     * Den Zwischenspeicher nachziehen — nach `php.versions` und nach jeder
     * Installation.
     *
     * Was nicht im Katalog steht, wird verworfen: Der Agent antwortet über
     * dieselbe Liste, aber ein alter Agent neben einem neuen Panel ist genau
     * die Lage, in der eine unbekannte Version ankommt.
     *
     * @param  list<string>  $versions
     */
    public function remember(array $versions): void
    {
        $this->settings->savePhpVersions(array_values(array_filter(
            PhpVersions::CATALOG,
            static fn (string $version): bool => in_array($version, $versions, true),
        )));
    }
}
