<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;

/**
 * Cloudflare — und die Anmeldung, die dieses Panel bewusst nicht anbietet.
 *
 * **Cloudflare kennt zwei Arten von Zugangsdaten.** Die ältere ist der globale
 * API-Schlüssel zusammen mit der Kontoadresse (`X-Auth-Email`, `X-Auth-Key`);
 * sie öffnet **das ganze Konto** — alle Zonen, alle Einstellungen, den
 * Zugriffsschutz. Die neuere ist ein API-Token, das sich auf einzelne Zonen und
 * auf zwei Rechte eingrenzen lässt: `Zone:Read`, um die Zonen zu finden, und
 * `DNS:Edit`, um den Prüfeintrag zu setzen.
 *
 * **Angeboten wird nur das Token, und das ist eine Entscheidung.** lego nimmt
 * beide entgegen und rät im Kommentar vom globalen Schlüssel ab. Ein Rat in
 * einem Kommentar ist hier zu wenig: Was in einem Formular steht, wird
 * ausgefüllt, und was ausgefüllt wird, liegt danach als Geheimnis auf der
 * Platte eines Servers, auf dem Kunden Websites betreiben. Ein Formularfeld,
 * das ein ganzes Cloudflare-Konto aufmacht, ist keines, dessen Fehlen jemand
 * vermisst.
 *
 * **Die Zone wird gefragt** (`GET /zones`), und die längste passende gewinnt
 * ({@see Zones}). lego nimmt hier den SOA-Satz der autoritativen Nameserver und
 * fragt Cloudflare erst danach nach der Kennung.
 *
 * **Und gelöscht wird über eine Eintragskennung, nicht über den Wert.** Das ist
 * der Unterschied zu Hetzner und IPv64.net. lego merkt sich die Kennung beim
 * Anlegen in einer Ablage; wir suchen sie beim Abräumen. Der Grund steht in
 * {@see DnsProvider::remove()}: Der Aufruf läuft auch nach einem Fehlschlag,
 * und dann ist die Ablage leer — lego bricht an dieser Stelle mit „unknown
 * record ID" ab und macht aus einem Fehlschlag zwei.
 */
final class Cloudflare implements DnsProvider
{
    public const ENDPOINT = 'https://api.cloudflare.com/client/v4';

    /** Wie viele Zonen eine Seite trägt. */
    public const PER_PAGE = 50;

    /** Mehr Seiten holt niemand — siehe {@see Hetzner::MAX_PAGES}. */
    public const MAX_PAGES = 20;

    /** Der TTL des Prüfeintrags. Cloudflare nimmt ab 60 Sekunden. */
    public const TTL = 120;

    /**
     * Die Zonen des Kontos als Name → Kennung, einmal geholt.
     *
     * **Die Kennung ist der Grund, warum hier eine Ablage steht und keine
     * Liste.** Jeder Aufruf auf einem Eintrag adressiert die Zone über ihre
     * Kennung; der Name kommt nur im Abgleich vor.
     *
     * @var array<string, string>|null
     */
    private ?array $zones = null;

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
            throw AgentException::badRequest('Für Cloudflare fehlt das API-Token.');
        }

        // Geprüft wird die Form und nicht die Gültigkeit. Ein Zeilenumbruch
        // darin hängte eine zweite Kopfzeile an jede Anfrage dieses Anbieters.
        if (preg_match('/\A[\x21-\x7E]{8,512}\z/D', $token) !== 1) {
            throw AgentException::badRequest(
                'Das Token für Cloudflare enthält Zeichen, die in einer Kopfzeile nicht stehen dürfen.',
            );
        }

        // **Die Kontoadresse wird abgewiesen und nicht ignoriert.** Wer sie
        // einträgt, will den globalen Schlüssel benutzen; ihn stillschweigend
        // fallenzulassen hiesse, ein Token zu erwarten und etwas anderes
        // entgegenzunehmen — und die Abweisung käme dann von Cloudflare, mit
        // einem Satz, der den Grund nicht nennt.
        if (isset($config['email']) && $config['email'] !== '') {
            throw AgentException::badRequest(
                'Für Cloudflare nimmt dieses Panel nur ein API-Token entgegen, nicht den globalen '.
                'API-Schlüssel mit Kontoadresse: Der öffnet das ganze Konto.',
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
        $name = self::normalize($record);

        $this->read(
            $this->http->send(
                'POST',
                self::ENDPOINT.'/zones/'.rawurlencode($this->zoneIdFor($record)).'/dns_records',
                [...$this->headers(), 'Content-Type: application/json'],
                (string) json_encode([
                    'type' => 'TXT',
                    // **Der volle Name, nicht der Präfix.** Cloudflare erwartet
                    // hier den ganzen Namen samt Zone; ein Präfix legte den
                    // Eintrag unter `_acme-challenge.example.de.example.de` an.
                    'name' => $name,
                    'content' => TxtValue::quoted($value),
                    'ttl' => self::TTL,
                ], JSON_THROW_ON_ERROR),
            ),
            'Cloudflare hat den Eintrag für '.$name.' nicht angenommen',
        );
    }

    public function remove(string $record, string $value): void
    {
        $name = self::normalize($record);
        $zoneId = $this->zoneIdFor($record);

        foreach ($this->recordsOf($zoneId, $name, $value) as $id) {
            $this->read(
                $this->http->send(
                    'DELETE',
                    self::ENDPOINT.'/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($id),
                    $this->headers(),
                ),
                'Cloudflare hat den Eintrag für '.$name.' nicht entfernt',
            );
        }
    }

    /**
     * Die Kennungen der Einträge, die genau diesen Wert tragen.
     *
     * **Gefiltert wird zweimal, und das ist Absicht.** Cloudflare grenzt
     * serverseitig ein (`type`, `name.exact`), aber seine Filter sind
     * ausdrücklich *nicht* auf Gross- und Kleinschreibung bedacht — ein
     * ACME-Prüfwert ist Base64 und damit sehr wohl. Deshalb wird der Wert hier
     * noch einmal Zeichen für Zeichen verglichen. Nach dem Namen allein zu
     * löschen wäre der teure Fall: Laufen zwei Bestellungen für dieselbe Zone,
     * räumte das die Prüfung des anderen Vorgangs mit ab.
     *
     * @return list<string>
     */
    private function recordsOf(string $zoneId, string $name, string $value): array
    {
        $answer = $this->read(
            $this->http->send(
                'GET',
                self::ENDPOINT.'/zones/'.rawurlencode($zoneId).'/dns_records'.
                '?type=TXT&name.exact='.rawurlencode($name).'&per_page='.self::PER_PAGE,
                $this->headers(),
            ),
            'Die Einträge von Cloudflare ließen sich nicht abfragen',
        );

        $ids = [];

        foreach (is_array($answer['result'] ?? null) ? $answer['result'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $content = $entry['content'] ?? null;

            if (is_string($id) && $id !== '' && is_string($content) && TxtValue::matches($content, $value)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Die Kennung der Zone, in der dieser Name liegt. */
    private function zoneIdFor(string $record): string
    {
        $zones = $this->zoneIds();
        $zone = Zones::pick($record, array_keys($zones));

        if ($zone === null) {
            throw AgentException::badRequest(
                'Für diesen Namen führt das Cloudflare-Konto keine Zone: '.self::normalize($record),
            );
        }

        return $zones[$zone];
    }

    /**
     * Die Zonen des Kontos, über alle Seiten.
     *
     * @return array<string, string>
     */
    private function zoneIds(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $zones = [];
        $page = 1;

        // Gezählt werden die Runden und nicht die Seitennummer — der Grund
        // steht bei {@see Hetzner}: Eine Auskunft, die im Kreis zeigt, hielte
        // eine Bedingung über die Seitennummer für immer erfüllt.
        for ($round = 0; $page > 0; $round++) {
            if ($round >= self::MAX_PAGES) {
                throw AgentException::execFailed(
                    'Die Zonenliste von Cloudflare hört nach '.self::MAX_PAGES.' Seiten nicht auf.',
                );
            }

            $answer = $this->read(
                $this->http->send(
                    'GET',
                    self::ENDPOINT.'/zones?page='.$page.'&per_page='.self::PER_PAGE,
                    $this->headers(),
                ),
                'Die Zonen von Cloudflare ließen sich nicht abfragen',
            );

            foreach (is_array($answer['result'] ?? null) ? $answer['result'] : [] as $zone) {
                $name = is_array($zone) ? ($zone['name'] ?? null) : null;
                $id = is_array($zone) ? ($zone['id'] ?? null) : null;

                if (is_string($name) && $name !== '' && is_string($id) && $id !== '') {
                    $zones[strtolower($name)] = $id;
                }
            }

            $page = self::nextPage($answer, $page);
        }

        if ($zones === []) {
            throw AgentException::badRequest(
                'Das Cloudflare-Token sieht keine Zone. Es braucht das Recht Zone:Read.',
            );
        }

        return $this->zones = $zones;
    }

    /**
     * Die nächste Seite — oder 0, wenn es keine gibt.
     *
     * Cloudflare nennt keine „nächste Seite", sondern die Gesamtzahl der Seiten
     * unter `result_info.total_pages`. Fehlt die Angabe, ist die Antwort
     * einseitig und die Schleife zu Ende.
     *
     * @param  array<string, mixed>  $answer
     */
    private static function nextPage(array $answer, int $page): int
    {
        $info = is_array($answer['result_info'] ?? null) ? $answer['result_info'] : [];
        $total = $info['total_pages'] ?? null;

        return is_int($total) && $page < $total ? $page + 1 : 0;
    }

    /** Kleingeschrieben und ohne abschliessenden Punkt — so nennt Cloudflare seine Namen. */
    private static function normalize(string $record): string
    {
        return strtolower(trim($record, ". \t\n\r\0\x0B"));
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Accept: application/json', 'Authorization: Bearer '.$this->token];
    }

    /**
     * Die Antwort lesen — und einen Fehlschlag als Satz weitergeben.
     *
     * @return array<string, mixed>
     */
    private function read(Response $response, string $what): array
    {
        if ($response->status === 429) {
            throw AgentException::execFailed($what.': Cloudflare drosselt gerade (429).');
        }

        // **Der teure Fall dieses Anbieters.** Ein Token ohne `Zone:Read` sieht
        // keine Zone, eines ohne `DNS:Edit` darf nichts schreiben — und beide
        // Male sagt Cloudflare nur „Authentication error". Wer das liest, hält
        // das Token für falsch und legt ein neues an, mit denselben Rechten.
        if ($response->status === 401 || $response->status === 403) {
            throw AgentException::execFailed(
                $what.': Cloudflare weist das Token zurück ('.$response->status.'). '.
                'Es braucht die Rechte Zone:Read und DNS:Edit für diese Zone.',
            );
        }

        $raw = trim($response->body);
        $data = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw AgentException::execFailed($what.': die Antwort ist keine Auskunft ('.$response->status.').');
        }

        // **`success` zählt und nicht nur der HTTP-Code.** Cloudflare antwortet
        // auf einen abgelehnten Vorgang durchaus mit 200 und `"success": false`;
        // wer nur den Code liest, hält das für erledigt.
        if (! $response->successful() || ($data['success'] ?? null) !== true) {
            throw AgentException::execFailed($what.': '.self::reason($data, $response->status));
        }

        return $data;
    }

    /**
     * Was Cloudflare zum Fehlschlag sagt.
     *
     * Die Begründungen stehen als Liste unter `errors`, jede mit einer Nummer.
     * Die Nummer geht mit, weil sie das ist, wonach jemand in Cloudflares
     * Dokumentation sucht.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reason(array $data, int $status): string
    {
        foreach (is_array($data['errors'] ?? null) ? $data['errors'] : [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;
            $code = $error['code'] ?? null;

            if (is_string($message) && $message !== '') {
                return is_int($code) && $code > 0 ? $message.' ('.$code.')' : $message;
            }
        }

        return 'ohne Begründung (HTTP '.$status.').';
    }
}
