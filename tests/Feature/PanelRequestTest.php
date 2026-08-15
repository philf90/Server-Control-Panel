<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Es gibt genau eine Stelle, die `fetch` ruft.
 *
 * ## Der Anlass ist ein Satz, der stimmte und von nichts geprüft war
 *
 * In `useConsole.ts` stand seit P5c:
 *
 * > **Ein Mechanismus, den zwei Stellen selbst bauen, hat zwei Fassungen — und
 * > die zweite ist die, die den Kopf vergisst.**
 *
 * Darüber die Zeile „die einzige Stelle, die `fetch` ruft". Sie war richtig,
 * solange es eine gab — und als P6 den Baum bekam, brauchte der denselben Weg.
 * **Der zweite Aufrufer ist genau der Fall, vor dem der Kommentar warnte**, und
 * nichts hätte es gemeldet.
 *
 * Der Mechanismus steht seitdem in `usePanelRequest.ts`. Dieser Wächter hält
 * fest, dass es dabei bleibt.
 *
 * ## Warum die drei Kopfzeilen mitgeprüft werden
 *
 * Sie sind der Grund, warum eine zweite Fassung teuer wäre, und jede fehlt auf
 * ihre eigene Art:
 *
 * | fehlt | Folge |
 * |---|---|
 * | `X-XSRF-TOKEN` | 419 nach der Anmeldung, ohne dass die Seite etwas falsch machte |
 * | `Accept: application/json` | ein Validierungsfehler kommt als 302 zurück, `fetch` folgt ihr, und der Aufrufer bekommt HTML |
 * | `X-Requested-With` | dasselbe eine Schicht tiefer |
 *
 * Der mittlere Fall ist der unangenehmste: Er meldet „unerwartete Antwort", wo
 * eine brauchbare Begründung stand.
 */
final class PanelRequestTest extends TestCase
{
    /** Die eine Stelle. */
    private const TRANSPORT = 'resources/js/Composables/usePanelRequest.ts';

    /**
     * Quelltext ohne Kommentare.
     *
     * **Der Bruch hat das erzwungen.** `test_the_one_place_sends_all_three_headers`
     * suchte nach `X-Requested-With` im rohen Quelltext — und fand es im
     * Klassenkopf dieses Wächters, wo die drei Kopfzeilen in einer Tabelle
     * erklärt sind. Die Kopfzeile aus dem Code zu entfernen liess ihn grün.
     *
     * > **Ein Wächter, der im Kommentar findet, wonach er im Code sucht, prüft
     * > die Erklärung und nicht die Sache.**
     */
    private function withoutComments(string $quelle): string
    {
        return (string) preg_replace(
            ['#/\*.*?\*/#su', '#(^|\s)//[^\n]*#m'],
            ' ',
            $quelle,
        );
    }

    /** @return array<string, string> */
    private function frontend(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'vue'], true)) {
                continue;
            }

            $dateien[str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname())]
                = (string) file_get_contents($file->getPathname());
        }

        ksort($dateien);

        return $dateien;
    }

    /**
     * Nur `usePanelRequest.ts` ruft `fetch`.
     *
     * **Gesucht wird der Aufruf und nicht das Wort.** In Kommentaren steht
     * `fetch` mehrfach — als Begriff, nicht als Anweisung. `fetch(` mit
     * Klammer trifft den Aufruf; ein `// fetch` im Fliesstext nicht.
     */
    public function test_only_one_place_calls_fetch(): void
    {
        $gefunden = [];

        foreach ($this->frontend() as $pfad => $quelle) {
            if (preg_match('/\bfetch\s*\(/', $this->withoutComments($quelle)) === 1) {
                $gefunden[] = $pfad;
            }
        }

        $this->assertSame(
            [self::TRANSPORT],
            $gefunden,
            sprintf(
                "Diese Dateien rufen `fetch`: %s\n\n".
                "Es soll genau eine sein. Ein Mechanismus, den zwei Stellen selbst bauen, hat\n".
                "zwei Fassungen — und die zweite ist die, die eine der drei Kopfzeilen vergisst.\n".
                'Der Weg dorthin ist `ask()` aus `usePanelRequest.ts`.',
                implode(', ', $gefunden) ?: '(keine)',
            ),
        );
    }

    /**
     * Und diese eine Stelle schickt alle drei Kopfzeilen mit.
     *
     * Ohne diese Prüfung wäre die obige erfüllt und wertlos: Eine einzige
     * Fassung, der eine Kopfzeile fehlt, ist schlechter als zwei, die
     * vollständig sind.
     */
    public function test_the_one_place_sends_all_three_headers(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.self::TRANSPORT),
        );

        foreach ([
            'X-XSRF-TOKEN' => 'Ohne ihn weist ValidateCsrfToken jeden Griff mit 419 ab.',
            'application/json' => 'Ohne `Accept` kommt ein Validierungsfehler als 302 zurück, und `fetch` folgt ihr stillschweigend.',
            'X-Requested-With' => 'Daran erkennt Laravel eine Anfrage, die keine Umleitung verträgt.',
        ] as $kopf => $warum) {
            $this->assertStringContainsString(
                $kopf,
                $quelle,
                sprintf('`%s` fehlt. %s', $kopf, $warum),
            );
        }
    }

    /**
     * Der Rumpf wird vor dem Status gelesen.
     *
     * **Das ist die Zeile, an der zwei Abnahmekriterien aus `docs/46 §4`
     * hingen.** Ein 422 trägt die Begründung; wer beim Status abbricht, wirft
     * genau sie weg und zeigt „fehlgeschlagen".
     */
    public function test_the_body_is_read_before_the_status_decides(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::TRANSPORT);

        $rumpf = strpos($quelle, 'await antwort.text()');
        $status = strpos($quelle, 'if (antwort.ok)');

        $this->assertNotFalse($rumpf, 'Der Rumpf wird nicht mehr gelesen.');
        $this->assertNotFalse($status, 'Über den Status wird nicht mehr entschieden.');

        $this->assertLessThan(
            $status,
            $rumpf,
            'Über den Status wird entschieden, bevor der Rumpf gelesen ist. Dann geht die '.
            'Begründung eines 422 verloren, und der Kunde liest „fehlgeschlagen".',
        );
    }
}
