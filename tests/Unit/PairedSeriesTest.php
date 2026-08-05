<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Store;
use PHPUnit\Framework\TestCase;

/**
 * Zwei Kurven in einem Feld teilen sich die Achse — sonst lügt das Bild.
 *
 * **Der Fehler, gegen den dieser Test steht, sieht auf einem Bildschirmfoto
 * richtig aus.** `series()` normiert jede Reihe auf ihr eigenes Kleinstes und
 * Grösstes; zweimal aufgerufen ergibt das zwei Kurven, die beide die volle
 * Höhe der Kachel ausfüllen — die eine für 4 bis 13 Kilobyte, die andere für
 * 38 bis 90 Megabyte. Nebeneinander in **einer** Kachel heisst das: gleich
 * hoch, gleich weit ausschlagend, tausendfacher Unterschied. Wer hinsieht,
 * liest „etwa gleich viel in beide Richtungen".
 *
 * Kein Testlauf hätte das gemeldet — beide Reihen wären ja da, beide mit
 * richtigen Zahlen daneben. Nur das Bild wäre falsch gewesen. Deshalb prüft
 * dieser Test die **Geometrie** und nicht die Anwesenheit.
 *
 * Die Gegenprobe steht mit im Test: Die Zahlen teilen sich die Vorsilbe
 * gerade **nicht**. Eine gemeinsame Achse ist eine Aussage über die
 * Darstellung; „0,0 MB/s" für 12,9 kB/s wäre eine über den Messwert, und sie
 * wäre falsch.
 */
final class PairedSeriesTest extends TestCase
{
    private string $directory;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/srvpanel-pair-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->store = new Store($this->directory, 100);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /** @param  list<array{float,float}>  $rows */
    private function write(array $rows): void
    {
        $time = 1_754_000_000.0;

        foreach ($rows as $i => $row) {
            $this->store->buffer('network', 2)->write([$row[0], $row[1]], $time + $i * 10);
        }
    }

    public function test_the_smaller_direction_stays_flat_at_the_bottom(): void
    {
        // Eingehend schwankt zwischen 4 und 13 kB/s, ausgehend zwischen 38 und
        // 90 MB/s — tausendfach mehr.
        $this->write([
            [4_000.0, 38_000_000.0],
            [13_000.0, 90_000_000.0],
            [9_000.0, 66_000_000.0],
        ]);

        $pair = $this->store->pair('network', 2, 0, 1);

        $klein = array_column($pair['first']['points'], 'y');
        $gross = array_column($pair['second']['points'], 'y');

        /*
         * y wächst im SVG nach unten: 28 ist der Boden des Feldes, 4 die
         * Decke. Die kleinere Richtung darf sich vom Boden kaum lösen.
         */
        $this->assertGreaterThan(27.9, min($klein), sprintf(
            "Die kleinere Richtung schlägt aus, statt flach zu liegen (kleinstes y = %.2f).\n".
            'Dann rechnet jede Kurve wieder gegen ihre eigene Spanne, und das Bild behauptet, '.
            'in beide Richtungen liefe etwa gleich viel.',
            min($klein),
        ));

        // Die grössere reicht bis an die Decke — sie bestimmt das Grösste.
        $this->assertEqualsWithDelta(4.0, min($gross), 0.01);

        /*
         * **Und sie berührt den Boden gerade nicht.** Der gehört der kleineren
         * Richtung: Das Kleinste der gemeinsamen Spanne sind 4 kB/s, und
         * 38 MB/s liegen darüber. Genau das ist der Unterschied, den zwei
         * einzeln gerechnete Reihen verlieren würden — dort begänne jede bei
         * ihrem eigenen Kleinsten und läge unten auf.
         */
        $this->assertLessThan(27.0, max($gross), sprintf(
            'Die grössere Richtung liegt am Boden auf (grösstes y = %.2f) — dann rechnet sie '.
            'gegen ihr eigenes Kleinstes und nicht gegen das gemeinsame.',
            max($gross),
        ));
    }

    public function test_each_direction_keeps_its_own_unit(): void
    {
        $this->write([
            [4_000.0, 38_000_000.0],
            [13_000.0, 90_000_000.0],
            [9_000.0, 66_000_000.0],
        ]);

        $pair = $this->store->pair('network', 2, 0, 1);

        $this->assertSame('kB/s', $pair['first']['unit']);
        $this->assertSame('MB/s', $pair['second']['unit']);

        // Und die Zahl ist die der eigenen Einheit, nicht der gemeinsamen.
        $this->assertSame('9,0 kB/s', $pair['first']['points'][2]['v']);
        $this->assertSame('66,0 MB/s', $pair['second']['points'][2]['v']);
    }

    public function test_both_directions_are_measured_against_the_same_threshold(): void
    {
        // 112,5 MB/s ist die Schwelle der Kachel (900 Mbit/s). Ausgehend geht
        // darüber, eingehend nicht — und genau das ist der Fall, für den jede
        // Richtung ihre eigene Warnung bekommt.
        $this->write([
            [4_000.0, 38_000_000.0],
            [9_000.0, 130_000_000.0],
        ]);

        $pair = $this->store->pair('network', 2, 0, 1, threshold: 112_500_000.0);

        $this->assertFalse($pair['first']['warns']);
        $this->assertTrue($pair['second']['warns'], 'Eine volle Leitung in eine Richtung ist eine volle Leitung.');
    }

    public function test_an_empty_pair_carries_both_sides(): void
    {
        // Ohne Messwerte darf die Kachel nicht auf ein fehlendes Feld greifen.
        $pair = $this->store->pair('network', 2, 0, 1);

        $this->assertFalse($pair['has']);
        $this->assertFalse($pair['first']['has']);
        $this->assertFalse($pair['second']['has']);
        $this->assertSame([], $pair['second']['points']);
    }

    /**
     * Die Grössenordnung gilt für die ganze Reihe und nicht je Stützstelle.
     *
     * Sonst spränge die Ablesung beim Wandern über die Kurve zwischen kB/s und
     * MB/s, und zwei Zahlen liessen sich nur mit der Einheit im Kopf
     * vergleichen.
     */
    public function test_one_magnitude_for_the_whole_series(): void
    {
        $this->write([
            [900.0, 1.0],
            [5_000_000.0, 1.0],
        ]);

        $pair = $this->store->pair('network', 2, 0, 1);

        $this->assertSame('MB/s', $pair['first']['unit']);
        $this->assertSame('0,0 MB/s', $pair['first']['points'][0]['v']);
        $this->assertSame('5,0 MB/s', $pair['first']['points'][1]['v']);
    }
}
