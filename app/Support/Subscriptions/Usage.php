<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\Client;

/**
 * Den belegten Speicher messen und an die Abonnements schreiben.
 *
 * **Eine Messung für alle, nicht eine je Abonnement.** Die Operation
 * `subscription.usage` liest die Quota-Datei des Dateisystems einmal und
 * kennt danach jeden Systembenutzer darin. Bei hundert Abonnements ist das ein
 * Aufruf statt hundert — und der Unterschied ist nicht Geschmack: Hundert
 * Aufrufe je Viertelstunde wären hundert Prozessgründungen auf einem Server,
 * der nebenbei Webseiten ausliefert.
 *
 * **Keine Vorgänge.** Messen ist kein Vorgang: Niemand hat es ausgelöst, es
 * ändert nichts, und es liefe alle fünfzehn Minuten durch das Protokoll und
 * die Vorgangsliste jedes Kunden. Der Aufruf geht deshalb direkt an den
 * Agenten — derselbe Weg, den der Kennzahlensammler nimmt.
 *
 * **Ohne Mandantenklammer, und ausdrücklich.** Der Timer läuft ohne
 * angemeldetes Konto; der Grundzustand der Klammer ist „nichts", und damit
 * fände er kein einziges Abonnement, dessen Verbrauch er schreiben soll.
 * Dieselbe Stelle, derselbe Name wie in {@see Lifecycle::afterSuccess()}.
 */
final class Usage
{
    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Einmal messen und schreiben.
     *
     * @return array{measured: int, available: bool, reason?: string}
     */
    public function measure(): array
    {
        return $this->apply($this->agent->call('subscription.usage'));
    }

    /**
     * Die Antwort des Agenten an die Abonnements schreiben.
     *
     * **Getrennt vom Holen, aus demselben Grund wie beim Kennzahlensammler:**
     * Was hier passiert, ist Zuordnen — welcher Systembenutzer gehört zu
     * welcher Zeile, was passiert mit einem Benutzer, den das Panel nicht
     * kennt, was mit einem Abonnement, das in der Messung fehlt. Solange das
     * hinter einem Socket steckte, wäre es nur mit laufendem Agenten und
     * eingerichteter Quota zu prüfen.
     *
     * @param  array<string, mixed>  $result
     * @return array{measured: int, available: bool, reason?: string}
     */
    public function apply(array $result): array
    {
        if (($result['available'] ?? false) !== true) {
            // **Kein Wert wird zurückgesetzt.** Ohne Quota-Unterstützung weiss
            // das Panel nichts Neues — und „nichts Neues" ist kein Grund, eine
            // Messung von gestern zu verwerfen. Der Zeitstempel daneben sagt
            // ohnehin, wie alt sie ist.
            return [
                'measured' => 0,
                'available' => false,
                'reason' => (string) ($result['reason'] ?? 'kein Grund genannt'),
            ];
        }

        $users = is_array($result['users'] ?? null) ? $result['users'] : [];
        $now = now();

        return $this->tenancy->withoutRestriction(function () use ($users, $now): array {
            $measured = 0;

            // In Schritten und nicht alle auf einmal: Bei hundert Abonnements
            // ist das gleichgültig, bei zehntausend nicht, und der Unterschied
            // kostet hier eine Zeile.
            Subscription::query()
                ->whereNotNull('system_user')
                ->chunkById(200, function ($subscriptions) use ($users, $now, &$measured): void {
                    foreach ($subscriptions as $subscription) {
                        $entry = $users[(string) $subscription->system_user] ?? null;

                        // Ein Abonnement ohne Eintrag hat noch nie etwas
                        // geschrieben — die Quota-Datei kennt einen Benutzer
                        // erst, wenn ihm ein Block gehört. Das ist eine
                        // gemessene Null und keine fehlende Messung.
                        $used = is_array($entry) ? max(0, (int) ($entry['used_mb'] ?? 0)) : 0;

                        $subscription->forceFill([
                            'disk_used_mb' => $used,
                            'disk_usage_measured_at' => $now,
                        ])->saveQuietly();

                        $measured++;
                    }
                });

            return ['measured' => $measured, 'available' => true];
        });
    }
}
