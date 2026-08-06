<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die Zugangsdaten eines DNS-Anbieters hinterlegen, ansehen, entfernen.
 *
 * **Unmittelbar und nicht über die Warteschlange.** Ein eingereihter Vorgang
 * legt seine Argumente in `operations.payload` ab — ein DNS-Token läge damit im
 * Klartext in der Datenbank, dauerhaft und für jeden lesbar, der sie liest.
 * Dieselbe Entscheidung wie beim Hochladen eines Zertifikats (`docs/34 §5`).
 *
 * **Und das Token steht nicht in der Antwort.** Was hier ausgegeben wird, ist
 * der Profilname und der Anbieter — mehr gibt der Agent nicht heraus, auch
 * nicht die letzten Zeichen.
 *
 * **Warum die Kommandozeile zuerst.** Wer einen Server einrichtet, hat das
 * Token gerade im Terminal; die Oberfläche dazu kommt danach. Bis dahin ist
 * das hier der Weg, und er ist keiner, der später wegfällt: Ein Betreiber, der
 * ein Profil aus einem Skript setzt, will keine Seite dafür öffnen.
 */
final class DnsCredentials extends Command
{
    protected $signature = 'srvpanel:dns
        {--profile= : Der Profilname — betrieb oder abo-<nummer>}
        {--provider= : Der Anbieter: rfc2136, hetzner, cloudflare, netcup oder ipv64}
        {--token= : Das Zugangstoken des Anbieters}
        {--server= : Nur für rfc2136: der Nameserver, der die Aktualisierung annimmt}
        {--forget= : Ein Profil wieder entfernen}';

    protected $description = 'Zugangsdaten für DNS-01 hinterlegen — ohne Angaben zeigt es, was hinterlegt ist';

    public function handle(Client $agent): int
    {
        $forget = $this->option('forget');

        if (is_string($forget) && $forget !== '') {
            return $this->forget($agent, $forget);
        }

        $profile = $this->option('profile');
        $provider = $this->option('provider');
        $token = $this->option('token');

        if (! is_string($profile) || ! is_string($provider) || ! is_string($token)) {
            return $this->show($agent);
        }

        $config = ['token' => $token];
        $server = $this->option('server');

        if (is_string($server) && $server !== '') {
            $config['server'] = $server;
        }

        try {
            $result = $agent->call('dns.credential.store', [
                'profile' => $profile,
                'provider' => $provider,
                'config' => $config,
            ], $this->actor());
        } catch (AgentException $error) {
            $this->error('Zugangsdaten: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Hinterlegt: %s bei %s.',
            is_string($result['profile'] ?? null) ? $result['profile'] : $profile,
            Providers::label($result['provider'] ?? $provider),
        ));

        return self::SUCCESS;
    }

    /** Was hinterlegt ist — ohne jeden Ausschnitt des Tokens. */
    private function show(Client $agent): int
    {
        try {
            $result = $agent->call('dns.credential.list', [], $this->actor());
        } catch (AgentException $error) {
            $this->error('Zugangsdaten: '.$error->getMessage());

            return self::FAILURE;
        }

        $profiles = $result['profiles'] ?? [];

        if (! is_array($profiles) || $profiles === []) {
            $this->line('Es ist kein Profil hinterlegt.');
            $this->line('  Anbieter: '.implode(', ', Providers::keys()));

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $stored = $profile['stored_at'] ?? 0;

            $this->line(sprintf(
                '  %-16s %-20s seit %s',
                is_string($profile['profile'] ?? null) ? $profile['profile'] : '?',
                is_string($profile['provider_label'] ?? null) ? $profile['provider_label'] : '?',
                is_int($stored) && $stored > 0 ? date('d.m.Y', $stored) : '?',
            ));
        }

        return self::SUCCESS;
    }

    private function forget(Client $agent, string $profile): int
    {
        try {
            $agent->call('dns.credential.forget', ['profile' => $profile], $this->actor());
        } catch (AgentException $error) {
            $this->error('Zugangsdaten: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info('Entfernt: '.$profile);

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:dns'];
    }
}
