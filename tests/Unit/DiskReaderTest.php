<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Disks;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SystemFilesystems;
use SrvPanel\Agent\Ops\SystemInfo;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\Runner;

/**
 * Welche Platten der Agent kennt und wie voll sie sind (`docs/136 §5`).
 *
 * ## Ein Gerät, eine Zeile
 *
 * Bis zum 27. September 2026 unterschied `SystemInfo::filesystems()` nach dem
 * Einhängepunkt. Unter `PrivateTmp=yes` — der Sandbox der Agenten-Unit — stehen
 * `/tmp` und `/var/tmp` als eigene Einhängungen der Wurzel in `/proc/mounts`,
 * und aus einer Platte wurden drei (`docs/136 §3` M4). Die Zeilen hier sind die
 * gemessenen, gekürzt.
 *
 * ## Die Inodes aus `stat -f`
 *
 * Gelesen wird die Ausgabe und nicht der Rückgabewert: Fehlt ein Pfad, endet
 * `stat` mit rc=1 und druckt die übrigen Zeilen trotzdem (M6).
 */
final class DiskReaderTest extends TestCase
{
    private string $proc;

    protected function setUp(): void
    {
        $this->proc = sys_get_temp_dir().'/disks-'.bin2hex(random_bytes(4));
        mkdir($this->proc.'/tmp', 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->proc.'/mounts');
        @rmdir($this->proc.'/tmp');
        @rmdir($this->proc);
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    /**
     * Die gemessene Lage aus M4: die Wurzel dreimal, dazu ein tmpfs.
     *
     * **`/tmp` steht hier vor `/`**, anders als in `/proc/mounts`. Sonst hielte
     * auch „der erste bleibt" diesen Fall ein — und die Regel heisst „der
     * kürzeste".
     */
    public function test_one_device_is_one_row_and_the_shortest_mount_stays(): void
    {
        $zeilen = Disks::storage([
            '/dev/vda /tmp ext4 rw,relatime 0 0',
            '/dev/vda / ext4 rw,relatime 0 0',
            'tmpfs /run tmpfs rw 0 0',
            '/dev/vda /var/tmp ext4 rw,relatime 0 0',
            '/dev/vdb /srv ext4 rw,relatime 0 0',
        ]);

        self::assertSame(
            [['mount' => '/', 'device' => '/dev/vda', 'type' => 'ext4'], ['mount' => '/srv', 'device' => '/dev/vdb', 'type' => 'ext4']],
            $zeilen,
        );
    }

    /** Bei gleicher Länge bleibt der, der in `/proc/mounts` zuerst steht. */
    public function test_at_equal_length_the_earlier_mount_stays(): void
    {
        $zeilen = Disks::storage([
            '/dev/sdb1 /var/www ext4 rw 0 0',
            '/dev/sdb1 /srv/www ext4 rw 0 0',
        ]);

        self::assertSame(['/var/www'], array_column($zeilen, 'mount'));
    }

    /**
     * **Der Kernel maskiert als Oktalfolge**, und nicht nur das Leerzeichen.
     * Bis hierher ersetzte `SystemInfo` allein `\040`; ein Tabulator wäre als
     * Pfad angekommen, den es nicht gibt.
     */
    public function test_the_mount_point_is_unmasked(): void
    {
        $zeilen = Disks::storage([
            '/dev/sdc1 /srv/mit\040leer ext4 rw 0 0',
            '/dev/sdd1 /srv/mit\011tab ext4 rw 0 0',
        ]);

        // Ohne Reihenfolge: Der Leser sortiert nach dem Pfad, und der Tabulator
        // steht vor dem Leerzeichen — gefragt ist hier die Auflösung.
        self::assertEqualsCanonicalizing(['/srv/mit leer', "/srv/mit\ttab"], array_column($zeilen, 'mount'));
    }

    public function test_a_kernel_view_is_no_disk(): void
    {
        self::assertSame([], Disks::storage([
            'proc /proc proc rw 0 0',
            'devtmpfs /dev devtmpfs rw 0 0',
            'tmpfs /tmp tmpfs rw 0 0',
            'kaputt',
        ]));
    }

    /** Die Wirkung durch die Operation, mit zwei echten Pfaden auf einem Gerät. */
    public function test_the_overview_sees_one_row_for_one_device(): void
    {
        file_put_contents($this->proc.'/mounts', implode("\n", [
            "/dev/sda1 {$this->proc} ext4 rw 0 0",
            "/dev/sda1 {$this->proc}/tmp ext4 rw 0 0",
        ])."\n");

        $zeilen = (new SystemInfo($this->proc))->execute([], $this->context())['filesystems'];

        self::assertCount(1, $zeilen, 'PrivateTmp macht aus einer Platte keine zwei Zeilen mehr.');
        self::assertSame($this->proc, $zeilen[0]['mount']);
        self::assertGreaterThan(0, $zeilen[0]['total']);
    }

    /**
     * Und `system.filesystems` liefert dieselbe Auswahl — mit Inodes.
     *
     * Gefahren gegen das echte `stat -f` dieses Rechners: Es gehört zu
     * coreutils und liegt auf jedem Debian und Ubuntu, auch in der CI.
     */
    public function test_the_check_sees_the_same_rows_with_inodes(): void
    {
        file_put_contents($this->proc.'/mounts', implode("\n", [
            "/dev/sda1 {$this->proc} ext4 rw 0 0",
            "/dev/sda1 {$this->proc}/tmp ext4 rw 0 0",
        ])."\n");

        $zeilen = (new SystemFilesystems($this->proc))->execute([], $this->context())['filesystems'];

        self::assertSame([$this->proc], array_column($zeilen, 'mount'));
        self::assertIsArray($zeilen[0]['inodes'], 'Ohne Inodes wäre M3 unsichtbar.');
        self::assertGreaterThan(0, $zeilen[0]['inodes']['total']);
    }

    /** Ohne Liste der Einhängungen ist nichts gemessen — und das sagt sie laut. */
    public function test_without_mounts_the_check_does_not_answer_empty(): void
    {
        $this->expectException(AgentException::class);

        (new SystemFilesystems($this->proc.'/gibt-es-nicht'))->execute([], $this->context());
    }

    /** rc=1 mit Ausgabe: Die Zeilen, die da sind, zählen. */
    public function test_the_inodes_are_read_from_the_output_and_not_from_the_code(): void
    {
        $inodes = Disks::inodes(new Result(
            1,
            "16777216 16561815 /\n4128 4115 /opt/mit leer\n",
            "stat: cannot read file system information for '/weg': No such file or directory\n",
        ));

        self::assertSame(['/', '/opt/mit leer'], array_keys($inodes));
        self::assertSame(1.3, $inodes['/']['percent']);
    }

    /**
     * **btrfs meldet 0 und vergibt Inodes nach Bedarf.** „0 von 0" ist keine
     * Belegung, und ein geteilt durch null ist keine Zahl.
     */
    public function test_a_disk_without_an_inode_count_has_no_row(): void
    {
        self::assertSame([], Disks::inodes(new Result(0, "0 0 /btrfs\n", '')));
        self::assertSame([], Disks::inodes(new Result(0, "10 12 /kaputt\nkeine zahlen /x\n", '')));
    }
}
