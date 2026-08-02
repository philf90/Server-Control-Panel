<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\Context;
use CloudSrv\Agent\Op;

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
            'distribution' => $this->distribution(),
            'uptime_s' => $this->uptime(),
            'load' => $this->load(),
            'memory' => $this->memory(),
            'cpu' => $this->cpuRaw(),
        ];
    }

    /** @return array{name:string,version:string} */
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
            if (! preg_match('/^([A-Za-z()_]+):\s+(\d+)\s*kB$/', trim($line), $match)) {
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
