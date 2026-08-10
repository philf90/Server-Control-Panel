<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Systemkennzahlen aus /proc.
 *
 * Kein Programmaufruf: /proc lesen ist billiger als einen Prozess zu starten,
 * und die Werte stehen dort in der Form, in der der Kernel sie führt. Die
 * Umrechnung in Prozent passiert nicht hier, sondern dort, wo zwei Messungen
 * vorliegen — eine CPU-Auslastung aus einem einzelnen Blick auf /proc/stat
 * gibt es nicht.
 */
final class SystemInfo implements Op
{
    public function __construct(private readonly string $procRoot = '/proc') {}

    public static function name(): string
    {
        return 'system.info';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        return [
            'hostname' => php_uname('n'),
            'kernel' => php_uname('r'),

            /*
             * **Ob ein neuerer Kernel installiert ist, aber nicht läuft.**
             *
             * Das ist die Frage, wegen der man den Kernel überhaupt anzeigt:
             * Nach einem `apt upgrade` läuft der alte weiter, bis jemand neu
             * startet, und dem Panel sieht man das sonst nicht an.
             *
             * **Gelesen wird `/boot` und kein Programm gerufen.** Was dort als
             * `vmlinuz-…` liegt, kann starten — das ist die ehrliche Antwort auf
             * „es gäbe einen neueren". `/lib/modules` wäre der zweite Kandidat
             * und der schlechtere: Dort bleiben Verzeichnisse zurück, wenn ein
             * Paket entfernt wird, und ein Kernel ohne Abbild startet nicht.
             *
             * **Und `null` heisst „nicht nachgesehen".** Ist `/boot` leer oder
             * unlesbar, steht hier kein `false` — das wäre eine Aussage über den
             * Zustand, wo nur eine über unsere Sicht darauf möglich ist. Die
             * Lehre vom 10. August 2026, gleich dreimal an einem Tag bezahlt.
             */
            'kernel_stale' => $this->kernelStale(),
            'distribution' => $this->distribution(),
            'uptime_s' => $this->uptime(),
            'load' => $this->load(),
            'memory' => $this->memory(),
            'cpu' => $this->cpuRaw(),
            'network' => $this->networkRaw(),
            'disk_io' => $this->diskRaw(),
            'filesystems' => $this->filesystems(),
            'processes' => $this->processes(),
        ];
    }

    /**
     * Gesendete und empfangene Bytes, aufsummiert über alle echten Schnittstellen.
     *
     * Roh wie bei der CPU: Der Kernel führt Zählerstände, eine Rate entsteht
     * erst aus zwei Messungen. `lo` bleibt draußen — der Verkehr eines Rechners
     * mit sich selbst sagt über seine Anbindung nichts, würde die Zahl aber
     * beliebig aufblähen, sobald Panel und Datenbank miteinander reden.
     *
     * @return array{rx:int,tx:int,interfaces:int}
     */
    private function networkRaw(): array
    {
        $raw = @file_get_contents($this->procRoot.'/net/dev');

        if ($raw === false) {
            return ['rx' => 0, 'tx' => 0, 'interfaces' => 0];
        }

        $rx = 0;
        $tx = 0;
        $count = 0;

        foreach (explode("\n", $raw) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $values] = explode(':', $line, 2);
            $name = trim($name);

            if ($name === 'lo' || str_starts_with($name, 'veth') || str_starts_with($name, 'docker')) {
                continue;
            }

            $columns = preg_split('/\s+/', trim($values)) ?: [];

            // Spalte 0 sind empfangene Bytes, Spalte 8 gesendete — die
            // Reihenfolge steht seit Jahrzehnten fest und ist in
            // Documentation/filesystems/proc.rst beschrieben.
            $rx += (int) ($columns[0] ?? 0);
            $tx += (int) ($columns[8] ?? 0);
            $count++;
        }

        return ['rx' => $rx, 'tx' => $tx, 'interfaces' => $count];
    }

    /**
     * Gelesene und geschriebene Bytes über alle Datenträger.
     *
     * `/proc/diskstats` zählt in Sektoren zu 512 Byte — das ist die Einheit
     * des Kernels an dieser Stelle und unabhängig von der tatsächlichen
     * Sektorgröße des Geräts.
     *
     * Partitionen werden übersprungen: `sda` und `sda1` zählen dieselben
     * Zugriffe, und wer beide addiert, misst doppelt.
     *
     * @return array{read:int,write:int}
     */
    private function diskRaw(): array
    {
        $raw = @file_get_contents($this->procRoot.'/diskstats');

        if ($raw === false) {
            return ['read' => 0, 'write' => 0];
        }

        $read = 0;
        $write = 0;

        foreach (explode("\n", $raw) as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) < 10) {
                continue;
            }

            $name = $columns[2];

            if (! $this->isWholeDevice($name)) {
                continue;
            }

            $read += (int) $columns[5] * 512;
            $write += (int) $columns[9] * 512;
        }

        return ['read' => $read, 'write' => $write];
    }

    /**
     * Ein ganzes Gerät oder nur eine Partition darauf?
     *
     * `sda1` ist eine Partition von `sda`, `nvme0n1p1` eine von `nvme0n1`.
     * Loop- und RAM-Geräte interessieren nicht.
     */
    private function isWholeDevice(string $name): bool
    {
        foreach (['loop', 'ram', 'sr', 'fd', 'dm-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        if (preg_match('/^nvme\d+n\d+p\d+$/D', $name)) {
            return false;
        }

        if (preg_match('/^(sd|vd|hd|xvd)[a-z]+\d+$/D', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Belegung der eingehängten Dateisysteme.
     *
     * Gefiltert auf das, worauf Daten liegen: Was aus `/proc`, `/sys`, `tmpfs`
     * und Ähnlichem kommt, ist kein Datenträger, sondern eine Sicht des
     * Kernels. Eine Warnung „98 % voll" über ein `devtmpfs` wäre ein Fehlalarm,
     * den man sich nach dem zweiten Mal abgewöhnt — und dann übersieht man den
     * echten.
     *
     * @return list<array{mount:string,device:string,type:string,total:int,free:int,used:int,percent:float}>
     */
    private function filesystems(): array
    {
        $raw = @file_get_contents($this->procRoot.'/mounts');

        if ($raw === false) {
            return [];
        }

        $interesting = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'f2fs', 'jfs', 'reiserfs', 'vfat'];
        $rows = [];
        $seen = [];

        foreach (explode("\n", $raw) as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) < 3) {
                continue;
            }

            [$device, $mount, $type] = $columns;

            if (! in_array($type, $interesting, true) || isset($seen[$mount])) {
                continue;
            }

            // Der Kernel maskiert Leerzeichen im Einhängepunkt als \040.
            $mount = str_replace('\\040', ' ', $mount);

            $total = @disk_total_space($mount);
            $free = @disk_free_space($mount);

            if ($total === false || $free === false || $total <= 0) {
                continue;
            }

            $seen[$mount] = true;
            $used = $total - $free;

            $rows[] = [
                'mount' => $mount,
                'device' => $device,
                'type' => $type,
                'total' => (int) $total,
                'free' => (int) $free,
                'used' => (int) $used,
                'percent' => round($used / $total * 100, 1),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['mount'], $b['mount']));

        return $rows;
    }

    /**
     * Die größten Prozesse nach Speicher.
     *
     * **Nach RSS und nicht nach CPU.** Eine CPU-Angabe je Prozess bräuchte auch
     * hier zwei Messungen und einen Zustand über die Zeit; der Agent führt
     * keinen. Was ohne Zustand zu haben wäre, ist die Gesamtzeit seit dem Start
     * eines Prozesses — die sagt aber nur, dass ein alter Prozess viel
     * gerechnet hat, und nicht, dass er es gerade tut. Der Speicher dagegen ist
     * ein Augenblickswert und beantwortet die Frage, die man auf einem vollen
     * Server tatsächlich hat.
     *
     * @return list<array{pid:int,name:string,rss:int,state:string,user:int}>
     */
    private function processes(int $limit = 15): array
    {
        $entries = @scandir($this->procRoot);

        if ($entries === false) {
            return [];
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $status = @file_get_contents($this->procRoot.'/'.$entry.'/status');

            if ($status === false) {
                // Zwischen scandir und dem Lesen kann ein Prozess enden. Das
                // ist der Normalfall auf einem beschäftigten Server und kein
                // Grund, die ganze Liste aufzugeben.
                continue;
            }

            $name = $this->statusField($status, 'Name');

            $rows[] = [
                'pid' => (int) $entry,
                'name' => $name,
                'rss' => (int) $this->statusField($status, 'VmRSS') * 1024,
                'state' => substr($this->statusField($status, 'State'), 0, 1),
                'user' => (int) strtok($this->statusField($status, 'Uid'), " \t"),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['rss'] <=> $a['rss']);

        return array_slice($rows, 0, $limit);
    }

    private function statusField(string $status, string $field): string
    {
        if (preg_match('/^'.preg_quote($field, '/').':\s*(.*)$/m', $status, $match) !== 1) {
            return '';
        }

        return trim(str_replace(' kB', '', $match[1]));
    }

    /** @return array{name:string,version:string} */
    /**
     * Läuft ein älterer Kernel, als installiert ist?
     *
     * `true` — in `/boot` liegt ein neuerer als der laufende.
     * `false` — der laufende ist der neueste, den es hier gibt.
     * `null` — `/boot` liess sich nicht lesen; dann wird nichts behauptet.
     *
     * **Verglichen werden Namen, und das ist genau genug.** Debian und Ubuntu
     * vergeben `6.8.0-51-generic`; `version_compare` liest die Zahlen darin
     * numerisch und ordnet `-51` vor `-52` und `6.8` vor `6.11`. Ein
     * Vergleich, der die Paketverwaltung fragt, wäre genauer und brauchte ein
     * Programm auf der Positivliste — für eine Zeile in der Übersicht ist das
     * der falsche Preis.
     */
    private function kernelStale(): ?bool
    {
        $images = glob('/boot/vmlinuz-*');

        if ($images === false || $images === []) {
            return null;
        }

        $running = php_uname('r');

        foreach ($images as $image) {
            $installed = substr(basename($image), strlen('vmlinuz-'));

            if (version_compare($installed, $running, '>')) {
                return true;
            }
        }

        return false;
    }

    private function distribution(): array
    {
        $name = 'unbekannt';
        $version = '';

        foreach (['/etc/os-release', '/usr/lib/os-release'] as $file) {
            if (! is_readable($file)) {
                continue;
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $value = trim($value, "\"'");

                if ($key === 'NAME') {
                    $name = $value;
                }
                if ($key === 'VERSION_ID') {
                    $version = $value;
                }
            }
            break;
        }

        return ['name' => $name, 'version' => $version];
    }

    private function uptime(): int
    {
        $raw = @file_get_contents($this->procRoot.'/uptime');

        return $raw === false ? 0 : (int) (float) strtok($raw, ' ');
    }

    /** @return array{0:float,1:float,2:float} */
    private function load(): array
    {
        $raw = @file_get_contents($this->procRoot.'/loadavg');

        if ($raw === false) {
            return [0.0, 0.0, 0.0];
        }

        $parts = preg_split('/\s+/', trim($raw)) ?: [];

        return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
    }

    /** @return array<string,int> Werte in Bytes */
    private function memory(): array
    {
        $raw = @file_get_contents($this->procRoot.'/meminfo');

        if ($raw === false) {
            return [];
        }

        $wanted = [
            'MemTotal' => 'total',
            'MemAvailable' => 'available',
            'MemFree' => 'free',
            'Buffers' => 'buffers',
            'Cached' => 'cache',
            'SwapTotal' => 'swap_total',
            'SwapFree' => 'swap_free',
        ];

        $values = [];

        foreach (explode("\n", $raw) as $line) {
            if (! preg_match('/^([A-Za-z()_]+):\s+(\d+)\s*kB$/D', trim($line), $match)) {
                continue;
            }
            if (isset($wanted[$match[1]])) {
                $values[$wanted[$match[1]]] = (int) $match[2] * 1024;
            }
        }

        return $values;
    }

    /**
     * Die Rohwerte aus /proc/stat, unverrechnet.
     *
     * Der Aufrufer bildet die Differenz zur vorigen Messung — das ist die
     * einzige Art, an eine Auslastung zu kommen, und sie gehört auf die Seite,
     * die zwei Messungen hat.
     *
     * @return array<string,int>
     */
    private function cpuRaw(): array
    {
        $raw = @file_get_contents($this->procRoot.'/stat');

        if ($raw === false) {
            return [];
        }

        foreach (explode("\n", $raw) as $line) {
            if (! str_starts_with($line, 'cpu ')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line)) ?: [];
            array_shift($parts);
            $names = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
            $values = [];

            foreach ($names as $i => $name) {
                $values[$name] = (int) ($parts[$i] ?? 0);
            }

            $values['cores'] = (int) substr_count($raw, "\ncpu");

            return $values;
        }

        return [];
    }
}
