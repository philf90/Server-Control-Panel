<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Kennzahlen\Sammler;
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
final class KennzahlenSammeln extends Command
{
    protected $signature = 'cloudsrv:kennzahlen
                            {--einmal : Nur eine Messung, dann beenden}
                            {--takt= : Sekunden zwischen zwei Messungen}';

    protected $description = 'Misst Systemkennzahlen im Takt und schreibt sie in die Ringpuffer';

    public function handle(Sammler $sammler): int
    {
        $takt = (int) ($this->option('takt') ?: config('cloudsrv.kennzahlen.takt_s'));
        $einmal = (bool) $this->option('einmal');
        $laeuft = true;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$laeuft): void {
                $laeuft = false;
            }, false);
            pcntl_signal(SIGINT, static function () use (&$laeuft): void {
                $laeuft = false;
            }, false);
        }

        $fehlerInFolge = 0;

        while ($laeuft) {
            $begonnen = microtime(true);

            try {
                $geschrieben = $sammler->messe();
                $fehlerInFolge = 0;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf('%s: %s', date('H:i:s'), implode(', ', array_keys($geschrieben))));
                }
            } catch (AgentException $fehler) {
                $fehlerInFolge++;

                // Ein nicht erreichbarer Agent ist nach einem Update der
                // Normalfall für ein paar Sekunden. Erst wenn es dabei bleibt,
                // gehört es ins Log — sonst füllt ein Neustart die Platte mit
                // Meldungen über einen Zustand, der sich selbst behebt.
                if ($fehlerInFolge === 1 || $fehlerInFolge % 30 === 0) {
                    $this->error(sprintf('Messung scheiterte (%dx): %s', $fehlerInFolge, $fehler->getMessage()));
                }
            }

            if ($einmal) {
                return self::SUCCESS;
            }

            $rest = $takt - (microtime(true) - $begonnen);

            if ($rest > 0) {
                usleep((int) ($rest * 1_000_000));
            }
        }

        return self::SUCCESS;
    }
}
