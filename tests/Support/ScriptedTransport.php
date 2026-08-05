<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\Acme\Transport;

/**
 * Eine Zertifizierungsstelle aus Papier.
 *
 * **Warum der ACME-Client gegen ein Drehbuch geprüft wird und nicht gegen einen
 * Server.** Der Ablauf besteht aus einem Dutzend Anfragen in fester Reihenfolge
 * und aus Antworten, auf die er unterschiedlich reagieren muss: ein
 * verbrauchter Einmalwert, eine Bestellung, die noch nicht fertig ist, eine
 * Prüfung, die scheitert, eine Autorisierung, die schon gilt. Gegen einen
 * echten Server geprüft hiesse: Netz in der CI, eine Ratenbegrenzung, die den
 * Lauf sperrt — und keine Möglichkeit, den seltenen Fall absichtlich
 * herzustellen. Hier ist er eine Zeile.
 *
 * Das Drehbuch liegt je Adresse. Sind mehrere Antworten hinterlegt, kommen sie
 * der Reihe nach; die letzte bleibt stehen. Das ist genau das, was eine
 * Bestellung braucht, die erst `processing` und dann `valid` meldet.
 *
 * **Mitgeschrieben wird jede Anfrage.** Ohne das könnte ein Test nur prüfen,
 * *dass* etwas herauskam — nicht, dass die Anfrage die richtige Form hatte. Der
 * leere Rumpf beim Anstossen einer Prüfung ist genau so ein Fall: `[]` statt
 * `{}` fällt an der Antwort nicht auf, weil das Drehbuch ja antwortet.
 */
final class ScriptedTransport implements Transport
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $calls = [];

    /** @var array<string, list<Response>> */
    private array $script = [];

    public function on(string $url, Response ...$responses): self
    {
        $this->script[$url] = array_values($responses);

        return $this;
    }

    /**
     * Eine Antwort mit JSON-Rumpf.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function json(array $fields, int $status = 200, string $location = '', string $nonce = 'nonce'): Response
    {
        $headers = ['content-type' => 'application/json', 'replay-nonce' => $nonce];

        if ($location !== '') {
            $headers['location'] = $location;
        }

        return new Response($status, $headers, json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /** Eine Fehlerantwort, wie ACME sie schickt. */
    public static function problem(string $type, int $status = 400, string $detail = ''): Response
    {
        return new Response(
            $status,
            ['content-type' => 'application/problem+json', 'replay-nonce' => 'nonce-frisch'],
            json_encode(['type' => $type, 'detail' => $detail], JSON_THROW_ON_ERROR),
        );
    }

    public static function text(string $body): Response
    {
        return new Response(200, ['content-type' => 'application/pem-certificate-chain'], $body);
    }

    public function get(string $url): Response
    {
        return $this->next('GET', $url, '');
    }

    public function post(string $url, string $body): Response
    {
        return $this->next('POST', $url, $body);
    }

    /**
     * Alle Anfragen an eine Adresse, in der Reihenfolge, in der sie kamen.
     *
     * @return list<string>
     */
    public function bodiesFor(string $url): array
    {
        $bodies = [];

        foreach ($this->calls as $call) {
            if ($call['url'] === $url) {
                $bodies[] = $call['body'];
            }
        }

        return $bodies;
    }

    /**
     * Der entschlüsselte Rumpf einer signierten Anfrage.
     *
     * Ein JWS trägt seinen Inhalt in `payload`, base64url-kodiert. Was ein Test
     * prüfen will, steht darin — nicht in der Hülle.
     */
    public static function payloadOf(string $jws): string
    {
        $envelope = json_decode($jws, true);
        $payload = is_array($envelope) ? ($envelope['payload'] ?? null) : null;

        if (! is_string($payload)) {
            throw new RuntimeException('Das ist kein JWS.');
        }

        if ($payload === '') {
            return '';
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Der Rumpf ist nicht base64url.');
        }

        return $decoded;
    }

    /**
     * Der geschützte Kopf einer signierten Anfrage.
     *
     * @return array<string, mixed>
     */
    public static function headerOf(string $jws): array
    {
        $envelope = json_decode($jws, true);
        $protected = is_array($envelope) ? ($envelope['protected'] ?? null) : null;

        if (! is_string($protected)) {
            throw new RuntimeException('Das ist kein JWS.');
        }

        $decoded = base64_decode(strtr($protected, '-_', '+/'), true);
        $fields = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($fields)) {
            throw new RuntimeException('Der Kopf ist nicht lesbar.');
        }

        $header = [];

        foreach ($fields as $key => $value) {
            $header[(string) $key] = $value;
        }

        return $header;
    }

    private function next(string $method, string $url, string $body): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

        $queue = $this->script[$url] ?? [];

        if ($queue === []) {
            throw new RuntimeException('Für '.$url.' steht nichts im Drehbuch.');
        }

        $response = $queue[0];

        // `array_shift` nummeriert selbst neu — ein `array_values` danach sähe
        // nach Sorgfalt aus und täte nichts.
        if (count($queue) > 1) {
            array_shift($queue);
            $this->script[$url] = $queue;
        }

        return $response;
    }
}
