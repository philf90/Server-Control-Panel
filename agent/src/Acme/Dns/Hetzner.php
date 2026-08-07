<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\DnsChallenge;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;

/**
 * Hetzner — und die Frage, gegen welche der beiden Schnittstellen.
 *
 * **Hetzner führt zwei APIs für dasselbe.** Die ältere gehört zur DNS-Konsole
 * (`dns.hetzner.com/api/v1`, Kopfzeile `Auth-API-Token`), die neuere ist Teil
 * der Cloud-API (`api.hetzner.cloud/v1`, `Authorization: Bearer`). lego hält
 * beide vor und entscheidet daran, welche Umgebungsvariable gesetzt ist. Wir
 * bauen gegen die **Cloud-API**, weil Hetzner die DNS-Verwaltung dorthin
 * überführt hat und ein Panel, das gegen die auslaufende Strecke baut, sie
 * zweimal bauen muss.
 *
 * **Ein Token der einen gilt bei der anderen nicht**, und die Abweisung sagt
 * das nicht von selbst. Auseinanderhalten lassen sich die beiden an ihrer Form
 * nicht — nachgesehen und nicht gefunden —, deshalb steht der Hinweis dort, wo
 * er ankommt: in der Meldung zu 401 und 403.
 *
 * **Die Zone wird gefragt.** `GET /zones` nennt die Zonen des Projekts, die
 * längste passende gewinnt ({@see Zones}). lego macht das hier anders und fragt
 * die autoritativen Nameserver nach dem SOA-Satz; das ist eine zweite Auskunft
 * über etwas, das die Schnittstelle selbst weiss, und sie ist bei frisch
 * angelegten Zonen die langsamere.
 *
 * **Und der Schreibvorgang ist nicht fertig, wenn die Antwort kommt.** Die
 * Cloud-API antwortet mit einer *Action*, die auf `running` stehen kann. Wir
 * lesen ihren Zustand **einmal** und warten nicht: Ob der Eintrag wirklich da
 * ist, beantwortet ohnehin nur {@see DnsChallenge}, und die fragt die
 * autoritativen Nameserver — eine strengere Frage als „der Auftrag ist
 * durchgelaufen". Was ein einmaliger Blick bringt, ist der Fall `error`: Der
 * steht sofort da und spart eine Prüfung, die zwei Minuten lang auf einen
 * Eintrag wartet, den niemand mehr anlegen wird.
 */
final class Hetzner implements DnsProvider
{
    public const ENDPOINT = 'https://api.hetzner.cloud/v1';

    /** Wie viele Zonen eine Seite trägt — der Höchstwert der Schnittstelle. */
    public const PER_PAGE = 50;

    /**
     * Mehr Seiten holt niemand.
     *
     * **Eine Schleife, die einer Antwort glaubt, ist eine Schleife, die eine
     * Antwort anhalten kann.** `next_page` kommt von aussen; zeigt es im Kreis,
     * läuft hier ein Prozess mit Systemrechten, bis ihn jemand bemerkt.
     */
    public const MAX_PAGES = 20;

    /** Der TTL des Prüfeintrags — kurz, er wird binnen Minuten wieder abgeräumt. */
    public const TTL = 60;

    /**
     * Die Zonen des Projekts, einmal geholt.
     *
     * `add()` und `remove()` laufen im selben Vorgang, und beide brauchen die
     * Auflösung.
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
     * **Nur das Token.** Die Zonen kennt die Schnittstelle selbst; ein Feld
     * daneben, das jemand ausfüllt und niemand liest, wäre eine zweite Auskunft
     * über dieselbe Sache.
     *
     * @param  array<string, mixed>  $config
     * @return array{token: string}
     */
    public static function configure(array $config): array
    {
        $token = is_string($config['token'] ?? null) ? trim($config['token']) : '';

        if ($token === '') {
            throw AgentException::badRequest('Für Hetzner fehlt das Token.');
        }

        // Geprüft wird die Form und nicht die Gültigkeit. Ein Zeilenumbruch
        // darin hängte eine zweite Kopfzeile an jede Anfrage dieses Anbieters.
        if (preg_match('/\A[\x21-\x7E]{8,512}\z/D', $token) !== 1) {
            throw AgentException::badRequest(
                'Das Token für Hetzner enthält Zeichen, die in einer Kopfzeile nicht stehen dürfen.',
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

        $this->act($zone, $prefix, 'add_records', [
            'ttl' => self::TTL,
            'records' => [['value' => self::quoted($value)]],
        ], $record);
    }

    public function remove(string $record, string $value): void
    {
        [$zone, $prefix] = $this->split($record);

        // **Der Wert geht mit.** Laufen zwei Bestellungen für dieselbe Zone,
        // stehen zwei `_acme-challenge`-Werte in derselben RRSet; ein Aufruf
        // ohne Wert räumte die Prüfung des anderen Vorgangs mit ab.
        $this->act($zone, $prefix, 'remove_records', [
            'records' => [['value' => self::quoted($value)]],
        ], $record);
    }

    /**
     * Ein TXT-Wert, wie ihn die Zonendatei schreibt: in Anführungszeichen.
     *
     * **Und ohne Fluchtfolgen.** Ein Wert mit einem Anführungszeichen oder
     * einem Rückstrich darin bräuchte eine Fluchtregel, und die wäre eine
     * eigene kleine Sprache mit eigenen Fehlern. Ein ACME-Prüfwert ist Base64
     * ohne Polster und enthält beides nie — was ihn doch enthält, ist kein
     * Prüfwert und wird abgewiesen statt halb richtig verpackt.
     */
    private static function quoted(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, '\\')) {
            throw AgentException::badRequest('Dieser Prüfwert lässt sich nicht als TXT-Eintrag schreiben.');
        }

        return '"'.$value.'"';
    }

    /**
     * Einen Auftrag auf einer RRSet absetzen.
     *
     * Der Name der RRSet ist der Präfix **relativ zur Zone** — `_acme-challenge`
     * und nicht der volle Name. Wer den vollen Namen schickt, legt den Eintrag
     * unter `_acme-challenge.example.de.example.de` an; angenommen wird das,
     * gefunden wird es nie.
     *
     * @param  array<string, mixed>  $payload
     */
    private function act(string $zone, string $prefix, string $action, array $payload, string $record): void
    {
        $answer = $this->read(
            $this->http->send(
                'POST',
                self::ENDPOINT.'/zones/'.rawurlencode($zone).'/rrsets/'.rawurlencode($prefix).'/TXT/actions/'.$action,
                [...$this->headers(), 'Content-Type: application/json'],
                (string) json_encode($payload, JSON_THROW_ON_ERROR),
            ),
            'Hetzner hat den Eintrag für '.$record.' nicht angenommen',
        );

        $this->checkAction($answer, $record);
    }

    /**
     * Der Zustand des Auftrags, einmal gelesen.
     *
     * `running` ist kein Befund — siehe die Klassenbeschreibung. `error` ist
     * einer, und die Begründung steht in einem eigenen Feld daneben.
     *
     * @param  array<string, mixed>  $answer
     */
    private function checkAction(array $answer, string $record): void
    {
        $action = is_array($answer['action'] ?? null) ? $answer['action'] : [];

        if (($action['status'] ?? null) !== 'error') {
            return;
        }

        $error = is_array($action['error'] ?? null) ? $action['error'] : [];
        $message = $error['message'] ?? null;

        throw AgentException::execFailed(
            'Hetzner hat den Eintrag für '.$record.' abgelehnt: '.
            (is_string($message) && $message !== '' ? $message : 'ohne Begründung.'),
        );
    }

    /**
     * Zone und Präfix zu einem Namen — gefragt, nicht gerechnet.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $record): array
    {
        $zone = Zones::pick($record, $this->knownZones());

        if ($zone === null) {
            throw AgentException::badRequest(
                'Für diesen Namen führt das Hetzner-Projekt keine Zone: '.strtolower(trim($record)),
            );
        }

        $prefix = Zones::prefix($record, $zone);

        // Eine RRSet ohne Namen gibt es bei Hetzner nur als `@` für die Zone
        // selbst. Ein Prüfname trägt immer `_acme-challenge` vor sich; ist der
        // Präfix trotzdem leer, heisst die Zone wörtlich so, und dann ist das
        // kein Fall, den ein stiller Ausweichwert richtig behandelt.
        if ($prefix === '') {
            throw AgentException::badRequest(
                'Dieser Prüfname ist bei Hetzner selbst eine Zone: '.$zone,
            );
        }

        return [$zone, $prefix];
    }

    /**
     * Die Zonen des Projekts, über alle Seiten.
     *
     * @return list<string>
     */
    private function knownZones(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $zones = [];
        $page = 1;

        // **Gezählt werden die Runden und nicht die Seitennummer.** Diese
        // Schleife lief beim Bauen endlos: `next_page` kam mit `1` zurück,
        // während `$page` auf `1` stand — die Bedingung „Seite kleiner als die
        // Obergrenze" war damit für immer erfüllt. Was hier begrenzt gehört,
        // ist die Zahl der Anfragen, denn die ist das, was der Prozess tut.
        for ($round = 0; $page > 0; $round++) {
            if ($round >= self::MAX_PAGES) {
                // **Kein stilles Abschneiden.** Wer hier einfach aufhört,
                // meldet gleich darauf „für diesen Namen keine Zone" — und
                // damit einen Grund, der nicht stimmt.
                throw AgentException::execFailed(
                    'Die Zonenliste von Hetzner hört nach '.self::MAX_PAGES.' Seiten nicht auf.',
                );
            }

            $answer = $this->read(
                $this->http->send(
                    'GET',
                    self::ENDPOINT.'/zones?page='.$page.'&per_page='.self::PER_PAGE,
                    $this->headers(),
                ),
                'Die Zonen von Hetzner ließen sich nicht abfragen',
            );

            foreach (is_array($answer['zones'] ?? null) ? $answer['zones'] : [] as $zone) {
                $name = is_array($zone) ? ($zone['name'] ?? null) : null;

                if (is_string($name) && $name !== '') {
                    $zones[] = strtolower($name);
                }
            }

            $page = self::nextPage($answer);
        }

        if ($zones === []) {
            throw AgentException::badRequest('Das Hetzner-Projekt führt keine Zone.');
        }

        return $this->zones = $zones;
    }

    /**
     * Die nächste Seite — oder 0, wenn es keine gibt.
     *
     * Der Wert steht bei Hetzner als `null`, sobald die letzte Seite erreicht
     * ist. Gegen einen Kreis hilft das nicht — dafür zählt der Aufrufer seine
     * Runden.
     *
     * @param  array<string, mixed>  $answer
     */
    private static function nextPage(array $answer): int
    {
        $meta = is_array($answer['meta'] ?? null) ? $answer['meta'] : [];
        $pagination = is_array($meta['pagination'] ?? null) ? $meta['pagination'] : [];
        $next = $pagination['next_page'] ?? null;

        return is_int($next) && $next > 0 ? $next : 0;
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
        // Die Cloud-API drosselt und sagt es mit 429. Das gehört in die
        // Meldung: Ein Vorgang, der ohne Grund scheitert, wird wiederholt —
        // einer, der „zu schnell" sagt, wird abgewartet.
        if ($response->status === 429) {
            throw AgentException::execFailed($what.': Hetzner drosselt gerade (429).');
        }

        // **Der teure Fall dieses Anbieters.** Ein Token der DNS-Konsole ist
        // hier kein gültiges Token, und die Antwort sagt nur „unauthorized".
        // Wer das liest, sucht den Fehler beim Token und nicht bei der
        // Schnittstelle — und trägt dasselbe Token noch einmal ein.
        if ($response->status === 401 || $response->status === 403) {
            throw AgentException::execFailed(
                $what.': Hetzner weist das Token zurück ('.$response->status.'). '.
                'Gebraucht wird ein Token der Cloud-Konsole; eines aus der alten DNS-Konsole '.
                '(dns.hetzner.com) gilt hier nicht.',
            );
        }

        $raw = trim($response->body);
        $data = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw AgentException::execFailed($what.': die Antwort ist keine Auskunft ('.$response->status.').');
        }

        if (! $response->successful()) {
            throw AgentException::execFailed($what.': '.self::reason($data, $response->status));
        }

        return $data;
    }

    /**
     * Was die Schnittstelle zum Fehlschlag sagt.
     *
     * Sie legt Kennung und Satz unter `error` ab. Die Kennung geht mit, weil
     * sie das ist, wonach jemand in Hetzners Dokumentation sucht.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reason(array $data, int $status): string
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = $error['message'] ?? null;
        $code = $error['code'] ?? null;

        if (! is_string($message) || $message === '') {
            return 'ohne Begründung (HTTP '.$status.').';
        }

        return is_string($code) && $code !== '' ? $message.' ('.$code.')' : $message;
    }
}
