<?php

declare(strict_types=1);

namespace App\Support\Tls;

use Illuminate\Validation\ValidationException;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Der Weg der Oberfläche zu den DNS-Zugangsdaten.
 *
 * **Eine Stelle für beide Seiten.** Die Zugangsdaten hängen am Betreiber
 * (`betrieb`) oder am Abonnement (`abo-1042`) — das ist dieselbe Sache unter
 * zwei Profilnamen (`docs/34 §5`). Zwei Controller, die je für sich mit dem
 * Agenten sprechen, wären zwei Fassungen derselben Entscheidung, und die
 * zweite ist die, die veraltet.
 *
 * **Unmittelbar und nicht über die Warteschlange.** Ein eingereihter Vorgang
 * legt seine Argumente in `operations.payload` ab; ein DNS-Token läge damit im
 * Klartext in der Datenbank, dauerhaft und für jeden lesbar, der sie liest.
 * Dieselbe Entscheidung wie beim Hochladen eines Zertifikats und auf der
 * Kommandozeile.
 *
 * **Der Profilname kommt nie aus einer Anfrage.** Er wird von
 * {@see DnsProfile} abgeleitet und hier nur weitergereicht. Käme er aus dem
 * Formular, könnte ein Kunde das Profil eines anderen nennen und dessen Zone
 * bearbeiten lassen — dieselbe Haltung wie bei den Verzeichnisnamen der
 * Systembenutzer.
 */
final class DnsCredentialAccess
{
    /**
     * Der Schlüssel, unter dem eine Abweisung des Agenten im Formular landet.
     *
     * **Kein Feldname.** Was der Agent bemängelt, kann jede der Angaben sein —
     * der Nameserver, eine Zone, das Verfahren, das Geheimnis. Ein Fehler, den
     * man an das falsche Feld hängt, schickt den Betreiber auf die Suche an
     * der falschen Stelle; unter diesem Schlüssel steht er in der
     * Zusammenfassung oben, wo der Blick nach der Antwort landet.
     */
    public const ERROR_KEY = 'credential';

    /**
     * Die Antwort des Agenten, einmal je Anfrage.
     *
     * Eine Seite fragt für mehrere Stellen dasselbe; der Socket ist schnell,
     * aber eine Frage je Stelle wäre eine zu viel.
     *
     * @var array{profiles: list<array<string, mixed>>, providers: list<string>, available: list<string>}|null
     */
    private ?array $listed = null;

    public function __construct(private readonly Client $agent) {}

    /**
     * Was über ein Profil gesagt werden darf — oder `null`, wenn es keines gibt.
     *
     * **Gefragt wird der Agent.** Das Panel führt über die Profile keine
     * zweite Liste; sie läge in der Datenbank und wäre die zweite Wahrheit zu
     * derselben Frage.
     *
     * @return array{profile: string, provider: string, provider_label: string, stored_at: int, zones: list<string>}|null
     */
    public function describe(string $profile): ?array
    {
        foreach ($this->listed()['profiles'] as $described) {
            if (($described['profile'] ?? null) === $profile) {
                return $described;
            }
        }

        return null;
    }

    /**
     * Die Anbieter für das Formular — die brauchbaren zuerst, die offenen dazu.
     *
     * **Beide Listen, und die offenen als offen gekennzeichnet.** Wer seinen
     * Anbieter gar nicht findet, trägt ihn beim falschen ein; wer ihn ausgegraut
     * sieht, weiss, dass er kommt. Angeboten zur Auswahl wird trotzdem nur, was
     * {@see Providers::make()} auch bauen kann — ein Eintrag, den der Agent
     * abweist, ist genau die Sorte Zeichenkette, die auf nichts zeigt.
     *
     * @return list<array{value: string, label: string, usable: bool}>
     */
    public function providers(): array
    {
        $listed = $this->listed();
        $usable = $listed['available'];

        return array_map(static fn (string $key): array => [
            'value' => $key,
            'label' => Providers::label($key),
            'usable' => in_array($key, $usable, true),
        ], $listed['providers']);
    }

    /**
     * Die Anbieterschlüssel, die {@see Providers::make()} auch bauen kann.
     *
     * Beide Formulare prüfen ihre Auswahl dagegen — der Betreiber in den
     * Einstellungen, das Abonnement an seinem Abonnement. Zwei Fassungen dieser
     * Liste wären eine zu viel.
     *
     * @return list<string>
     */
    public function usable(): array
    {
        return $this->listed()['available'];
    }

    /**
     * Zugangsdaten hinterlegen — das Geheimnis überquert den Socket genau hier.
     *
     * **Geprüft wird im Agenten und nicht hier.** Ob Nameserver, Zonen,
     * Schlüsselname und Verfahren zusammenpassen, entscheidet
     * `Rfc2136::configure()`. Eine zweite Prüfung in diesem Controller wäre
     * eine zu viel, und die zweite ist die, die veraltet — dieselbe Aufteilung
     * wie auf der Kommandozeile.
     *
     * **Was der Agent bemängelt, wird zur Formularmeldung.** Sonst käme eine
     * Abweisung als Serverfehler an, und der Betreiber sähe eine leere Seite
     * statt des Satzes, der sagt, was fehlt.
     *
     * @param  array<string, mixed>  $config
     * @return array{profile: string, provider: string}
     *
     * @throws ValidationException
     */
    public function store(string $profile, string $provider, array $config): array
    {
        try {
            $answer = $this->agent->call('dns.credential.store', [
                'profile' => $profile,
                'provider' => $provider,
                'config' => $config,
            ], ['source' => 'web', 'command' => 'settings.dns']);
        } catch (AgentException $error) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => $error->getMessage(),
            ]);
        }

        return [
            'profile' => is_string($answer['profile'] ?? null) ? $answer['profile'] : $profile,
            'provider' => is_string($answer['provider'] ?? null) ? $answer['provider'] : $provider,
        ];
    }

    /**
     * Ein Profil wieder entfernen.
     *
     * @throws ValidationException
     */
    public function forget(string $profile): void
    {
        try {
            $this->agent->call(
                'dns.credential.forget',
                ['profile' => $profile],
                ['source' => 'web', 'command' => 'settings.dns'],
            );
        } catch (AgentException $error) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => $error->getMessage(),
            ]);
        }
    }

    /**
     * Die Antwort von `dns.credential.list`, einmal je Anfrage.
     *
     * **Ein nicht erreichbarer Agent ist kein Fehler dieser Seite.** Sie sagt
     * dann, dass nichts hinterlegt ist, statt eine Fehlerseite zu zeigen —
     * dieselbe Haltung wie in der Übersicht und bei {@see AgentDnsCredentials}.
     * Die vorsichtige Richtung ist hier „kein Profil": Ein Knopf, der eine
     * Bestellung auslöst, die mangels Zugangsdaten scheitert, verbrennt einen
     * Fehlversuch, der für jeden Kunden dieses Servers zählt.
     *
     * @return array{profiles: list<array<string, mixed>>, providers: list<string>, available: list<string>}
     */
    private function listed(): array
    {
        if ($this->listed !== null) {
            return $this->listed;
        }

        try {
            $answer = $this->agent->call(
                'dns.credential.list',
                [],
                ['source' => 'web', 'command' => 'settings.dns'],
            );
        } catch (AgentException) {
            return $this->listed = [
                'profiles' => [],
                'providers' => Providers::keys(),
                'available' => [],
            ];
        }

        return $this->listed = [
            'profiles' => $this->describedProfiles($answer),
            'providers' => self::names($answer['providers'] ?? null, Providers::keys()),
            'available' => self::names($answer['available'] ?? null, []),
        ];
    }

    /**
     * Die beschriebenen Profile aus der Antwort — jedes einzeln geprüft.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    private function describedProfiles(array $answer): array
    {
        $profiles = [];

        foreach (is_array($answer['profiles'] ?? null) ? $answer['profiles'] : [] as $described) {
            if (! is_array($described) || ! is_string($described['profile'] ?? null)) {
                continue;
            }

            $provider = is_string($described['provider'] ?? null) ? $described['provider'] : '?';
            $storedAt = $described['stored_at'] ?? 0;

            $profiles[] = [
                'profile' => $described['profile'],
                'provider' => $provider,
                'provider_label' => is_string($described['provider_label'] ?? null)
                    ? $described['provider_label']
                    : Providers::label($provider),
                'stored_at' => is_int($storedAt) ? $storedAt : 0,
                'zones' => self::names($described['zones'] ?? null, []),
            ];
        }

        return $profiles;
    }

    /**
     * Eine Liste von Zeichenketten aus einer Antwort — oder der Ersatz.
     *
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function names(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $names = [];

        foreach ($value as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
