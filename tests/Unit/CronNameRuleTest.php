<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\Cron\CronName;
use Tests\Support\WithoutPhpComments;

/**
 * Es gibt **eine** Stelle, die sagt, welchen Dateinamen cron liest.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Die Regel steht seit P6 im Repo — auf der **Schreib**seite:
 * {@see CronFile} prüft den Systembenutzer, bevor daraus
 * ein Dateiname wird, und der Kommentar darüber ist gemessen (`docs/60 §5`):
 * `srvpanel.punkt` und `srvpanel+plus` werden von cron **wortlos** übergangen,
 * `srvpanel_unterstrich` nicht.
 *
 * A6 ist die **Lese**seite derselben Regel: Sie muss für fremde Dateien
 * beantworten, was dort für die eigenen beantwortet wird. Zwei Fassungen wären
 * zwei, und die zweite ist die, die veraltet — dasselbe Vorbild wie
 * `Net\Cidr`, das aus `Pg\Hba` herausgelöst wurde.
 *
 * > **Zwei Fassungen derselben Regel sind zwei, und die zweite ist die, die
 * > veraltet.**
 *
 * ## Was dieser Wächter **nicht** hält
 *
 * Dass `run-parts` dieselbe Regel hat. Auf allen zwölf gemessenen Prüfkörpern
 * fallen die beiden zusammen, und trotzdem sind es zwei Werkzeuge mit zwei
 * Regeln: Die **Entscheidung**, ob ein Skript läuft, kommt in A6 immer von
 * `run-parts` selbst ({@see RunPartsSeamTest}), und `CronName` beschriftet sie
 * nur.
 */
final class CronNameRuleTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Regel, wie sie aussieht, wenn jemand sie ein zweites Mal hinschreibt.
     *
     * **Der Quantor gehört dazu, und das ist gemessen.** Ohne ihn meldet dieser
     * Ausdruck `agent/src/Connection.php` — dort steht
     * `^[A-Za-z0-9_\-]{1,64}$` für die **Kennung einer Anfrage**, also eine
     * ganz andere Regel mit derselben Zeichenklasse. Ein Wächter, der sie
     * mitmeldet, wird abgeschaltet, und zwar von dem, der ihn gebaut hat.
     *
     * > **Ein Wächter, der zu viel meldet, wird abgeschaltet.**
     *
     * Was er dafür nicht sieht: eine dritte Fassung, die `{1,255}` schreibt
     * statt `+`. Das ist die Grenze eines Ausdrucks über Quelltext, und sie
     * steht hier als Frage statt als Zusage.
     */
    private const RULE = '/\[A-Za-z0-9_\\-\]\+/';

    /**
     * Die gemessenen Namen aus `docs/60 §5` und `docs/81 §2.3t` M3.
     *
     * @return list<array{string, bool, ?string}>
     */
    public static function names(): array
    {
        return [
            // Was cron liest.
            ['srvpanel-p1139', true, null],
            ['srvpanel_unterstrich', true, null],
            ['e2scrub_all', true, null],
            ['php', true, null],
            ['UPPER', true, null],
            ['00-erstes', true, null],

            // Und was es wortlos übergeht.
            ['srvpanel.punkt', false, 'dot'],
            ['probe.punkt', false, 'dot'],
            ['logrotate.dpkg-new', false, 'dot'],
            ['srvpanel+plus', false, 'character'],
            ['alt~', false, 'character'],
            ['mit leerzeichen', false, 'character'],
            ['', false, 'character'],
            ['a/b', false, 'character'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_rule_answers_the_measured_names(string $name, bool $liest, ?string $grund): void
    {
        $this->assertSame($liest, CronName::readable($name), sprintf('„%s" wird falsch beurteilt.', $name));
        $this->assertSame($grund, CronName::reason($name), sprintf('Der Grund für „%s" stimmt nicht.', $name));
    }

    /**
     * Die Schreibseite fragt diese Stelle und hat keine eigene Fassung.
     *
     * **Gesucht wird die Zeichenklasse und nicht der Aufruf.** Ein Wächter, der
     * `CronName::readable` in `CronFile` findet, wäre auch dann grün, wenn
     * daneben noch der alte Ausdruck stünde — und der wäre die zweite Fassung.
     */
    public function test_the_writing_side_carries_no_second_version(): void
    {
        $this->assertStringContainsString(
            'CronName::readable(',
            $this->quelle('agent/src/Cron/CronFile.php'),
            'Die Schreibseite fragt die gemeinsame Stelle nicht mehr.',
        );

        $this->assertDoesNotMatchRegularExpression(
            self::RULE,
            $this->quelle('agent/src/Cron/CronFile.php'),
            'Die Schreibseite trägt wieder eine eigene Fassung der Namensregel.',
        );
    }

    /**
     * Und die Leseseite ebenso.
     */
    public function test_the_reading_side_carries_no_second_version(): void
    {
        $this->assertStringContainsString(
            'CronName::',
            $this->quelle('agent/src/CronState.php'),
            'Die Leseseite fragt die gemeinsame Stelle nicht.',
        );

        $this->assertDoesNotMatchRegularExpression(
            self::RULE,
            $this->quelle('agent/src/CronState.php'),
            'Die Leseseite trägt eine eigene Fassung der Namensregel.',
        );
    }

    /**
     * Und ausser {@see CronName} trägt sie unter `agent/` niemand.
     *
     * **Die Gegenrichtung, und die ist die, an der eine dritte Fassung
     * wirklich entsteht.** Beim nächsten Merkmal, das Dateinamen liest,
     * schreibt jemand den Ausdruck ein drittes Mal hin, ohne die beiden
     * bestehenden anzufassen — beide Prüfungen oben blieben dabei grün.
     */
    public function test_no_third_version_exists(): void
    {
        $fremd = [];

        foreach ($this->dateien() as $pfad) {
            if (str_ends_with($pfad, 'agent/src/Cron/CronName.php')) {
                continue;
            }

            if (preg_match(self::RULE, $this->quelle($pfad)) === 1) {
                $fremd[] = $pfad;
            }
        }

        $this->assertSame([], $fremd, implode("\n  ", array_merge(
            ['Diese Dateien tragen eine zweite Fassung der Namensregel:'],
            $fremd,
        )));
    }

    /**
     * Ohne diese Zahl wäre die Gegenrichtung auch dann grün, wenn sie keine
     * einzige Datei läse.
     */
    public function test_the_sweep_reaches_the_agent(): void
    {
        $dateien = $this->dateien();

        $this->assertGreaterThan(100, count($dateien), 'Es werden kaum Dateien gelesen — dann prüft die Gegenrichtung nichts.');
        $this->assertContains('agent/src/CronState.php', $dateien);
        $this->assertContains('agent/src/Cron/CronFile.php', $dateien);
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel.'/agent/src'));

        $dateien = [];

        foreach ($lauf as $datei) {
            if ($datei instanceof \SplFileInfo && $datei->getExtension() === 'php') {
                $dateien[] = substr($datei->getPathname(), strlen($wurzel) + 1);
            }
        }

        sort($dateien);

        return $dateien;
    }

    private function quelle(string $pfad): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        $this->assertIsString($inhalt, sprintf('%s ist nicht lesbar.', $pfad));

        // **Ohne die Kommentare**, denn jede Behebung in diesem Repo hält ihren
        // Vorzustand im Kommentar fest — und ein Kommentar, der die entfernte
        // Zeile zitiert, stellt sie für einen Wächter wieder her.
        return $this->withoutComments($inhalt);
    }
}
