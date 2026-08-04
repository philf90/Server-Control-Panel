<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Das Zertifikat der Panel-Oberfläche nachsehen und bei Bedarf erneuern.
 *
 * **Warum es dieses Kommando gibt.** Die Prüfung, ob ein Zertifikat noch
 * lange genug gilt, stand von Anfang an in `panel.tls.ensure` — sie lief nur
 * nie: Aufgerufen wurde die Operation ausschliesslich von `srvpanel setup`.
 * Nach der Einrichtung rührte sie niemand mehr an, und das Zertifikat wäre
 * eines Tages abgelaufen, ohne dass etwas passiert. Der erste, der es
 * gemerkt hätte, wäre der Betreiber vor einer Fehlermeldung gewesen.
 *
 * **Ein Timer und kein Dauerlauf** — dieselbe Überlegung wie bei der
 * Speichermessung: Ein Zertifikat ändert seinen Zustand einmal im Jahr, und
 * ein Prozess, der darauf wartet, ist ein Prozess, den jemand überwachen muss.
 *
 * **Und kein Eintrag in `srvpanel update`.** Das Kommando stösst dort nur eine
 * systemd-Unit an und ist danach fertig; ein Update kann Monate auseinander
 * liegen. Eine Erneuerung, die an Updates hängt, erneuert genau dann nicht,
 * wenn ein Server lange unangetastet läuft — also im einzigen Fall, der zählt.
 */
final class EnsureTls extends Command
{
    protected $signature = 'srvpanel:tls {--force : Neu ausstellen, auch wenn das vorhandene noch gilt}';

    protected $description = 'Prüft das Zertifikat der Oberfläche und erneuert es, bevor es abläuft';

    public function handle(Client $agent): int
    {
        try {
            $result = $agent->call(
                'panel.tls.ensure',
                ['force' => (bool) $this->option('force')],
                ['source' => 'cli', 'command' => 'srvpanel:tls'],
            );
        } catch (AgentException $error) {
            $this->error('Zertifikat: '.$error->getMessage());

            return self::FAILURE;
        }

        $names = $result['names'] ?? ['dns' => [], 'ip' => []];

        // Die Namen stehen in beiden Fällen da, nicht nur beim Ausstellen.
        // „Gilt noch" beantwortet die Frage nicht, die jemand hat, der wegen
        // einer Browserwarnung hier nachsieht: unter welchem Namen denn?
        $liste = implode(', ', [...$names['dns'] ?? [], ...$names['ip'] ?? []]);

        if ($result['created'] !== true) {
            $this->info('Das Zertifikat gilt noch und deckt diesen Rechner ab.');
            $this->line('  Namen: '.$liste);

            return self::SUCCESS;
        }

        $this->info('Neues Zertifikat ausgestellt für '.$liste.'.');

        // Ob nginx es schon ausliefert, ist die Angabe, auf die es ankommt:
        // Ein erneuertes Zertifikat, das der Webserver nicht kennt, läuft
        // genauso ab wie ein nicht erneuertes.
        $this->line($result['reloaded'] === true
            ? '  nginx wurde neu geladen.'
            : '  nginx läuft nicht — es wird beim nächsten Start übernommen.');

        return self::SUCCESS;
    }
}
