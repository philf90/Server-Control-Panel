<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tls\DnsCredentialInput;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
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
 * **Und das Geheimnis steht nicht in der Antwort.** Was hier ausgegeben wird,
 * ist der Profilname, der Anbieter und die Zonen — mehr gibt der Agent nicht
 * heraus, auch nicht die letzten Zeichen.
 *
 * **Warum die Kommandozeile zuerst.** Wer einen Server einrichtet, hat den
 * Schlüssel gerade im Terminal; die Oberfläche dazu kommt danach. Bis dahin ist
 * das hier der Weg, und er ist keiner, der später wegfällt: Ein Betreiber, der
 * ein Profil aus einem Skript setzt, will keine Seite dafür öffnen.
 *
 * **Welche Angabe zu welchem Anbieter gehört, steht nicht hier.** Bis zum
 * 7. August 2026 baute dieses Kommando die Angaben selbst zusammen — und zwar
 * ausschliesslich die von RFC 2136, weil das beim Schreiben der einzige
 * Anbieter war. Schritt 9 hat sieben gebaut, das Formular verzweigt seither an
 * ihnen, und hier stand weiter „heute geht nur rfc2136": **Ein Betreiber, der
 * IPv64 aus einem Skript setzen wollte, hatte keinen Weg.** Genau das Muster,
 * an dem dieses Projekt am häufigsten verliert — zwei Fassungen derselben
 * Regel, und die zweite ist die, die veraltet.
 *
 * Gebaut werden die Angaben deshalb von {@see DnsCredentialInput}, derselben
 * Stelle, an der auch das Formular fragt. Hier steht nur noch, wie eine Angabe
 * von der Kommandozeile dorthin kommt.
 *
 * **Geheimnisse werden gefragt, wenn sie nicht dastehen.** Was in der
 * Kommandozeile steht, steht danach in der Verlaufsdatei der Shell und in
 * `ps` — bei einem Token, das eine ganze Zone öffnet, ist das kein
 * Schönheitsfehler.
 */
final class DnsCredentials extends Command
{
    protected $signature = 'srvpanel:dns
        {--profile= : Der Profilname — betrieb oder abo-<nummer>}
        {--provider=rfc2136 : Der Anbieter — srvpanel dns nennt ohne Angaben alle}
        {--token= : Das Token; ohne diese Angabe wird gefragt (IPv64.net, Hetzner, Cloudflare, deSEC)}
        {--server= : Der Nameserver, der die Aktualisierung annimmt (RFC 2136)}
        {--port= : Sein Port, sofern nicht 53 (RFC 2136)}
        {--zone=* : Eine Zone, die dieses Profil ändern darf — mehrfach möglich (RFC 2136, netcup)}
        {--key= : Der Name des TSIG-Schlüssels (RFC 2136)}
        {--secret= : Sein Geheimnis in Base64; ohne diese Angabe wird gefragt (RFC 2136)}
        {--algorithm= : Das TSIG-Verfahren, sonst hmac-sha256 (RFC 2136)}
        {--customer-number= : Die Kundennummer (netcup)}
        {--api-key= : Der API-Schlüssel; bei IONOS <präfix>.<geheimnis> (netcup, IONOS)}
        {--api-password= : Das API-Passwort; ohne diese Angabe wird gefragt (netcup)}
        {--forget= : Ein Profil wieder entfernen}';

    protected $description = 'Zugangsdaten für DNS-01 hinterlegen — ohne Angaben zeigt es, was hinterlegt ist';

    public function handle(Client $agent): int
    {
        $forget = $this->option('forget');

        if (is_string($forget) && $forget !== '') {
            return $this->forget($agent, $forget);
        }

        $profile = $this->option('profile');

        // Ohne Profil ist das hier keine Eingabe, sondern die Frage „was ist
        // eigentlich hinterlegt?".
        //
        // **Die Zone gehört nicht mehr in diese Bedingung.** Sie stand hier,
        // solange es nur RFC 2136 gab; seit Schritt 9 bringen fünf der sieben
        // Anbieter ihre Zonen aus ihrer eigenen Auskunft mit. Wer für sie
        // `--zone` mitgeben musste, um überhaupt anzukommen, bekam eine
        // Positivliste, die der Agent gar nicht liest.
        if (! is_string($profile) || $profile === '') {
            return $this->show($agent);
        }

        try {
            $provider = DnsCredentialInput::provider(
                ['provider' => $this->option('provider')],
                Providers::available(),
            );

            $config = DnsCredentialInput::config($this->inputFor($provider), $provider);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error('Zugangsdaten: '.$message);
                }
            }

            return self::FAILURE;
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

        // **Die Zeile steht nur, wenn das Profil wirklich eine Liste trägt.**
        // Bei fünf der sieben Anbieter gibt es keine: Ihre Zonen kommen aus
        // ihrer eigenen Auskunft, und der Agent fragt danach erst beim
        // Bestellen. „Zonen: —" zu schreiben hiesse, eine Einschränkung zu
        // behaupten, die es nicht gibt.
        $zones = $config['zones'] ?? null;

        if (is_array($zones) && $zones !== []) {
            $this->line('  Zonen: '.implode(', ', array_map(strval(...), $zones)));
        }

        return self::SUCCESS;
    }

    /**
     * Die Angaben aus der Kommandozeile — in der Form, die das Formular liefert.
     *
     * **Was zu welchem Anbieter gehört, steht nicht hier.** Diese Methode
     * sammelt ein, was dasteht, und fragt nach den Geheimnissen, die fehlen;
     * ob der Satz zusammenpasst, entscheidet {@see DnsCredentialInput::config()}
     * — dieselbe Stelle, die auch das Formular prüft. Eine zweite Fassung
     * dieser Verzweigung wäre genau die, die beim achten Anbieter veraltet.
     *
     * **Gefragt wird nur, was gebraucht wird.** Ein `secret`-Prompt bei einem
     * Anbieter mit Token wäre eine Frage nach etwas, das der Agent gar nicht
     * annimmt — und der Betreiber tippt ein Geheimnis in eine Zeile, die es
     * verwirft.
     *
     * @return array<string, mixed>
     */
    private function inputFor(string $provider): array
    {
        $input = [
            'server' => $this->optionText('server'),
            'key_name' => $this->optionText('key'),
            'algorithm' => $this->optionText('algorithm'),
            'customer_number' => $this->optionText('customer-number'),
            'api_key' => $this->optionText('api-key'),

            // `zones` ist beim Formular ein Textfeld und hier eine Angabe, die
            // mehrfach stehen darf. Zusammengesetzt wird sie mit Zeilenumbruch,
            // weil `DnsCredentialInput::zones()` daran ohnehin trennt — so
            // bleibt die Zerlegung an einer Stelle.
            'zones' => implode("\n", array_map(strval(...), (array) $this->option('zone'))),
        ];

        $port = $this->optionText('port');

        if ($port !== '') {
            $input['port'] = (int) $port;
        }

        return $input + $this->secretsFor($provider);
    }

    /**
     * Die Geheimnisse — aus der Kommandozeile oder aus der Rückfrage.
     *
     * @return array<string, string>
     */
    private function secretsFor(string $provider): array
    {
        $gefragt = match ($provider) {
            Providers::RFC2136 => ['secret' => 'Das Geheimnis des TSIG-Schlüssels (Base64)'],
            Providers::NETCUP => ['api_password' => 'Das API-Passwort'],
            Providers::IPV64, Providers::HETZNER, Providers::CLOUDFLARE, Providers::DESEC => [
                'token' => 'Das Token',
            ],

            // IONOS trägt sein Geheimnis im Schlüssel selbst (`<präfix>.<…>`),
            // und `--api-key` steht oben schon in der Sammlung. Ein zweiter
            // Prompt dafür fragte dasselbe noch einmal.
            default => [],
        };

        $secrets = [];

        foreach ($gefragt as $key => $frage) {
            $option = str_replace('_', '-', $key);
            $wert = $this->optionText($option);

            $secrets[$key] = $wert !== '' ? $wert : (string) $this->secret($frage);
        }

        return $secrets;
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
