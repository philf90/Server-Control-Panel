<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Collector;
use App\Support\Metrics\Store;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Client;

/**
 * Aus Zählerständen werden Raten — und dabei kann man sich still verrechnen.
 *
 * Netz und Datenträger liefert der Kernel als Summen seit dem Systemstart. Eine
 * Rate daraus ist eine Differenz durch eine Zeitspanne, und jeder der drei
 * Teile hat einen Weg, falsch zu sein, der plausibel aussieht: die erste
 * Messung ohne Vorgänger (dann wäre die „Rate" der gesamte Verkehr seit dem
 * Systemstart), ein Neustart mitten im Betrieb (dann ist die Differenz
 * negativ), und die Annahme, es seien genau zehn Sekunden vergangen, wenn der
 * Dienst ins Stocken geriet.
 *
 * Geprüft wird `record()` und nicht `collect()`: Der Agent hat nichts damit zu
 * tun, und ein Test, der ihn braucht, prüft am Ende ihn statt der Rechnung.
 */
final class CollectorRatesTest extends TestCase
{
    private string $directory;

    private Collector $collector;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/metrics-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o755, true);

        // Der Client wird nie benutzt — record() spricht mit niemandem.
        $this->collector = new Collector(new Client('/dev/null'), new Store($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function counters(int $rx, int $tx, int $read = 0, int $write = 0): array
    {
        return [
            'network' => ['rx' => $rx, 'tx' => $tx, 'interfaces' => 1],
            'disk_io' => ['read' => $read, 'write' => $write],
        ];
    }

    public function test_the_first_measurement_yields_no_rate(): void
    {
        // Sonst stünde beim Start des Dienstes eine Spitze in der Kurve, die
        // den gesamten Verkehr seit dem Systemstart als eine Sekunde ausweist
        // — und die Skala aller folgenden Werte unbrauchbar macht.
        $written = $this->collector->record($this->counters(1_000_000, 500_000), 1000.0);

        $this->assertArrayNotHasKey('network', $written);
        $this->assertArrayNotHasKey('disk_io', $written);
    }

    public function test_the_rate_is_the_difference_over_the_elapsed_time(): void
    {
        $this->collector->record($this->counters(1000, 2000, 3000, 4000), 1000.0);

        $written = $this->collector->record(
            $this->counters(1000 + 500, 2000 + 1000, 3000 + 1500, 4000 + 2000),
            1010.0,
        );

        $this->assertSame([50.0, 100.0], $written['network']);
        $this->assertSame([150.0, 200.0], $written['disk_io']);
    }

    public function test_a_longer_pause_lowers_the_rate(): void
    {
        $this->collector->record($this->counters(0, 0), 1000.0);

        // Der Dienst hing zwanzig Sekunden. Würde mit dem eingestellten Takt
        // gerechnet statt mit der vergangenen Zeit, zeigte die Kachel das
        // Doppelte.
        $written = $this->collector->record($this->counters(1000, 0), 1020.0);

        $this->assertSame(50.0, $written['network'][0]);
    }

    public function test_a_counter_that_went_backwards_yields_no_rate(): void
    {
        $this->collector->record($this->counters(1_000_000, 1_000_000), 1000.0);

        // Nach einem Neustart stehen die Zähler wieder bei fast null. Eine
        // Rate daraus wäre erfunden — für diesen Takt gibt es eben keine.
        $written = $this->collector->record($this->counters(10, 10), 1010.0);

        $this->assertArrayNotHasKey('network', $written);
    }

    public function test_the_measurement_after_a_restart_counts_again(): void
    {
        $this->collector->record($this->counters(1_000_000, 1_000_000), 1000.0);
        $this->collector->record($this->counters(10, 10), 1010.0);

        // Der Ausfall betrifft genau einen Takt. Bliebe der alte Zählerstand
        // gemerkt, gäbe es nie wieder eine Rate.
        $written = $this->collector->record($this->counters(110, 10), 1020.0);

        $this->assertSame(10.0, $written['network'][0]);
    }

    public function test_an_agent_without_the_new_fields_is_simply_quiet(): void
    {
        // Ein älterer Agent nach einem Teilupdate: Er liefert die Abschnitte
        // nicht, und dann darf auch nichts gemerkt werden. Sonst entstünde
        // beim ersten Auftauchen eine Rate aus dem Zählerstand seit dem
        // Systemstart.
        $this->assertArrayNotHasKey(
            'network',
            $this->collector->record(['memory' => ['total' => 100, 'available' => 50]], 1000.0),
        );

        $this->assertArrayNotHasKey('network', $this->collector->record($this->counters(5000, 5000), 1010.0));
        $this->assertSame(50.0, $this->collector->record($this->counters(5500, 5500), 1020.0)['network'][0]);
    }

    public function test_two_measurements_at_the_same_instant_yield_no_rate(): void
    {
        $this->collector->record($this->counters(0, 0), 1000.0);

        // Eine Division durch null wäre hier kein Absturz, sondern INF in der
        // Kurve — und das fällt erst auf, wenn die Kachel nichts mehr zeigt.
        $this->assertArrayNotHasKey('network', $this->collector->record($this->counters(100, 100), 1000.0));
    }
}
