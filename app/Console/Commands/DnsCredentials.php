<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Acme\Dns\Rfc2136;
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
 * **Und das Geheimnis steht nicht in der Antwort.** Was hier ausgegeben wird,
 * ist der Profilname, der Anbieter und die Zonen — mehr gibt der Agent nicht
 * heraus, auch nicht die letzten Zeichen.
 *
 * **Warum die Kommandozeile zuerst.** Wer einen Server einrichtet, hat den
 * Schlüssel gerade im Terminal; die Oberfläche dazu kommt danach. Bis dahin ist
 * das hier der Weg, und er ist keiner, der später wegfällt: Ein Betreiber, der
 * ein Profil aus einem Skript setzt, will keine Seite dafür öffnen.
 *
 * **Und die Angaben sind die von RFC 2136**, weil das der einzige Anbieter ist,
 * den es heute gibt. Ein `--token` für Hetzner oder Cloudflare stünde hier als
 * Angebot, das der Agent abweist — genau die Sorte Zeichenkette, die auf nichts
 * zeigt. Es kommt mit Schritt 9 des Plans, zusammen mit den vier Anbietern.
 *
 * **Das Geheimnis wird gefragt, wenn es nicht dasteht.** Was in der
 * Kommandozeile steht, steht danach in der Verlaufsdatei der Shell und in
 * `ps` — bei einem Schlüssel, der eine ganze Zone öffnet, ist das kein
 * Schönheitsfehler.
 */
final class DnsCredentials extends Command
{
    protected $signature = 'srvpanel:dns
        {--profile= : Der Profilname — betrieb oder abo-<nummer>}
        {--provider=rfc2136 : Der Anbieter; heute geht nur rfc2136}
        {--server= : Der Nameserver, der die Aktualisierung annimmt}
        {--port= : Sein Port, sofern nicht 53}
        {--zone=* : Eine Zone, die dieses Profil ändern darf — mehrfach möglich}
        {--key= : Der Name des TSIG-Schlüssels}
        {--secret= : Sein Geheimnis in Base64; ohne diese Angabe wird gefragt}
        {--algorithm= : Das TSIG-Verfahren, sonst hmac-sha256}
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
        $zones = $this->option('zone');

        // Ohne Profil und Zone ist das hier keine Eingabe, sondern die Frage
        // „was ist eigentlich hinterlegt?".
        if (! is_string($profile) || $profile === '' || ! is_string($provider) || ! is_array($zones) || $zones === []) {
            return $this->show($agent);
        }

        $names = array_values(array_map(strval(...), $zones));

        try {
            $result = $agent->call('dns.credential.store', [
                'profile' => $profile,
                'provider' => $provider,
                'config' => $this->configFor($names),
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

        $this->line('  Zonen: '.implode(', ', $names));

        return self::SUCCESS;
    }

    /**
     * Die Angaben zu RFC 2136 — geprüft wird sie der Agent.
     *
     * Hier steht nur, was aus welcher Angabe wird; ob es zusammenpasst,
     * entscheidet {@see Rfc2136::configure()}. Zwei Fassungen derselben Prüfung
     * wären eine zu viel, und die zweite ist die, die veraltet.
     *
     * @param  list<string>  $zones
     * @return array<string, mixed>
     */
    private function configFor(array $zones): array
    {
        $config = [
            'server' => $this->optionText('server'),
            'zones' => $zones,
            'key_name' => $this->optionText('key'),
            'secret' => $this->keySecret(),
        ];

        $port = $this->optionText('port');
        $algorithm = $this->optionText('algorithm');

        if ($port !== '') {
            $config['port'] = (int) $port;
        }

        if ($algorithm !== '') {
            $config['algorithm'] = $algorithm;
        }

        return $config;
    }

    /**
     * Eine Angabe, die eine Zeichenkette ist — oder eben keine.
     *
     * **Nicht `text()`.** Ein Name, der der Basisklasse gehört, bricht schon
     * beim Laden der Klasse und nicht beim Ausführen; in diesem Projekt ist
     * das dreimal passiert — `count()`, `configure()`, `name()` — und beim
     * dritten Mal stand nicht ein Kommando still, sondern `artisan` mit allen.
     */
    private function optionText(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? trim($value) : '';
    }

    /** Steht es nicht in der Kommandozeile, wird danach gefragt — siehe oben. */
    private function keySecret(): string
    {
        $secret = $this->optionText('secret');

        if ($secret !== '') {
            return $secret;
        }

        return (string) $this->secret('Das Geheimnis des TSIG-Schlüssels (Base64)');
    }

    /** Was hinterlegt ist — ohne jeden Ausschnitt des Geheimnisses. */
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
            $available = $result['available'] ?? Providers::available();

            $this->line('Es ist kein Profil hinterlegt.');
            $this->line('  Anbieter: '.implode(', ', is_array($available) ? $available : []));

            // Und was zurückgehalten wird, mit dem Grund: Wer INWX hier
            // vermisst, soll nicht auf ihn warten — er kommt nicht, solange
            // seine Zugangsdaten das Registrarkonto öffnen.
            foreach (Providers::WITHHELD as $key => $reason) {
                $this->line('  Nicht angeboten: '.Providers::label($key).' — '.$reason);
            }

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
