<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;

/**
 * {@see RunLog} über die Einstellungen — dort, wo auch die anderen Zeitpunkte
 * dieses Panels liegen.
 *
 * Kein eigener Tisch für einen einzigen Wert: `Settings` führt seit P1 die
 * Zeitpunkte der PHP-Fassungsmessung und der Quota-Prüfung, und diese Angabe
 * ist von derselben Art.
 *
 * ## Warum der Schlüssel von aussen kommt
 *
 * Seit P8 Schritt 6 gibt es **zwei** Nachtläufe: den der Bestandsdiagnose und
 * den, der die Sicherungen prüft (`docs/117 §13`). Teilten sie sich einen
 * Zeitstempel, stünde auf der Diagnoseseite der des zuletzt gefahrenen, und
 * „zuletzt gemessen" wäre für die Hälfte der Befunde falsch.
 *
 * > **Zwei Läufe, die sich einen Zeitstempel teilen, sagen beide die Wahrheit
 * > über den letzten von beiden und über keinen etwas Verlässliches.**
 *
 * Der Schlüssel ist trotzdem **kein freier Text**: {@see Settings::RUN_KEYS} ist
 * die Positivliste, und ein unbekannter Wert wirft. Ein Tippfehler legte sonst
 * wortlos einen dritten an, der für immer nach „noch nie gemessen" aussähe.
 */
final class SettingsRunLog implements RunLog
{
    public function __construct(
        private readonly Settings $settings,
        private readonly string $key = Settings::DIAGNOSE,
    ) {}

    public function record(Carbon $ranAt): void
    {
        $this->settings->saveDiagnoseRun($ranAt->toDateTimeString(), $this->key);
    }

    public function lastRunAt(): ?string
    {
        return $this->settings->diagnoseRunAt($this->key);
    }
}
