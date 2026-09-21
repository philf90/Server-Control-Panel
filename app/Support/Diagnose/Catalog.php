<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Checks\Agent;
use App\Support\Diagnose\Checks\Backups;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\Checks\MaintenanceFlag;
use App\Support\Diagnose\Checks\MaintenanceWindow;
use App\Support\Diagnose\Checks\ManagedBlocks;
use App\Support\Diagnose\Checks\Orphans;
use App\Support\Diagnose\Checks\QuotaOverrun;
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
        QuotaOverrun::class,
        MaintenanceWindow::class,
        MaintenanceFlag::class,
    ];

    /**
     * Die Prüfungen, die in einer **eigenen** Unit laufen.
     *
     * ## Warum es eine zweite Liste gibt und keine zweite Regel
     *
     * `srvpanel-diagnose.service` kostet gemessen **391 ms** (`docs/100` M19) —
     * es fragt den Agenten nach Konfigurationsdateien, öffnet je Domain eine
     * TLS-Verbindung und liest ein paar Tabellen. Eine Prüfung, die jedes
     * Archiv des Servers von der Platte liest und über jedes Byte rechnet,
     * gehört nicht daneben.
     *
     * > **Ein `check`, der den Bestand des Kunden liest, gehört nicht in
     * > denselben Lauf wie einer, der eine Konfigurationsdatei prüft — auch
     * > wenn beide dieselbe Form von Befund erzeugen.** (`docs/81 §11`, an A13
     * > entschieden)
     *
     * Getrennt sind die **Läufe** und nicht die Befunde: Beide schreiben in
     * dieselbe Liste, weil jemand, der sie liest, „was ist auf diesem Server
     * nicht in Ordnung" fragt und nicht „welcher Zeitgeber hat das gemessen".
     *
     * Und getrennt sind sie **hier** und nicht in den Kommandos, aus demselben
     * Grund, aus dem es {@see self::CHECKS} gibt: Es soll einen Ort geben, an
     * dem steht, was gefahren wird — und je Lauf einen, an dem steht, von wem.
     *
     * @var list<class-string<Check>>
     */
    public const BACKUP_CHECKS = [
        Backups::class,
    ];

    /**
     * Jede Prüfung, die irgendein Lauf fährt.
     *
     * **Über diese Liste gehen die Wächter und nicht über eine der beiden
     * einzeln.** Die Zusage „jeder Schlüssel des Katalogs hat genau einen
     * Schreiber" ist eine über den Bestand der Befunde und nicht über einen
     * Zeitgeber; gegen `CHECKS` allein gemessen wäre sie rot, sobald eine
     * Prüfung in eine eigene Unit zieht.
     *
     * > **Eine Zusage, die man an einer von zwei Listen misst, gilt für die
     * > andere nicht — und welche der beiden gemeint war, sagt die Messung
     * > nicht.**
     *
     * @return list<class-string<Check>>
     */
    public static function every(): array
    {
        return [...self::CHECKS, ...self::BACKUP_CHECKS];
    }

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
