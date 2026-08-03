<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Plans\Quotas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $this->step('Standardplan');
        $this->done($this->ensureStandardPlan());

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

        [$host, $why] = $this->reachableHost();

        $this->newLine();
        $this->line('  Das Panel ist erreichbar unter:');
        $this->line(sprintf('  <options=bold>https://%s:%d/</>', $host, $port));

        if ($why !== null) {
            $this->newLine();
            $this->line('  '.$why);
        }

        $this->newLine();
        $this->line('  Das Zertifikat ist selbstsigniert — der Browser warnt beim ersten Aufruf.');
        $this->line('  Ein Zertifikat von Let\'s Encrypt kommt mit der Ausbaustufe P4.');
        $this->newLine();

        // Der letzte Satz muss der nächste Schritt sein.
        //
        // Hier stand „Anmeldung und Konten kommen mit der Ausbaustufe P1" —
        // richtig, solange es keine gab, und danach stehengeblieben. Wer das
        // nach einer erfolgreichen Einrichtung liest, hört auf zu suchen:
        // Die Anmeldemaske ist da, ein Konto nicht, und der Text sagt ihm,
        // das sei so gewollt.
        if (Account::query()->where('type', AccountType::Admin)->exists()) {
            $this->line('  Ein Adminkonto ist vorhanden — die Anmeldung steht offen.');
        } else {
            $this->comment('  Es gibt noch kein Adminkonto. Ohne eines kommt niemand hinein:');
            $this->newLine();
            $this->line('      srvpanel admin --generate');
            $this->newLine();
            $this->line('  Das erzeugt ein Passwort und zeigt es genau einmal an.');
        }

        return self::SUCCESS;
    }

    /**
     * Dafür sorgen, dass es einen Standardplan gibt.
     *
     * **Das ist kein Testdatensatz.** Der Seeder dieses Projekts ist mit
     * Absicht leer (siehe DatabaseSeeder) — ein Panel, das als root läuft,
     * bringt keine Konten mit bekanntem Passwort mit. Ein Plan ist etwas
     * anderes: Er trägt keine Zugangsdaten, und ohne einen liesse sich kein
     * einziges Abonnement anlegen. Der Betreiber stünde vor einem Formular,
     * das nach einem Plan fragt, den es nicht gibt.
     *
     * Die Werte sind die Vorgabewerte des Katalogs. Sie sind ein Anfang und
     * keine Empfehlung; wer sie ändern will, findet den Plan unter „Pläne".
     */
    private function ensureStandardPlan(): string
    {
        // Ohne Migrationen kann die Tabelle fehlen — `srvpanel setup
        // --skip-migrations` auf einem frischen System. Dann ist die
        // Antwort „später", nicht ein Absturz nach drei erfolgreichen
        // Schritten.
        if (! Schema::hasTable('plans')) {
            return 'übersprungen (keine Tabellen)';
        }

        $existing = Plan::standard();

        if ($existing !== null) {
            return sprintf('war vorhanden (%s)', $existing->name);
        }

        // Es kann Pläne geben, aber keinen mit der Marke — etwa nachdem
        // jemand den Standardplan von Hand aus der Datenbank entfernt hat.
        // Dann wird der älteste zum Standard, statt einen zweiten anzulegen.
        $orphan = Plan::query()->orderBy('id')->first();

        if ($orphan !== null) {
            $orphan->update(['is_default' => true]);

            return sprintf('%s zum Standard gemacht', $orphan->name);
        }

        $plan = Plan::query()->create([
            'name' => 'Standard',
            'description' => 'Vorgabewerte der Ersteinrichtung.',
            'quotas' => Quotas::defaults(),
            'features' => Quotas::featureDefaults(),
            'is_default' => true,
        ]);

        return sprintf('angelegt (%s)', $plan->name);
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

    /**
     * Eine Adresse, unter der das Panel tatsächlich zu erreichen ist.
     *
     * **Der Rechnername allein taugt oft nicht.** `gethostname()` liefert auf
     * den meisten Servern den kurzen Namen — „cloudsrv24" statt
     * „cloudsrv24.example.de". Als Link ist das wertlos: Außerhalb des
     * Rechners löst ihn niemand auf. Genau so stand es hier, und der erste
     * Mensch, der die Einrichtung durchlaufen ließ, bekam eine Adresse, die
     * nicht funktioniert.
     *
     * Die Reihenfolge geht vom Brauchbarsten zum Sichersten: ein Name mit
     * Punkt, sonst der Name aus der Rückwärtsauflösung, sonst die IP-Adresse.
     * Die IP ist hässlich und stimmt immer — das ist hier die richtige
     * Rangfolge.
     *
     * @return array{0:string,1:?string} Adresse und, falls nötig, die Erklärung
     */
    private function reachableHost(): array
    {
        $name = gethostname() ?: '';

        if (str_contains($name, '.')) {
            return [$name, null];
        }

        $address = $this->primaryAddress();

        if ($address === null) {
            return [$name !== '' ? $name : 'localhost', null];
        }

        $reverse = gethostbyaddr($address);

        if (is_string($reverse) && str_contains($reverse, '.') && $reverse !== $address) {
            return [$reverse, null];
        }

        return [$address, sprintf(
            'Der Rechnername „%s" enthält keine Domain und ist von außen nicht'."\n".
            '  auflösbar; deshalb steht hier die IP-Adresse.',
            $name !== '' ? $name : 'unbekannt',
        )];
    }

    /**
     * Die IP-Adresse, über die dieser Rechner nach außen spricht.
     *
     * Über einen verbundenen UDP-Socket: Das schickt kein einziges Paket — der
     * Kernel wählt beim `connect` nur die Route aus und trägt die passende
     * Quelladresse ein, und die lesen wir zurück. Der Weg über
     * `gethostbyname(gethostname())` liefert dagegen auf vielen Servern
     * 127.0.1.1, weil genau das in /etc/hosts steht.
     */
    private function primaryAddress(): ?string
    {
        if (! function_exists('socket_create')) {
            return null;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            return null;
        }

        // Dokumentationsadresse nach RFC 5737 — sie wird nie erreicht und
        // soll es auch nicht.
        $connected = @socket_connect($socket, '203.0.113.1', 53);
        $address = null;

        if ($connected && @socket_getsockname($socket, $local)) {
            $address = $local;
        }

        socket_close($socket);

        return is_string($address) && $address !== '' && ! str_starts_with($address, '127.') ? $address : null;
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
