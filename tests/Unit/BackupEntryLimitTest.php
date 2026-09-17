<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Packer;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Ops\BackupCreate;

/**
 * Die Obergrenze einer Sicherung ist gerechnet und nicht gesetzt.
 *
 * **Er ist die Antwort auf M4** (`docs/116`, `docs/117 §7`). Die Frage dort
 * lautete, ob ein Verzeichnis, das je Datei Rechte und Verweisziel trägt, über
 * die Leitung passt — und die Antwort war nein: Bei rund 14 000 Einträgen ist
 * `Connection::CONTENT_MAX` zu Ende.
 *
 * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
 *
 * Daraus folgen **zwei** Zusagen, und dieser Wächter hält beide. Die erste ist
 * die, an die man denkt; die zweite ist die, die still bricht.
 *
 * ## 1 · Das Verzeichnis reist nicht über den Socket
 *
 * `backup.create` gibt die **Zahl** der Einträge zurück und nicht die Einträge.
 * Wer `'entries' => $entries` schriebe statt `count($entries)`, bekäme eine
 * Operation, die bei kleinen Abonnements tadellos läuft und bei einem grossen
 * an einer Stelle stirbt, die mit Sicherungen nichts zu tun hat.
 *
 * ## 2 · Die Zahl der Einträge passt in den Speicher des Agenten
 *
 * Und zwar **gerechnet gegen die Unit-Datei**, nicht gegen eine Zahl im Test.
 * Wer `MemoryMax` senkt, bekommt es hier gesagt — sonst fiele es erst dem
 * Kunden auf, dessen Vorgang wortlos stirbt, weil der Kernel den Prozess
 * genommen hat.
 *
 * > **Ein Vorgang, der wortlos stirbt, sieht aus wie ein hängender Agent.**
 *
 * ## Zwei Fragen, zwei Messmittel — und das ist der Kern dieses Wächters
 *
 * Seine erste Fassung hat beide Fragen mit **einem** gemessen: der Grösse des
 * JSON, mal einem Faktor. Das war falsch, und die Messung hat es gesagt.
 *
 * | Prüfkörper | JSON je Eintrag | Spitze bei 100 000 |
 * |---|---|---|
 * | `httpdocs/datei-000001.php` | 125 B | 122 MiB |
 * | `httpdocs/wp-content/plugins/…/klasse-000001.php` | 171 B | 122 MiB |
 *
 * Die Länge der Pfade ändert das JSON um 37 % und die Spitze um **nichts**.
 * Was den Speicher füllt, ist das Feld aus 100 000 kleinen Feldern; PHP hält
 * dafür 1042 Bytes je Eintrag, gleich welcher Pfad darin steht.
 *
 * > **Zwei Grössen, die man zusammen misst, sehen verbunden aus — und welche
 * > von beiden die Zahl treibt, sagt erst der Prüfkörper, der nur eine von
 * > ihnen ändert.**
 *
 * Gemessen wird deshalb der **Speicher** für die Speicherfrage und die
 * **JSON-Grösse** für die Leitungsfrage. Beide je Lauf neu: Ein Wächter, der
 * 1042 Bytes im Kopf trägt, hält an dem Tag still, an dem das Verzeichnis ein
 * Feld dazubekommt — also genau dann, wenn er etwas sagen müsste.
 *
 * ## Eine Falle für den, der den Eingriff dazu schreibt
 *
 * Der erste Eingriff „das Verzeichnis bekommt ein Feld" hat **nichts**
 * gemessen: `1044 B` je Eintrag mit dem Feld und ohne, auf das Byte gleich. Er
 * hängte ein Feldliteral aus lauter Konstanten an, und **ein solches Literal
 * legt PHP einmal unveränderlich ab** — 20 000 Einträge zeigten auf dasselbe
 * Feld. Mit Werten je Eintrag sind es 1484 B, und der Wächter wird rot.
 *
 * > **Ein Eingriff, der einen Zustand herstellt, den der Prüfling ohnehin
 * > gleich beantwortet, misst die Regel nicht — er misst, dass sie
 * > unempfindlich ist.**
 *
 * Wer hier bricht, gibt dem neuen Feld deshalb je Eintrag einen anderen Wert.
 */
final class BackupEntryLimitTest extends TestCase
{
    /**
     * Wie viel von `MemoryMax` das Verzeichnis höchstens belegen darf.
     *
     * **Ein Drittel, und die Zahl hat einen Grund.** Während des Packens hält
     * der Agent ausser dem Verzeichnis noch die offene Zip-Struktur, die
     * Anfrage und PHP selbst; zwei Drittel dafür ist die Linie, hinter der ein
     * Vorgang vom Kernel genommen würde. Gemessen liegt der gebaute Wert bei
     * **24 %** — die Schranke klemmt heute also nicht und schlägt an, sobald
     * jemand die Zahl der Einträge oder die Grösse eines Eintrags ungefähr
     * verdoppelt.
     */
    private const SHARE = 1 / 3;

    /**
     * Was der Allokator über PHPs eigene Rechnung hinaus nimmt.
     *
     * **Gemessen am 16. September 2026, ein Fall je Prozess:** 100 000 Einträge
     * sind in `memory_get_usage(false)` 99,4 MiB und in
     * `memory_get_peak_usage(true)` **122 MiB**. Der Aufschlag ist der
     * Allokator, und er ist die Zahl, mit der der Kernel rechnet — `MemoryMax`
     * zählt zugeteilte Seiten und nicht PHPs Buchhaltung.
     *
     * Die erste Fassung dieser Messung lief alle Fälle in **einem** Prozess und
     * gab Faktoren zwischen 1,5 und 9,7 aus: Der Heap wächst über die Fälle
     * hinweg, und `memory_get_peak_usage(true)` misst ihn mit.
     *
     * > **Ein Prüfkörper, der sich am gegenwärtigen Zustand bemisst, verändert
     * > den Zustand, an dem er sich bemisst.**
     */
    private const ALLOCATOR = 1.23;

    /**
     * Wie viele Einträge die Stichprobe hat.
     *
     * Gross genug, dass der Wert stabil ist — gemessen 1050 / 1043 / 1042 B bei
     * 5000 / 10 000 / 20 000 —, und klein genug, dass der Wächter im Testlauf
     * 20 MiB kostet und nicht 122.
     */
    private const SAMPLE = 20_000;

    /**
     * Einträge, wie sie ein echter Baum erzeugt.
     *
     * Kein `a/b.php`: Ein Verzeichnis aus kurzen Pfaden wäre kleiner als jedes
     * echte, und die Leitungsfrage bekäme eine Antwort, die zu freundlich ist.
     * Für die Speicherfrage ist die Länge gemessen egal — für die andere nicht.
     *
     * @return list<array{path: string, kind: string, mode: string, target?: string}>
     */
    private function sampleEntries(int $count): array
    {
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $entries[] = Manifest::entry(
                sprintf('httpdocs/wp-content/plugins/irgendein-plugin/includes/klasse-%06d.php', $i),
                Manifest::KIND_FILE,
                0644,
            );
        }

        return $entries;
    }

    /**
     * Was ein Eintrag **im Speicher** kostet.
     *
     * `memory_get_usage(false)` und nicht `memory_get_peak_usage(true)`: Die
     * erste ist PHPs eigene Buchhaltung und damit additiv — gemessen Byte für
     * Byte derselbe Wert in einem frischen Prozess und in einem, dem 40 000
     * fremde Einträge vorausgingen. Die zweite misst den Heap des Prozesses
     * mit, und in einem PHPUnit-Lauf ist der längst gewachsen.
     */
    private function bytesPerEntryInMemory(): float
    {
        gc_collect_cycles();

        $before = memory_get_usage(false);
        $entries = $this->sampleEntries(self::SAMPLE);
        $delta = memory_get_usage(false) - $before;

        // Ohne diese Zeile darf PHP das Feld vor der Messung freigeben — der
        // Aufruf ist die Zusicherung, dass es beim Messen noch da war.
        $this->assertCount(self::SAMPLE, $entries);

        return $delta / self::SAMPLE;
    }

    /** Was ein Eintrag **auf der Leitung** kostet — also als JSON. */
    private function bytesPerEntryOnTheWire(): float
    {
        $json = Manifest::encode(
            'messung',
            1000,
            'xk3f9a',
            '0.7.4',
            $this->sampleEntries(self::SAMPLE),
            [],
        );

        return strlen($json) / self::SAMPLE;
    }

    /** Die Grenze der Einträge passt in den Speicher, den die Unit zulässt. */
    public function test_the_entry_limit_fits_the_memory_the_unit_grants(): void
    {
        $memoryMax = $this->memoryMaxOfTheAgent();
        $perEntry = $this->bytesPerEntryInMemory();

        $needed = Packer::MAX_ENTRIES * $perEntry * self::ALLOCATOR;
        $allowed = $memoryMax * self::SHARE;

        $this->assertLessThan($allowed, $needed, sprintf(
            "Das Verzeichnis einer vollen Sicherung passt nicht mehr in den Speicher des Agenten.\n"
            ."  %s Einträge × %.0f Bytes × %.2f = %.0f MiB\n"
            ."  MemoryMax = %.0f MiB, davon ein Drittel = %.0f MiB\n\n"
            .'Wer `MemoryMax` senkt oder dem Verzeichnis ein Feld gibt, senkt `Packer::MAX_ENTRIES` mit — '
            .'sonst nimmt der Kernel den Vorgang, und das sieht aus wie ein hängender Agent.',
            number_format(Packer::MAX_ENTRIES, 0, ',', '.'),
            $perEntry,
            self::ALLOCATOR,
            $needed / 1048576,
            $memoryMax / 1048576,
            $allowed / 1048576,
        ));
    }

    /**
     * **Die Gegenprobe zu M4**, und sie ist der Grund für die Zusage darunter.
     *
     * Bei rund 14 000 Einträgen ist `Connection::CONTENT_MAX` zu Ende. Schlüge
     * das hier nicht aus, wäre der Umweg über das Archiv Zierat — und die
     * Zusage „das Verzeichnis reist nicht" prüfte etwas, das ohnehin niemanden
     * stört.
     */
    public function test_a_manifest_of_a_large_subscription_does_not_fit_the_wire(): void
    {
        $fitting = (int) (Connection::CONTENT_MAX / $this->bytesPerEntryOnTheWire());

        $this->assertLessThan(
            Packer::MAX_ENTRIES / 5,
            $fitting,
            sprintf(
                'Über die Leitung passen %s Einträge, gepackt werden bis zu %s — passte ein volles '
                .'Verzeichnis hindurch, bräuchte es den Weg über das Archiv nicht.',
                number_format($fitting, 0, ',', '.'),
                number_format(Packer::MAX_ENTRIES, 0, ',', '.'),
            ),
        );

        // Und die Untergrenze daneben: Wäre der Wert null, hätte die Messung
        // nichts gemessen, und „passt nicht" käme aus einer Division und nicht
        // aus einer Grenze.
        $this->assertGreaterThan(1000, $fitting, 'Eine Null ist nur dann eine Messung, wenn daneben etwas steht.');
    }

    /**
     * `backup.create` gibt eine Zahl zurück und nicht die Liste.
     *
     * Gehalten am **Quelltext**, weil die Operation für einen Lauf hier
     * `/var/www/vhosts` bräuchte. Was der Ausdruck nicht kann, ist eine
     * Umbenennung des Schlüssels — deshalb steht die Untergrenze daneben.
     */
    public function test_the_operation_returns_a_count_and_not_the_manifest(): void
    {
        $quelltext = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/BackupCreate.php');

        $stelle = strpos($quelltext, 'return [');
        $this->assertIsInt($stelle, 'Ohne die Rückgabe prüft dieser Fall nichts.');

        $rumpf = substr($quelltext, $stelle);

        $this->assertMatchesRegularExpression(
            "/'entries'\s*=>\s*count\(/",
            $rumpf,
            'Die Zahl der Einträge gehört in die Antwort, die Einträge selbst nicht — '
            .'sie sprengen `Connection::CONTENT_MAX` bei rund 14 000 Dateien.',
        );

        $this->assertSame('backup.create', BackupCreate::name());
    }

    /**
     * `MemoryMax` aus der Unit-Datei des Agenten — gelesen und nicht gewusst.
     *
     * Fehlt der Wert, ist das ein Befund und kein Rückfall auf eine Vorgabe:
     * Ein Wächter, der bei einer fehlenden Angabe eine erfindet, rechnet gegen
     * eine Grenze, die es nicht gibt.
     */
    private function memoryMaxOfTheAgent(): float
    {
        $unit = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/systemd/srvpanel-agentd.service',
        );

        $this->assertMatchesRegularExpression(
            '/^MemoryMax=(\d+)M$/m',
            $unit,
            'Ohne `MemoryMax` in der Unit hat dieser Wächter keine Grenze, gegen die er rechnet.',
        );

        preg_match('/^MemoryMax=(\d+)M$/m', $unit, $treffer);

        return ((int) $treffer[1]) * 1048576;
    }
}
