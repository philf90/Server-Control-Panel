<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\Kontext;
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
    public function __construct(private readonly string $procWurzel = '/proc') {}

    public static function name(): string
    {
        return 'system.info';
    }

    public static function veraendernd(): bool
    {
        return false;
    }

    public function fuehreAus(array $args, Kontext $kontext): array
    {
        return [
            'hostname' => php_uname('n'),
            'kernel' => php_uname('r'),
            'distribution' => $this->distribution(),
            'uptime_s' => $this->uptime(),
            'load' => $this->load(),
            'speicher' => $this->speicher(),
            'cpu' => $this->cpuRoh(),
        ];
    }

    /** @return array{name:string,version:string} */
    private function distribution(): array
    {
        $name = 'unbekannt';
        $version = '';

        foreach (['/etc/os-release', '/usr/lib/os-release'] as $datei) {
            if (! is_readable($datei)) {
                continue;
            }

            foreach (file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
                if (! str_contains($zeile, '=')) {
                    continue;
                }
                [$schluessel, $wert] = explode('=', $zeile, 2);
                $wert = trim($wert, "\"'");

                if ($schluessel === 'NAME') {
                    $name = $wert;
                }
                if ($schluessel === 'VERSION_ID') {
                    $version = $wert;
                }
            }
            break;
        }

        return ['name' => $name, 'version' => $version];
    }

    private function uptime(): int
    {
        $roh = @file_get_contents($this->procWurzel.'/uptime');

        return $roh === false ? 0 : (int) (float) strtok($roh, ' ');
    }

    /** @return array{0:float,1:float,2:float} */
    private function load(): array
    {
        $roh = @file_get_contents($this->procWurzel.'/loadavg');

        if ($roh === false) {
            return [0.0, 0.0, 0.0];
        }

        $teile = preg_split('/\s+/', trim($roh)) ?: [];

        return [(float) ($teile[0] ?? 0), (float) ($teile[1] ?? 0), (float) ($teile[2] ?? 0)];
    }

    /** @return array<string,int> Werte in Bytes */
    private function speicher(): array
    {
        $roh = @file_get_contents($this->procWurzel.'/meminfo');

        if ($roh === false) {
            return [];
        }

        $gesucht = [
            'MemTotal' => 'gesamt',
            'MemAvailable' => 'verfuegbar',
            'MemFree' => 'frei',
            'Buffers' => 'puffer',
            'Cached' => 'cache',
            'SwapTotal' => 'swap_gesamt',
            'SwapFree' => 'swap_frei',
        ];

        $werte = [];

        foreach (explode("\n", $roh) as $zeile) {
            if (! preg_match('/^([A-Za-z()_]+):\s+(\d+)\s*kB$/', trim($zeile), $treffer)) {
                continue;
            }
            if (isset($gesucht[$treffer[1]])) {
                $werte[$gesucht[$treffer[1]]] = (int) $treffer[2] * 1024;
            }
        }

        return $werte;
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
    private function cpuRoh(): array
    {
        $roh = @file_get_contents($this->procWurzel.'/stat');

        if ($roh === false) {
            return [];
        }

        foreach (explode("\n", $roh) as $zeile) {
            if (! str_starts_with($zeile, 'cpu ')) {
                continue;
            }

            $teile = preg_split('/\s+/', trim($zeile)) ?: [];
            array_shift($teile);
            $namen = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
            $werte = [];

            foreach ($namen as $i => $name) {
                $werte[$name] = (int) ($teile[$i] ?? 0);
            }

            $werte['kerne'] = (int) substr_count($roh, "\ncpu");

            return $werte;
        }

        return [];
    }
}
