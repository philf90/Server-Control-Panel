<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Metrics\Collector;
use CloudSrv\Agent\AgentException;
use Illuminate\Console\Command;

/**
 * Der Dauerlauf hinter den Verlaufskacheln (§4.6 des Plans).
 *
 * Kein Zeitplan-Eintrag, sondern eine eigene Unit: Der Zeitplan von Laravel
 * hat Minutenauflösung, gebraucht werden zehn Sekunden. Ein Prozess, der
 * schläft und misst, ist dafür das einfachere Mittel als sechs Läufe je
 * Minute, die jedes Mal das Framework hochfahren.
 */
final class CollectMetrics extends Command
{
    protected $signature = 'cloudsrv:metrics
                            {--once : Nur eine Messung, dann beenden}
                            {--interval= : Sekunden zwischen zwei Messungen}';

    protected $description = 'Misst Systemkennzahlen im Takt und schreibt sie in die RingBuffer';

    public function handle(Collector $collector): int
    {
        $interval = (int) ($this->option('interval') ?: config('cloudsrv.metrics.interval_s'));
        $once = (bool) $this->option('once');
        $running = true;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            }, false);
            pcntl_signal(SIGINT, static function () use (&$running): void {
                $running = false;
            }, false);
        }

        $failures = 0;

        while ($running) {
            $startedAt = microtime(true);

            try {
                $written = $collector->collect();
                $failures = 0;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf('%s: %s', date('H:i:s'), implode(', ', array_keys($written))));
                }
            } catch (AgentException $error) {
                $failures++;

                // Ein nicht erreichbarer Agent ist nach einem Update der
                // Normalfall für ein paar Sekunden. Erst wenn es dabei bleibt,
                // gehört es ins Log — sonst füllt ein Neustart die Platte mit
                // Meldungen über einen Zustand, der sich selbst behebt.
                if ($failures === 1 || $failures % 30 === 0) {
                    $this->error(sprintf('Messung scheiterte (%dx): %s', $failures, $error->getMessage()));
                }
            }

            if ($once) {
                return self::SUCCESS;
            }

            $rest = $interval - (microtime(true) - $startedAt);

            if ($rest > 0) {
                usleep((int) ($rest * 1_000_000));
            }
        }

        return self::SUCCESS;
    }
}
