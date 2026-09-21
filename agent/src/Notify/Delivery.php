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
 * `server` setzt diese Klasse; was das Panel mitgibt, ist der Befund. Ein
 * Empfänger, der Meldungen mehrerer Server sammelt, soll den Absender nicht von
 * dem erfahren, der die Meldung erzeugt hat.
 *
 * **Welche Form der Rumpf hat, entscheidet {@see Providers}.** Slack will
 * `text`, Discord `content`, der eigene Empfänger die volle Meldung — und eine
 * Verzweigung darüber hier wäre eine zweite Fassung jener Liste. Eine
 * Obergrenze braucht diese Klasse nicht: Was das Panel schickt, hat den Socket
 * überquert, und {@see Connection::CONTENT_MAX} ist die
 * Grenze dorthin; was der Empfänger annimmt, deckelt `Providers`.
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
        $stamp = time();
        $body = Providers::body($target['provider'], Names::host(), date(DATE_ATOM), $event);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        /*
         * **Signiert wird, wo jemand nachrechnet.** `Target::store()` lässt bei
         * Slack und Discord gar kein Geheimnis zu; die Frage hier ist deshalb
         * keine zweite Fassung jener Regel, sondern ihre Wirkung — ein Ziel aus
         * der Zeit davor könnte beides tragen.
         */
        if ($target['secret'] !== null && Providers::signs($target['provider'])) {
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
}
