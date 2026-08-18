<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Runner;

/**
 * Jeder Datei-Vorgang sagt, unter wem er gelaufen ist.
 *
 * ## Die Regel, und warum sie einen Wächter braucht
 *
 * `docs/51 §4` verlangt in Punkt 13 und 14, dass **jeder** Datei-Vorgang seine
 * `uid` und seine Zusatzgruppen meldet. Punkt 15 des Kriteriums steht darauf:
 * Die Nullen der abgewiesenen Angriffe bedeuten erst etwas, wenn daneben eine
 * Zahl steht, die belegt, dass überhaupt jemand gelaufen ist.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * Bis zum 18. August meldete das keiner. Das Kind der Sandbox erhob beides und
 * schickte es durch das Socketpaar zurück, `Sandbox::parent()` prüfte es — und
 * warf die Zahlen dann weg. Gefunden hat es kein Test, sondern das Ausschreiben
 * des Abnahmelaufs (`docs/61 §0a`), also der Versuch, das Kriterium in Befehle
 * zu übersetzen.
 *
 * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
 * > keine.**
 *
 * ## Und die zweite Fassung, die beim ersten Wurf beinahe entstanden wäre
 *
 * Der Beleg gehörte scheinbar an das Ergebnis der Sandbox: eine Stelle,
 * `Files\Workspace::run()`, für alle dreizehn Operationen. Gemessen ist das
 * falsch — `files.list` und `files.extract` bauen aus dem Ergebnis ein
 * **frisches** Feld-Array und geben nur einzelne Werte daraus weiter. Elf von
 * dreizehn hätten gemeldet, zwei nicht, und keiner hätte es gesagt.
 *
 * > **Ein Beleg, den die Zwischenstelle weiterreichen muss, ist bei der ersten
 * > Zwischenstelle weg, die ihn nicht kennt.**
 *
 * Deshalb prüft dieser Wächter beide Richtungen: dass der Beleg an genau einer
 * Stelle **angehängt** wird, und dass ihn keine Operation selbst zusammenbaut.
 */
final class SandboxCredentialsTest extends TestCase
{
    /** Die Sandbox reicht den Beleg heraus — und prüft ihn weiterhin. */
    public function test_the_sandbox_hands_the_credentials_out(): void
    {
        $source = $this->read('agent/src/Sandbox.php');

        $this->assertStringContainsString(
            'return self::parent($pid, $parentSide, $ranAs);',
            $source,
            'Die Sandbox gibt den Beleg nicht mehr aus dem Elternprozess heraus.',
        );

        $this->assertStringContainsString(
            "\$ranAs = [\n            'uid' => (int) \$decoded['uid'],",
            $source,
            'Der Elternprozess setzt den Beleg nicht mehr — dann verwirft er ihn wieder.',
        );

        /*
         * **Herausreichen ersetzt das Prüfen nicht.** Eine Zahl, die nur
         * weitergegeben wird, ist eine Auskunft; die Zusage, dass sie nie 0
         * ist, kommt aus dieser Zeile. Wer sie beim Umbau mitnimmt, tauscht
         * eine Schranke gegen eine Anzeige.
         */
        $this->assertStringContainsString(
            "if ((\$decoded['uid'] ?? 0) === 0 || in_array(0, \$decoded['groups'] ?? [0], true)) {",
            $source,
            'Die Sandbox prüft den Beleg nicht mehr, sondern reicht ihn nur noch durch.',
        );
    }

    /** Der Arbeitsbereich erhebt ihn und gibt ihn an die Anfrage. */
    public function test_the_workspace_records_them_on_the_request(): void
    {
        $source = $this->read('agent/src/Files/Workspace.php');

        $this->assertStringContainsString(
            'public function run(Context $context, callable $work',
            $source,
            'Workspace::run() bekommt keinen Context mehr — dann kann es den Beleg nirgends abliefern.',
        );

        $this->assertStringContainsString(
            '$context->recordRanAs($ranAs);',
            $source,
            'Workspace::run() liefert den Beleg nicht mehr ab.',
        );
    }

    /**
     * Jede Datei-Operation reicht den Context durch.
     *
     * Ein Aufruf ohne ihn wäre ein Vorgang, der lief und nicht sagt, unter wem
     * — und zwar genau einer, während die anderen zwölf es tun.
     */
    public function test_every_file_operation_passes_the_request_along(): void
    {
        $ops = glob(dirname(__DIR__, 2).'/agent/src/Ops/Files*.php') ?: [];

        $this->assertGreaterThanOrEqual(
            13,
            count($ops),
            'Es werden kaum Datei-Operationen gefunden — dann prüft dieser Wächter nichts.',
        );

        foreach ($ops as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, '$workspace->run(')) {
                continue;
            }

            $this->assertSame(
                substr_count($source, '$workspace->run('),
                substr_count($source, '$workspace->run($context, '),
                sprintf(
                    '%s ruft die Sandbox ohne den Context auf. Dann läuft der Vorgang, '.
                    'und niemand erfährt, unter wem.',
                    basename($path),
                ),
            );
        }
    }

    /**
     * Angehängt wird an genau einer Stelle, und keine Operation baut ihn selbst.
     *
     * **Das ist die eigentliche Aussage dieses Wächters.** Eine Operation, die
     * den Beleg selbst in ihr Ergebnis schreibt, sieht richtig aus und macht
     * die Regel wieder zu einer, die dreizehnmal befolgt werden muss.
     */
    public function test_the_answer_carries_it_once_and_no_operation_builds_it(): void
    {
        $this->assertSame(
            1,
            substr_count($this->read('agent/src/Connection.php'), 'Context::RAN_AS'),
            'Der Beleg wird nicht mehr genau einmal an die Antwort gehängt.',
        );

        foreach (glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            foreach (['RAN_AS', "'ran_as'"] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    sprintf(
                        '%s baut den Beleg selbst zusammen. Er gehört an die eine Stelle, die '.
                        'ihn anhängt — sonst gilt die Regel wieder in dreizehn Dateien, und die '.
                        'vierzehnte vergisst sie.',
                        basename($path),
                    ),
                );
            }
        }
    }

    /**
     * Zwei verschiedene Konten in einer Anfrage sind ein Fehler.
     *
     * Kein Textvergleich, sondern der Vorgang selbst: Die Frage „unter wem lief
     * er?" hat nur dann eine Antwort, wenn es eine gibt.
     */
    public function test_two_accounts_in_one_request_are_refused(): void
    {
        $context = $this->context();

        $context->recordRanAs(['uid' => 1004, 'groups' => [1004]]);
        $context->recordRanAs(['uid' => 1004, 'groups' => [1004]]);

        $this->assertSame(
            ['uid' => 1004, 'groups' => [1004]],
            $context->ranAs(),
            'Zweimal dasselbe Konto ist kein Fehler — es ist derselbe Vorgang.',
        );

        try {
            $context->recordRanAs(['uid' => 1005, 'groups' => [1005]]);
            $this->fail('Zwei verschiedene Konten in einer Anfrage sind durchgegangen.');
        } catch (AgentException $e) {
            $this->assertStringContainsString('zwei verschiedenen Konten', $e->getMessage());
        }
    }

    /** Ohne Sandbox bleibt der Beleg leer — und nicht etwa 0. */
    public function test_a_request_without_a_sandbox_reports_nothing(): void
    {
        $this->assertNull(
            $this->context()->ranAs(),
            'Eine Anfrage ohne Sandbox meldet einen Beleg, den es nicht gibt.',
        );
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static fn (array $line): null => null);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }
}
