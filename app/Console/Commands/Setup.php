<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\EnvFile;

/**
 * Die Ersteinrichtung: `srvpanel setup`.
 *
 * Sie ist wiederholbar. Ein zweiter Lauf legt nichts doppelt an, wechselt
 * keinen Schlüssel und wirft keine bestehende Datenbank weg — das ist die
 * Bedingung dafür, dass sie auch nach einer misslungenen Installation noch
 * benutzbar ist, und die einzige Art, wie ein Betreiber sie tatsächlich
 * benutzt.
 *
 * Alles Privilegierte geht über den Agenten. Dieses Kommando läuft als
 * Benutzer `srvpanel` und legt selbst weder Datenbank noch Zertifikat an.
 */
final class Setup extends Command
{
    protected $signature = 'srvpanel:setup
                            {--port=8443 : Port der Panel-Oberfläche}
                            {--skip-migrations : Migrationen nicht ausführen}';

    protected $description = 'Richtet Datenbank, Schlüssel, Zertifikat und Webserver für das Panel ein';

    public function handle(Client $agent): int
    {
        $port = (int) $this->option('port');

        if ($port < 1 || $port > 65535) {
            $this->error('Der Port muss zwischen 1 und 65535 liegen.');

            return self::FAILURE;
        }

        if (! $agent->reachable()) {
            $this->error('Der Agent ist nicht erreichbar.');
            $this->line('  systemctl status srvpanel-agentd');

            return self::FAILURE;
        }

        if (($missing = $this->missingExtensions()) !== []) {
            return $this->reportMissingExtensions($missing);
        }

        try {
            $this->step('Datenbank und Schlüssel');
            $provision = $agent->call('panel.provision', ['port' => $port], $this->actor());
            $this->done(sprintf(
                '%s (Datenbank %s, Benutzer %s)',
                $provision['created'] ? 'angelegt' : 'war vorhanden',
                $provision['database'],
                $provision['user'],
            ));

            $this->step('Zertifikat');
            $tls = $agent->call('panel.tls.ensure', [], $this->actor());
            $this->done($tls['created'] ? 'neu ausgestellt (selbstsigniert)' : 'vorhanden und gültig');

            $this->step('Webserver');
            $vhost = $agent->call('panel.vhost.apply', ['port' => $port], $this->actor());
            $this->done(sprintf('%s auf Port %d', $vhost['replaced'] ? 'ersetzt' : 'angelegt', $vhost['port']));
        } catch (AgentException $error) {
            $this->newLine();
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('skip-migrations')) {
            $this->step('Migrationen');

            // Die Umgebungsdatei ist gerade erst entstanden — dieser Prozess
            // hat sie beim Start noch nicht gesehen, und Artisan::call läuft
            // im selben Prozess. Ohne diese Zeilen liefe die Migration gegen
            // die Vorgabewerte statt gegen die eben angelegte Datenbank, und
            // zwar mit einer Fehlermeldung, die auf alles Mögliche deutet.
            $this->applyDatabaseConfig((string) $provision['env']);

            $code = Artisan::call('migrate', ['--force' => true]);
            $this->done($code === 0 ? 'ausgeführt' : 'fehlgeschlagen');

            if ($code !== 0) {
                $this->error(trim(Artisan::output()));

                return self::FAILURE;
            }
        }

        try {
            $this->step('Dienste');
            foreach (['srvpanel-web', 'srvpanel-worker', 'srvpanel-metrics'] as $unit) {
                $agent->call('service.action', ['unit' => $unit.'.service', 'action' => 'enable'], $this->actor());
                $agent->call('service.action', ['unit' => $unit.'.service', 'action' => 'restart'], $this->actor());
            }
            $this->done('gestartet');
        } catch (AgentException $error) {
            $this->newLine();
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Das Panel ist erreichbar unter:');
        $this->line(sprintf('  <options=bold>https://%s:%d/</>', gethostname() ?: 'localhost', $port));
        $this->newLine();
        $this->line('  Das Zertifikat ist selbstsigniert — der Browser warnt beim ersten Aufruf.');
        $this->line('  Ein Zertifikat von Let\'s Encrypt kommt mit der Ausbaustufe P4.');
        $this->newLine();

        // Kein Einmal-Link: Es gibt in dieser Ausbaustufe noch keine Konten,
        // hinter die er führen könnte. Er entsteht in P1 zusammen mit dem
        // Administratorkonto — eine Tür zu einem leeren Raum wäre kein
        // Fortschritt, sondern eine Attrappe.
        $this->comment('  Anmeldung und Konten kommen mit der Ausbaustufe P1.');

        return self::SUCCESS;
    }

    /**
     * Die frisch geschriebenen Zugangsdaten in die laufende Konfiguration
     * heben, damit die Migration die neue Datenbank trifft.
     */
    private function applyDatabaseConfig(string $envPath): void
    {
        $values = (new EnvFile($envPath))->read();

        if ($values === []) {
            return;
        }

        $connection = $values['DB_CONNECTION'] ?? 'mariadb';

        config([
            'database.default' => $connection,
            "database.connections.{$connection}.host" => $values['DB_HOST'] ?? '127.0.0.1',
            "database.connections.{$connection}.port" => $values['DB_PORT'] ?? '3306',
            "database.connections.{$connection}.database" => $values['DB_DATABASE'] ?? 'srvpanel',
            "database.connections.{$connection}.username" => $values['DB_USERNAME'] ?? 'srvpanel',
            "database.connections.{$connection}.password" => $values['DB_PASSWORD'] ?? '',
        ]);

        // Die Verbindung kann bereits mit den alten Werten aufgebaut worden
        // sein; ohne das Trennen bleibt sie bestehen und die Migration liefe
        // weiter ins Leere.
        DB::purge($connection);
    }

    /**
     * Erweiterungen, die fehlen — geprüft, bevor irgendetwas angefasst wird.
     *
     * **Warum das hier steht, obwohl das Paket sie als Abhängigkeit führt.**
     * Es hat sie eine Zeit lang nicht geführt, und der Fehlschlag kam dann
     * mitten in der Einrichtung: Datenbank angelegt, Zertifikat ausgestellt,
     * Webserver geschrieben — und dann „could not find driver" aus einer
     * Framework-Datei, die nicht sagt, welches Paket fehlt. Wer das liest,
     * sucht in der falschen Richtung.
     *
     * Die Prüfung kostet nichts und beantwortet die Frage in einer Zeile. Sie
     * läuft vor dem ersten Schritt, damit ein Abbruch nichts halb Fertiges
     * hinterlässt.
     *
     * @return array<string,string> Erweiterung => Debian-Paket
     */
    private function missingExtensions(): array
    {
        $required = [
            'pdo_mysql' => 'php8.4-mysql',
            'mbstring' => 'php8.4-mbstring',
            'dom' => 'php8.4-xml',
            'curl' => 'php8.4-curl',
            'openssl' => 'php8.4-cli',
            'tokenizer' => 'php8.4-cli',
            'sockets' => 'php8.4-cli',
        ];

        return array_filter(
            $required,
            static fn (string $package, string $extension): bool => ! extension_loaded($extension),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @param array<string,string> $missing */
    private function reportMissingExtensions(array $missing): int
    {
        $this->newLine();
        $this->error('Dem PHP dieser Installation fehlen Erweiterungen:');
        $this->newLine();

        foreach ($missing as $extension => $package) {
            $this->line(sprintf('    %-12s aus %s', $extension, $package));
        }

        $this->newLine();
        $this->line('  Nachinstallieren:');
        $this->line('      apt install '.implode(' ', array_unique(array_values($missing))));
        $this->newLine();
        $this->comment('  Das sollte nicht passieren — sie stehen als Abhängigkeit im Paket.');
        $this->comment('  Bitte melden: https://github.com/philf90/Server-Control-Panel/issues');
        $this->newLine();

        return self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:setup', 'uid' => function_exists('posix_getuid') ? posix_getuid() : null];
    }

    private function step(string $text): void
    {
        $this->output->write(sprintf('  %-22s ', $text.' …'));
    }

    private function done(string $text): void
    {
        $this->output->writeln('<info>'.$text.'</info>');
    }
}
