<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Checks\Agent;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\Checks\MaintenanceWindow;
use App\Support\Diagnose\Checks\ManagedBlocks;
use App\Support\Diagnose\Checks\Orphans;
use App\Support\Diagnose\Checks\SystemUsers;
use App\Support\Diagnose\Checks\Units;

/**
 * Welche Prüfungen ein Nachtlauf fährt (A10 Schritt 6).
 *
 * ## Warum es diese Liste gibt
 *
 * Aus demselben Grund wie {@see \SrvPanel\Agent\Catalog} für die Units: Damit
 * es **einen** Ort gibt, an dem steht, was gefahren wird. Ohne sie stünde die
 * Aufzählung im Kommando, und die Seite, die zeigt, wann zuletzt gemessen
 * wurde, hätte eine zweite — und die zweite ist die, die veraltet.
 *
 * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
 * > beiden ist der Ort, an dem man nachsieht.**
 *
 * ## Die Reihenfolge ist keine Zusage
 *
 * Jede Prüfung schreibt ihre eigenen Schlüssel, und keine liest, was eine
 * andere geschrieben hat. Sie stehen hier trotzdem in einer festen Ordnung:
 * `DiagnoseRunTest` prüft, dass jeder Schlüssel des Katalogs genau einen
 * Schreiber hat, und eine feste Reihenfolge macht die Ausgabe eines Laufs von
 * Nacht zu Nacht vergleichbar.
 */
final class Catalog
{
    /**
     * Die Prüfungen in der Reihenfolge, in der sie gefahren werden.
     *
     * @var list<class-string<Check>>
     */
    public const CHECKS = [
        Agent::class,
        ManagedBlocks::class,
        Units::class,
        Certificates::class,
        SystemUsers::class,
        Orphans::class,
        MaintenanceWindow::class,
    ];

    /**
     * Jeder Schlüssel, den ein Nachtlauf schreibt.
     *
     * Abgeleitet aus dem, was die Prüfungen selbst sagen — nicht abgeschrieben.
     *
     * @param  list<Check>  $checks
     * @return list<FindingCheck>
     */
    public static function written(array $checks): array
    {
        $keys = [];

        foreach ($checks as $check) {
            foreach ($check->writes() as $key) {
                $keys[$key->value] = $key;
            }
        }

        return array_values($keys);
    }
}
