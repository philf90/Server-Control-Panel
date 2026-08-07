<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Patience;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;

/**
 * IONOS — ein Feld, das in Wahrheit zwei ist.
 *
 * **Der Schlüssel hat die Form `<präfix>.<geheimnis>`.** IONOS zeigt beide
 * Teile getrennt an und erwartet sie zusammengesetzt in einer Kopfzeile. Wer
 * nur den Präfix einträgt — und der steht in der Oberfläche von IONOS
 * obenan —, bekommt eine Abweisung, die von einem ungültigen Schlüssel
 * spricht, und sucht den Fehler beim Kopieren. Das ist eine Prüfung, die sich
 * beim Hinterlegen erledigen lässt, und genau dafür gibt es
 * {@see self::configure()}.
 *
 * **Beim Anlegen wird gelesen, und hier folgen wir lego.** `PATCH /zones/<id>`
 * bekommt eine Liste von Sätzen; ob der Aufruf sie *hinzufügt* oder den Bestand
 * zu diesem Namen *ersetzt*, geht aus legos Code nicht hervor, und die Seiten
 * von IONOS sind aus diesem Container nicht erreichbar. Bei netcup liess sich
 * dieselbe Frage aus legos eigenem `CleanUp` beantworten — hier nicht. Solange
 * das offen ist, gilt der Weg, der unter **beiden** Lesarten richtig ist: erst
 * die vorhandenen Sätze zu diesem Namen holen, den neuen anhängen, alles
 * schicken. Gelesen wird dabei nur, was auf denselben Namen zeigt, und nicht
 * die ganze Zone.
 *
 * **Und der Wert geht ohne Anführungszeichen hinaus, wird aber in beiden Formen
 * wiedererkannt.** legos `Present` schickt ihn nackt, sein `CleanUp` sucht ihn
 * in Anführungszeichen — eines von beidem stimmt nicht, und welches, hängt
 * daran, ob IONOS beim Ablegen umschreibt. {@see TxtValue::matches()} nimmt
 * beide; damit ist die Frage für uns keine.
 */
final class Ionos implements DnsProvider
{
    public const ENDPOINT = 'https://api.hosting.ionos.com/dns/v1';

    /** Der kleinste TTL, den IONOS für einen Satz annimmt. */
    public const TTL = 300;

    /**
     * Die Zonen des Kontos als Name → Kennung, einmal geholt.
     *
     * @var array<string, string>|null
     */
    private ?array $zones = null;

    /** Wie lange auf die Sichtbarkeit gewartet wird — die Zahl von lego (`docs/34 §11`). */
    public const PATIENCE_SECONDS = 900;

    /** Und in welchem Abstand nachgefragt wird. */
    public const PATIENCE_INTERVAL = 2;

    public function __construct(
        private readonly string $apiKey,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * @param  array<string, mixed>  $config
     * @return array{api_key: string}
     */
    public static function configure(array $config): array
    {
        $key = is_string($config['api_key'] ?? null) ? trim($config['api_key']) : '';

        if ($key === '') {
            throw AgentException::badRequest('Für IONOS fehlt der API-Schlüssel.');
        }

        if (preg_match('/\A[\x21-\x7E]{8,512}\z/D', $key) !== 1) {
            throw AgentException::badRequest(
                'Der API-Schlüssel für IONOS enthält Zeichen, die in einer Kopfzeile nicht stehen dürfen.',
            );
        }

        // **Die Form ist der ganze Punkt dieser Prüfung.** Ein Schlüssel ohne
        // Punkt ist der halbe Schlüssel — meistens nur der Präfix, weil der in
        // der Oberfläche von IONOS obenan steht. Ohne diese Zeile fällt das
        // erst nachts bei einer Erneuerung auf, mit einer Meldung von IONOS,
        // die von einem ungültigen Schlüssel spricht.
        $parts = explode('.', $key);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw AgentException::badRequest(
                'Der API-Schlüssel für IONOS besteht aus zwei Teilen, verbunden mit einem Punkt: '.
                'dem Präfix und dem Geheimnis. IONOS zeigt beide getrennt an.',
            );
        }

        return ['api_key' => $key];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?Outbound $http = null): self
    {
        $checked = self::configure($config);

        return new self($checked['api_key'], $http ?? new Curl);
    }

    public function add(string $record, string $value): void
    {
        $name = self::normalize($record);
        $zoneId = $this->zoneIdFor($record);

        // Siehe die Klassenbeschreibung: Die vorhandenen Sätze zu **diesem**
        // Namen gehen mit, weil offen ist, ob `PATCH` anlegt oder ersetzt.
        $records = $this->recordsOf($zoneId, $name);

        $records[] = [
            'name' => $name,
            'type' => 'TXT',
            'content' => $value,
            'ttl' => self::TTL,
        ];

        $this->read(
            $this->http->send(
                'PATCH',
                self::ENDPOINT.'/zones/'.rawurlencode($zoneId),
                [...$this->headers(), 'Content-Type: application/json'],
                (string) json_encode($records, JSON_THROW_ON_ERROR),
            ),
            'IONOS hat den Eintrag für '.$name.' nicht angenommen',
        );
    }

    public function remove(string $record, string $value): void
    {
        $name = self::normalize($record);
        $zoneId = $this->zoneIdFor($record);

        foreach ($this->recordsOf($zoneId, $name) as $entry) {
            $id = $entry['id'] ?? null;
            $content = $entry['content'] ?? null;

            // **Der Wert entscheidet.** Nach dem Namen allein zu löschen räumte
            // die Prüfung eines gleichzeitig laufenden Vorgangs mit ab. Und er
            // wird in beiden Formen wiedererkannt, weil offen ist, ob IONOS
            // beim Ablegen Anführungszeichen setzt.
            if (! is_string($id) || $id === '' || ! is_string($content) || ! TxtValue::matches($content, $value)) {
                continue;
            }

            $this->read(
                $this->http->send(
                    'DELETE',
                    self::ENDPOINT.'/zones/'.rawurlencode($zoneId).'/records/'.rawurlencode($id),
                    $this->headers(),
                ),
                'IONOS hat den Eintrag für '.$name.' nicht entfernt',
            );
        }

        // Nichts gefunden ist kein Fehlschlag: `remove()` läuft auch, wenn die
        // Bestellung vorher gescheitert ist. lego wirft hier — und macht damit
        // aus einem Fehlschlag zwei.
    }

    /**
     * Die Sätze dieses Namens — vom Anbieter gefiltert.
     *
     * @return list<array<string, mixed>>
     */
    private function recordsOf(string $zoneId, string $name): array
    {
        $answer = $this->read(
            $this->http->send(
                'GET',
                self::ENDPOINT.'/zones/'.rawurlencode($zoneId).
                '?suffix='.rawurlencode($name).'&recordType=TXT',
                $this->headers(),
            ),
            'Die Einträge von IONOS ließen sich nicht abfragen',
        );

        $records = [];

        foreach (is_array($answer['records'] ?? null) ? $answer['records'] : [] as $entry) {
            // **`suffix` ist ein Suffix und kein Name.** IONOS liefert damit
            // auch `x.<name>` mit; was nicht genau dieser Name ist, gehört
            // weder in die Liste, die zurückgeschickt wird, noch in die
            // Auswahl beim Löschen.
            if (is_array($entry) && is_string($entry['name'] ?? null) && strtolower($entry['name']) === $name) {
                $records[] = $entry;
            }
        }

        return $records;
    }

    /** Die Kennung der Zone, in der dieser Name liegt. */
    private function zoneIdFor(string $record): string
    {
        $zones = $this->zoneIds();
        $zone = Zones::pick($record, array_keys($zones));

        if ($zone === null) {
            throw AgentException::badRequest(
                'Für diesen Namen führt das IONOS-Konto keine Zone: '.self::normalize($record),
            );
        }

        return $zones[$zone];
    }

    /**
     * Die Zonen des Kontos.
     *
     * **Ohne Blättern.** IONOS antwortet auf `GET /zones` mit einer schlichten
     * Liste und nennt keine Seitenzahl; was hier ankommt, ist alles.
     *
     * @return array<string, string>
     */
    private function zoneIds(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $answer = $this->readList(
            $this->http->send('GET', self::ENDPOINT.'/zones', $this->headers()),
            'Die Zonen von IONOS ließen sich nicht abfragen',
        );

        $zones = [];

        foreach ($answer as $zone) {
            $name = is_array($zone) ? ($zone['name'] ?? null) : null;
            $id = is_array($zone) ? ($zone['id'] ?? null) : null;

            if (is_string($name) && $name !== '' && is_string($id) && $id !== '') {
                $zones[strtolower($name)] = $id;
            }
        }

        if ($zones === []) {
            throw AgentException::badRequest('Das IONOS-Konto führt keine Zone.');
        }

        return $this->zones = $zones;
    }

    /** Kleingeschrieben und ohne abschliessenden Punkt. */
    private static function normalize(string $record): string
    {
        return strtolower(trim($record, ". \t\n\r\0\x0B"));
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Accept: application/json', 'X-Api-Key: '.$this->apiKey];
    }

    /**
     * Eine Antwort mit Ablage — der Normalfall.
     *
     * @return array<string, mixed>
     */
    private function read(Response $response, string $what): array
    {
        $data = $this->decode($response, $what);

        return is_array($data) ? self::keyed($data) : [];
    }

    /**
     * Und eine Antwort, die eine schlichte Liste ist.
     *
     * `GET /zones` gibt bei IONOS ein Feld zurück und keine Ablage. Wer beides
     * durch dieselbe Stelle schickt, muss sich für einen Typ entscheiden — und
     * die Entscheidung kostet dann an jeder Fundstelle eine Prüfung.
     *
     * @return list<mixed>
     */
    private function readList(Response $response, string $what): array
    {
        $data = $this->decode($response, $what);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Den Rumpf lesen und einen Fehlschlag als Satz weitergeben.
     *
     * **Ein leerer Rumpf ist hier in Ordnung.** IONOS antwortet auf ein
     * gelöschtes oder geändertes Objekt ohne Inhalt; das ist kein Fehler,
     * solange der Code stimmt.
     */
    private function decode(Response $response, string $what): mixed
    {
        if ($response->status === 429) {
            throw AgentException::execFailed($what.': IONOS drosselt gerade (429).');
        }

        if ($response->status === 401 || $response->status === 403) {
            throw AgentException::execFailed(
                $what.': IONOS weist den Schlüssel zurück ('.$response->status.'). '.
                'Gebraucht wird der ganze Schlüssel aus Präfix und Geheimnis, verbunden mit einem Punkt.',
            );
        }

        $raw = trim($response->body);

        if (! $response->successful()) {
            throw AgentException::execFailed($what.': '.self::reason($raw, $response->status));
        }

        return $raw === '' ? [] : json_decode($raw, true);
    }

    /**
     * Eine Ablage mit Zeichenketten als Schlüsseln.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function keyed(array $data): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /**
     * Was IONOS zum Fehlschlag sagt.
     *
     * Die Begründungen stehen als Liste unter `errors`, jede mit einer Kennung
     * — und die geht mit, weil sie das ist, wonach jemand bei IONOS sucht.
     */
    private static function reason(string $raw, int $status): string
    {
        $data = $raw === '' ? null : json_decode($raw, true);
        $errors = is_array($data) ? ($data['errors'] ?? null) : null;

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;
            $code = $error['code'] ?? null;

            if (is_string($message) && $message !== '') {
                return is_string($code) && $code !== '' ? $message.' ('.$code.')' : $message;
            }
        }

        return 'ohne Begründung (HTTP '.$status.').';
    }

    /**
     * Wie lange es hier dauert, bis der Eintrag draussen ist.
     *
     * **Fünfzehn Minuten.** lego setzt sie an, und mit 120 Sekunden bräche eine Bestellung hier ab, bevor der Eintrag überhaupt draussen ist.
     */
    public function patience(): Patience
    {
        return new Patience(self::PATIENCE_SECONDS, self::PATIENCE_INTERVAL);
    }
}
