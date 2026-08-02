<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use CloudSrv\Agent\Client;

/**
 * Der Collector: fragt den Agenten nach Rohwerten und legt sie in die RingBuffer.
 *
 * Er rechnet die CPU-Auslastung hier aus und nicht im Agenten — dafür braucht
 * es zwei Messungen, und die zweite hat, wer im Takt läuft. Der Agent liefert
 * die Zählerstände aus /proc/stat, so wie der Kernel sie führt.
 */
final class Collector
{
    /** @var array<string,int>|null Zählerstände der vorigen Messung */
    private ?array $previous = null;

    public function __construct(
        private readonly Client $agent,
        private readonly Store $store,
    ) {}

    /** @return array<string,list<float>> was geschrieben wurde */
    public function collect(): array
    {
        $info = $this->agent->call('system.info');
        $now = microtime(true);
        $written = [];

        $cpu = $this->utilization(is_array($info['cpu'] ?? null) ? $info['cpu'] : []);
        if ($cpu !== null) {
            $written['cpu'] = [$cpu['total'], $cpu['iowait']];
            $this->store->buffer('cpu', 2)->write($written['cpu'], $now);
        }

        $ram = is_array($info['memory'] ?? null) ? $info['memory'] : [];
        $total = (float) ($ram['total'] ?? 0);
        if ($total > 0) {
            $used = $total - (float) ($ram['available'] ?? 0);
            $written['ram'] = [round($used / $total * 100, 2), $used];
            $this->store->buffer('ram', 2)->write($written['ram'], $now);
        }

        $load = is_array($info['load'] ?? null) ? $info['load'] : [];
        if ($load !== []) {
            $written['load'] = [(float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0)];
            $this->store->buffer('load', 3)->write($written['load'], $now);
        }

        return $written;
    }

    /**
     * Auslastung aus zwei Zählerständen.
     *
     * Beim ersten Aufruf gibt es keine Differenz und deshalb keinen Wert —
     * eine geschätzte erste Messung wäre eine Zahl, die nichts misst.
     *
     * @param  array<string,int>  $raw
     * @return array{gesamt:float,iowait:float}|null
     */
    private function utilization(array $raw): ?array
    {
        if ($raw === []) {
            return null;
        }

        $before = $this->previous;
        $this->previous = $raw;

        if ($before === null) {
            return null;
        }

        $fields = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $sum = 0.0;
        $idle = 0.0;
        $iowait = 0.0;

        foreach ($fields as $field) {
            $diff = (float) (($raw[$field] ?? 0) - ($before[$field] ?? 0));

            if ($diff < 0) {
                // Der Zähler ist zurückgesprungen — das passiert nach einem
                // Neustart. Eine Auslastung daraus wäre erfunden.
                return null;
            }

            $sum += $diff;

            if ($field === 'idle') {
                $idle = $diff;
            }
            if ($field === 'iowait') {
                $iowait = $diff;
            }
        }

        if ($sum <= 0.0) {
            return null;
        }

        return [
            'total' => round((1 - $idle / $sum) * 100, 2),
            'iowait' => round($iowait / $sum * 100, 2),
        ];
    }
}
