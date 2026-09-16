<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\Backups\Backups;
use App\Support\Backups\Retention;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sichern und abräumen (`docs/117 §6` Schritt 9).
 *
 * `srvpanel-backups.timer` ruft dieses Kommando; von Hand fährt es der
 * Betreiber, wenn er die Aufbewahrung sofort greifen lassen will.
 *
 * ## Abräumen immer, anlegen nur auf Ansage
 *
 * Die beiden Hälften hängen nicht zusammen, und das ist der Grund, dass sie
 * hier getrennt stehen:
 *
 * - **Aufräumen ist Hygiene.** Die Aufbewahrungszahl steht im Plan, und sie
 *   gilt auch für Sicherungen, die ein Kunde von Hand angelegt hat. Sie nur
 *   dann greifen zu lassen, wenn die Automatik an ist, hiesse: Wer nicht
 *   automatisch sichert, hat gar keine Grenze.
 * - **Anlegen ist eine Entscheidung.** `Settings::backups()['automatic']` steht
 *   auf **aus**; ein Update, das für jedes Abonnement nächtliche Sicherungen
 *   anschaltet, füllt den Datenträger, ohne dass jemand gefragt hätte.
 *
 * ## Der Rückgabewert sagt etwas über den Lauf und nicht über den Bestand
 *
 * Ein Abonnement, dessen Sicherung scheitert, ist kein gescheiterter Lauf —
 * sonst stünde die Unit nach dem ersten vollen Datenträger dauerhaft auf
 * `failed`. `1` bedeutet: Der Lauf selbst ist nicht durchgekommen.
 *
 * > **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet,
 * > macht aus dem Boten den Schuldigen.**
 */
final class RunBackups extends Command
{
    protected $signature = 'srvpanel:backups';

    protected $description = 'Legt fällige Sicherungen an und räumt über die Aufbewahrung hinaus ab';

    /**
     * Ab wann eine Sicherung fällig ist.
     *
     * **20 und nicht 24, und die Zahl ist gerechnet.** Der Timer steht auf
     * `OnCalendar=daily` mit zwei Stunden Streuung; zwei aufeinanderfolgende
     * Läufe liegen damit zwischen **22 und 26 Stunden** auseinander. Bei 24
     * fiele jeder Lauf aus, dessen Abstand zum letzten kürzer ausgewürfelt
     * wurde — und zwar still: Es entstünde einfach keine Sicherung.
     *
     * > **Ein Fälligkeitsfenster, das so gross ist wie der Takt, verliert
     * > jeden Lauf, den die Streuung nach vorn zieht.**
     */
    private const DUE_AFTER_HOURS = 20;

    public function handle(
        Settings $settings,
        Retention $retention,
        Backups $backups,
        Tenancy $tenancy,
    ): int {
        $automatisch = $settings->backups()['automatic'];

        /*
         * **Ohne Mandantenklammer, und ohne sie sähe der Lauf nichts.** Ein
         * Zeitgeber hat kein angemeldetes Konto; der Grundzustand der Klammer
         * ist `whereRaw('0 = 1')`, und die leere Liste sähe aus wie „nichts zu
         * tun". Derselbe Befund, den `Cron::store()` in P6 gekostet hat.
         */
        $abos = $tenancy->withoutRestriction(
            static fn (): array => Subscription::query()->with('plan')->orderBy('id')->get()->all(),
        );

        $angelegt = 0;
        $abgeraeumt = 0;
        $fehler = [];

        foreach ($abos as $subscription) {
            try {
                $abgeraeumt += count($retention->prune($subscription));

                if ($automatisch && $this->eligible($subscription, $backups) && $retention->isDue($subscription, self::DUE_AFTER_HOURS)) {
                    $backups->create($subscription);
                    $angelegt++;
                }
            } catch (Throwable $e) {
                // **Gesammelt und nicht geworfen.** Ein Abonnement, dessen
                // Sicherung scheitert, darf die übrigen nicht mitnehmen — sonst
                // hinge die ganze Nacht am ersten vollen Datenträger.
                $fehler[(string) $subscription->name] = $e->getMessage();
            }
        }

        $this->line(sprintf(
            '%s angelegt, %s abgeräumt.',
            $angelegt === 1 ? '1 Sicherung' : sprintf('%d Sicherungen', $angelegt),
            $abgeraeumt === 1 ? '1 Sicherung' : sprintf('%d Sicherungen', $abgeraeumt),
        ));

        if (! $automatisch) {
            // **Gesagt und nicht verschwiegen.** Ein Lauf, der „0 angelegt"
            // meldet, sieht sonst aus wie einer, bei dem nichts fällig war.
            $this->line('Automatische Sicherungen sind ausgeschaltet — es wurde nur abgeräumt.');
        }

        if ($fehler === []) {
            return self::SUCCESS;
        }

        foreach ($fehler as $name => $meldung) {
            $this->error(sprintf('%s: %s', $name, $meldung));
        }

        return self::FAILURE;
    }

    /**
     * Darf für dieses Abonnement automatisch gesichert werden?
     *
     * Drei Bedingungen, und jede hat ihren eigenen Grund:
     *
     * - **Der Plan gibt Sicherungen frei.** Dieselbe Funktion, die auch den
     *   Knopf auf der Seite entscheidet — eine zweite Fassung hier wäre die,
     *   die veraltet.
     * - **Das Abonnement ist aktiv.** Ein gesperrtes hat kein laufendes
     *   Verzeichnis; ein Abonnement im Anlegen hat noch keines.
     * - **Es behält mindestens einen Stand.** Eine Sicherung anzulegen, die der
     *   nächste Griff sofort abräumt, ist Arbeit ohne Ergebnis.
     */
    private function eligible(Subscription $subscription, Backups $backups): bool
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return false;
        }

        $features = $subscription->plan->features ?? [];

        if (($features[Feature::Backups->value] ?? false) !== true) {
            return false;
        }

        /*
         * **Der Schlüssel kommt aus der Aufzählung und nicht als Wort.** Eine
         * Zeichenkette, die auf ein Kontingent zeigt, ohne dass etwas den Bezug
         * prüft, ist die Fehlerklasse, die dieses Repo am häufigsten bezahlt
         * hat — und {@see Retention::keeps()} fragt dasselbe Kontingent zwei
         * Dateien weiter über `Quota::Backups->value`.
         */
        if ((int) ($subscription->quota(Quota::Backups->value) ?? 0) <= 0) {
            return false;
        }

        /*
         * **Und dieselbe Frage wie vor dem Rückbau.** Ein Abonnement ohne
         * Systembenutzer hat kein Verzeichnis; eine Sicherung davon wäre ein
         * Vorgang, der an einem fehlenden Pfad scheitert — hier jede Nacht
         * einer, samt Fehlschlag des Kommandos.
         */
        return $backups->hasDirectory($subscription);
    }
}
