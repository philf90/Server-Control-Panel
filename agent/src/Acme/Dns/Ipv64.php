<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;

/**
 * IPv64.net — der Anbieter, an dem sich die Zonenauflösung beweist.
 *
 * **Warum er der erste der vier ist.** Bei IPv64.net ist die Zone häufig selbst
 * eine Unterdomain: `meinname.ipv64.de` ist eine ganze Zone, nicht ein Name
 * darin. Wer die Zone aus dem Namen errechnet — „die registrierbare Domain",
 * „die letzten zwei Bestandteile" —, liegt hier falsch. Was in `docs/34 §4` als
 * Vorsicht stand, ist bei diesem Anbieter der Normalfall.
 *
 * **Deshalb wird gefragt und nicht gerechnet.** `get_domains` nennt die Zonen
 * des Kontos; die längste, die auf den Namen passt, gewinnt. Das ist bewusst
 * eine andere Wahl als bei lego, dessen `splitDomain` die **letzten drei**
 * Bestandteile nimmt — nachgesehen am 6. August in `providers/dns/ipv64`. Für
 * `meinname.ipv64.de` kommt dabei dasselbe heraus, und für eine eigene Domain,
 * die jemand zu IPv64.net bringt, nicht mehr: `example.de` hat zwei
 * Bestandteile, und die Regel gäbe `_acme-challenge.example.de` als Zone aus.
 * Eine Antwort, die in der Mehrzahl der Fälle stimmt, ist genau die Sorte
 * Regel, die dieses Projekt teuer bezahlt hat.
 *
 * **`praefix` ist deutsch geschrieben, weil der Anbieter es so nennt.** Das ist
 * eine echte Schnittstelle nach aussen; `docs/19 §4a` lässt sie, wie sie ist.
 *
 * **Und das Token steht in keiner Meldung.** Es geht als `Authorization` hinaus
 * und sonst nirgends hin — die Fehler dieses Anbieters kommen als Satz aus
 * seiner Antwort, und die enthält es nicht.
 */
final class Ipv64 implements DnsProvider
{
    public const ENDPOINT = 'https://ipv64.net/api';

    /** Der Satz, den der Anbieter bei Erfolg zurückgibt. */
    public const OK = 'success';

    /**
     * Die Zonen des Kontos, einmal geholt.
     *
     * `add()` und `remove()` laufen im selben Vorgang, und beide brauchen die
     * Auflösung. Zweimal zu fragen wäre eine Anfrage zu viel an einen Anbieter,
     * der ausdrücklich drosselt.
     *
     * @var list<string>|null
     */
    private ?array $zones = null;

    public function __construct(
        private readonly string $token,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * **Nur das Token.** Alles andere, was dieser Anbieter braucht, steht in
     * seiner eigenen Auskunft: die Zonen kommen aus `get_domains`, die Adresse
     * ist fest. Ein Feld, das der Betreiber ausfüllen könnte und das niemand
     * liest, wäre genau die Sorte Zeichenkette, gegen die dieses Projekt seine
     * Wächter stellt.
     *
     * @param  array<string, mixed>  $config
     * @return array{token: string}
     */
    public static function configure(array $config): array
    {
        $token = is_string($config['token'] ?? null) ? trim($config['token']) : '';

        if ($token === '') {
            throw AgentException::badRequest('Für IPv64.net fehlt das Token.');
        }

        // Geprüft wird die Form und nicht die Gültigkeit: Ob das Token gilt,
        // weiss allein der Anbieter. Was hier abgewiesen wird, ist, was in
        // einer Kopfzeile nichts zu suchen hat — ein Zeilenumbruch darin
        // hängte eine zweite Kopfzeile an die Anfrage.
        if (preg_match('/\A[\x21-\x7E]{8,512}\z/D', $token) !== 1) {
            throw AgentException::badRequest(
                'Das Token für IPv64.net enthält Zeichen, die in einer Kopfzeile nicht stehen dürfen.',
            );
        }

        return ['token' => $token];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?Outbound $http = null): self
    {
        $checked = self::configure($config);

        return new self($checked['token'], $http ?? new Curl);
    }

    public function add(string $record, string $value): void
    {
        [$zone, $prefix] = $this->split($record);

        $this->write('POST', [
            'add_record' => $zone,
            'praefix' => $prefix,
            'type' => 'TXT',
            'content' => $value,
        ], $record);
    }

    public function remove(string $record, string $value): void
    {
        [$zone, $prefix] = $this->split($record);

        // **Der Wert geht mit.** Laufen zwei Bestellungen für dieselbe Zone,
        // stehen zwei `_acme-challenge`-Einträge nebeneinander; wer nur nach
        // dem Namen löscht, räumt die Prüfung des anderen Vorgangs mit ab.
        $this->write('DELETE', [
            'del_record' => $zone,
            'praefix' => $prefix,
            'type' => 'TXT',
            'content' => $value,
        ], $record);
    }

    /**
     * Zone und Präfix zu einem Namen — gefragt, nicht gerechnet.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $record): array
    {
        $name = strtolower(trim($record));
        $zone = '';

        foreach ($this->knownZones() as $candidate) {
            // Die längste passende gewinnt. Führt jemand `example.de` und
            // `kunde.example.de` beim selben Anbieter, gehört der Eintrag in
            // die engere — sonst legt er ihn in der falschen Zone an, und die
            // Prüfung findet ihn nie.
            if (Name::within($name, $candidate) && strlen($candidate) > strlen($zone)) {
                $zone = $candidate;
            }
        }

        if ($zone === '') {
            throw AgentException::badRequest(
                'Für diesen Namen führt das IPv64.net-Konto keine Zone: '.$name,
            );
        }

        // Der Präfix ist, was vor der Zone steht — leer, wenn der Name die
        // Zone selbst ist.
        $prefix = $name === $zone ? '' : substr($name, 0, -(strlen($zone) + 1));

        return [$zone, $prefix];
    }

    /**
     * Die Zonen des Kontos.
     *
     * @return list<string>
     */
    private function knownZones(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $answer = $this->read($this->http->send(
            'GET',
            self::ENDPOINT.'?get_domains',
            $this->headers(),
        ), 'Die Zonen von IPv64.net ließen sich nicht abfragen');

        $zones = [];

        // Die Zonen stehen als **Schlüssel** unter `subdomains` — der Wert
        // daneben ist der Bestand der Zone und geht uns nichts an.
        foreach (is_array($answer['subdomains'] ?? null) ? $answer['subdomains'] : [] as $zone => $ignored) {
            if (is_string($zone) && $zone !== '') {
                $zones[] = strtolower($zone);
            }
        }

        if ($zones === []) {
            throw AgentException::badRequest('Das IPv64.net-Konto führt keine Zone.');
        }

        return $this->zones = $zones;
    }

    /**
     * Einen Eintrag schreiben oder löschen.
     *
     * @param  array<string, string>  $fields
     */
    private function write(string $method, array $fields, string $record): void
    {
        $this->read(
            $this->http->send(
                $method,
                self::ENDPOINT,
                [...$this->headers(), 'Content-Type: application/x-www-form-urlencoded'],
                http_build_query($fields),
            ),
            'IPv64.net hat den Eintrag für '.$record.' nicht angenommen',
        );
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Accept: application/json', 'Authorization: Bearer '.$this->token];
    }

    /**
     * Die Antwort lesen — und einen Fehlschlag als Satz weitergeben.
     *
     * **`null` ist hier ein Fehler und kein leeres Ergebnis.** Der Anbieter
     * antwortet in diesem Fall mit dem vier Zeichen langen Rumpf `null`, und
     * `json_decode` macht daraus brav ein PHP-`null`. Wer das als „nichts
     * gefunden" liest, hält einen Fehlschlag für einen Normalfall.
     *
     * @return array<string, mixed>
     */
    private function read(Response $response, string $what): array
    {
        $raw = trim($response->body);

        // Der Anbieter drosselt und sagt es mit 429. Das gehört in die
        // Meldung: Ein Vorgang, der ohne Grund scheitert, wird wiederholt —
        // einer, der „zu schnell" sagt, wird abgewartet.
        if ($response->status === 429) {
            throw AgentException::execFailed($what.': IPv64.net drosselt gerade (429).');
        }

        $data = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw AgentException::execFailed($what.': die Antwort ist keine Auskunft ('.$response->status.').');
        }

        if (! $response->successful() || ($data['info'] ?? null) !== self::OK) {
            throw AgentException::execFailed($what.': '.self::reason($data, $response->status));
        }

        return $data;
    }

    /**
     * Was der Anbieter zum Fehlschlag sagt.
     *
     * Er legt die Begründung je nach Aufruf in ein anderes Feld — `add_record`,
     * `del_record` — und lässt `info` dann auf einem allgemeinen Wort stehen.
     * Wer nur `info` liest, bekommt „Nope" und weiss nichts.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reason(array $data, int $status): string
    {
        foreach (['add_record', 'del_record', 'info', 'status'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && $value !== '' && $value !== self::OK) {
                return $value;
            }
        }

        return 'ohne Begründung (HTTP '.$status.').';
    }
}
