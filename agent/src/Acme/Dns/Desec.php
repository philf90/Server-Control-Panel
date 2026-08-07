<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Patience;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;

/**
 * deSEC — der Anbieter, der die Zonenfrage selbst beantwortet.
 *
 * **`owns_qname` ist die beste Auskunft der sieben.** Alle anderen nennen ihre
 * Zonen, und dieses Panel sucht sich die längste passende heraus
 * ({@see Zones}); deSEC nimmt den vollen Namen entgegen und antwortet mit
 * genau der Domain, die für ihn zuständig ist. Das ist eine Anfrage statt
 * einer Liste, es gibt kein Blättern, und die Regel „die längste gewinnt"
 * steht hier gar nicht erst zur Debatte — sie ist beim Anbieter.
 *
 * **deSEC führt RRsets, keine einzelnen Sätze.** Alle TXT-Werte zu einem Namen
 * sind ein Gegenstand mit einer Liste. Einen Prüfwert hinzuzufügen heisst
 * deshalb: lesen, anhängen, zurückschreiben — hier ist das
 * Lesen-Ändern-Schreiben keine Bequemlichkeit wie bei netcup, sondern die Form
 * der Schnittstelle. Entsprechend wird beim Abräumen **nur der eigene Wert**
 * aus der Liste genommen: Wer die Liste leert, nimmt einer gleichzeitig
 * laufenden Bestellung ihre Prüfung weg.
 *
 * **Und ein leerer RRset ist keiner.** Nimmt man den letzten Wert heraus,
 * antwortet deSEC mit `204` und löscht ihn. Das ist der Normalfall am Ende
 * einer Bestellung und kein Fehlschlag — wer nur `200` gelten lässt, macht
 * daraus einen.
 *
 * **Warum dieser Anbieter überhaupt auf der Liste steht:** Er ist der Ausweg
 * für alle, deren Anbieter keine Schnittstelle hat — Strato zum Beispiel
 * (`docs/34 §6`). Die Zone zieht um, die Domain bleibt.
 */
final class Desec implements DnsProvider
{
    public const ENDPOINT = 'https://desec.io/api/v1';

    /**
     * Der TTL des Prüfeintrags.
     *
     * **Der höchste der sieben.** deSEC nimmt für ein gewöhnliches Konto nichts
     * Kürzeres; das ist der Grund, warum `ready()` hier nicht nach zwei Minuten
     * aufgeben darf.
     */
    public const TTL = 3600;

    /** Wie deSEC den Namen der Zone selbst schreibt. */
    public const APEX = '@';

    /** Wie lange auf die Sichtbarkeit gewartet wird — die Zahl von lego (`docs/34 §11`). */
    public const PATIENCE_SECONDS = 120;

    /** Und in welchem Abstand nachgefragt wird. */
    public const PATIENCE_INTERVAL = 4;

    public function __construct(
        private readonly string $token,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * @param  array<string, mixed>  $config
     * @return array{token: string}
     */
    public static function configure(array $config): array
    {
        $token = is_string($config['token'] ?? null) ? trim($config['token']) : '';

        if ($token === '') {
            throw AgentException::badRequest('Für deSEC fehlt das Token.');
        }

        if (preg_match('/\A[\x21-\x7E]{8,512}\z/D', $token) !== 1) {
            throw AgentException::badRequest(
                'Das Token für deSEC enthält Zeichen, die in einer Kopfzeile nicht stehen dürfen.',
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
        [$domain, $subname] = $this->split($record);
        $quoted = TxtValue::quoted($value);

        $existing = $this->rrset($domain, $subname);

        if ($existing === null) {
            // Es gibt noch keinen RRset für diesen Namen — dann wird er
            // angelegt und nicht geändert. deSEC beantwortet ein `PATCH` auf
            // etwas, das es nicht gibt, mit 404.
            $this->call('POST', '/domains/'.rawurlencode($domain).'/rrsets/', [
                'subname' => $subname,
                'type' => 'TXT',
                'records' => [$quoted],
                'ttl' => self::TTL,
            ], 'deSEC hat den Eintrag für '.$record.' nicht angenommen');

            return;
        }

        // **Anhängen, nicht ersetzen.** Läuft eine zweite Bestellung für
        // dieselbe Zone, steht ihr Wert schon in dieser Liste.
        if (in_array($quoted, $existing, true)) {
            return;
        }

        $this->patch($domain, $subname, [...$existing, $quoted], $record);
    }

    public function remove(string $record, string $value): void
    {
        [$domain, $subname] = $this->split($record);

        $existing = $this->rrset($domain, $subname);

        // Nichts da, nichts zu tun: `remove()` läuft auch nach einer
        // gescheiterten Bestellung.
        if ($existing === null) {
            return;
        }

        $left = [];

        foreach ($existing as $entry) {
            // **Nur der eigene Wert fällt heraus.** Die Liste zu leeren nähme
            // einer gleichzeitig laufenden Bestellung ihre Prüfung weg.
            if (! TxtValue::matches($entry, $value)) {
                $left[] = $entry;
            }
        }

        if (count($left) === count($existing)) {
            return;
        }

        $this->patch($domain, $subname, $left, $record);
    }

    /**
     * Die Werte eines RRsets zurückschreiben.
     *
     * Eine leere Liste löscht den RRset, und deSEC quittiert das mit `204`.
     *
     * **Geschickt werden nur die Werte, kein TTL.** Das ist die Form, die lego
     * benutzt und die damit im Einsatz belegt ist. Einen TTL mitzuschicken
     * überschriebe ausserdem, was am RRset steht — und beim Löschen wäre es
     * eine Angabe zu einem Gegenstand, den es gleich nicht mehr gibt.
     *
     * @param  list<string>  $records
     */
    private function patch(string $domain, string $subname, array $records, string $record): void
    {
        $this->call(
            'PATCH',
            '/domains/'.rawurlencode($domain).'/rrsets/'.rawurlencode($subname).'/TXT/',
            ['records' => $records],
            'deSEC hat den Eintrag für '.$record.' nicht geändert',
        );
    }

    /**
     * Die Werte, die für diesen Namen schon dastehen — oder `null`.
     *
     * `null` heisst „es gibt keinen RRset", und das ist kein Fehler: beim
     * Anlegen der Normalfall, beim Abräumen die Auskunft, dass nichts zu tun
     * ist.
     *
     * @return list<string>|null
     */
    private function rrset(string $domain, string $subname): ?array
    {
        $response = $this->http->send(
            'GET',
            self::ENDPOINT.'/domains/'.rawurlencode($domain).'/rrsets/'.rawurlencode($subname).'/TXT/',
            $this->headers(),
        );

        if ($response->status === 404) {
            return null;
        }

        $answer = $this->read($response, 'Die Einträge von deSEC ließen sich nicht abfragen');

        $records = [];

        foreach (is_array($answer['records'] ?? null) ? $answer['records'] : [] as $entry) {
            if (is_string($entry)) {
                $records[] = $entry;
            }
        }

        return $records;
    }

    /**
     * Domain und Name darunter — von deSEC beantwortet, nicht gerechnet.
     *
     * `owns_qname` nimmt den vollen Namen und nennt die zuständige Domain. Das
     * ist der Grund, warum dieser Anbieter ohne {@see Zones} auskommt: Die
     * Frage, welche Zone die richtige ist, stellt sich hier nicht — sie wird
     * gestellt.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $record): array
    {
        $name = self::normalize($record);

        $answer = $this->readList(
            $this->http->send(
                'GET',
                self::ENDPOINT.'/domains/?owns_qname='.rawurlencode($name),
                $this->headers(),
            ),
            'Die zuständige Domain ließ sich bei deSEC nicht ermitteln',
        );

        $domain = null;

        foreach ($answer as $entry) {
            $found = is_array($entry) ? ($entry['name'] ?? null) : null;

            if (is_string($found) && $found !== '') {
                $domain = strtolower($found);

                break;
            }
        }

        if ($domain === null) {
            throw AgentException::badRequest(
                'Für diesen Namen führt das deSEC-Konto keine Domain: '.$name,
            );
        }

        // Der Name unterhalb der Domain — leer heisst bei deSEC `@`.
        $subname = Zones::prefix($name, $domain);

        return [$domain, $subname === '' ? self::APEX : $subname];
    }

    /**
     * Ein Aufruf mit Rumpf.
     *
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, string $path, array $payload, string $what): void
    {
        $this->read(
            $this->http->send(
                $method,
                self::ENDPOINT.$path,
                [...$this->headers(), 'Content-Type: application/json'],
                (string) json_encode($payload, JSON_THROW_ON_ERROR),
            ),
            $what,
        );
    }

    /** Kleingeschrieben und ohne abschliessenden Punkt. */
    private static function normalize(string $record): string
    {
        return strtolower(trim($record, ". \t\n\r\0\x0B"));
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Accept: application/json', 'Authorization: Token '.$this->token];
    }

    /**
     * Eine Antwort mit Ablage.
     *
     * @return array<string, mixed>
     */
    private function read(Response $response, string $what): array
    {
        $data = $this->decode($response, $what);

        if (! is_array($data)) {
            return [];
        }

        $fields = [];

        foreach ($data as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /**
     * Und eine Antwort, die eine Liste ist — `GET /domains/` gibt ein Feld.
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
     * **`204` ist Erfolg und nicht Leere.** deSEC antwortet damit, wenn ein
     * RRset durch das Herausnehmen des letzten Werts verschwindet — der
     * Normalfall am Ende einer Bestellung.
     */
    private function decode(Response $response, string $what): mixed
    {
        if ($response->status === 429) {
            throw AgentException::execFailed($what.': deSEC drosselt gerade (429).');
        }

        if ($response->status === 401 || $response->status === 403) {
            throw AgentException::execFailed(
                $what.': deSEC weist das Token zurück ('.$response->status.'). '.
                'Es braucht ein Token mit Schreibrecht auf diese Domain.',
            );
        }

        $raw = trim($response->body);

        if (! $response->successful()) {
            throw AgentException::execFailed($what.': '.self::reason($raw, $response->status));
        }

        return $raw === '' ? [] : json_decode($raw, true);
    }

    /**
     * Was deSEC zum Fehlschlag sagt.
     *
     * Ein einzelner Grund steht unter `detail`; eine Prüfung, die an Feldern
     * hängt, antwortet mit einer Ablage aus Feldnamen und Sätzen. Beides
     * kommt vor, und beides ist besser als „HTTP 400".
     */
    private static function reason(string $raw, int $status): string
    {
        $data = $raw === '' ? null : json_decode($raw, true);

        if (is_array($data)) {
            $detail = $data['detail'] ?? null;

            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

            foreach ($data as $field => $messages) {
                $first = is_array($messages) ? ($messages[0] ?? null) : $messages;

                if (is_string($first) && $first !== '') {
                    return (string) $field.': '.$first;
                }
            }
        }

        return 'ohne Begründung (HTTP '.$status.').';
    }

    /**
     * Wie lange es hier dauert, bis der Eintrag draussen ist.
     *
     * lego setzt 120 Sekunden an und fragt alle vier — deSEC verteilt über mehrere Sekundäre.
     */
    public function patience(): Patience
    {
        return new Patience(self::PATIENCE_SECONDS, self::PATIENCE_INTERVAL);
    }
}
