<?php

declare(strict_types=1);

namespace App\Support\Kennzahlen;

use CloudSrv\Agent\Client;

/**
 * Der Sammler: fragt den Agenten nach Rohwerten und legt sie in die Ringpuffer.
 *
 * Er rechnet die CPU-Auslastung hier aus und nicht im Agenten — dafür braucht
 * es zwei Messungen, und die zweite hat, wer im Takt läuft. Der Agent liefert
 * die Zählerstände aus /proc/stat, so wie der Kernel sie führt.
 */
final class Sammler
{
    /** @var array<string,int>|null Zählerstände der vorigen Messung */
    private ?array $vorige = null;

    public function __construct(
        private readonly Client $agent,
        private readonly Speicher $speicher,
    ) {}

    /** @return array<string,list<float>> was geschrieben wurde */
    public function messe(): array
    {
        $info = $this->agent->ruf('system.info');
        $jetzt = microtime(true);
        $geschrieben = [];

        $cpu = $this->auslastung(is_array($info['cpu'] ?? null) ? $info['cpu'] : []);
        if ($cpu !== null) {
            $geschrieben['cpu'] = [$cpu['gesamt'], $cpu['iowait']];
            $this->speicher->puffer('cpu', 2)->schreibe($geschrieben['cpu'], $jetzt);
        }

        $ram = is_array($info['speicher'] ?? null) ? $info['speicher'] : [];
        $gesamt = (float) ($ram['gesamt'] ?? 0);
        if ($gesamt > 0) {
            $belegt = $gesamt - (float) ($ram['verfuegbar'] ?? 0);
            $geschrieben['ram'] = [round($belegt / $gesamt * 100, 2), $belegt];
            $this->speicher->puffer('ram', 2)->schreibe($geschrieben['ram'], $jetzt);
        }

        $load = is_array($info['load'] ?? null) ? $info['load'] : [];
        if ($load !== []) {
            $geschrieben['load'] = [(float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0)];
            $this->speicher->puffer('load', 3)->schreibe($geschrieben['load'], $jetzt);
        }

        return $geschrieben;
    }

    /**
     * Auslastung aus zwei Zählerständen.
     *
     * Beim ersten Aufruf gibt es keine Differenz und deshalb keinen Wert —
     * eine geschätzte erste Messung wäre eine Zahl, die nichts misst.
     *
     * @param  array<string,int>  $roh
     * @return array{gesamt:float,iowait:float}|null
     */
    private function auslastung(array $roh): ?array
    {
        if ($roh === []) {
            return null;
        }

        $vorher = $this->vorige;
        $this->vorige = $roh;

        if ($vorher === null) {
            return null;
        }

        $felder = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $summe = 0.0;
        $unbeschaeftigt = 0.0;
        $warten = 0.0;

        foreach ($felder as $feld) {
            $diff = (float) (($roh[$feld] ?? 0) - ($vorher[$feld] ?? 0));

            if ($diff < 0) {
                // Der Zähler ist zurückgesprungen — das passiert nach einem
                // Neustart. Eine Auslastung daraus wäre erfunden.
                return null;
            }

            $summe += $diff;

            if ($feld === 'idle') {
                $unbeschaeftigt = $diff;
            }
            if ($feld === 'iowait') {
                $warten = $diff;
            }
        }

        if ($summe <= 0.0) {
            return null;
        }

        return [
            'gesamt' => round((1 - $unbeschaeftigt / $summe) * 100, 2),
            'iowait' => round($warten / $summe * 100, 2),
        ];
    }
}
