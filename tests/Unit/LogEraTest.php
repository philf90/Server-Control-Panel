<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\SiteTemplate;
use SrvPanel\Agent\Web\AccessLog;

/**
 * Der Leser zählt das neue Zeitalter und überspringt das alte — und sagt beides.
 *
 * ## Die Prüfkörper sind gemessen und nicht erfunden
 *
 * Jede Zeile unten ist am 20. September 2026 von **echtem nginx 1.24.0**
 * geschrieben worden: zwei Server-Blöcke auf demselben Lauf, einer mit
 * `combined`, einer mit dem Format aus
 * {@see SiteTemplate::httpConfig()}, dieselben vier Abrufe
 * gegen beide. Eine nachgebaute Zeile prüfte, was ich mir unter einer Zeile
 * vorstelle.
 *
 * > **Ein Prüfkörper, der eine andere Form misst als die des Prüflings, misst
 * > die falsche — und sein Grün liest sich wie ein Freispruch.**
 *
 * ## Warum eine alte Zeile nicht mitgezählt wird
 *
 * `combined` führt `$body_bytes_sent`. Gemessen ist das bei einem `304` eine
 * **Null**, während 189 Byte hinausgehen. Eine Summe über beide Zeitalter wäre
 * eine Zahl, die niemand nachrechnen kann — und sie sähe aus wie eine Zahl.
 * Der Leser zählt sie deshalb getrennt und macht die Null sichtbar.
 */
final class LogEraTest extends TestCase
{
    /** Vier Abrufe, geschrieben mit `combined`. */
    private const LEGACY = <<<'LOG'
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET / HTTP/1.1" 200 1000 "-" "curl/8.5.0"
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET /index.html HTTP/1.1" 200 1000 "-" "Mozilla/5.0 (sagt \x22hallo\x22; ein \x5C Backslash)"
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET /gibtsnicht HTTP/1.1" 404 162 "-" "curl/8.5.0"
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET / HTTP/1.1" 304 0 "-" "curl/8.5.0"
        LOG;

    /** Dieselben vier, geschrieben mit `srvpanel`. */
    private const CURRENT = <<<'LOG'
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET / HTTP/1.1" 200 1000 "-" "curl/8.5.0" 1248 72
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET /index.html HTTP/1.1" 200 1000 "-" "Mozilla/5.0 (sagt \x22hallo\x22; ein \x5C Backslash)" 1248 115
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET /gibtsnicht HTTP/1.1" 404 162 "-" "curl/8.5.0" 326 82
        127.0.0.1 - - [20/Sep/2026:18:29:56 +0000] "GET / HTTP/1.1" 304 0 "-" "curl/8.5.0" 189 103
        LOG;

    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    private function fileWith(string ...$blocks): string
    {
        $this->file = sys_get_temp_dir().'/srvpanel-era-'.bin2hex(random_bytes(6)).'.log';
        file_put_contents($this->file, implode("\n", $blocks)."\n");

        return $this->file;
    }

    public function test_a_line_of_the_new_era_carries_both_numbers(): void
    {
        $satz = AccessLog::parse(explode("\n", self::CURRENT)[0]);

        $this->assertSame([
            'day' => '2026-09-20',
            'status' => 200,
            'sent' => 1248,
            'received' => 72,
        ], $satz);
    }

    /**
     * Und eine alte Zeile wird gedeutet — aber ohne Zahlen.
     *
     * `null` bei `sent` heisst „diese Zeile weiss es nicht" und ist etwas
     * anderes als `0`. Eine Null hier wäre der Fehler, den der ganze Umbau
     * beheben soll.
     */
    public function test_a_line_of_the_old_era_has_no_numbers(): void
    {
        $satz = AccessLog::parse(explode("\n", self::LEGACY)[0]);

        $this->assertNotNull($satz);
        $this->assertSame(200, $satz['status']);
        $this->assertNull($satz['sent'], 'Eine combined-Zeile darf keine gesendeten Bytes behaupten.');
        $this->assertNull($satz['received']);
    }

    /** Ein Anführungszeichen im User-Agent zerlegt die Zeile nicht. */
    public function test_a_quoted_user_agent_does_not_split_the_line(): void
    {
        $satz = AccessLog::parse(explode("\n", self::CURRENT)[1]);

        $this->assertNotNull($satz, 'nginx maskiert als \x22; die Zeile bleibt in sieben Stücken.');
        $this->assertSame(1248, $satz['sent']);
        $this->assertSame(115, $satz['received']);
    }

    /**
     * Eine Datei des neuen Zeitalters, gezählt — mit von Hand nachgerechneten
     * Summen.
     */
    public function test_a_current_file_is_counted(): void
    {
        $ergebnis = AccessLog::countFile($this->fileWith(self::CURRENT));

        $this->assertSame(4, $ergebnis['lines']);
        $this->assertSame(4, $ergebnis['parsed']);
        $this->assertSame(0, $ergebnis['legacy']);
        $this->assertSame(0, $ergebnis['unreadable']);

        // 1248 + 1248 + 326 + 189 und 72 + 115 + 82 + 103.
        $this->assertSame([
            '2026-09-20' => ['requests' => 4, 'sent' => 3011, 'received' => 372, 'errors' => 1, 'legacy' => 0],
        ], $ergebnis['days']);
    }

    /**
     * Eine Datei des alten Zeitalters ergibt **keine** Zahlen — und sagt, wie
     * viele Zeilen sie dafür übersprungen hat.
     *
     * Das ist die wichtigere Hälfte: Eine leere Auswertung und eine leise
     * Domain sähen sonst gleich aus.
     *
     * **Der Tag steht trotzdem da, mit Nullen und seinem `legacy`.** Bis zum
     * 20. September 2026 gab diese Datei `days => []` zurück — „diesen Tag
     * gibt es nicht". Für den Nachtlauf aus `docs/129 §5` ist das die falsche
     * Auskunft: Er muss einen Tag, der alte Zeilen trägt, **überspringen** und
     * dafür wissen, dass es ihn gibt.
     *
     * > **„Nicht zählbar" und „nicht vorhanden" sind zwei Antworten, und ein
     * > leeres Feld gibt beide.**
     */
    public function test_a_legacy_file_is_read_and_not_counted(): void
    {
        $ergebnis = AccessLog::countFile($this->fileWith(self::LEGACY));

        $this->assertSame([
            '2026-09-20' => ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0, 'legacy' => 4],
        ], $ergebnis['days']);
        $this->assertSame(4, $ergebnis['lines']);
        $this->assertSame(0, $ergebnis['parsed']);
        $this->assertSame(4, $ergebnis['legacy'], 'Die übersprungenen Zeilen müssen gezählt werden, sonst ist die leere Auswertung stumm.');
    }

    /** Und eine Datei mit beidem zählt nur das neue und nennt beide Zahlen. */
    public function test_a_mixed_file_counts_only_the_new_era(): void
    {
        $ergebnis = AccessLog::countFile($this->fileWith(self::LEGACY, self::CURRENT));

        $this->assertSame(8, $ergebnis['lines']);
        $this->assertSame(4, $ergebnis['parsed']);
        $this->assertSame(4, $ergebnis['legacy']);
        $this->assertSame(3011, $ergebnis['days']['2026-09-20']['sent']);
    }

    /**
     * Der Tag kommt aus der Zeile und nicht aus der Datei.
     *
     * `access.log.1` ist der Ertrag einer Rotation, und die läuft zu einer
     * Uhrzeit und nicht um Mitternacht — die Datei trägt deshalb regelmässig
     * zwei Kalendertage.
     */
    public function test_two_calendar_days_in_one_file_stay_apart(): void
    {
        $gestern = str_replace('20/Sep/2026', '19/Sep/2026', self::CURRENT);
        $ergebnis = AccessLog::countFile($this->fileWith($gestern, self::CURRENT));

        $this->assertSame(['2026-09-19', '2026-09-20'], array_keys($ergebnis['days']));
        $this->assertSame(4, $ergebnis['days']['2026-09-19']['requests']);
        $this->assertSame(4, $ergebnis['days']['2026-09-20']['requests']);
    }

    /** Unrat wird gezählt und nicht stillschweigend übergangen. */
    public function test_rubbish_is_counted_as_unreadable(): void
    {
        $ergebnis = AccessLog::countFile($this->fileWith("das ist keine Zeile\nund das auch nicht", self::CURRENT));

        $this->assertSame(2, $ergebnis['unreadable']);
        $this->assertSame(4, $ergebnis['parsed']);
    }

    /** Eine Datei, die es nicht gibt, ist kein Fehler — aber auch keine Zahl. */
    public function test_a_missing_file_yields_nothing_and_does_not_throw(): void
    {
        $ergebnis = AccessLog::countFile(sys_get_temp_dir().'/srvpanel-gibt-es-nicht-'.bin2hex(random_bytes(6)));

        $this->assertSame([], $ergebnis['days']);
        $this->assertSame(0, $ergebnis['lines']);
    }
}
