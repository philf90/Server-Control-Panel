<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Notify;

use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\Acme\Outbound;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Names;

/**
 * Die Zustellung an das Meldeziel — der zweite Kanal aus `docs/129 §7`.
 *
 * **Derselbe Weg nach draussen wie ACME und die DNS-Anbieter.** Sie geht über
 * {@see Outbound} und damit über {@see Curl} — mit dessen vier Zusagen, die
 * hier nicht wiederholt werden: nur https, keine Umleitungen, gedeckelte
 * Antwort, Zeitlimit auf Verbindung und Gesamtdauer. Eine zweite Stelle, die
 * dieselben vier Optionen setzt, ist genau das Muster, an dem dieses Projekt am
 * häufigsten verloren hat.
 *
 * > **Der Agent spricht an einer Stelle nach draussen. Ein zweiter Ort wäre
 * > eine zweite Fassung von vier Zusagen, und die zweite ist die, die
 * > veraltet.**
 *
 * **Keine Umleitung ist hier wichtiger als bei ACME.** Eine Antwort `302` auf
 * eine signierte Meldung trüge die Signatur samt Inhalt an eine Adresse, die
 * niemand hinterlegt hat — und der Betreiber sähe auf seiner Seite weiterhin
 * „zuletzt erfolgreich zugestellt".
 *
 * **Der Absender steht im Agenten und nicht im Rumpf, den das Panel schickt.**
 * `server` und `at` setzt diese Klasse; was das Panel mitgibt, ist der Befund.
 * Ein Empfänger, der Meldungen mehrerer Server sammelt, soll den Absender nicht
 * von dem erfahren, der die Meldung erzeugt hat.
 */
final class Delivery
{
    /**
     * Die Kopfzeile, in der die Signatur steht.
     *
     * **Eigener Name und nicht `X-Hub-Signature`.** Wer den fremden Namen
     * benutzt, verspricht dessen Form — und GitHub signiert nur den Rumpf,
     * ohne Zeitstempel. Diese hier signiert beides, siehe {@see signature}.
     */
    public const SIGNATURE_HEADER = 'X-Srvpanel-Signature';

    public function __construct(
        private readonly Target $target = new Target,
        private readonly Outbound $http = new Curl,
    ) {}

    /**
     * Eine Meldung zustellen.
     *
     * **Der Rückgabewert nennt den Status und nicht den Rumpf.** Was ein
     * Eingangshaken antwortet, geht diesen Agenten nichts an; was das Panel
     * wissen muss, ist, ob es angekommen ist. Ein Rumpf, der zurückkäme, stünde
     * anschliessend in einem Vorgangsergebnis, und niemand hat entschieden, was
     * darin stehen darf.
     *
     * @param  array<string, mixed>  $event  Was zu melden ist — vom Panel
     * @return array{delivered: true, status: int, host: string}
     */
    public function send(array $event): array
    {
        $target = $this->target->read();
        $body = $this->body($event);
        $stamp = time();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($target['secret'] !== null) {
            $headers[] = self::SIGNATURE_HEADER.': '.self::signature($target['secret'], $stamp, $body);
        }

        $response = $this->http->send('POST', $target['url'], $headers, $body);

        if (! $response->successful()) {
            throw AgentException::execFailed(sprintf(
                'Das Meldeziel hat die Meldung abgewiesen (HTTP %d).',
                $response->status,
            ));
        }

        return [
            'delivered' => true,
            'status' => $response->status,
            'host' => (string) parse_url($target['url'], PHP_URL_HOST),
        ];
    }

    /**
     * Die Signatur über Zeitpunkt **und** Rumpf.
     *
     * **Der Zeitstempel steht im signierten Material und nicht nur daneben.**
     * Signierte man den Rumpf allein, könnte ein Mitleser dieselbe Meldung
     * morgen noch einmal einliefern und die Signatur stimmte — der Empfänger
     * sähe einen Dienst, der längst wieder läuft, als tot.
     *
     * > **Eine Signatur ohne Zeitstempel beglaubigt den Inhalt und nicht den
     * > Augenblick.**
     *
     * Die Form ist `t=<unix>,v1=<hex>`, und beides zusammen ist, was der
     * Empfänger nachrechnet: `hmac_sha256(secret, "<t>.<rumpf>")`.
     */
    public static function signature(string $secret, int $at, string $body): string
    {
        return sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, $secret));
    }

    /**
     * Der Rumpf einer Meldung.
     *
     * **Was das Panel schickt, steht unter `event` und nicht auf oberster
     * Ebene.** Sonst überschriebe ein Feld namens `server` den Absender, den
     * diese Klasse gerade gesetzt hat — derselbe Fehler wie eine Seite, die
     * eine geteilte Eigenschaft überschreibt.
     *
     * Eine Obergrenze braucht es hier nicht: Was das Panel schickt, hat den
     * Socket überquert, und {@see Connection::CONTENT_MAX} ist die Grenze
     * dorthin.
     *
     * @param  array<string, mixed>  $event
     */
    private function body(array $event): string
    {
        $body = json_encode([
            // **`host()` und nicht `fqdn()`.** Ein Empfänger, der Meldungen
            // mehrerer Server sammelt, kann mit `null` nichts anfangen — und
            // der Knotenname ist kein erfundener Name, sondern ein weniger
            // vollständiger.
            'server' => Names::host(),
            'at' => date(DATE_ATOM),
            'event' => $event,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body)) {
            throw AgentException::badRequest('Die Meldung ließ sich nicht in JSON fassen.');
        }

        return $body;
    }
}
