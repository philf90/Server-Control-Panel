<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Patience;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DomainName;

/**
 * netcup — der erste Anbieter mit einer Sitzung, und der erste ohne Token.
 *
 * **Drei Dinge sind hier anders als bei allen bisherigen.**
 *
 * **Erstens: eine Sitzung.** Vor jedem Zugriff steht ein `login`, das eine
 * Sitzungskennung zurückgibt, und danach ein `logout`. An- und abgemeldet wird
 * **je Vorgang** und nicht einmal für die Lebensdauer dieses Objekts: Ein
 * Abmelden im Destruktor wäre Netzverkehr zu einem Zeitpunkt, den niemand
 * bestimmt, und eine Ausnahme darin ist in PHP ein fataler Fehler. Bleibt eine
 * Sitzung liegen, weil der Vorgang dazwischen abbricht, läuft sie bei netcup
 * ab; ein Abmelden, das scheitert, macht dagegen aus einem erfolgreichen
 * Eintrag einen Fehlschlag — deshalb wird sein Ergebnis nicht geprüft.
 *
 * **Zweitens: die Zonen stehen in den Zugangsdaten.** Die DNS-Schnittstelle von
 * netcup kennt keine Auskunft, die die Domains eines Kontos aufzählt — lego
 * fragt deshalb die autoritativen Nameserver nach dem SOA-Satz. Das wäre hier
 * eine dritte Quelle für dieselbe Frage; stattdessen gilt dieselbe Antwort wie
 * bei RFC 2136, und aus demselben Grund: eine Positivliste, die der Betreiber
 * aufschreibt. Was nicht daraufsteht, wird gar nicht erst versucht.
 *
 * **Drittens: geschrieben wird nur der eine Eintrag.** lego liest an dieser
 * Stelle **die ganze Zone**, hängt den neuen Satz an und schickt alles zurück.
 * Das ist ein Lesen-Ändern-Schreiben über den Bestand eines Kunden, und es ist
 * unnötig: `updateDnsRecords` legt an, was keine Kennung hat, und löscht, was
 * `deleterecord` trägt — es ersetzt den Bestand nicht. Belegt ist das aus legos
 * eigenem `CleanUp`, das genau **einen** Satz schickt; wäre der Aufruf ein
 * Ersetzen, nähme er damit jedem netcup-Nutzer beim Abräumen die Zone. Beim
 * ersten echten Zugriff gehört das gegen die Dokumentation von netcup gehalten
 * — so wie es beim Endpunktsatz von IPv64.net gemacht wurde.
 */
final class Netcup implements DnsProvider
{
    public const ENDPOINT = 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON';

    /** Der Satz, den netcup bei Erfolg im Feld `status` zurückgibt. */
    public const OK = 'success';

    /** Wie lange auf die Sichtbarkeit gewartet wird — die Zahl von lego (`docs/34 §11`). */
    public const PATIENCE_SECONDS = 900;

    /** Und in welchem Abstand nachgefragt wird. */
    public const PATIENCE_INTERVAL = 30;

    public function __construct(
        private readonly string $customerNumber,
        private readonly string $apiKey,
        private readonly string $apiPassword,
        /** @var list<string> */
        private readonly array $zones,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * @param  array<string, mixed>  $config
     * @return array{customer_number: string, api_key: string, api_password: string, zones: list<string>}
     */
    public static function configure(array $config): array
    {
        $number = is_scalar($config['customer_number'] ?? null) ? trim((string) $config['customer_number']) : '';

        // Die Kundennummer von netcup ist eine Zahl und steht in jedem Rumpf.
        // Sie ist kein Geheimnis, aber sie gehört geprüft: Was hier durchgeht,
        // wandert in eine Anfrage, die als root hinausgeht.
        if (preg_match('/\A[0-9]{1,20}\z/D', $number) !== 1) {
            throw AgentException::badRequest('Die Kundennummer für netcup ist eine Zahl.');
        }

        return [
            'customer_number' => $number,
            'api_key' => self::secret($config['api_key'] ?? null, 'API-Schlüssel'),
            'api_password' => self::secret($config['api_password'] ?? null, 'API-Passwort'),
            'zones' => self::checkedZones($config['zones'] ?? null),
        ];
    }

    /**
     * Ein Geheimnis, das in einem JSON-Rumpf stehen darf.
     *
     * Anders als bei den Token der anderen Anbieter geht hier nichts in eine
     * Kopfzeile — geprüft wird deshalb auf Steuerzeichen und nicht auf den
     * druckbaren ASCII-Bereich: netcup vergibt die Schlüssel selbst, und was
     * darin vorkommt, bestimmt nicht dieses Panel.
     */
    private static function secret(mixed $value, string $what): string
    {
        $secret = is_string($value) ? trim($value) : '';

        if ($secret === '') {
            throw AgentException::badRequest('Für netcup fehlt der '.$what.'.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw AgentException::badRequest('Der '.$what.' für netcup enthält Steuerzeichen.');
        }

        return $secret;
    }

    /**
     * Die Zonen, auf die dieses Profil sich beschränkt.
     *
     * @return list<string>
     */
    private static function checkedZones(mixed $raw): array
    {
        $zones = [];

        foreach (is_array($raw) ? $raw : [] as $zone) {
            if (! is_string($zone)) {
                continue;
            }

            $name = DomainName::normalize($zone, 'zone');

            if (! in_array($name, $zones, true)) {
                $zones[] = $name;
            }
        }

        if ($zones === []) {
            throw AgentException::badRequest(
                'Für netcup ist keine Zone hinterlegt. Die Schnittstelle nennt die Domains eines Kontos '.
                'nicht selbst, deshalb steht die Liste hier.',
            );
        }

        return $zones;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?Outbound $http = null): self
    {
        $checked = self::configure($config);

        return new self(
            $checked['customer_number'],
            $checked['api_key'],
            $checked['api_password'],
            $checked['zones'],
            $http ?? new Curl,
        );
    }

    public function add(string $record, string $value): void
    {
        [$zone, $host] = $this->split($record);

        $this->inSession(function (string $session) use ($zone, $host, $value, $record): void {
            // **Nur der eine Satz.** Siehe die Klassenbeschreibung: `updateDnsRecords`
            // legt an, was keine Kennung hat. Die ganze Zone zurückzuschreiben,
            // wie lego es tut, setzt den Bestand eines Kunden aufs Spiel.
            $this->call('updateDnsRecords', [
                'domainname' => $zone,
                'apisessionid' => $session,
                'dnsrecordset' => ['dnsrecords' => [[
                    'hostname' => $host,
                    'type' => 'TXT',
                    'destination' => $value,
                ]]],
            ], 'netcup hat den Eintrag für '.$record.' nicht angenommen');
        });
    }

    public function remove(string $record, string $value): void
    {
        [$zone, $host] = $this->split($record);

        $this->inSession(function (string $session) use ($zone, $host, $value, $record): void {
            $found = $this->recordFor($session, $zone, $host, $value);

            // Nichts zu löschen ist kein Fehlschlag: `remove()` läuft auch,
            // wenn die Bestellung vorher gescheitert ist.
            if ($found === null) {
                return;
            }

            $this->call('updateDnsRecords', [
                'domainname' => $zone,
                'apisessionid' => $session,
                'dnsrecordset' => ['dnsrecords' => [$found + ['deleterecord' => true]]],
            ], 'netcup hat den Eintrag für '.$record.' nicht entfernt');
        });
    }

    /**
     * Der Satz, der genau diesen Namen und diesen Wert trägt.
     *
     * **Verglichen wird auch der Name.** lego vergleicht nur Wert und Art —
     * bei zwei Prüfeinträgen mit demselben Wert unter verschiedenen Namen ist
     * das der falsche Satz, und gelöscht würde dann die Prüfung eines anderen
     * Vorgangs.
     *
     * @return array<string, mixed>|null
     */
    private function recordFor(string $session, string $zone, string $host, string $value): ?array
    {
        $answer = $this->call('infoDnsRecords', [
            'domainname' => $zone,
            'apisessionid' => $session,
        ], 'Die Einträge von netcup ließen sich nicht abfragen');

        $data = is_array($answer['responsedata'] ?? null) ? $answer['responsedata'] : [];

        foreach (is_array($data['dnsrecords'] ?? null) ? $data['dnsrecords'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $destination = $entry['destination'] ?? null;

            if (
                ($entry['type'] ?? null) === 'TXT'
                && is_string($entry['hostname'] ?? null)
                && strtolower($entry['hostname']) === $host
                && is_string($destination)
                && TxtValue::matches($destination, $value)
                && (is_string($id) || is_int($id))
            ) {
                return ['id' => (string) $id, 'hostname' => $host, 'type' => 'TXT', 'destination' => $destination];
            }
        }

        return null;
    }

    /**
     * Anmelden, tun, abmelden.
     *
     * **Das Abmelden steht im `finally` und sein Ergebnis wird nicht geprüft.**
     * Der erste Teil, weil eine Sitzung sonst genau dann liegenbleibt, wenn
     * etwas schiefging; der zweite, weil ein gescheitertes Abmelden aus einem
     * gesetzten Eintrag sonst einen Fehlschlag machte — und der Vorgang würde
     * wiederholt, obwohl er durchgelaufen ist.
     *
     * @param  callable(string): void  $work
     */
    private function inSession(callable $work): void
    {
        $login = $this->call('login', [
            'apipassword' => $this->apiPassword,
        ], 'Die Anmeldung bei netcup ist gescheitert');

        $data = is_array($login['responsedata'] ?? null) ? $login['responsedata'] : [];
        $session = $data['apisessionid'] ?? null;

        if (! is_string($session) || $session === '') {
            throw AgentException::execFailed('netcup hat auf die Anmeldung keine Sitzungskennung geschickt.');
        }

        try {
            $work($session);
        } finally {
            try {
                $this->call('logout', ['apisessionid' => $session], 'Das Abmelden bei netcup ist gescheitert');
            } catch (AgentException) {
                // Siehe oben: Die Sitzung läuft bei netcup ohnehin ab.
            }
        }
    }

    /**
     * Ein Aufruf an die Schnittstelle.
     *
     * Kundennummer und Schlüssel stehen in **jedem** Rumpf; das ist die
     * Bauart von netcup und nicht unsere. Was der Aufrufer beisteuert, ist der
     * Rest.
     *
     * @param  array<string, mixed>  $param
     * @return array<string, mixed>
     */
    private function call(string $action, array $param, string $what): array
    {
        return $this->read(
            $this->http->send(
                'POST',
                self::ENDPOINT,
                ['Accept: application/json', 'Content-Type: application/json'],
                (string) json_encode([
                    'action' => $action,
                    'param' => [
                        'customernumber' => $this->customerNumber,
                        'apikey' => $this->apiKey,
                    ] + $param,
                ], JSON_THROW_ON_ERROR),
            ),
            $what,
        );
    }

    /**
     * Zone und Name relativ dazu.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $record): array
    {
        $zone = Zones::pick($record, $this->zones);

        if ($zone === null) {
            throw AgentException::badRequest(
                'Für diesen Namen ist in den netcup-Zugangsdaten keine Zone hinterlegt.',
                ['record' => $record, 'zones' => $this->zones],
            );
        }

        $host = Zones::prefix($record, $zone);

        // netcup schreibt den Namen der Zone selbst als `@`. Ein Prüfname
        // trägt immer `_acme-challenge` vor sich; der Zweig steht hier, weil
        // eine leere Zeichenkette an dieser Stelle stillschweigend zu einem
        // Eintrag an einer anderen Stelle würde.
        return [$zone, $host === '' ? '@' : $host];
    }

    /**
     * Die Antwort lesen — und einen Fehlschlag als Satz weitergeben.
     *
     * **netcup antwortet auch auf einen Fehlschlag mit HTTP 200.** Der Zustand
     * steht im Feld `status`; wer nur den Code liest, hält jeden abgewiesenen
     * Aufruf für erledigt und wartet danach auf einen Eintrag, den es nicht
     * gibt.
     *
     * @return array<string, mixed>
     */
    private function read(Response $response, string $what): array
    {
        $raw = trim($response->body);
        $data = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw AgentException::execFailed($what.': die Antwort ist keine Auskunft ('.$response->status.').');
        }

        if (! $response->successful() || ($data['status'] ?? null) !== self::OK) {
            throw AgentException::execFailed($what.': '.self::reason($data, $response->status));
        }

        return $data;
    }

    /**
     * Was netcup zum Fehlschlag sagt.
     *
     * `longmessage` trägt den Satz, `shortmessage` ein Stichwort, `statuscode`
     * die Nummer — und die geht mit, weil sie das ist, wonach jemand im Wiki
     * von netcup sucht.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reason(array $data, int $status): string
    {
        foreach (['longmessage', 'shortmessage'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $code = $data['statuscode'] ?? null;

                return is_int($code) && $code > 0 ? $value.' ('.$code.')' : $value;
            }
        }

        return 'ohne Begründung (HTTP '.$status.').';
    }

    /**
     * Wie lange es hier dauert, bis der Eintrag draussen ist.
     *
     * **Der langsamste der acht.** lego wartet fünfzehn Minuten und fragt nur alle dreissig Sekunden — beides gehört zusammen, denn häufiger zu fragen hiesse hier nur, den Anbieter zu drosseln.
     */
    public function patience(): Patience
    {
        return new Patience(self::PATIENCE_SECONDS, self::PATIENCE_INTERVAL);
    }
}
