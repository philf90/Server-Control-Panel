<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Notify;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\AgentException;

/**
 * Wohin der Server meldet — und warum das hier liegt und nicht im Panel.
 *
 * **Ein Ziel je Server, vom Betreiber gesetzt** (`docs/129 §2`, Entscheidung 3).
 * Das ist zugleich die kleinere Oberfläche und die, die keine neue Frage an die
 * Mandantenklammer stellt: Es gibt keinen Kunden, dem dieses Ziel gehörte.
 *
 * **Die Zugangsdaten liegen wie die des DNS-Anbieters** — 0600 in einem
 * 0700-Verzeichnis, und der Grund ist derselbe wie in
 * {@see Credentials}: Ein Geheimnis darf **nicht als
 * Argument eines Vorgangs reisen**, weil `Operations/Show.vue` den `payload`
 * als JSON rendert und `OperationPolicy::view()` jeden Admin und den Kunden des
 * Abonnements durchlässt (`docs/128` M8, die vierte Grenze).
 *
 * **Ein Unterschied zum Vorbild, und er spart eine ganze Fehlerklasse.**
 * `Credentials` nimmt einen Profilnamen von aussen und macht daraus einen
 * Dateinamen; deshalb steht dort {@see Credentials::NAME_PATTERN}
 * und die Warnung vor `../../etc/irgendwas`. Hier gibt es **einen** Namen, und
 * er steht in dieser Datei. Von aussen kommt kein Pfadbestandteil.
 *
 * > **Ein Pfad, den niemand von aussen mitbestimmt, braucht keine Prüfung, die
 * > jemand vergessen kann.**
 *
 * **Die Adresse ist ein halbes Geheimnis, und das entscheidet {@see describe}.**
 * Bei Slack, Discord und den meisten Eingangshaken berechtigt die Adresse
 * *allein* zur Zustellung — wer sie hat, schreibt in den Kanal. Sie steht
 * deshalb nicht vollständig in einer Antwort; heraus geht der **Rechnername**,
 * und der beantwortet die einzige Frage, die der Betreiber an eine hinterlegte
 * Adresse hat: welches Ziel steht hier.
 *
 * > **Eine Adresse, die allein zur Zustellung berechtigt, ist ein Geheimnis in
 * > Gestalt einer Adresse — und sie sieht auf einer Seite aus wie eine
 * > Auskunft.**
 */
final class Target
{
    /** Hier liegt das Ziel. Ein Server, eine Datei. */
    public const DIRECTORY = '/etc/srvpanel/notify';

    public const FILE = 'webhook.json';

    /**
     * Ein Geheimnis kürzer als das ist keines.
     *
     * Es wird nicht hier erzeugt, sondern vom Betreiber mitgebracht — bei
     * Slack und Discord gibt es gar keines, dort trägt die Adresse alles. Die
     * Untergrenze gilt deshalb nur, **wenn** eines mitkommt.
     */
    public const SECRET_MIN = 16;

    public function __construct(
        private readonly string $directory = self::DIRECTORY,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Das Ziel hinterlegen.
     *
     * **Die Adresse wird gegen {@see Outbound::permitted()} geprüft und nicht
     * gegen ein eigenes `str_starts_with`.** Sonst stünde die Regel „nur https"
     * zweimal da, und die zweite ist die, die veraltet — genau die Begründung,
     * aus der {@see Curl::permitted()} überhaupt eine eigene Methode ist.
     *
     * **Geprüft wird beim Hinterlegen und nicht beim Melden.** Was hier
     * durchginge, fiele sonst erst in der Nacht auf, in der etwas zu melden
     * wäre — und dann sieht der Betreiber einen stillen Server und keine
     * Ursache. Derselbe Grund wie bei `Credentials::store()`.
     *
     * **Geschrieben wird über eine Zwischendatei**, weil ein Lauf, der
     * mittendrin abbricht, sonst eine halbe Datei hinterlässt: Die ist beim
     * nächsten Lesen kein Fehler, sondern eine Adresse, die fast stimmt.
     */
    public function store(mixed $url, mixed $secret, mixed $provider = Providers::GENERIC): void
    {
        $address = self::address($url);
        $key = Providers::usable($provider);
        $signing = self::signingSecret($secret, $key);

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw AgentException::execFailed('Das Verzeichnis für das Meldeziel ließ sich nicht anlegen.');
        }

        chmod($this->directory, 0o700);

        $contents = json_encode([
            'url' => $address,
            'provider' => $key,
            'secret' => $signing,
            'stored_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($contents)) {
            throw AgentException::execFailed('Das Meldeziel ließ sich nicht schreiben.');
        }

        $path = $this->path();
        $temp = $path.'.neu';

        if (@file_put_contents($temp, $contents) === false) {
            throw AgentException::execFailed('Das Meldeziel ließ sich nicht schreiben: '.$path);
        }

        chmod($temp, 0o600);
        rename($temp, $path);
    }

    /**
     * Adresse und Geheimnis — für den Agenten selbst.
     *
     * **Diese Antwort verlässt den Agenten nie.** Sie ist für {@see Delivery},
     * und für keine Operation, die etwas zurückgibt.
     *
     * @return array{url: string, provider: string, secret: ?string}
     */
    public function read(): array
    {
        $data = $this->contents();

        if ($data === null) {
            throw AgentException::badRequest('Für diesen Server ist kein Meldeziel hinterlegt.');
        }

        $url = $data['url'] ?? null;
        $secret = $data['secret'] ?? null;

        if (! is_string($url) || $url === '') {
            throw AgentException::execFailed('Das Meldeziel ist unlesbar: '.$this->path());
        }

        return [
            'url' => $url,
            'provider' => Providers::normalize($data['provider'] ?? null),
            'secret' => is_string($secret) && $secret !== '' ? $secret : null,
        ];
    }

    /**
     * Was über das Ziel gesagt werden darf.
     *
     * **Rechnername statt Adresse**, aus dem Grund im Klassenkopf — und
     * `signed` statt des Geheimnisses, ohne Ausschnitt. Gelesen wird über eine
     * **Positivliste**: Was hinaus soll, steht hier; was neu dazukommt, fehlt
     * und fällt auf. Eine Liste dessen, was *nicht* hinaus darf, wäre beim
     * nächsten Feld unvollständig, und niemandem fiele es auf.
     *
     * @return array{host: string, provider: string, stored_at: int, signed: bool}|null
     */
    public function describe(): ?array
    {
        $data = $this->contents();

        if ($data === null) {
            return null;
        }

        $url = is_string($data['url'] ?? null) ? $data['url'] : '';
        $stored = $data['stored_at'] ?? 0;
        $secret = $data['secret'] ?? null;

        return [
            'host' => self::hostOf($url),

            /*
             * **Der Anbieter ist kein Geheimnis.** Er sagt, in welcher Form der
             * Rumpf geht, und das ist dieselbe Art Auskunft wie der Name des
             * DNS-Anbieters, den `Dns\Credentials::describe()` seit P4
             * herausgibt.
             */
            'provider' => Providers::normalize($data['provider'] ?? null),
            'stored_at' => is_int($stored) ? $stored : 0,
            'signed' => is_string($secret) && $secret !== '',
        ];
    }

    /**
     * Das Ziel wieder entfernen.
     *
     * **Es gibt einen Weg hinein, also gehört einer hinaus dazu** — derselbe
     * Satz wie bei `dns.credential.forget`. Ein Ziel, das sich nur
     * überschreiben und nie löschen lässt, meldet weiter an eine Adresse, die
     * niemand mehr will.
     */
    public function forget(): bool
    {
        $path = $this->path();

        return is_file($path) && @unlink($path);
    }

    /** Steht überhaupt eines da? */
    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Eine Adresse, die der Agent wählen darf.
     *
     * Die Schranke ist {@see Outbound::permitted()} und damit dieselbe, die
     * `Acme\Curl` für die Zertifizierungsstelle und die DNS-Anbieter zieht.
     * Grenze 1 lässt nichts anderes zu: **Wer eine Adresse nach draussen wählen
     * darf, wählt sonst auch `http://127.0.0.1:…`** (`docs/129 §7`).
     */
    private function address(mixed $url): string
    {
        $address = is_string($url) ? trim($url) : '';

        if ($address === '' || ! $this->http->permitted($address)) {
            throw AgentException::badRequest('Das Meldeziel muss eine https-Adresse sein.');
        }

        if (self::hostOf($address) === '') {
            throw AgentException::badRequest('Das Meldeziel nennt keinen Rechner.');
        }

        return $address;
    }

    /**
     * Kein Geheimnis ist erlaubt — ein zu kurzes nicht, und bei Slack und
     * Discord gar keines.
     *
     * **Abgewiesen und nicht stillschweigend weggelassen.** Wer bei Slack ein
     * Geheimnis einträgt, erwartet, dass die Meldung beglaubigt ist; dort
     * liest niemand unsere Kopfzeile, und die Seite schriebe „signiert: ja"
     * für etwas, das nichts bedeutet.
     *
     * > **Eine Beglaubigung, die der Empfänger nicht prüft, ist keine
     * > Beglaubigung, sondern eine Beschriftung.**
     */
    private static function signingSecret(mixed $secret, string $provider): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        if (! Providers::signs($provider)) {
            throw AgentException::badRequest(sprintf(
                'Für %s wird nicht signiert — dort ist die Adresse das Zugangsmittel.',
                Providers::LABELS[$provider] ?? $provider,
            ), ['provider' => $provider]);
        }

        if (! is_string($secret) || strlen($secret) < self::SECRET_MIN) {
            throw AgentException::badRequest(sprintf(
                'Ein Geheimnis zum Signieren braucht mindestens %d Zeichen.',
                self::SECRET_MIN,
            ));
        }

        return $secret;
    }

    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /** @return array<string, mixed>|null */
    private function contents(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    private function path(): string
    {
        return $this->directory.'/'.self::FILE;
    }
}
