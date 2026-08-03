<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use SrvPanel\Agent\Client;

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

    /** @var array{rx:int,tx:int}|null */
    private ?array $previousNetwork = null;

    /** @var array{read:int,write:int}|null */
    private ?array $previousDisk = null;

    /** Zeitpunkt der vorigen Messung — die Rate braucht die Spanne, nicht den Takt. */
    private ?float $previousAt = null;

    public function __construct(
        private readonly Client $agent,
        private readonly Store $store,
    ) {}

    /** @return array<string,list<float>> was geschrieben wurde */
    public function collect(): array
    {
        return $this->record($this->agent->call('system.info'), microtime(true));
    }

    /**
     * Die Rohwerte einer Messung verrechnen und ablegen.
     *
     * **Getrennt vom Holen, und zwar wegen der Prüfbarkeit.** Was hier
     * passiert, ist Rechnen: Differenzen, Zeitspannen, Grenzfälle. Solange es
     * hinter einem Socket steckte, war es nur mit laufendem Agenten zu prüfen —
     * und dann prüft man den Agenten und nicht die Rechnung. Der Zeitpunkt ist
     * ein Parameter, damit ein Test eine Spanne vorgeben kann, ohne zu warten.
     *
     * @param  array<string,mixed>  $info
     * @return array<string,list<float>>
     */
    public function record(array $info, float $now): array
    {
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

        // Netz und Datenträger sind Zählerstände wie die CPU: Eine Rate gibt es
        // erst ab der zweiten Messung. Fehlt der Abschnitt ganz — ein älterer
        // Agent —, wird auch nichts gemerkt; sonst entstünde beim ersten
        // Auftauchen eine Rate aus dem Zählerstand seit dem Systemstart.
        $network = $info['network'] ?? null;

        if (is_array($network)) {
            $counters = ['rx' => (int) ($network['rx'] ?? 0), 'tx' => (int) ($network['tx'] ?? 0)];
            $rates = $this->rates($counters, $this->previousNetwork, $now);
            $this->previousNetwork = $counters;

            if ($rates !== null) {
                $written['network'] = [$rates['rx'], $rates['tx']];
                $this->store->buffer('network', 2)->write($written['network'], $now);
            }
        }

        $disk = $info['disk_io'] ?? null;

        if (is_array($disk)) {
            $counters = ['read' => (int) ($disk['read'] ?? 0), 'write' => (int) ($disk['write'] ?? 0)];
            $rates = $this->rates($counters, $this->previousDisk, $now);
            $this->previousDisk = $counters;

            if ($rates !== null) {
                $written['disk_io'] = [$rates['read'], $rates['write']];
                $this->store->buffer('disk_io', 2)->write($written['disk_io'], $now);
            }
        }

        // Ganz zum Schluss: Beide Raten oben brauchen dieselbe Spanne, und die
        // ist die zum vorigen Durchlauf — nicht die zur vorigen Kennzahl.
        $this->previousAt = $now;

        return $written;
    }

    /**
     * Aus zwei Zählerständen eine Rate je Sekunde.
     *
     * Gerechnet wird mit der tatsächlich vergangenen Zeit und nicht mit dem
     * eingestellten Takt: Der Dienst kann ins Stocken geraten, und eine Rate,
     * die zehn Sekunden annimmt, wo zwanzig vergangen sind, zeigt die Hälfte.
     *
     * Ein rückläufiger Zähler bedeutet Neustart oder Überlauf. Daraus eine Rate
     * zu bilden hieße, eine Zahl zu erfinden — es gibt dann eben keine.
     *
     * @param  array<string,int>  $now
     * @param  array<string,int>|null  $before
     * @return array<string,float>|null
     */
    private function rates(array $now, ?array $before, float $at): ?array
    {
        if ($before === null || $this->previousAt === null) {
            return null;
        }

        $span = $at - $this->previousAt;

        if ($span <= 0.0) {
            return null;
        }

        $rates = [];

        foreach ($now as $key => $value) {
            $diff = $value - ($before[$key] ?? 0);

            if ($diff < 0) {
                return null;
            }

            $rates[$key] = round($diff / $span, 2);
        }

        return $rates;
    }

    /**
     * Auslastung aus zwei Zählerständen.
     *
     * Beim ersten Aufruf gibt es keine Differenz und deshalb keinen Wert —
     * eine geschätzte erste Messung wäre eine Zahl, die nichts misst.
     *
     * @param  array<string,int>  $raw
     * @return array{total:float,iowait:float}|null
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
