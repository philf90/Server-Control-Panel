<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Kennzahlen\Ringpuffer;
use PHPUnit\Framework\TestCase;

final class RingpufferTest extends TestCase
{
    private string $datei;

    protected function setUp(): void
    {
        $this->datei = sys_get_temp_dir().'/cloudsrv-test-'.bin2hex(random_bytes(6)).'.ring';
    }

    protected function tearDown(): void
    {
        @unlink($this->datei);
    }

    public function test_liest_zurueck_was_geschrieben_wurde(): void
    {
        $puffer = new Ringpuffer($this->datei, 2, 10);
        $puffer->schreibe([12.5, 1.25], 1000.0);
        $puffer->schreibe([13.5, 2.25], 1010.0);

        $saetze = $puffer->lies();

        $this->assertCount(2, $saetze);
        $this->assertSame(1000.0, $saetze[0]['zeit']);
        $this->assertEqualsWithDelta(12.5, $saetze[0]['werte'][0], 0.0001);
        $this->assertEqualsWithDelta(2.25, $saetze[1]['werte'][1], 0.0001);
    }

    public function test_datei_waechst_beim_umlauf_nicht(): void
    {
        $puffer = new Ringpuffer($this->datei, 1, 8);

        for ($i = 0; $i < 8; $i++) {
            $puffer->schreibe([(float) $i], 1000.0 + $i);
        }

        $groesse = filesize($this->datei);

        for ($i = 8; $i < 40; $i++) {
            $puffer->schreibe([(float) $i], 1000.0 + $i);
        }

        clearstatcache();

        // Das ist der ganze Grund für diese Klasse: Fünfmal so viele Messungen,
        // dieselbe Datei. Wächst sie hier, ist der Ringpuffer kaputt und die
        // Platte in ein paar Monaten voll.
        $this->assertSame($groesse, filesize($this->datei));
    }

    public function test_haelt_die_reihenfolge_ueber_den_umlauf_hinweg(): void
    {
        $puffer = new Ringpuffer($this->datei, 1, 4);

        for ($i = 0; $i < 6; $i++) {
            $puffer->schreibe([(float) $i], 1000.0 + $i);
        }

        $saetze = $puffer->lies();
        $werte = array_map(static fn (array $s): float => $s['werte'][0], $saetze);

        // Vier Plätze, sechs Messungen: Die beiden ältesten sind überschrieben,
        // der Rest steht in der Reihenfolge, in der er entstanden ist.
        $this->assertSame([2.0, 3.0, 4.0, 5.0], $werte);
    }

    public function test_faengt_von_vorn_an_wenn_sich_die_form_aendert(): void
    {
        (new Ringpuffer($this->datei, 2, 10))->schreibe([1.0, 2.0], 1000.0);

        // Dieselbe Datei, andere Spaltenzahl: Der Inhalt ist nicht mehr
        // deutbar. Lieber leer als falsche Kurven.
        $anders = new Ringpuffer($this->datei, 3, 10);

        $this->assertSame([], $anders->lies());

        $anders->schreibe([1.0, 2.0, 3.0], 2000.0);
        $this->assertCount(1, $anders->lies());
    }

    public function test_weist_falsche_spaltenzahl_ab(): void
    {
        $this->expectExceptionMessageMatches('/Erwartet werden 2 Werte/');

        (new Ringpuffer($this->datei, 2, 10))->schreibe([1.0]);
    }
}
