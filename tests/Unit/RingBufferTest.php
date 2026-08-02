<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\RingBuffer;
use PHPUnit\Framework\TestCase;

final class RingBufferTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/cloudsrv-test-'.bin2hex(random_bytes(6)).'.ring';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_reads_back_what_was_written(): void
    {
        $buffer = new RingBuffer($this->file, 2, 10);
        $buffer->write([12.5, 1.25], 1000.0);
        $buffer->write([13.5, 2.25], 1010.0);

        $records = $buffer->read();

        $this->assertCount(2, $records);
        $this->assertSame(1000.0, $records[0]['time']);
        $this->assertEqualsWithDelta(12.5, $records[0]['values'][0], 0.0001);
        $this->assertEqualsWithDelta(2.25, $records[1]['values'][1], 0.0001);
    }

    public function test_file_does_not_grow_on_wraparound(): void
    {
        $buffer = new RingBuffer($this->file, 1, 8);

        for ($i = 0; $i < 8; $i++) {
            $buffer->write([(float) $i], 1000.0 + $i);
        }

        $size = filesize($this->file);

        for ($i = 8; $i < 40; $i++) {
            $buffer->write([(float) $i], 1000.0 + $i);
        }

        clearstatcache();

        // Das ist der ganze Grund für diese Klasse: Fünfmal so viele Messungen,
        // dieselbe Datei. Wächst sie hier, ist der RingBuffer kaputt und die
        // Platte in ein paar Monaten voll.
        $this->assertSame($size, filesize($this->file));
    }

    public function test_keeps_order_across_wraparound(): void
    {
        $buffer = new RingBuffer($this->file, 1, 4);

        for ($i = 0; $i < 6; $i++) {
            $buffer->write([(float) $i], 1000.0 + $i);
        }

        $records = $buffer->read();
        $values = array_map(static fn (array $s): float => $s['values'][0], $records);

        // Vier Plätze, sechs Messungen: Die beiden ältesten sind überschrieben,
        // der Rest steht in der Reihenfolge, in der er entstanden ist.
        $this->assertSame([2.0, 3.0, 4.0, 5.0], $values);
    }

    public function test_starts_over_when_shape_changes(): void
    {
        (new RingBuffer($this->file, 2, 10))->write([1.0, 2.0], 1000.0);

        // Dieselbe Datei, andere Spaltenzahl: Der Inhalt ist nicht mehr
        // deutbar. Lieber leer als falsche Kurven.
        $other = new RingBuffer($this->file, 3, 10);

        $this->assertSame([], $other->read());

        $other->write([1.0, 2.0, 3.0], 2000.0);
        $this->assertCount(1, $other->read());
    }

    public function test_rejects_wrong_column_count(): void
    {
        $this->expectExceptionMessageMatches('/Erwartet werden 2 Werte/');

        (new RingBuffer($this->file, 2, 10))->write([1.0]);
    }
}
