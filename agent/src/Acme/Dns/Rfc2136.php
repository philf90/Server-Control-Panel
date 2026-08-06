<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DomainName;

/**
 * Der erste echte Anbieter: eine TSIG-unterschriebene Zonenaktualisierung.
 *
 * **Kein Anbietercode, sondern der Standard.** RFC 2136 bedient BIND, Knot und
 * PowerDNS gleichermassen — und damit die eigene Zone aus P7, ohne dass es
 * dafür eine zweite Umsetzung bräuchte (`docs/34 §6`). Deshalb steht er in der
 * Reihenfolge vorn.
 *
 * **Die Zonen stehen in den Zugangsdaten und werden nicht erraten.** Man
 * *könnte* sie über den SOA-Satz suchen, und die meisten Umsetzungen tun das.
 * Hier nicht, aus zwei Gründen: Ein TSIG-Schlüssel ist im Nameserver
 * üblicherweise ohnehin auf eine Zone eingegrenzt — wer sie errät, bekommt bei
 * jeder anderen ein stummes `REFUSED` —, und vor allem ist die Liste damit eine
 * **Positivliste**: Ein Profil kann genau die Zonen ändern, die der Betreiber
 * hineingeschrieben hat, und keine andere. Das ist dieselbe Entscheidung wie
 * bei den Programmen des Agenten.
 *
 * **Zusammengesetzt wird von unten**, also die längste passende Zone gewinnt:
 * Wer `example.de` und `intern.example.de` mit demselben Schlüssel führt, will
 * für `_acme-challenge.intern.example.de` die zweite genannt haben — sonst
 * antwortet der Nameserver mit `NOTZONE`.
 */
final class Rfc2136 implements DnsProvider
{
    /**
     * Kurz, weil der Satz Minuten später wieder abgeräumt wird.
     *
     * Eine hohe Haltbarkeit hätte hier einen unangenehmen Nebeneffekt: Nach dem
     * Abräumen liefern Zwischenspeicher den alten Wert weiter, und die nächste
     * Bestellung für dieselbe Zone läuft gegen einen Eintrag, den es nicht mehr
     * gibt.
     */
    public const TTL = 60;

    public const PORT = 53;

    /**
     * @param  list<string>  $zones  Die Zonen, die dieses Profil ändern darf
     */
    public function __construct(
        private readonly string $server,
        private readonly int $port,
        private readonly array $zones,
        private readonly Tsig $key,
        private readonly Exchange $exchange = new TcpExchange,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * **Der Unterschied ist eine Nacht.** Was hier durchgeht, fällt sonst erst
     * auf, wenn ein Zertifikat ablaufen will und die Erneuerung um drei Uhr
     * morgens an einem vertippten Schlüsselnamen scheitert.
     *
     * @param  array<string, mixed>  $config
     * @return array{server: string, port: int, zones: list<string>, key_name: string, algorithm: string, secret: string}
     */
    public static function configure(array $config): array
    {
        $server = is_string($config['server'] ?? null) ? trim($config['server']) : '';

        if ($server === '' || preg_match('/\A[a-zA-Z0-9._:\[\]-]{1,255}\z/D', $server) !== 1) {
            throw AgentException::badRequest('Der Nameserver fehlt oder ist kein Name und keine Adresse.');
        }

        $port = $config['port'] ?? self::PORT;
        $port = is_int($port) ? $port : (is_string($port) && ctype_digit($port) ? (int) $port : 0);

        if ($port < 1 || $port > 65535) {
            throw AgentException::badRequest('Der Port des Nameservers liegt außerhalb des Bereichs.');
        }

        $zones = [];

        foreach (is_array($config['zones'] ?? null) ? $config['zones'] : [] as $zone) {
            $zones[] = DomainName::normalize($zone, 'zone');
        }

        if ($zones === []) {
            throw AgentException::badRequest('Es ist keine Zone angegeben, die dieses Profil ändern darf.');
        }

        $keyName = is_string($config['key_name'] ?? null) ? strtolower(trim($config['key_name'])) : '';

        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,254}\z/D', $keyName) !== 1) {
            throw AgentException::badRequest('Der Name des TSIG-Schlüssels fehlt oder enthält Unzulässiges.');
        }

        return [
            'server' => $server,
            'port' => $port,
            'zones' => array_values(array_unique($zones)),
            'key_name' => $keyName,
            'algorithm' => Tsig::normalizeAlgorithm($config['algorithm'] ?? null),
            'secret' => self::secret($config['secret'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?Exchange $exchange = null): self
    {
        $checked = self::configure($config);

        return new self(
            $checked['server'],
            $checked['port'],
            $checked['zones'],
            new Tsig(
                $checked['key_name'],
                $checked['algorithm'],
                (string) base64_decode($checked['secret'], true),
            ),
            $exchange ?? new TcpExchange,
        );
    }

    public function add(string $record, string $value): void
    {
        $this->apply(
            fn (int $id, string $zone): string => UpdateMessage::add($id, $zone, $record, $value, self::TTL),
            $record,
        );
    }

    public function remove(string $record, string $value): void
    {
        $this->apply(
            fn (int $id, string $zone): string => UpdateMessage::remove($id, $zone, $record, $value),
            $record,
        );
    }

    /**
     * Unterschreiben, senden, die Antwort nachrechnen.
     *
     * **Drei Prüfungen und keine weniger.** Die Kennung, weil eine Antwort auf
     * eine andere Frage hier keine Antwort ist; die Unterschrift, weil ein
     * gefälschtes „in Ordnung" uns dazu brächte, der Zertifizierungsstelle zu
     * früh „prüf jetzt" zu sagen; und der Rückgabewert, weil er der einzige
     * Ort ist, an dem steht, warum eine Zone unverändert blieb.
     *
     * @param  callable(int, string): string  $build
     */
    private function apply(callable $build, string $record): void
    {
        $zone = $this->zoneFor($record);
        $id = random_int(0, 0xFFFF);

        $signed = $this->key->sign($build($id, $zone), time());
        $response = $this->exchange->send($this->server, $this->port, $signed['message']);

        if (UpdateMessage::id($response) !== $id) {
            throw AgentException::execFailed(
                'Die Antwort des Nameservers gehört zu einer anderen Frage.',
                ['server' => $this->server, 'zone' => $zone],
            );
        }

        if (! $this->key->verify($response, $signed['mac'], time())) {
            throw AgentException::execFailed(
                'Die Antwort des Nameservers trägt keine gültige Unterschrift.',
                ['server' => $this->server, 'zone' => $zone],
            );
        }

        $code = UpdateMessage::code($response);

        if ($code !== 0) {
            throw AgentException::execFailed(
                'Die Zone ließ sich nicht ändern: '.UpdateMessage::explain($code ?? -1),
                ['server' => $this->server, 'zone' => $zone, 'record' => $record, 'code' => $code],
            );
        }
    }

    /**
     * Zu welcher der hinterlegten Zonen gehört dieser Name?
     *
     * Die längste gewinnt — siehe die Klassenbeschreibung. Gehört er zu keiner,
     * ist das kein Grund, es trotzdem zu versuchen: Der Nameserver würde
     * ablehnen, und die Meldung dazu nennt den Grund nicht.
     */
    private function zoneFor(string $record): string
    {
        $found = null;

        foreach ($this->zones as $zone) {
            if (Name::within($record, $zone) && ($found === null || strlen($zone) > strlen($found))) {
                $found = $zone;
            }
        }

        if ($found === null) {
            throw AgentException::badRequest(
                'Für diesen Namen ist in den Zugangsdaten keine Zone hinterlegt.',
                ['record' => $record, 'zones' => $this->zones],
            );
        }

        return $found;
    }

    /**
     * Das Geheimnis, wie `named.conf` es schreibt: Base64.
     *
     * Geprüft wird, dass es sich überhaupt entschlüsseln lässt und nicht leer
     * ist — der Rest ist eine Frage, die nur der Nameserver beantworten kann.
     */
    private static function secret(mixed $secret): string
    {
        $value = is_string($secret) ? trim($secret) : '';
        $raw = $value === '' ? false : base64_decode($value, true);

        if (! is_string($raw) || $raw === '') {
            throw AgentException::badRequest('Das Geheimnis des TSIG-Schlüssels fehlt oder ist kein Base64.');
        }

        return $value;
    }
}
