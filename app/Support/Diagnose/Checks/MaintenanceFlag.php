<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Settings\Settings;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Maintenance;

/**
 * Sagt die Flagdatei dasselbe wie das Panel? — `maintenance.flag`
 * (`docs/911 §2`, M8).
 *
 * ## Warum es diese Prüfung gibt
 *
 * Das Panel zeigt den Wartungsmodus aus einer **Ablage**, und bis zum
 * 12. September 2026 konnte es ihn gar nicht anders zeigen: Der Agent kannte
 * nur `web.maintenance.set`, und die verlangt `enabled` als Pflichtfeld.
 *
 * > **Ein Zustand, den man nur durch Setzen erfahren kann, ist von aussen nicht
 * > lesbar — und die Anzeige daneben liest zwangsläufig eine Ablage.**
 *
 * Und niemand glich die beiden ab. {@see MaintenanceWindow} liest dieselbe
 * Ablage und meldet nur eine überschrittene Endzeit; die Wache in den
 * Vhost-Dateien steht seit A12 **dauerhaft** dort, gleich ob der Modus an ist,
 * und sagt deshalb nichts über ihn. Verschwand die Datei von Hand, behauptete
 * das Panel weiter „Wartung läuft" — und umgekehrt.
 *
 * ## Getrennt von `MaintenanceWindow`, und das ist kein Ordnungssinn
 *
 * Jene Prüfung schreibt in ihrem Kopf, dass sie **ohne den Agenten** auskommt,
 * und zwei Werte aus den Einstellungen antworten immer. Diese fragt eine
 * Leitung und kann deshalb `unreachable` werden. Zusammengelegt wäre aus einer
 * Prüfung, die nicht scheitern kann, eine geworden, die es kann — und
 * `DiagnoseRunTest` besteht ohnehin darauf, dass jeder Schlüssel genau einen
 * Schreiber hat.
 *
 * ## Was sie nicht kann
 *
 * Sie sagt **nicht**, wer die Datei angelegt oder entfernt hat. Der Agent
 * kennt nur ihr Dasein, und ein Ort im Dateisystem trägt keine Herkunft. Sie
 * sagt auch nicht, ob nginx die Datei gerade liest — das tut er bei jeder
 * Anfrage neu (gemessen, `docs/81 §2.3p`), aber gemessen wird hier die Datei
 * und nicht eine Antwort.
 */
final class MaintenanceFlag implements Check
{
    /**
     * Die Gründe, die diese Prüfung ausspricht — je Schlüssel.
     *
     * `DiagnoseSeamTest` hält sie gegen `FindingCheck` in beide Richtungen.
     */
    public const REASONS = [
        'maintenance.flag' => ['missing', 'unexpected', FindingCheck::UNREACHABLE],
    ];

    public function __construct(
        private readonly Client $agent,
        private readonly Settings $settings,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::MaintenanceFlag];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        try {
            $answer = $this->agent->call('web.maintenance.state');
        } catch (AgentException $e) {
            $log->unreachable(FindingCheck::MaintenanceFlag, [Maintenance::FLAG], $measuredAt, $e->getMessage());

            return;
        }

        $log->replace(
            FindingCheck::MaintenanceFlag,
            self::judge($this->settings->maintenance()['enabled'], $answer),
            $measuredAt,
        );
    }

    /**
     * Das Urteil — ohne Agent und ohne Einstellungen, damit es messbar ist.
     *
     * **Der Gegenstand ist der Pfad aus der Antwort und nicht die Konstante.**
     * Der Agent nennt die Datei, an der er nachgesehen hat; stünde hier
     * {@see Maintenance::FLAG}, wäre der Befund eine Behauptung über einen
     * Pfad, den vielleicht niemand gemessen hat. Fehlt er in der Antwort,
     * trägt die Konstante — dann ist sie das Beste, was dasteht.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    public static function judge(bool $abgelegt, array $answer): array
    {
        $liegt = ($answer['enabled'] ?? null) === true;

        if ($liegt === $abgelegt) {
            return [];
        }

        $flag = $answer['flag'] ?? null;

        return [[
            'subject' => is_string($flag) && $flag !== '' ? $flag : Maintenance::FLAG,
            'reason' => $abgelegt ? 'missing' : 'unexpected',

            /*
             * **Die Richtung steht im Text und nicht nur im Grund.** Wer den
             * Befund in der Liste sieht, liest zuerst diese Zeile; „Ablage und
             * Datei gehen auseinander" sagte nicht, welche von beiden die
             * Kundenwebsites gerade abschaltet.
             */
            'detail' => $abgelegt
                ? 'Das Panel führt den Modus als eingeschaltet; die Datei liegt nicht, und die Kundenwebsites sind erreichbar.'
                : 'Das Panel führt den Modus als ausgeschaltet; die Datei liegt, und die Kundenwebsites antworten mit 503.',
        ]];
    }
}
