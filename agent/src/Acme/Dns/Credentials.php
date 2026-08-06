<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * Wo die Zugangsdaten eines DNS-Anbieters liegen — und warum hier.
 *
 * **Ein DNS-Token ist ein grösseres Geheimnis als das Panel-Passwort.** Wer es
 * hat, kann sich für die Domain jedes Zertifikat der Welt ausstellen lassen,
 * auch anderswo. Es gehört deshalb dorthin, wo `panel.env` schon liegt: in eine
 * Datei, die root allein lesen darf — und nicht in die Datenbank des Panels,
 * aus der es bei jedem Vorgang über den Socket ginge und in jeder
 * Fehlermeldung auftauchen könnte (`docs/34 §5`).
 *
 * **Die Anwendung kennt nur den Profilnamen.** Sie schickt ihn, wenn bestellt
 * wird; das Token überquert den Socket **genau einmal**, beim Einrichten. Keine
 * Leseoperation gibt es je zurück — {@see self::describe()} nennt Anbieter und
 * Zeitpunkt, mehr nicht.
 *
 * **Der Profilname wird geprüft und nicht geglaubt.** Er wird zu einem
 * Dateinamen, und was hier durchginge, läge als `../../etc/irgendwas` auf der
 * Platte eines Prozesses mit Systemrechten. Zugelassen sind Kleinbuchstaben,
 * Ziffern und Bindestriche — genug für `betrieb` und `abo-1042`, und sonst
 * nichts.
 */
final class Credentials
{
    /** Hier liegen die Profile, eines je Datei. */
    public const DIRECTORY = '/etc/srvpanel/dns';

    /** Mehr braucht kein Profilname, und weniger ist keiner. */
    public const NAME_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,39}\z/D';

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    /**
     * Ein Profil ablegen — und dabei Anbieter und Zugangsdaten prüfen.
     *
     * **Geprüft wird hier und nicht beim Bestellen.** Was beim Hinterlegen
     * durchgeht, fällt sonst erst auf, wenn eine Erneuerung um drei Uhr
     * morgens an einem vertippten Schlüsselnamen scheitert — und der Betreiber
     * sieht am nächsten Tag ein abgelaufenes Zertifikat und keine Ursache.
     *
     * **Geschrieben wird über eine Zwischendatei**, aus demselben Grund wie
     * beim Zertifikat: Ein Lauf, der mittendrin abbricht, hinterlässt sonst
     * eine halbe Datei, und die ist beim nächsten Lesen kein Fehler, sondern
     * eine Zugangsangabe, die fast stimmt.
     *
     * @param  array<string, mixed>  $config
     */
    public function store(mixed $profile, mixed $provider, array $config): void
    {
        $name = self::name($profile);
        $key = Providers::usable($provider);
        $config = Providers::configure($key, $config);

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw AgentException::execFailed('Das Verzeichnis für die Zugangsdaten ließ sich nicht anlegen.');
        }

        chmod($this->directory, 0o700);

        $contents = json_encode([
            'provider' => $key,
            'config' => $config,
            'stored_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($contents)) {
            throw AgentException::execFailed('Die Zugangsdaten ließen sich nicht schreiben.');
        }

        $path = $this->path($name);
        $temp = $path.'.neu';

        if (@file_put_contents($temp, $contents) === false) {
            throw AgentException::execFailed('Die Zugangsdaten ließen sich nicht schreiben: '.$path);
        }

        chmod($temp, 0o600);
        rename($temp, $path);
    }

    /**
     * Die Zugangsdaten eines Profils — für den Agenten selbst.
     *
     * **Diese Antwort verlässt den Agenten nie.** Sie ist für die Stelle, die
     * gleich einen TXT-Eintrag anlegt, und für keine Operation, die etwas
     * zurückgibt.
     *
     * @return array{provider: string, config: array<string, mixed>}
     */
    public function read(mixed $profile): array
    {
        $path = $this->path(self::name($profile));

        if (! is_file($path)) {
            throw AgentException::badRequest(
                'Für dieses Profil sind keine Zugangsdaten hinterlegt.',
                ['profile' => self::name($profile)],
            );
        }

        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($data) || ! isset($data['provider'])) {
            throw AgentException::execFailed('Die Zugangsdaten sind unlesbar: '.$path);
        }

        $config = $data['config'] ?? [];

        return [
            'provider' => Providers::normalize($data['provider']),
            'config' => is_array($config) ? $config : [],
        ];
    }

    /**
     * Was über ein Profil gesagt werden darf.
     *
     * Anbieter und Zeitpunkt — nichts, was jemandem nützt, der die Antwort
     * abfängt. **Kein Ausschnitt des Tokens**, auch keine vier letzten Zeichen:
     * Bei einem kurzen Token ist das ein spürbarer Teil davon, und der Nutzen
     * ist eine Bequemlichkeit beim Wiedererkennen.
     *
     * @return array{profile: string, provider: string, stored_at: int}|null
     */
    public function describe(mixed $profile): ?array
    {
        $name = self::name($profile);
        $path = $this->path($name);

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($data)) {
            return null;
        }

        $stored = $data['stored_at'] ?? 0;

        return [
            'profile' => $name,
            'provider' => is_string($data['provider'] ?? null) ? $data['provider'] : '?',
            'stored_at' => is_int($stored) ? $stored : 0,
        ];
    }

    /** Ein Profil wieder entfernen. */
    public function forget(mixed $profile): bool
    {
        $path = $this->path(self::name($profile));

        return is_file($path) && @unlink($path);
    }

    /**
     * Die Profile, die es gibt.
     *
     * @return list<string>
     */
    public function known(): array
    {
        $names = [];

        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            $name = basename($path, '.json');

            if (preg_match(self::NAME_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Ein Profilname, der ein Dateiname sein darf.
     *
     * Siehe die Klassenbeschreibung: Was hier durchginge, läge als
     * `../../etc/irgendwas` auf der Platte eines Prozesses mit Systemrechten.
     */
    public static function name(mixed $profile): string
    {
        $name = is_string($profile) ? strtolower(trim($profile)) : '';

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw AgentException::badRequest(
                'Kein gültiger Profilname.',
                ['profile' => is_string($profile) ? $profile : '?'],
            );
        }

        return $name;
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.$name.'.json';
    }
}
