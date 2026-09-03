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
 */
final class SettingsRunLog implements RunLog
{
    public function __construct(private readonly Settings $settings) {}

    public function record(Carbon $ranAt): void
    {
        $this->settings->saveDiagnoseRun($ranAt->toDateTimeString());
    }

    public function lastRunAt(): ?string
    {
        return $this->settings->diagnoseRunAt();
    }
}
