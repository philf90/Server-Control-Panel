<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Store;
use PHPUnit\Framework\TestCase;

/**
 * Die Kurve warnt, wenn der letzte Wert über der Schwelle liegt.
 *
 * **Warum das ein Test ist und keine Sichtprüfung.** Der Wechsel der Farbe ist
 * das Merkmal, wegen dem sich der Betreiber für „Kontor" entschieden hat — das
 * bediente Muster nennt es „den Unterschied zwischen bunt und bedeutend". Beim
 * Umbau ist er trotzdem nicht mitgebaut worden: Die Kachel zeichnete jede Kurve
 * in derselben Farbe, ein Jahr lang, und aufgefallen ist es niemandem — im
 * Container gibt es keinen Agenten, also stand auf jeder Kachel „noch keine
 * Messwerte". Ein Merkmal, das man nur mit Daten sieht, braucht einen Test, der
 * Daten mitbringt.
 *
 * Geprüft wird `Store::series()` und nicht die Komponente: Hier entsteht die
 * Aussage, dort wird sie nur gezeichnet.
 */
final class SeriesThresholdTest extends TestCase
{
    private string $directory;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/srvpanel-series-'.bin2hex(random_bytes(6));
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

    /** @param  list<float>  $values */
    private function write(string $name, array $values): void
    {
        $time = 1_754_000_000.0;

        foreach ($values as $i => $value) {
            $this->store->buffer($name, 1)->write([$value], $time + $i * 10);
        }
    }

    public function test_a_curve_below_the_threshold_stays_quiet(): void
    {
        $this->write('cpu', [10.0, 20.0, 30.0]);

        $this->assertFalse($this->store->series('cpu', 1, threshold: 85.0)['warns']);
    }

    public function test_a_curve_above_the_threshold_warns(): void
    {
        $this->write('cpu', [10.0, 20.0, 91.0]);

        $this->assertTrue($this->store->series('cpu', 1, threshold: 85.0)['warns']);
    }

    public function test_the_threshold_itself_already_counts(): void
    {
        // „Ab 85" und nicht „über 85": Wer die Grenze erreicht hat, ist an der
        // Grenze, und genau darum geht es.
        $this->write('cpu', [10.0, 85.0]);

        $this->assertTrue($this->store->series('cpu', 1, threshold: 85.0)['warns']);
    }

    /**
     * **Der letzte Wert entscheidet, nicht der höchste.**
     *
     * Eine Kurve, die vor einer Stunde einmal ausgeschlagen ist und seitdem
     * ruhig läuft, warnte sonst für immer — und eine Warnung, die nicht mehr
     * weggeht, liest nach dem dritten Mal niemand.
     */
    public function test_an_old_spike_does_not_keep_warning(): void
    {
        $this->write('cpu', [99.0, 98.0, 12.0, 11.0, 10.0]);

        $this->assertFalse($this->store->series('cpu', 1, threshold: 85.0)['warns']);
    }

    /**
     * Ohne Schwelle warnt nichts — auch nicht bei hohen Werten.
     *
     * `null` heisst „für diese Kennzahl gibt es keine allgemeingültige
     * Schwelle" und ist eine Angabe. Der Schreibdurchsatz ist der Fall: Eine
     * NVMe schreibt zwei Gigabyte je Sekunde, ein Netzlaufwerk hundert
     * Megabyte.
     */
    public function test_without_a_threshold_nothing_warns(): void
    {
        $this->write('disk_io', [900_000_000.0, 950_000_000.0]);

        $this->assertFalse($this->store->series('disk_io', 1)['warns']);
    }

    /** Ohne Messwerte gibt es keine Kurve und also auch keine Warnung. */
    public function test_an_empty_series_carries_the_field_too(): void
    {
        $reihe = $this->store->series('leer', 1, threshold: 1.0);

        $this->assertFalse($reihe['has']);

        // Das Feld muss auch hier stehen: Die Komponente liest `warns` ohne
        // Prüfung auf Vorhandensein, und `undefined` wäre in TypeScript ein
        // Fehler, den erst der Browser zeigt.
        $this->assertArrayHasKey('warns', $reihe);
        $this->assertFalse($reihe['warns']);
    }
}
