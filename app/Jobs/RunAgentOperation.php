<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Operation;
use App\Support\Operations\OperationRecorder;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(Client $agent, Tenancy $tenancy, Lifecycle $lifecycle): void
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
                function (array $frame) use ($recorder): void {
                    $this->consume($recorder, $frame);
                },
                static fn (): bool => $operation->cancelRequested(),
            );

            $recorder->succeed($result);

            // Erst jetzt ändert sich der Zustand des Abonnements. Vorher wäre
            // es eine Behauptung über ein System, das noch gar nicht
            // geantwortet hat — siehe App\Support\Subscriptions\Lifecycle.
            $lifecycle->afterSuccess($operation);
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
        } catch (Throwable $error) {
            // Alles andere: Socket weg, Agent tot, Fehler im Panel. Die
            // Meldung kann Pfade und Klassennamen enthalten, deshalb steht
            // sie im Protokoll und nicht im Vorgang.
            report($error);
            $recorder->fail('Der Vorgang ist unerwartet abgebrochen. Näheres im Protokoll des Panels.');
        }
    }

    /**
     * Eine Meldung des Agenten verarbeiten.
     *
     * @param  array<string,mixed>  $frame
     */
    private function consume(OperationRecorder $recorder, array $frame): void
    {
        $type = $frame['type'] ?? null;

        if ($type === 'progress') {
            $recorder->progress(
                is_numeric($frame['percent'] ?? null) ? (int) $frame['percent'] : 0,
                is_string($frame['message'] ?? null) ? $frame['message'] : null,
            );

            return;
        }

        if ($type === 'output' && is_string($frame['line'] ?? null)) {
            $recorder->output($frame['line']);
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

    public function failed(Throwable $error): void
    {
        // Greift, wenn die Warteschlange den Auftrag selbst abbricht — etwa
        // beim Zeitlimit. Ohne das bliebe der Vorgang für immer auf „läuft".
        app(Tenancy::class)->withoutRestriction(function (): void {
            $operation = Operation::query()->find($this->operationId);

            if ($operation !== null && $operation->open()) {
                (new OperationRecorder($operation))->fail(
                    'Der Vorgang wurde von der Warteschlange abgebrochen — vermutlich Zeitüberschreitung.'
                );
            }
        });
    }
}
