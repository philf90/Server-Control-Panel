<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ein Lauf der Bestandsdiagnose (A10 Schritt 6, `docs/98 §4`).
 *
 * ## Ein Zeitstempel für alle
 *
 * Sonst stünden auf der Seite so viele Werte für „zuletzt gemessen", wie es
 * Prüfungen gibt, und sie unterschieden sich um Millisekunden. Der Zeitpunkt
 * kommt deshalb von hier und nicht aus jeder Prüfung.
 *
 * ## Eine gescheiterte Prüfung hält den Lauf nicht auf
 *
 * Was eine Prüfung selbst als Ausfall kennt — ein schweigender Agent —, meldet
 * sie als `unreachable`. Was hier ankommt, ist etwas anderes: eine Ausnahme,
 * die niemand vorgesehen hat. Sie wird festgehalten und der Lauf geht weiter,
 * denn die übrigen Prüfungen haben mit ihr nichts zu tun.
 *
 * **Ihre alten Befunde bleiben stehen.** Sie sind nicht widerlegt, sondern
 * ungeprüft — dieselbe Regel wie bei `unreachable`. Ein `replace()` mit einer
 * leeren Liste hiesse „geprüft, nichts gefunden", und genau das wäre der
 * Fehler aus `docs/44`.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 *
 * Der Rückgabewert des Kommandos sagt trotzdem „nicht in Ordnung": Ein Lauf,
 * der die Hälfte seiner Prüfungen verloren hat, darf nicht wie ein gelungener
 * aussehen.
 */
final class Run
{
    /** @param list<Check> $checks */
    public function __construct(
        private readonly array $checks,
        private readonly FindingLog $log,
        private readonly RunLog $runs,
    ) {}

    /**
     * Alle Prüfungen fahren.
     *
     * @return array{measured_at: Carbon, ran: list<string>, failed: array<string, string>}
     */
    public function all(Carbon $measuredAt): array
    {
        $ran = [];
        $failed = [];

        foreach ($this->checks as $check) {
            $name = $check::class;

            try {
                $check->run($measuredAt, $this->log);
                $ran[] = $name;
            } catch (Throwable $error) {
                $failed[$name] = $error->getMessage();
            }
        }

        /*
         * **Der Zeitpunkt wird festgehalten, auch wenn eine Prüfung gescheitert
         * ist.** Gelaufen ist der Lauf; was er nicht messen konnte, steht als
         * Befund oder als Fehlschlag daneben. Ihn nur im Erfolgsfall zu
         * schreiben hiesse, dass die Seite nach einer gescheiterten Prüfung
         * behauptet, seit Tagen habe niemand gemessen.
         *
         * **Am Ende und nicht am Anfang**: Ein Lauf, der auf halbem Weg
         * abgeschossen wird, hat nicht gemessen — und soll es auch nicht
         * behaupten.
         */
        $this->runs->record($measuredAt);

        return ['measured_at' => $measuredAt, 'ran' => $ran, 'failed' => $failed];
    }
}
