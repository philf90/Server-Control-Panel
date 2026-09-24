<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\WebAccessCount;
use SrvPanel\Agent\Runner;
use Tests\Support\WithoutPhpComments;

/**
 * Der Nachtlauf liest aus der Antwort von `web.access.count` nur, was die
 * Operation auch schreibt.
 *
 * ## Der Fund
 *
 * `docs/134 §0` Punkt 3, 24. September 2026: `CollectTraffic` druckte jede
 * Nacht eine zweite Zeile „Laufender Tag auf dem Server" mit
 * `(Zeitzone unbekannt)` — unter der ersten, die die Zone richtig nannte. Sie
 * las einen Schlüssel, den der Agent bis `bd5611bb` schrieb und seitdem nicht
 * mehr; der Commit hat die Frage an `ServerZone` gegeben und den alten Leser
 * stehen lassen.
 *
 * > **Ein Feld, das gelesen und nicht mehr geschrieben wird, liest sich als
 * > „unbekannt" — und die Zeile sieht aus wie eine Auskunft.**
 *
 * Kein Wächter konnte das sehen: Die Zeile war richtig geschrieben, sie las nur
 * einen Schlüssel, den niemand mehr füllte.
 *
 * ## Wie gemessen wird
 *
 * **Was die Operation schreibt, kommt aus einem echten Aufruf** von
 * `execute()` und nicht aus einer Liste im Test. **Was die Leser lesen, steht
 * im Quelltext** — gelesen ohne Kommentare, denn der Absatz, der die Behebung
 * erklärt, nennt den alten Schlüssel wörtlich. Leser sind die beiden Stellen im
 * Panel, die die Antwort in die Hand bekommen: `CollectTraffic` und
 * `AccessCounts`.
 *
 * ## Was er nicht kann
 *
 * Er sieht nur die Form `$result['schlüssel']`. Ein Leser, der die Antwort
 * weiterreicht und anderswo unter einem anderen Namen liest, entgeht ihm. Und
 * die Gegenrichtung gilt nicht: `root` schreibt die Operation für ihr eigenes
 * Protokoll, und niemand muss es lesen.
 */
final class TrafficReportSeamTest extends TestCase
{
    use WithoutPhpComments;

    /** Die Stellen im Panel, die die Antwort von `web.access.count` lesen. */
    private const READERS = [
        'app/Console/Commands/CollectTraffic.php',
        'app/Support/Web/AccessCounts.php',
    ];

    public function test_the_nightly_run_reads_only_what_the_operation_writes(): void
    {
        $geschrieben = array_keys($this->report());

        /*
         * **Zwei Untergrenzen.** Ohne sie wäre der Wächter grün, sobald einer
         * der beiden Leser ins Leere greift — eine leere Liste gelesener
         * Schlüssel liegt immer in jeder geschriebenen.
         */
        $this->assertGreaterThanOrEqual(5, count($geschrieben), 'Die Operation schreibt weniger Schlüssel als erwartet — liest der Aufruf überhaupt?');

        $gelesen = $this->read();

        $this->assertGreaterThanOrEqual(4, count($gelesen), sprintf(
            'Nur %d gelesene Schlüssel gefunden — dann liest dieser Wächter die Leser nicht mehr.',
            count($gelesen),
        ));

        $this->assertSame([], array_values(array_diff(array_keys($gelesen), $geschrieben)), sprintf(
            "Diese Schlüssel liest das Panel aus der Antwort von web.access.count, und die Operation schreibt sie nicht:\n\n  %s\n\n".
            'Ein solcher Leser bekommt immer seinen Rückfall — und druckt ihn, als wäre er eine Auskunft.',
            implode("\n  ", array_map(
                static fn (string $schluessel, string $datei): string => $schluessel.' — in '.$datei,
                array_keys(array_diff_key($gelesen, array_flip($geschrieben))),
                array_diff_key($gelesen, array_flip($geschrieben)),
            )),
        ));
    }

    /**
     * Was `execute()` wirklich zurückgibt.
     *
     * Die Wurzel ist die feste der Operation; auf einem Rechner ohne
     * `/var/www/vhosts` kommt eine leere Zählung heraus — mit denselben
     * Schlüsseln. Das kleinste Budget, damit ein Rechner mit vielen Protokollen
     * den Test nicht aufhält.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $journal = new Journal('/dev/null');
        $context = new Context(new Runner($journal), $journal, static function (array $line): void {});

        return (new WebAccessCount)->execute(['budget_seconds' => WebAccessCount::BUDGET_MIN], $context);
    }

    /**
     * Welche Schlüssel die Leser aus `$result` nehmen — Kommentare zählen nicht.
     *
     * @return array<string, string> Schlüssel => Datei, in der er zuerst gelesen wird
     */
    private function read(): array
    {
        $gelesen = [];

        foreach (self::READERS as $datei) {
            $code = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/'.$datei));

            preg_match_all("/\\\$result\\['([a-z_]+)'\\]/", $code, $treffer);

            foreach ($treffer[1] as $schluessel) {
                $gelesen[$schluessel] ??= $datei;
            }
        }

        ksort($gelesen);

        return $gelesen;
    }
}
