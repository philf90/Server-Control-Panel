<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OperationStatus;
use App\Models\Operation;
use App\Support\Operations\Lifecycles;
use App\Support\Operations\OperationRecorder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Nachlese für einen Lauf, den der Agent nur abgesetzt hat.
 *
 * ## Warum es diesen Job gibt
 *
 * `system.packages.upgrade` setzt seinen Lauf über `systemd-run` ab und kehrt
 * zurück, bevor er fertig ist. Das ist tragend: Punkt 5 des Abnahmelaufs von A1
 * belegt, dass die transiente Unit den Neustart von `srvpanel-worker` überlebt,
 * wenn `srvpanel` selbst im Lauf steckt.
 *
 * > **Eine Behebung, die das Merkmal zurücknimmt, für das der Lauf gefahren
 * > wurde, ist keine.**
 *
 * Also kein `--wait`, sondern eine Nachlese.
 *
 * ## Warum ein Job und keine eigene Unit
 *
 * `QUEUE_CONNECTION=database`: Der Job liegt in der Tabelle `jobs`, bis sein
 * `available_at` erreicht ist. Damit überlebt er **beide** Ereignisse, die hier
 * vorkommen — den Neustart von `srvpanel-worker` durch ein Update, das das
 * Panel selbst enthält, und einen ganzen Serverneustart. Eine eigene
 * systemd-Unit wäre ein zweiter Zeitgeber für eine Frage, die die Warteschlange
 * schon beantwortet.
 *
 * ## Die Frist, und in welche Richtung sie fällt
 *
 * Ohne Frist bliebe ein Vorgang auf `running` stehen, wenn der Lauf ohne Urteil
 * endet — etwa weil der Kernel ihn wegen Speichermangels beendet hat. Mit
 * Frist endet er als **Fehlschlag**, und das ist die sichere Richtung:
 *
 * > **Ein Ausgang, der sich nicht feststellen liess, ist kein Erfolg.**
 */
final class AwaitDispatchedRun implements ShouldQueue
{
    use Queueable;

    /**
     * Wie lange zwischen zwei Blicken ins Log liegt.
     *
     * **Fünfzehn Sekunden und kein Rückstoss.** Ein Upgrade über 142 Pakete
     * dauert Minuten, ein Blick kostet einen Aufruf an den Agenten; ein
     * wachsender Abstand spart davon wenig und macht die letzte Wartezeit
     * lang — der Betreiber sieht dann minutenlang „läuft", obwohl es fertig
     * ist.
     */
    public const INTERVAL = 15;

    /**
     * Wann aufgegeben wird.
     *
     * Zwei Stunden. `docs/81 §2.3h` Punkt 1 ist bis heute nicht gemessen — wie
     * lange ein Lauf über 142 Pakete wirklich dauert, weiss niemand; der
     * Abnahmelauf hatte sechs und fünf Pakete. Die Zahl ist deshalb bewusst
     * grosszügig: Eine zu kurze Frist meldete einen laufenden Lauf als
     * gescheitert, und das ist der teurere Irrtum.
     *
     * > **Eine Frist, die man nicht gemessen hat, wird lang gewählt und nicht
     * > plausibel.**
     */
    public const DEADLINE = 7200;

    public function __construct(
        private readonly int $operationId,
        private readonly int $startedAt,
    ) {
        $this->onQueue('operations');
    }

    public function handle(Client $agent, Lifecycles $lifecycles, Tenancy $tenancy): void
    {
        $operation = $tenancy->withoutRestriction(
            fn (): ?Operation => Operation::query()->find($this->operationId),
        );

        // Der Vorgang ist fort oder jemand anders hat ihn schon entschieden —
        // beides ist kein Fehler, sondern ein Grund, nichts mehr zu tun.
        if (! $operation instanceof Operation || $operation->status !== OperationStatus::Running) {
            return;
        }

        $ergebnis = is_array($operation->result) ? $operation->result : [];
        $recorder = new OperationRecorder($operation);

        try {
            $antwort = $agent->call('system.run.outcome', [
                'key' => (string) ($ergebnis['run'] ?? ''),
                'offset' => (int) ($ergebnis['log_offset'] ?? 0),
                'unit' => (string) ($ergebnis['unit'] ?? ''),
            ]);
        } catch (AgentException $fehler) {
            /*
             * **Ein Agent, der gerade nicht antwortet, ist kein Urteil.** Er
             * wird während eines Panel-Updates selbst neu gestartet; wer daraus
             * einen Fehlschlag machte, meldete genau den Lauf als gescheitert,
             * der gerade erfolgreich durchläuft.
             */
            $this->again($operation, $recorder, $fehler->getMessage());

            return;
        }

        $urteil = $antwort['verdict'] ?? null;

        if (! is_string($urteil)) {
            $this->again($operation, $recorder, null);

            return;
        }

        if ($antwort['failed'] === true) {
            $recorder->fail($urteil, $ergebnis);
            $lifecycles->afterFailure($operation);

            return;
        }

        /*
         * **Das Urteil ist die Meldung und nicht nur ein Feld.** Es steht
         * ohnehin im Ergebnis; sichtbar wird es dadurch nur für den, der die
         * Seite neu lädt — der Strom überträgt `status`, `progress`, `message`
         * und die Ausgabe, `result` nicht (`docs/88`, Befund 6). Wer dem
         * Vorgang beim Enden zusieht, sähe sonst eine Marke, die auf `fertig`
         * springt, und darunter das Wort, mit dem der Agent ihn abgesetzt hat.
         *
         * Im Fehlerfall stand es längst dort — `fail()` reicht es als
         * Begründung durch. Das war die eigentliche Lücke:
         *
         * > **Ein Urteil, das nur im Fehlerfall sichtbar wird, ist keine
         * > Auskunft über den Ausgang — es ist eine Fehlermeldung.**
         */
        $recorder->succeed([...$ergebnis, 'verdict' => $urteil], $urteil);
        $lifecycles->afterSuccess($operation);
    }

    /**
     * Noch einmal nachsehen — oder aufgeben.
     *
     * Der Grund steht im Fehlschlag, wenn es einen gibt: Ein „der Ausgang liess
     * sich nicht feststellen" ohne den letzten Hinderungsgrund schickt den
     * Leser dorthin, wo nichts steht.
     */
    private function again(Operation $operation, OperationRecorder $recorder, ?string $hindernis): void
    {
        if (time() - $this->startedAt < self::DEADLINE) {
            self::dispatch($this->operationId, $this->startedAt)
                ->delay(now()->addSeconds(self::INTERVAL));

            return;
        }

        $recorder->fail(
            'Der Ausgang dieses Laufs liess sich nicht feststellen.'
            .($hindernis === null ? '' : ' Zuletzt: '.$hindernis)
            .' Was er getan hat, steht in seinem Protokoll.',
            is_array($operation->result) ? $operation->result : [],
        );
    }
}
