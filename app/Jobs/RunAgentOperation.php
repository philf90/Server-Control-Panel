<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Operation;
use App\Support\Operations\Lifecycles;
use App\Support\Operations\OperationRecorder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use Throwable;

/**
 * Führt einen Vorgang über den Agenten aus und schreibt ihn dabei fort.
 *
 * **Der Arbeiter hat keinen Mandanten.** Er läuft ohne Anfrage und ohne
 * angemeldetes Konto; der Grundzustand der Mandantenklammer ist „nichts", und
 * damit fände er den Vorgang nicht einmal, den er ausführen soll. Deshalb
 * steht hier ein ausdrückliches `withoutRestriction` — an genau einer Stelle,
 * mit einem Namen, der beim Lesen auffällt.
 *
 * **Kein automatischer zweiter Anlauf.** `$tries = 1`, und das ist keine
 * Nachlässigkeit: Ein Vorgang ändert das System. Ein zweiter Anlauf nach einem
 * Abbruch mitten in einer Paketinstallation oder einem Zertifikatswechsel
 * täte dasselbe noch einmal, auf einem halb veränderten Zustand. Wer wiederholen
 * will, löst den Vorgang neu aus und sieht dabei, was beim ersten Mal geschah.
 */
final class RunAgentOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Der Vorgang darf länger dauern als eine Anfrage — aber nicht endlos. */
    public int $timeout = 1800;

    /**
     * Eine eigene Warteschlange, und zwar ausdrücklich.
     *
     * Ein Vorgang darf eine halbe Stunde dauern. Läge er in derselben
     * Warteschlange wie alles Kurze — eine Mail, ein Aufräumen —, stünde das
     * hinter ihm und wartete. Der Name steht hier und in
     * packaging/systemd/srvpanel-worker.service; dass beide übereinstimmen,
     * prüft tests/Feature/PackagingTest.php. Ohne diese Prüfung wären es zwei
     * Zeichenketten an zwei Orten, und ein Auftrag, den kein Arbeiter abholt,
     * sieht in der Oberfläche aus wie einer, der noch wartet.
     */
    public const QUEUE = 'operations';

    public function __construct(private readonly int $operationId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(Client $agent, Tenancy $tenancy, Lifecycles $lifecycles): void
    {
        $operation = $tenancy->withoutRestriction(
            fn (): ?Operation => Operation::query()->find($this->operationId)
        );

        if ($operation === null) {
            // Der Vorgang ist verschwunden, während er in der Warteschlange
            // stand — das Abonnement wurde gelöscht. Kein Fehler.
            return;
        }

        if (! $operation->open()) {
            // Schon abgeschlossen. Kommt vor, wenn die Warteschlange denselben
            // Auftrag ein zweites Mal zustellt.
            return;
        }

        // Der billige Abbruch: Wer abbricht, während der Vorgang noch wartet,
        // kommt gar nicht erst an das System. Hier ist der Abbruch vollständig
        // und sofort — es gibt nichts zu beenden, weil noch nichts läuft.
        if ($operation->cancel_requested_at !== null) {
            (new OperationRecorder($operation))->cancel();

            return;
        }

        $recorder = new OperationRecorder($operation);
        $recorder->start();

        try {
            $result = $agent->call(
                $operation->type,
                $operation->payload ?? [],
                $this->actor($operation),
                $recorder->consume(...),
                static fn (): bool => $operation->cancelRequested(),
            );

            $recorder->succeed($result);

            // Erst jetzt ändert sich der Zustand des Abonnements. Vorher wäre
            // es eine Behauptung über ein System, das noch gar nicht
            // geantwortet hat — siehe App\Support\Operations\Lifecycles.
            $lifecycles->afterSuccess($operation);
        } catch (AgentException $error) {
            // Ein Abbruch ist kein Fehlschlag. Er steht hier trotzdem im
            // catch, weil er über dieselbe Ausnahme kommt: Der Aufruf endet
            // vorzeitig, und das ist die Form, in der das durch den Aufrufer
            // hindurchgereicht wird.
            if ($error->errorCode === AgentException::CANCELLED) {
                $recorder->cancel();

                return;
            }

            // Der Agent hat geantwortet und abgelehnt. Seine Begründung ist
            // für den Betreiber lesbar und gehört an den Vorgang.
            $recorder->fail($error->getMessage(), ['code' => $error->errorCode]);

            // Und dann wird aufgeräumt. Bis zum 9. August gab es diese Zeile
            // nicht, und damit blieb nach jedem Fehlschlag der Bestand stehen,
            // wie er beim Einreihen war — samt hochgeladener Datei in der
            // Übergabe (docs/36 §22.3w).
            $lifecycles->afterFailure($operation);
        } catch (Throwable $error) {
            // Alles andere: Socket weg, Agent tot, Fehler im Panel. Die
            // Meldung kann Pfade und Klassennamen enthalten, deshalb steht
            // sie im Protokoll und nicht im Vorgang.
            report($error);
            $recorder->fail('Der Vorgang ist unerwartet abgebrochen. Näheres im Protokoll des Panels.');

            /*
             * **Auch hier, und in einem eigenen `try`.** Was diesen Zweig
             * erreicht, ist bereits unerwartet; scheitert das Aufräumen
             * ebenfalls, darf es den Vorgang nicht ein zweites Mal umwerfen —
             * dann stünde er wieder auf „läuft", und zwar genau in dem Fall,
             * in dem am wenigsten bekannt ist.
             */
            try {
                $lifecycles->afterFailure($operation);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }
        }
    }

    /**
     * Wer den Vorgang ausgelöst hat — für das Protokoll des Agenten.
     *
     * @return array<string,mixed>|null
     */
    private function actor(Operation $operation): ?array
    {
        if ($operation->account_id === null) {
            return null;
        }

        return [
            'account_id' => (int) $operation->account_id,
            'subscription_id' => $operation->subscription_id,
        ];
    }

    /**
     * Der letzte Halt — wenn der Auftrag selbst gestorben ist.
     *
     * Greift, wenn `handle()` gar nicht mehr dazu kam, den Vorgang zu
     * schliessen. Ohne das bliebe er für immer auf „läuft".
     *
     * ## Hier stand eine Vermutung, und sie war falsch
     *
     * Bis zum 11. August 2026 schrieb dieser Handler *„vermutlich
     * Zeitüberschreitung"* — in jedem Fall, unabhängig davon, was Laravel ihm
     * gerade übergeben hatte. Im Abnahmelauf von P5b stand dieser Satz an einem
     * Vorgang, der **eine Sekunde** lief: Die Zeitstempel daneben widerlegten
     * ihn, und die eigentliche Ursache — eine `PDOException`, weil die
     * Begründung des Agenten nicht in ihre Spalte passte — stand nirgends.
     *
     * > **Ein Fehlertext, der eine Ursache rät, ist schlimmer als einer, der
     * > keine nennt — er beendet die Suche.**
     *
     * Unterschieden wird jetzt an dem, was der Handler **weiss**: der Klasse
     * der Ausnahme. Ein Zeitlimit meldet Laravel als eigene Ausnahme; alles
     * andere ist unbekannt und heisst so. Und `report()` gehört dazu, sonst
     * bleibt die Ausnahme in `failed_jobs` liegen, wo sie im Panel niemand
     * sieht — genau der Grund, warum die Ursache erst über einen SQL-Aufruf
     * von Hand zu finden war.
     */
    public function failed(Throwable $error): void
    {
        report($error);

        $message = $error instanceof TimeoutExceededException || $error instanceof MaxAttemptsExceededException
            ? 'Der Vorgang hat das Zeitlimit der Warteschlange überschritten.'
            : 'Der Vorgang ist in der Warteschlange gescheitert. Näheres im Protokoll des Panels.';

        app(Tenancy::class)->withoutRestriction(function () use ($message): void {
            $operation = Operation::query()->find($this->operationId);

            if ($operation !== null && $operation->open()) {
                (new OperationRecorder($operation))->fail($message);
            }
        });
    }
}
