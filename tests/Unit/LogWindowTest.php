<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemLogsTail;
use SrvPanel\Agent\Ops\WebLogsTail;
use SrvPanel\Agent\Result;

/**
 * Wovon ein gelesenes Protokollfenster begrenzt ist — und dass die Antwort es
 * sagt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `WebLogsTail::tail()` hört aus drei Gründen auf, und von aussen sahen alle
 * drei gleich aus: Die Datei war zu Ende, es waren genug Zeilen beisammen,
 * oder {@see WebLogsTail::MAX_BYTES} war erreicht. Die Fusszeile von `/logs`
 * hat daraus „gelesen wurden die letzten 500 Zeilen" gebaut — auch bei einer
 * Datei mit 118 Zeilen (`docs/86`, Befund 14) und auch bei einer, von der sie
 * nur ein Viertel gesehen hat (`docs/914 §1`, M3).
 *
 * > **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht derselbe Grund —
 * > und die Abhilfe für den einen lässt den anderen stehen.**
 *
 * ## Gemessen wird die Wirkung
 *
 * Jeder Prüfkörper ist eine **echte Datei**, und gefragt wird der echte
 * Leser. Ein Wächter über den Quelltext sagte, dass die Felder gesetzt werden;
 * er sagte nicht, ob sie stimmen — und genau das war die Frage.
 *
 * **Der Prüfkörper M3 ist der, den es ohne die Messrunde nicht gäbe.** Die
 * Schwelle liegt bei `512 KiB ÷ 500 = 1048 B` je Zeile, und darüber liegen
 * ein nginx-`error.log` mit Stacktraces und ein `upgrade.log` von apt
 * regelmässig.
 *
 * **Was er nicht hält:** ob die Seite die Felder auch zeigt. Das hält
 * {@see LogFooterTest}.
 */
final class LogWindowTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/logfenster-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $datei) {
            unlink($datei);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * Die vier Lagen aus `docs/914 §1` plus die, die beim Bauen dazukam.
     *
     * @return array<string, array{int, int, int, int, bool, bool}>
     */
    public static function lagen(): array
    {
        return [
            // Name                        Zeilen  Breite  Fenster  erwartet  complete  capped
            'M1 kürzer als das Fenster' => [118, 80, 500, 118, true, false],
            'M2 länger als das Fenster' => [5000, 80, 500, 500, false, false],
            'M3 Bytedeckel vor Zeilen' => [500, 4096, 500, 128, false, true],
            'M4 leer' => [0, 80, 500, 0, true, false],

            /*
             * **Der Fall, der beim Bauen dazukam.** Eine Datei, die ganz in
             * einen Block passt und trotzdem mehr Zeilen hat als gewünscht,
             * verlässt die Schleife über das `break` — und ist vollständig
             * gelesen. Wer `complete` am Ausstiegsgrund festmachte statt an
             * der Lage, nennte sie unvollständig und böte „weiter zurück"
             * an, wo nichts mehr ist.
             */
            'M7 ganz in einem Block, mehr Zeilen als gefragt' => [60, 40, 50, 50, true, false],
        ];
    }

    #[DataProvider('lagen')]
    public function test_the_reader_says_what_bounded_it(
        int $zeilen,
        int $breite,
        int $fenster,
        int $erwartet,
        bool $complete,
        bool $capped,
    ): void {
        $pfad = $this->bau($zeilen, $breite);

        $found = WebLogsTail::tail($pfad, $fenster);

        $this->assertCount($erwartet, $found['lines']);
        $this->assertSame($complete, $found['complete']);
        $this->assertSame($capped, $found['capped']);
    }

    /**
     * Die Gegenprobe zur Schwelle: Sie liegt zwischen 1024 und 1100 Bytes.
     *
     * Ohne sie wäre M3 eine Zahl ohne Nachbarn — und ein Leser, der **immer**
     * `capped` meldete, bestünde sie genauso.
     */
    public function test_the_cap_bites_only_above_the_measured_threshold(): void
    {
        $unten = WebLogsTail::tail($this->bau(2000, 1024, 'unten'), 500);
        $oben = WebLogsTail::tail($this->bau(2000, 1100, 'oben'), 500);

        $this->assertFalse($unten['capped'], 'Bei 1024 B je Zeile darf der Deckel nicht greifen.');
        $this->assertCount(500, $unten['lines']);

        $this->assertTrue($oben['capped'], 'Bei 1100 B je Zeile muss er greifen.');
        $this->assertLessThan(500, count($oben['lines']));
    }

    /**
     * Der Journalweg hat denselben Deckel — er steht nur woanders.
     *
     * Nicht in `journalctl`, sondern in `Runner::OUTPUT_MAX`: Der Runner
     * schneidet jede Ausgabe bei 4 MiB ab und hält das in `Result::truncated`
     * fest. Dieses Feld hat bis zum 13. September 2026 **niemand** gelesen.
     */
    public function test_the_journal_reads_the_runners_cap(): void
    {
        $kurz = SystemLogsTail::readJournal(new Result(0, "eins\nzwei\n", '', false));

        $this->assertTrue($kurz['complete'], 'Weniger Einträge als das Fenster heisst: das war alles.');
        $this->assertFalse($kurz['capped']);

        $abgeschnitten = SystemLogsTail::readJournal(new Result(0, "eins\nzwei\n", '', true));

        $this->assertTrue($abgeschnitten['capped'], 'Result::truncated muss ankommen.');
        $this->assertFalse(
            $abgeschnitten['complete'],
            'Eine abgeschnittene Ausgabe ist nie vollständig — auch nicht bei wenigen Zeilen.',
        );
    }

    /**
     * Ein volles Fenster ist nicht vollständig.
     *
     * `journalctl --lines=N` liefert höchstens N Einträge; kommen genau N
     * zurück, kann es mehr geben.
     */
    public function test_a_full_journal_window_is_not_complete(): void
    {
        $voll = implode("\n", array_fill(0, SystemLogsTail::MAX_LINES, 'zeile'));

        $this->assertFalse(SystemLogsTail::readJournal(new Result(0, $voll, '', false))['complete']);
    }

    private function bau(int $zeilen, int $breite, string $name = 'probe'): string
    {
        $pfad = $this->dir.'/'.$name.'.log';
        $f = fopen($pfad, 'wb');

        for ($i = 1; $i <= $zeilen; $i++) {
            fwrite($f, sprintf('%06d ', $i).str_repeat('x', max(0, $breite - 8))."\n");
        }

        fclose($f);

        return $pfad;
    }
}
