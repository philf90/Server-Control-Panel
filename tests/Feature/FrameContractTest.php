<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Models\Operation;
use App\Support\Operations\OperationRecorder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Frame;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Runner;
use Tests\TestCase;

/**
 * Was der Agent während eines Vorgangs meldet, kommt auch an.
 *
 * **Der Anlass ist der teuerste Fund des P5-Abnahmelaufs** (`docs/36 §22.3w`).
 * Der Agent schickte `['type' => 'progress', 'pct' => …, 'text' => …]`, das
 * Panel las `percent` und `message`; für die Ausgabe sendete er `type: 'log'`
 * und das Panel prüfte auf `'output'`. Vier Zeichenketten über eine
 * Prozessgrenze, keine davon passend — und beide Seiten sahen für sich richtig
 * aus.
 *
 * **Gemerkt hat es zehn Monate niemand**, weil das Fehlverhalten eine
 * Abwesenheit war: ein Balken, der von 0 auf 100 springt, und eine Ausgabe, die
 * leer bleibt. Auf 471 Vorgängen gemessen, kein einziger mit einem Wert
 * dazwischen.
 *
 * **Dieser Test fährt einen echten Frame durch beide Seiten.** Gebaut wird mit
 * {@see Frame} — derselben Klasse, die der Agent benutzt —, gelesen mit
 * {@see OperationRecorder::consume()}, und geprüft wird die Zeile in der
 * Datenbank. Ein Namenswechsel auf einer Seite allein kann damit nicht mehr
 * still durchgehen.
 *
 * Die Gegenrichtung steht mit: Jede Art aus {@see Frame::KINDS} muss beim
 * Verbraucher etwas bewirken. Eine Art, die niemand liest, ist genau der
 * Zustand, aus dem dieser Fehler entstanden ist.
 */
final class FrameContractTest extends TestCase
{
    use RefreshDatabase;

    private function operation(): Operation
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): Operation => Operation::factory()->create([
                'status' => OperationStatus::Running,
                'progress' => 0,
            ])
        );
    }

    /**
     * Ein echter {@see Context}, dessen Rückkanal in ein Feld schreibt.
     *
     * **Runner und Journal kosten nichts** — der eine hält nur das andere, das
     * andere einen Dateinamen —, und nur so ist es *der* Code, den der Agent im
     * Betrieb ausführt. Ein von Hand gebauter Frame wäre die dritte Fassung
     * derselben Namen.
     *
     * @param  list<array<string, mixed>>  $gesendet
     */
    private function context(array &$gesendet): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(
            new Runner($journal),
            $journal,
            static function (array $frame) use (&$gesendet): void {
                $gesendet[] = $frame;
            },
        );
    }

    /**
     * Ein Fortschrittsframe des Agenten landet als Fortschritt im Bestand.
     *
     * **Der Frame wird nicht von Hand aufgeschrieben.** Stünde hier
     * `['type' => 'progress', 'pct' => 42]`, wäre dieser Test die dritte
     * Fassung derselben Namen — und die dritte veraltet genauso wie die zweite.
     * Er kommt aus {@see Context}, also aus dem Code, den der Agent im Betrieb
     * ausführt.
     */
    public function test_a_progress_frame_reaches_the_record(): void
    {
        $operation = $this->operation();

        $gesendet = [];
        $context = $this->context($gesendet);

        $context->progress(42, 'Platz prüfen');

        $this->assertCount(1, $gesendet, 'Der Agent hat gar keinen Frame geschickt.');

        (new OperationRecorder($operation))->consume($gesendet[0]);

        $operation->refresh();

        $this->assertSame(42, (int) $operation->progress, implode("\n", [
            'Der Fortschritt des Agenten kommt im Bestand nicht an.',
            '',
            'Genau das war zehn Monate lang der Fall: Der Agent schickt `pct`, das Panel las',
            '`percent` — und schrieb bei jedem Frame stillschweigend 0. Beide Seiten müssen',
            'durch SrvPanel\\Agent\\Frame gehen.',
        ]));

        $this->assertSame('Platz prüfen', $operation->message, 'Der Text des Fortschritts geht verloren.');
    }

    /**
     * Und eine Ausgabezeile landet als Ausgabe.
     *
     * Sie geht durch den Puffer des Recorders, deshalb erst nach `flush()` —
     * das besorgt hier der Abschluss des Vorgangs, wie im Betrieb auch.
     */
    public function test_a_log_frame_reaches_the_record(): void
    {
        $operation = $this->operation();

        $gesendet = [];
        $context = $this->context($gesendet);

        $context->output('stdout', 'mysqldump: 12 Tabellen');

        $recorder = new OperationRecorder($operation);
        $recorder->consume($gesendet[0]);
        $recorder->succeed();

        $operation->refresh();

        $this->assertStringContainsString('mysqldump: 12 Tabellen', (string) $operation->output, implode("\n", [
            'Die Ausgabe des Agenten kommt im Bestand nicht an.',
            '',
            'Der Agent sendet `type: log`, das Panel prüfte auf `type: output` — und verwarf',
            'damit jede Zeile, die je ein Programm geschrieben hat.',
        ]));
    }

    /**
     * Ein Frame ohne Prozentzahl setzt den Fortschritt nicht zurück.
     *
     * **Das ist die Hälfte des Fehlers, die am meisten geschadet hat.** Die
     * alte Leseseite setzte bei einem unbekannten Schlüssel `0` ein — bei
     * *jedem* Frame. Ein Vorgang, der ordentlich von 10 auf 80 meldet, stand
     * damit die ganze Zeit auf null, und nicht etwa auf dem letzten guten Wert.
     */
    public function test_a_frame_without_a_percentage_leaves_the_progress_alone(): void
    {
        $operation = $this->operation();
        $recorder = new OperationRecorder($operation);

        $recorder->consume(Frame::progress(60, 'Platz prüfen'));
        $recorder->consume(['type' => Frame::PROGRESS, 'text' => 'ohne Zahl']);

        $operation->refresh();

        $this->assertSame(60, (int) $operation->progress, 'Ein Frame ohne Prozentzahl hat den Fortschritt zurückgesetzt.');
    }

    /**
     * Jede Art, die der Agent kennt, bewirkt beim Verbraucher etwas.
     *
     * **Die Gegenrichtung, und sie ist die, die diesen Fehler gefangen hätte.**
     * Eine Art, die gesendet, aber von niemandem gelesen wird, ist keine
     * Auffälligkeit — sie ist Stille. Geprüft wird deshalb je Art, dass sich die
     * Zeile *überhaupt* ändert.
     */
    public function test_every_kind_the_agent_can_send_changes_something(): void
    {
        $this->assertNotSame([], Frame::KINDS, 'Es gibt keine Arten mehr — dann prüft dieser Test nichts.');

        /*
         * **Eine Zuordnung und kein `match`.** Hier stand eines, und die beiden
         * Umgebungen haben sich darüber widersprochen: In der CI meldete
         * PHPStan `match.alwaysTrue` (der `default`-Zweig sei unerreichbar,
         * weil {@see Frame::KINDS} genau diese zwei Werte kennt), ohne larastan
         * meldete er `match.unhandled` (der Wert sei `mixed`). Beide Male ging
         * es um dieselbe Zeile, und beide Male hatte er nach seiner Sicht
         * recht.
         *
         * Ein Feldzugriff wird von keiner der beiden Sichten auf
         * Vollständigkeit geprüft — und die Meldung für eine neue Art steht
         * damit wieder da, wo sie hingehört: in einer Behauptung, die sagt, was
         * zu tun ist.
         */
        $bauplan = [
            Frame::PROGRESS => Frame::progress(37, 'unterwegs'),
            Frame::LOG => Frame::log('stdout', 'eine Zeile'),
        ];

        foreach (Frame::KINDS as $kind) {
            $operation = $this->operation();
            $recorder = new OperationRecorder($operation);

            $this->assertArrayHasKey($kind, $bauplan, sprintf(
                'Für die Art „%s" weiss dieser Test nichts zu bauen — wer eine Art dazunimmt, '
                .'nimmt sie hier mit auf.',
                $kind,
            ));

            $frame = $bauplan[$kind];

            $recorder->consume($frame);
            $operation->refresh();
            $fortschritt = (int) $operation->progress;

            // Erst danach abschliessen: `succeed()` leert den Ausgabepuffer —
            // und setzt den Fortschritt auf 100, weshalb er vorher abgelesen
            // wird. Sonst prüfte diese Schleife für `progress` genau die Zahl,
            // die der Abschluss ohnehin schreibt.
            $recorder->succeed();
            $operation->refresh();

            $veraendert = $fortschritt === 37
                || str_contains((string) $operation->output, 'eine Zeile');

            $this->assertTrue($veraendert, sprintf(
                'Die Art „%s" schickt der Agent, und beim Verbraucher passiert nichts.',
                $kind,
            ));
        }
    }
}
