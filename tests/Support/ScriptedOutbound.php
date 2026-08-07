<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\Acme\Response;

/**
 * Ein DNS-Anbieter aus Papier.
 *
 * **Warum gegen ein Drehbuch und nicht gegen den Anbieter.** Ein echter Zugang
 * in der CI hiesse: ein Token im Repository, eine Zone, die jemandem gehört,
 * und eine Drosselung, die den Lauf irgendwann sperrt — und keinen Weg, den
 * seltenen Fall herzustellen. Die Antwort `null`, das HTTP 429, die Begründung
 * im Feld `add_record` statt in `info`: Hier ist jeder davon eine Zeile.
 *
 * **Mitgeschrieben wird jede Anfrage.** Ohne das könnte ein Test nur prüfen,
 * *dass* etwas herauskam — nicht, dass die Anfrage die richtige Form hatte. Ob
 * das Token als Kopfzeile mitgeht und ob beim Löschen der Wert dabeisteht,
 * fällt an der Antwort nicht auf, weil das Drehbuch ja antwortet.
 *
 * Dieselbe Machart wie {@see ScriptedTransport} für ACME und
 * {@see ScriptedExchange} für DNS über TCP.
 */
final class ScriptedOutbound implements Outbound
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $calls = [];

    /** @var list<Response> */
    private array $script = [];

    public function on(Response ...$responses): self
    {
        $this->script = [...$this->script, ...array_values($responses)];

        return $this;
    }

    /**
     * Eine Antwort mit JSON-Rumpf.
     *
     * **Auch eine schlichte Liste geht.** IONOS antwortet auf `GET /zones` mit
     * einem Feld und nicht mit einer Ablage; ein Drehbuch, das nur Ablagen
     * kennt, könnte diesen Anbieter gar nicht nachstellen.
     *
     * @param  array<array-key, mixed>|string  $fields  Ein Feldsatz, eine Liste
     *                                                  — oder roher Text für
     *                                                  die Fälle, die kein JSON
     *                                                  sind (`null`).
     */
    public static function json(array|string $fields, int $status = 200): Response
    {
        return new Response(
            $status,
            ['content-type' => 'application/json'],
            is_string($fields) ? $fields : (string) json_encode($fields, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Die Zonen eines Kontos, wie IPv64.net sie nennt.
     *
     * Die Zonen stehen als **Schlüssel** unter `subdomains`; was daneben steht,
     * ist der Bestand und geht den Anbieter dieses Panels nichts an.
     *
     * @param  list<string>  $zones
     */
    public static function domains(array $zones): Response
    {
        $subdomains = [];

        foreach ($zones as $zone) {
            $subdomains[$zone] = ['records' => []];
        }

        return self::json(['info' => 'success', 'subdomains' => $subdomains]);
    }

    /** @param  list<string>  $headers */
    public function send(string $method, string $url, array $headers, ?string $body = null): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->script === []) {
            throw new RuntimeException('Das Drehbuch ist zu Ende: '.$method.' '.$url);
        }

        // Die letzte Antwort bleibt stehen. Das ist genau das, was eine
        // Bestellung braucht, die mehrfach dasselbe fragt.
        return count($this->script) === 1 ? $this->script[0] : array_shift($this->script);
    }

    /**
     * Der Rumpf einer Anfrage, aufgeschlüsselt.
     *
     * @return array<string, string>
     */
    public function fieldsOf(int $index): array
    {
        parse_str($this->calls[$index]['body'] ?? '', $fields);

        return array_map(strval(...), $fields);
    }
}
