<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Patience;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Totp;

/**
 * INWX — der teuerste der sieben, und der einzige mit einem Kontopasswort.
 *
 * **Was hier hinterlegt wird, öffnet ein Registrarkonto und nicht eine Zone.**
 * Bei allen anderen ist es ein Token, das sich einschränken lässt; hier sind es
 * Benutzername und Passwort desselben Zugangs, mit dem man Domains kauft,
 * überträgt und löscht. Für einen **Kunden** ist das die falsche
 * Grössenordnung — die Frage steht in `docs/34 §11` und gehört dem Betreiber.
 *
 * **Vier Dinge sind hier anders als bei allen anderen.**
 *
 * **Erstens: XML-RPC.** INWX spricht kein JSON. Der Umgang damit steht in
 * {@see XmlRpc} und nicht hier.
 *
 * **Zweitens: eine Sitzung über ein Cookie.** `account.login` setzt
 * `domrobot=…`, und jeder weitere Aufruf trägt es mit. Anders als bei netcup
 * wird **einmal je Vorgang** angemeldet und nicht je Aufruf — der Grund steht
 * beim dritten Punkt.
 *
 * **Drittens: der zweite Faktor.** Ist das Konto gesichert, antwortet
 * `account.login` mit `tfa: GOOGLE-AUTH`, und es folgt `account.unlock` mit
 * einem TAN aus dem gemeinsamen Geheimnis. **INWX nimmt denselben TAN kein
 * zweites Mal.** lego wartet deshalb notfalls dreissig Sekunden auf den
 * nächsten Zeitschritt. Wir schlafen nicht: Anlegen und Abräumen benutzen
 * dieselbe Instanz dieses Anbieters — `Ops\AcmeCertificate` baut sie einmal je
 * Bestellung —, also gibt es genau **eine** Anmeldung und damit genau einen
 * TAN. Der Klassenname steht hier als Text und nicht als Marke: Daraus machte
 * der Formatierer einen Import, und dann hinge ein DNS-Anbieter an einer
 * Operation, nur wegen eines Kommentars. Dieselbe Falle steht schon in
 * `Totp`. Ein Schlaf im Agenten wäre eine halbe Minute, in der
 * ein Prozess mit Systemrechten nichts tut und sein Zeitlimit näherkommt.
 *
 * **Viertens: die Zonen kommen aus `nameserver.list`.** INWX nennt sie selbst,
 * anders als netcup — die Positivliste im Formular braucht es hier also nicht.
 */
final class Inwx implements DnsProvider
{
    public const ENDPOINT = 'https://api.domrobot.com/xmlrpc/';

    /** Die Adresse des Testbetriebs — sie wird nicht angeboten, steht hier aber als Beleg. */
    public const SANDBOX = 'https://api.ote.domrobot.com/xmlrpc/';

    /** Was INWX als Erfolg zurückgibt: 1000 „completed", 1500 „completed, session ended". */
    public const OK = [1000, 1500];

    /** Die Antwort auf `account.login`, wenn das Konto einen zweiten Faktor trägt. */
    public const TFA = 'GOOGLE-AUTH';

    /** Der TTL des Prüfeintrags. */
    public const TTL = 300;

    /** Wie viele Zonen eine Seite trägt. */
    public const PAGE = 1000;

    private ?string $session = null;

    /** @var array<string, true>|null */
    private ?array $zones = null;

    /** Wie lange auf die Sichtbarkeit gewartet wird — die Zahl von lego (`docs/34 §11`). */
    public const PATIENCE_SECONDS = 360;

    /** Und in welchem Abstand nachgefragt wird. */
    public const PATIENCE_INTERVAL = 2;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $sharedSecret,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Die Zugangsdaten prüfen — beim Hinterlegen und nicht erst beim Bestellen.
     *
     * Das gemeinsame Geheimnis ist freiwillig: Ein Konto ohne zweiten Faktor
     * braucht keines, und eines mit erfährt es beim ersten Anmelden. Was hier
     * geprüft wird, ist nur die Form.
     *
     * @param  array<string, mixed>  $config
     * @return array{username: string, password: string, shared_secret: string}
     */
    public static function configure(array $config): array
    {
        $username = is_string($config['username'] ?? null) ? trim($config['username']) : '';
        $password = is_string($config['password'] ?? null) ? $config['password'] : '';
        $secret = is_string($config['shared_secret'] ?? null) ? trim($config['shared_secret']) : '';

        if ($username === '') {
            throw AgentException::badRequest('Für INWX fehlt der Benutzername.');
        }

        if (trim($password) === '') {
            throw AgentException::badRequest('Für INWX fehlt das Passwort.');
        }

        foreach (['Benutzername' => $username, 'Passwort' => $password] as $what => $value) {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw AgentException::badRequest('Der '.$what.' für INWX enthält Steuerzeichen.');
            }
        }

        // **Das Geheimnis wird geprüft, wenn eines da ist.** Base32 mit einem
        // Tippfehler ergibt einen TAN, der aussieht wie einer und nie stimmt —
        // und das fiele erst auf, wenn eine Erneuerung nachts scheitert.
        if ($secret !== '' && preg_match('/\A[A-Za-z2-7 =-]{16,}\z/D', $secret) !== 1) {
            throw AgentException::badRequest(
                'Das gemeinsame Geheimnis für INWX ist Base32 — Buchstaben und die Ziffern 2 bis 7.',
            );
        }

        return ['username' => $username, 'password' => $password, 'shared_secret' => $secret];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?Outbound $http = null): self
    {
        $checked = self::configure($config);

        return new self(
            $checked['username'],
            $checked['password'],
            $checked['shared_secret'],
            $http ?? new Curl,
        );
    }

    public function add(string $record, string $value): void
    {
        [$zone, $name] = $this->split($record);

        // **„Object exists" ist hier kein Fehlschlag.** Der Eintrag steht dann
        // schon da — etwa weil ein früherer Versuch abgebrochen ist, nachdem er
        // ihn gesetzt hatte.
        $this->call('nameserver.createRecord', [
            'domain' => $zone,
            'type' => 'TXT',
            'name' => $name,
            'content' => $value,
            'ttl' => self::TTL,
        ], 'INWX hat den Eintrag für '.$record.' nicht angenommen', ['Object exists']);
    }

    public function remove(string $record, string $value): void
    {
        try {
            [$zone, $name] = $this->split($record);

            foreach ($this->recordsOf($zone, $name, $value) as $id) {
                $this->call('nameserver.deleteRecord', ['id' => $id],
                    'INWX hat den Eintrag für '.$record.' nicht entfernt');
            }
        } finally {
            // **Abgemeldet wird am Ende der Bestellung**, und `remove()` ist
            // deren letzter Aufruf. Das Ergebnis wird nicht geprüft: Ein
            // gescheitertes Abmelden machte aus einem abgeräumten Eintrag einen
            // Fehlschlag. Bricht der Vorgang vorher ab, bleibt eine Sitzung
            // liegen — INWX lässt sie ablaufen.
            $this->logout();
        }
    }

    /**
     * Die Kennungen der Einträge, die genau diesen Namen und Wert tragen.
     *
     * **Der Name zählt mit.** lego vergleicht nur den Wert; stehen zwei
     * Prüfeinträge mit demselben Wert unter verschiedenen Namen, ist das der
     * falsche Satz.
     *
     * @return list<int>
     */
    private function recordsOf(string $zone, string $name, string $value): array
    {
        $full = $name === '' ? $zone : $name.'.'.$zone;

        $answer = $this->call('nameserver.info', [
            'domain' => $zone,
            'name' => $full,
            'type' => 'TXT',
        ], 'Die Einträge von INWX ließen sich nicht abfragen');

        $data = is_array($answer['resData'] ?? null) ? $answer['resData'] : [];
        $ids = [];

        foreach (is_array($data['record'] ?? null) ? $data['record'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $content = $entry['content'] ?? null;
            $found = $entry['name'] ?? null;

            // **Der Name wird hier noch einmal verglichen und nicht dem Filter
            // überlassen.** Dieselbe Lehre wie bei IONOS: Was ein Anbieter als
            // Filter versteht, ist seine Sache; was gelöscht wird, ist unsere.
            if (
                is_int($id)
                && is_string($found)
                && strtolower($found) === $full
                && is_string($content)
                && TxtValue::matches($content, $value)
            ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Zone und Name darunter.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $record): array
    {
        $zone = Zones::pick($record, array_keys($this->knownZones()));

        if ($zone === null) {
            throw AgentException::badRequest(
                'Für diesen Namen führt das INWX-Konto keine Zone: '.strtolower(trim($record)),
            );
        }

        return [$zone, Zones::prefix($record, $zone)];
    }

    /**
     * Die Zonen des Kontos.
     *
     * @return array<string, true>
     */
    private function knownZones(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $answer = $this->call('nameserver.list', ['pagelimit' => self::PAGE],
            'Die Zonen von INWX ließen sich nicht abfragen');

        $data = is_array($answer['resData'] ?? null) ? $answer['resData'] : [];
        $zones = [];

        foreach (is_array($data['domains'] ?? null) ? $data['domains'] : [] as $entry) {
            $name = is_array($entry) ? ($entry['domain'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $zones[strtolower($name)] = true;
            }
        }

        if ($zones === []) {
            throw AgentException::badRequest('Das INWX-Konto führt keine Zone.');
        }

        // **Der Deckel wird gemeldet und nicht verschwiegen.** Hat ein Konto
        // mehr Zonen, als eine Seite trägt, fehlte hier still die gesuchte —
        // und die Meldung spräche von einem Namen ausserhalb aller Zonen.
        $count = $data['count'] ?? null;

        if (is_int($count) && $count > count($zones)) {
            throw AgentException::execFailed(
                'Das INWX-Konto führt '.$count.' Zonen, geholt wurden '.count($zones).'.',
            );
        }

        return $this->zones = $zones;
    }

    /**
     * Ein Aufruf — meldet beim ersten Mal an.
     *
     * @param  array<string, string|int>  $params
     * @param  list<string>  $tolerated  Meldungen, die kein Fehlschlag sind
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, string $what, array $tolerated = []): array
    {
        $this->login();

        return $this->send($method, $params, $what, $tolerated);
    }

    /**
     * Anmelden — genau einmal je Bestellung.
     *
     * Siehe die Klassenbeschreibung: Das ist der Grund, warum hier nicht wie
     * bei netcup je Aufruf an- und abgemeldet wird. INWX nimmt denselben TAN
     * kein zweites Mal, und zwei Anmeldungen im selben Zeitschritt hätten
     * denselben.
     */
    private function login(): void
    {
        if ($this->session !== null) {
            return;
        }

        $response = $this->post(XmlRpc::request('account.login', [
            'user' => $this->username,
            'pass' => $this->password,
            'lang' => 'en',
        ]));

        $cookie = self::cookieOf($response);

        if ($cookie === null) {
            throw AgentException::execFailed('INWX hat auf die Anmeldung keine Sitzung geschickt.');
        }

        $this->session = $cookie;

        $answer = self::checked(XmlRpc::response($response->body), 'Die Anmeldung bei INWX ist gescheitert', []);

        $data = is_array($answer['resData'] ?? null) ? $answer['resData'] : [];

        if (($data['tfa'] ?? null) !== self::TFA) {
            return;
        }

        if ($this->sharedSecret === '') {
            throw AgentException::badRequest(
                'Das INWX-Konto ist mit einem zweiten Faktor gesichert; dafür fehlt das gemeinsame Geheimnis.',
            );
        }

        $this->send('account.unlock', [
            'tan' => Totp::codeAt($this->sharedSecret, intdiv(time(), Totp::PERIOD)),
        ], 'Der zweite Faktor bei INWX wurde nicht angenommen');
    }

    /** Abmelden, ohne dass ein Fehlschlag daraus wird. */
    private function logout(): void
    {
        if ($this->session === null) {
            return;
        }

        try {
            $this->send('account.logout', [], 'Das Abmelden bei INWX ist gescheitert');
        } catch (AgentException) {
            // Siehe `remove()`: Die Sitzung läuft bei INWX ohnehin ab.
        }

        $this->session = null;
    }

    /**
     * Ein Aufruf in einer bestehenden Sitzung.
     *
     * @param  array<string, string|int>  $params
     * @param  list<string>  $tolerated
     * @return array<string, mixed>
     */
    private function send(string $method, array $params, string $what, array $tolerated = []): array
    {
        $response = $this->post(XmlRpc::request($method, $params));

        if (! $response->successful()) {
            throw AgentException::execFailed($what.': INWX antwortet mit HTTP '.$response->status.'.');
        }

        return self::checked(XmlRpc::response($response->body), $what, $tolerated);
    }

    private function post(string $xml): Response
    {
        $headers = ['Accept: text/xml', 'Content-Type: text/xml'];

        if ($this->session !== null) {
            $headers[] = 'Cookie: '.$this->session;
        }

        $response = $this->http->send('POST', self::ENDPOINT, $headers, $xml);

        if ($response->status === 429) {
            throw AgentException::execFailed('INWX drosselt gerade (429).');
        }

        return $response;
    }

    /**
     * Die Sitzungskennung aus der Antwort auf die Anmeldung.
     *
     * Gesucht wird `domrobot=…`; alles dahinter — Pfad, Ablauf, Marken —
     * gehört dem Browser und nicht uns.
     */
    private static function cookieOf(Response $response): ?string
    {
        $header = $response->header('set-cookie');

        if ($header === null || preg_match('/\bdomrobot=([^;\s]+)/', $header, $found) !== 1) {
            return null;
        }

        return 'domrobot='.$found[1];
    }

    /**
     * Die Antwort prüfen — der Code steht im Rumpf und nicht im HTTP-Status.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $tolerated
     * @return array<string, mixed>
     */
    private static function checked(array $answer, string $what, array $tolerated): array
    {
        $code = $answer['code'] ?? null;

        if (is_int($code) && in_array($code, self::OK, true)) {
            return $answer;
        }

        $message = is_string($answer['msg'] ?? null) ? $answer['msg'] : '';

        // **„Object exists" ist beim Anlegen kein Fehlschlag.** Der Eintrag
        // steht dann schon da — etwa weil ein früherer Versuch abgebrochen ist,
        // nachdem er ihn gesetzt hatte.
        if ($message !== '' && in_array($message, $tolerated, true)) {
            return $answer;
        }

        $reason = is_string($answer['reason'] ?? null) && $answer['reason'] !== ''
            ? $answer['reason']
            : ($message !== '' ? $message : 'ohne Begründung');

        throw AgentException::execFailed(
            $what.': '.$reason.(is_int($code) ? ' ('.$code.')' : ''),
        );
    }

    /**
     * Wie lange es hier dauert, bis der Eintrag draussen ist.
     *
     * lego setzt hier sechs Minuten an.
     */
    public function patience(): Patience
    {
        return new Patience(self::PATIENCE_SECONDS, self::PATIENCE_INTERVAL);
    }
}
