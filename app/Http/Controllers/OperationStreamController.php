<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die Live-Ausgabe eines Vorgangs (Server-Sent Events).
 *
 * **Warum SSE und nicht WebSocket.** Der Verlauf fließt in eine Richtung, und
 * SSE kommt ohne zusätzlichen Dienst, ohne eigenen Port und ohne Bibliothek im
 * Browser aus. Ein WebSocket-Server wäre ein weiterer Prozess, den jemand
 * überwachen, aktualisieren und absichern muss — für einen Textstrom, den der
 * Browser von sich aus wieder aufnimmt, wenn die Verbindung abreißt.
 *
 * **Jede offene Verbindung belegt einen PHP-FPM-Arbeiter.** Das ist der Preis,
 * und er ist nicht klein: Der Pool hat eine feste Größe, und wer fünf Reiter
 * offen lässt, belegt fünf Plätze. Deshalb endet der Strom nach einer festen
 * Zeit von selbst und schickt vorher `reconnect` — der Browser baut ihn neu
 * auf, und der Platz ist zwischendurch frei. Ohne diese Grenze könnten ein
 * paar vergessene Reiter das Panel für alle unerreichbar machen.
 *
 * **Wiederaufnahme über `Last-Event-ID`.** Die Kennung jedes Ereignisses ist
 * die Zahl der bisher gesendeten Ausgabezeichen. Bei einem neuen Aufbau schickt
 * der Browser sie mit, und der Strom setzt dort fort — auch nach einem
 * Seitenwechsel. Ohne das begönne die Ausgabe bei jedem Wiederaufbau von vorn.
 */
final class OperationStreamController extends Controller
{
    public function __invoke(Request $request, Operation $operation): StreamedResponse
    {
        $pollMs = (int) config('srvpanel.operations.poll_ms', 500);
        $maxSeconds = (int) config('srvpanel.operations.stream_seconds', 300);

        $offset = $this->resumeOffset($request);

        // Die Sitzung wird geschrieben und geschlossen, bevor der Strom
        // beginnt. Sonst hielte diese Anfrage sie über ihre ganze Laufzeit —
        // bei Treibern mit Sperre stünde damit jede weitere Anfrage desselben
        // Browsers still, und die Oberfläche wirkte eingefroren, während der
        // Vorgang läuft.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $response = new StreamedResponse(function () use ($operation, $offset, $pollMs, $maxSeconds): void {
            $this->stream($operation, $offset, $pollMs, $maxSeconds);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('Connection', 'keep-alive');

        // Gürtel und Hosenträger: Der Server-Block schaltet fastcgi_buffering
        // bereits ab. Steht doch einmal ein Proxy davor, den niemand
        // eingeplant hat, sagt dieser Kopf ihm dasselbe.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function stream(Operation $operation, int $offset, int $pollMs, int $maxSeconds): void
    {
        $deadline = microtime(true) + $maxSeconds;
        $lastSignature = null;

        while (true) {
            $operation->refresh();

            $output = (string) ($operation->output ?? '');
            $fresh = $offset < strlen($output) ? substr($output, $offset) : '';
            $offset += strlen($fresh);

            $signature = $operation->status->value.'|'.$operation->progress.'|'.$operation->message;

            if ($fresh !== '' || $signature !== $lastSignature) {
                $this->send('state', $offset, [
                    'status' => $operation->status->value,

                    /*
                     * `status_label` und nicht `label`: In der Nutzlast der
                     * Seite heisst `label` die **Aufgabe** („db.restore"), hier
                     * hiess es der **Zustand** („fehlgeschlagen"). Zwei
                     * Bedeutungen für einen Namen auf derselben Seite, und der
                     * Wächter unten hätte sie für dieselbe Angabe gehalten.
                     */
                    'status_label' => $operation->status->label(),
                    'progress' => $operation->progress,
                    'message' => $operation->message,
                    'output' => $fresh,
                    'open' => $operation->open(),

                    /*
                     * **Die Zeitstempel gehören mit, und der Grund ist eine
                     * Aufnahme vom 8. August 2026.** Ein fehlgeschlagener
                     * Vorgang zeigte „Begonnen —" und „Beendet —": Der Kanal
                     * führte Zustand und Meldung nach, die Zeiten kamen aus
                     * der ersten Antwort — und zu dem Zeitpunkt stand der
                     * Vorgang in der Warteschlange. Die Seite zeigte damit
                     * einen Zustand, den es nie gab (docs/36 §22.3m).
                     *
                     * Die Signatur unten braucht sie nicht: Beide ändern sich
                     * genau dann, wenn auch der Zustand sich ändert.
                     */
                    'started_at' => $operation->started_at?->toDateTimeString(),
                    'finished_at' => $operation->finished_at?->toDateTimeString(),
                ]);

                $lastSignature = $signature;
            }

            if (! $operation->open()) {
                $this->send('done', $offset, [
                    'status' => $operation->status->value,
                    'result' => $operation->result,
                ]);

                return;
            }

            if (microtime(true) >= $deadline) {
                // Kein Fehler, sondern eine Aufforderung: Der Browser baut die
                // Verbindung neu auf und schickt Last-Event-ID mit.
                $this->send('reconnect', $offset, ['after_seconds' => $maxSeconds]);

                return;
            }

            if (connection_aborted() === 1) {
                return;
            }

            usleep($pollMs * 1000);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function send(string $event, int $id, array $data): void
    {
        echo 'id: '.$id."\n";
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * Wo der Strom fortsetzen soll.
     *
     * Der Wert kommt vom Browser und wird entsprechend behandelt: Alles, was
     * keine nichtnegative Zahl ist, beginnt bei null. Eine Kennung jenseits
     * der vorhandenen Ausgabe ist harmlos — dann gibt es eben nichts
     * Neues, bis welche dazukommt.
     */
    private function resumeOffset(Request $request): int
    {
        $header = $request->header('Last-Event-ID');

        if (! is_string($header) || ! ctype_digit($header)) {
            return 0;
        }

        return max(0, (int) $header);
    }
}
