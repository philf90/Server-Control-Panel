<?php

declare(strict_types=1);

namespace App\Console\Commands;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Client;
use Illuminate\Console\Command;

/**
 * `cloudsrv update` — stößt das Update an und tritt dann zur Seite.
 *
 * Es wartet bewusst nicht auf das Ergebnis: Der Lauf beendet den Prozess, der
 * ihn angestoßen hat. Was danach passiert — Migrationen, Umschalten,
 * Bereitschaftsprüfung, im Zweifel das Zurücknehmen — steht im
 * postinstall-Skript des Pakets und im Protokoll unter
 * /var/log/cloudsrv/update.log.
 */
final class Update extends Command
{
    protected $signature = 'cloudsrv:update';

    protected $description = 'Installiert eine neue Fassung des Panels aus der Paketquelle';

    public function handle(Client $agent): int
    {
        try {
            $result = $agent->call('panel.update', [], ['source' => 'cli', 'command' => 'cloudsrv:update']);
        } catch (AgentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('  Das Update läuft als <options=bold>%s</>.', $result['unit']));
        $this->line('  Das Panel startet dabei neu; diese Sitzung endet vorher.');
        $this->newLine();
        $this->line(sprintf('  Zusehen:   tail -f %s', $result['log']));
        $this->line(sprintf('  Zustand:   systemctl status %s', $result['unit']));
        $this->newLine();
        $this->comment('  Antwortet die Bereitschaftsprüfung danach nicht, nimmt das Paket die Fassung selbst zurück.');

        return self::SUCCESS;
    }
}
