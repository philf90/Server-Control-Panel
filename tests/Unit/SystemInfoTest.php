<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SystemInfo;
use SrvPanel\Agent\Runner;

/**
 * Die Kennzahlen gegen ein erfundenes /proc.
 *
 * **Warum nicht gegen das echte.** Ein Test gegen `/proc` dieses Rechners
 * prüft, dass sich etwas lesen lässt — nicht, dass es richtig gelesen wird. Er
 * kann nicht behaupten, dass Spalte 9 die gesendeten Bytes sind, denn er kennt
 * die Antwort nur von derselben Stelle. Mit vorgegebenen Dateien steht die
 * erwartete Zahl vorher fest.
 *
 * Die Beispiele sind echte Ausschnitte, gekürzt: Die Spaltenzahl und die
 * Trennung durch mehrere Leerzeichen sind Teil dessen, was hier schiefgehen
 * kann.
 */
final class SystemInfoTest extends TestCase
{
    private string $proc;

    protected function setUp(): void
    {
        $this->proc = sys_get_temp_dir().'/proc-'.bin2hex(random_bytes(4));
        mkdir($this->proc.'/net', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (['net/dev', 'diskstats', 'mounts', 'stat', 'meminfo', 'uptime', 'loadavg'] as $file) {
            @unlink($this->proc.'/'.$file);
        }

        @rmdir($this->proc.'/net');
        @rmdir($this->proc);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->proc.'/'.$name, $content);
    }

    /** @return array<string,mixed> */
    private function info(): array
    {
        $context = new Context(
            new Runner(new Journal('/dev/null')),
            new Journal('/dev/null'),
            static function (array $line): void {},
        );

        return (new SystemInfo($this->proc))->execute([], $context);
    }

    public function test_the_network_counters_skip_the_loopback(): void
    {
        $this->write('net/dev', <<<'TEXT'
        Inter-|   Receive                                                |  Transmit
         face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
            lo: 9999999   1000    0    0    0     0          0         0  9999999    1000    0    0    0     0       0          0
          eth0: 1000       10     0    0    0     0          0         0      2000      20    0    0    0     0       0          0
          eth1:  500        5     0    0    0     0          0         0       750       7    0    0    0     0       0          0
        TEXT);

        $network = $this->info()['network'];

        // Ohne lo — sonst zählte jeder Zugriff des Panels auf die eigene
        // Datenbank als Netzverkehr des Servers.
        $this->assertSame(1500, $network['rx']);
        $this->assertSame(2750, $network['tx']);
        $this->assertSame(2, $network['interfaces']);
    }

    public function test_the_disk_counters_skip_partitions(): void
    {
        // sda und sda1 zählen dieselben Zugriffe. Wer beide addiert, misst
        // doppelt — und zwar plausibel genug, dass es niemandem auffällt.
        $this->write('diskstats', <<<'TEXT'
           8       0 sda 100 0 200 0 300 0 400 0 0 0 0
           8       1 sda1 100 0 200 0 300 0 400 0 0 0 0
         259       0 nvme0n1 10 0 20 0 30 0 40 0 0 0 0
         259       1 nvme0n1p1 10 0 20 0 30 0 40 0 0 0 0
           7       0 loop0 5 0 10 0 15 0 20 0 0 0 0
        TEXT);

        $io = $this->info()['disk_io'];

        // Sektoren zu 512 Byte: (200 + 20) gelesen, (400 + 40) geschrieben.
        $this->assertSame(220 * 512, $io['read']);
        $this->assertSame(440 * 512, $io['write']);
    }

    public function test_only_real_filesystems_are_listed(): void
    {
        $this->write('mounts', <<<TEXT
        proc /proc proc rw,nosuid 0 0
        sysfs /sys sysfs rw,nosuid 0 0
        tmpfs /run tmpfs rw,nosuid 0 0
        devtmpfs /dev devtmpfs rw 0 0
        /dev/sda1 {$this->proc} ext4 rw,relatime 0 0
        TEXT);

        $filesystems = $this->info()['filesystems'];

        // Nur das ext4 bleibt übrig. Eine Warnung „98 % voll" über ein
        // devtmpfs ist ein Fehlalarm, den man sich abgewöhnt — und dann
        // übersieht man den echten.
        $this->assertCount(1, $filesystems);
        $this->assertSame($this->proc, $filesystems[0]['mount']);
        $this->assertSame('ext4', $filesystems[0]['type']);
        $this->assertGreaterThan(0, $filesystems[0]['total']);
        $this->assertLessThanOrEqual(100.0, $filesystems[0]['percent']);
    }

    public function test_memory_is_reported_in_bytes(): void
    {
        $this->write('meminfo', <<<'TEXT'
        MemTotal:        8000000 kB
        MemFree:         1000000 kB
        MemAvailable:    6000000 kB
        Buffers:          100000 kB
        Cached:          2000000 kB
        SwapTotal:       2000000 kB
        SwapFree:        2000000 kB
        TEXT);

        $memory = $this->info()['memory'];

        // /proc/meminfo rechnet in kB, die Schnittstelle in Byte. Diese
        // Umrechnung an einer Stelle falsch zu haben, ergäbe eine Kachel, die
        // um Faktor 1024 danebenliegt und trotzdem plausibel aussieht.
        $this->assertSame(8000000 * 1024, $memory['total']);
        $this->assertSame(6000000 * 1024, $memory['available']);
    }

    public function test_the_cpu_line_is_read_and_cores_are_counted(): void
    {
        $this->write('stat', <<<'TEXT'
        cpu  100 200 300 400 500 600 700 800 0 0
        cpu0 50 100 150 200 250 300 350 400 0 0
        cpu1 50 100 150 200 250 300 350 400 0 0
        intr 12345
        TEXT);

        $cpu = $this->info()['cpu'];

        $this->assertSame(100, $cpu['user']);
        $this->assertSame(400, $cpu['idle']);
        $this->assertSame(500, $cpu['iowait']);
        $this->assertSame(2, $cpu['cores']);
    }

    public function test_missing_files_do_not_break_the_answer(): void
    {
        // Ein leeres Verzeichnis: kein /proc/stat, kein /proc/mounts. Der
        // Agent läuft in Containern und auf Kerneln, die nicht alles anbieten
        // — eine Ausnahme hier hiesse, dass die ganze Übersicht ausfällt,
        // weil eine Zahl fehlt.
        $info = $this->info();

        $this->assertSame(0, $info['uptime_s']);
        $this->assertSame([], $info['cpu']);
        $this->assertSame([], $info['filesystems']);
        $this->assertSame(['rx' => 0, 'tx' => 0, 'interfaces' => 0], $info['network']);
        $this->assertSame(['read' => 0, 'write' => 0], $info['disk_io']);
    }

    public function test_the_process_list_reads_the_real_proc(): void
    {
        // Prozesse lassen sich nicht sinnvoll erfinden — hier zählt, dass die
        // Auswertung von /proc/<pid>/status stimmt. Deshalb ausnahmsweise
        // gegen das echte Verzeichnis, und geprüft wird die Form: der eigene
        // Prozess muss dabei sein, wenn er zu den grössten gehört, und jeder
        // Eintrag muss vollständig sein.
        $context = new Context(
            new Runner(new Journal('/dev/null')),
            new Journal('/dev/null'),
            static function (array $line): void {},
        );

        $processes = (new SystemInfo)->execute([], $context)['processes'];

        $this->assertNotEmpty($processes);
        $this->assertLessThanOrEqual(15, count($processes));

        foreach ($processes as $process) {
            $this->assertGreaterThan(0, $process['pid']);
            $this->assertNotSame('', $process['name']);
            $this->assertGreaterThanOrEqual(0, $process['rss']);
        }

        // Absteigend nach Speicher — die Liste beantwortet die Frage „wer
        // frisst den Speicher", und dafür muss der Grösste oben stehen.
        $sizes = array_map(static fn (array $p): int => $p['rss'], $processes);
        $sorted = $sizes;
        rsort($sorted);

        $this->assertSame($sorted, $sizes);
    }
}
