<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Notify;

use SrvPanel\Agent\Acme\Dns\Providers as DnsProviders;
use SrvPanel\Agent\AgentException;

/**
 * Wem der Server meldet — als Positivliste, nach dem Vorbild von
 * {@see DnsProviders}.
 *
 * **Bis zum 24. September gab es diesen Begriff hier nicht.** Der Webhook
 * kannte eine Adresse und **eine** Form: `{server, at, event}`. Das reicht für
 * einen eigenen Empfänger und für nichts sonst — Slack verlangt einen Rumpf mit
 * `text`, Discord einen mit `content`, und beide weisen alles andere mit `400`
 * ab. `docs/133 §0` hat das beim Ausschreiben des Abnahmelaufs als hergeleitet
 * und ungemessen festgehalten; der Betreiber hat die beiden am selben Tag
 * bestellt.
 *
 * > **Ein Hinweis, der einen Dienst beim Namen nennt, verspricht, dass er
 * > funktioniert.**
 *
 * **Die Adresse kommt weiterhin vom Betreiber und der Schlüssel aus dieser
 * Datei.** Das ist der Unterschied zu {@see DnsProviders}, wo auch die Adresse
 * hier steht: Ein Eingangshaken hat keine feste Adresse, er **ist** eine. Was
 * diese Liste entscheidet, ist die **Form des Rumpfes** — und die ist genau das,
 * woran eine Zustellung sonst scheitert.
 *
 * **Drei und nicht mehr.** Wer einen vierten braucht, ändert diese Datei, und
 * das ist eine Änderung, die jemand liest — kein Feld in einem Formular.
 */
final class Providers
{
    /** Der eigene Empfänger: die volle Meldung als JSON. */
    public const GENERIC = 'generic';

    public const SLACK = 'slack';

    public const DISCORD = 'discord';

    /**
     * Was auf der Seite steht.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::GENERIC => 'Eigener Empfänger (JSON)',
        self::SLACK => 'Slack',
        self::DISCORD => 'Discord',
    ];

    /**
     * Wie lang der Text sein darf, den der Anbieter annimmt.
     *
     * **Gemessene Grenzen des Empfängers und keine gewählten.** Discord weist
     * ein `content` über 2000 Zeichen ab, Slack ein `text` über 40000. Ein
     * Deckel, der darüber liegt, ist keiner — er verschiebt den Fehlschlag
     * bloss ans andere Ende der Leitung.
     *
     * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        self::SLACK => 40000,
        self::DISCORD => 2000,
    ];

    /** Wieviel vom Wortlaut eines Werkzeugs in eine Zeile passt. */
    private const DETAIL_MAX = 200;

    /**
     * Prüft dieser Anbieter eine Signatur?
     *
     * **Nur der eigene Empfänger.** Slack und Discord lesen unsere Kopfzeile
     * nicht; dort ist die **Adresse** das Zugangsmittel. Eine Signatur, die
     * niemand nachrechnet, wäre eine Zusage an den Betreiber, die er auf der
     * Seite als „signiert" abliest — und die nichts bedeutet.
     *
     * > **Eine Beglaubigung, die der Empfänger nicht prüft, ist keine
     * > Beglaubigung, sondern eine Beschriftung.**
     */
    public static function signs(string $provider): bool
    {
        return $provider === self::GENERIC;
    }

    /**
     * Ein Anbieter, den es gibt — oder eine Ablehnung mit Grund.
     *
     * Ein unbekannter Schlüssel wird abgewiesen und nicht auf den Standard
     * zurückgeführt: Wer sich vertippt, bekäme sonst wortlos den eigenen
     * Empfänger und wunderte sich über ein `400` von Slack.
     */
    public static function usable(mixed $provider): string
    {
        $key = is_string($provider) ? strtolower(trim($provider)) : '';

        if (! array_key_exists($key, self::LABELS)) {
            throw AgentException::badRequest('Diesen Empfänger kennt der Agent nicht.', [
                'provider' => is_string($provider) ? $provider : '?',
                'known' => array_keys(self::LABELS),
            ]);
        }

        return $key;
    }

    /**
     * Ein abgelegter Anbieter — mit Rückfall auf den Standard.
     *
     * **Der Rückfall gilt für das Lesen und nicht für das Schreiben.** Eine
     * Datei aus der Zeit vor dieser Liste trägt kein `provider`, und ihr Ziel
     * bekam damals die Form, die heute `generic` heisst. Ein Wurf an dieser
     * Stelle machte aus einem hinterlegten Ziel ein unlesbares.
     */
    public static function normalize(mixed $provider): string
    {
        $key = is_string($provider) ? strtolower(trim($provider)) : '';

        return array_key_exists($key, self::LABELS) ? $key : self::GENERIC;
    }

    /**
     * Der Rumpf einer Meldung, in der Form, die dieser Anbieter annimmt.
     *
     * @param  array<string, mixed>  $event
     */
    public static function body(string $provider, string $server, string $at, array $event): string
    {
        $fields = match ($provider) {
            self::SLACK => ['text' => self::text($provider, $server, $event)],
            self::DISCORD => ['content' => self::text($provider, $server, $event)],

            /*
             * **Der eigene Empfänger bekommt die volle Meldung.** Was das Panel
             * schickt, steht unter `event` und nicht auf oberster Ebene — sonst
             * überschriebe ein Feld namens `server` den Absender, den
             * {@see Delivery} gerade gesetzt hat.
             */
            default => ['server' => $server, 'at' => $at, 'event' => $event],
        };

        $body = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body)) {
            throw AgentException::badRequest('Die Meldung ließ sich nicht in JSON fassen.');
        }

        return $body;
    }

    /**
     * Die Meldung als Fliesstext — für die Anbieter, die einen wollen.
     *
     * **Der Absender steht in der ersten Zeile.** Ein Kanal, in dem drei Server
     * melden, ist sonst eine Liste von Sätzen ohne Herkunft — und der Absender
     * ist genau die Angabe, die {@see Delivery} setzt und das Panel nicht
     * bestimmen darf.
     *
     * **Drei Arten von Ereignis, und die dritte kam am 24. September 2026
     * dazu:** `test` die Probezustellung, `findings` ein Befund,
     * `resolved` seine Entwarnung. Ein unbekanntes `kind` wird wie `findings`
     * gelesen — es trägt dieselben Zeilen, und ein Wurf an dieser Stelle
     * machte aus einer Meldung, die der Empfänger verstanden hätte, gar keine.
     *
     * @param  array<string, mixed>  $event
     */
    private static function text(string $provider, string $server, array $event): string
    {
        $findings = is_array($event['findings'] ?? null) ? $event['findings'] : [];
        $kind = is_string($event['kind'] ?? null) ? $event['kind'] : '';

        if ($kind === 'test') {
            return sprintf('%s — Probezustellung von SrvPanel.', $server);
        }

        $zeilen = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $zeilen[] = self::line($finding);
        }

        if ($zeilen === []) {
            return sprintf('%s — eine Meldung ohne Befund.', $server);
        }

        /*
         * **Der Gegenstand steht am Ereignis und nicht am Befund.** Der
         * Webhook bündelt je Gegenstand ({@see \App\Support\Notify\WebhookChannel}),
         * also teilen sich alle Zeilen einer Meldung denselben — und ihn je
         * Zeile zu wiederholen hiesse, ihn so oft hinzuschreiben, wie es
         * Gründe gibt.
         */
        $ort = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';

        return self::capped($provider, self::head($kind, $server, $ort), $zeilen);
    }

    /**
     * Die erste Zeile — sie sagt, wovon die Rede ist und in welche Richtung.
     *
     * **„Behoben" steht vor dem Gegenstand und nicht dahinter.** In einem
     * Kanal, in dem Meldungen und Entwarnungen untereinander stehen, liest
     * jemand die Zeilenanfänge; ein Wort am Ende einer Zeile, die einen langen
     * Namen trägt, steht auf dem Telefon in der nächsten.
     *
     * > **Ein Unterschied, der am Ende einer Zeile steht, ist auf einer
     * > schmalen Anzeige keiner.**
     *
     * **Ein Wort und kein Zeichen.** Ein Haken oder ein Punkt in einer Farbe
     * sähe auf jedem Empfänger anders aus, und wer Zeichen nicht sieht, sähe
     * gar keinen Unterschied — derselbe Grund, aus dem die Oberfläche dieses
     * Panels ohne Emoji auskommt.
     */
    private static function head(string $kind, string $server, string $ort): string
    {
        $behoben = $kind === 'resolved';

        if ($ort === '') {
            return $behoben ? sprintf('%s — behoben', $server) : $server;
        }

        return $behoben
            ? sprintf('%s — behoben: %s', $server, $ort)
            : sprintf('%s — %s', $server, $ort);
    }

    /**
     * Eine Zeile je Befund.
     *
     * @param  array<string, mixed>  $finding
     */
    private static function line(array $finding): string
    {
        $satz = is_string($finding['label'] ?? null) ? $finding['label'] : '(ohne Satz)';
        $detail = is_string($finding['detail'] ?? null) ? trim($finding['detail']) : '';

        $zeile = '• '.$satz;

        if ($detail !== '') {
            /*
             * **Gekürzt wird hier und nicht am Deckel.** `Finding::DETAIL_MAX`
             * lässt 8 KiB zu; eine einzige solche Zeile füllte Discords
             * Grenze viermal. Wer den ungekürzten Wortlaut will, liest die
             * Diagnoseseite — dafür gibt es sie.
             */
            $zeile .= "\n  ".mb_strimwidth($detail, 0, self::DETAIL_MAX, '…');
        }

        return $zeile;
    }

    /**
     * Den Text auf die Grenze des Anbieters bringen — und sagen, was fehlt.
     *
     * **Abgeschnitten wird zwischen Zeilen und nicht mitten in einer.** Eine
     * Meldung, die im Wort endet, sieht aus wie eine kaputte Leitung; eine, die
     * „und 4 weitere" sagt, sagt dem Leser, dass er nachsehen muss.
     *
     * > **Ein Deckel, der nicht sagt, dass er gegriffen hat, macht aus einer
     * > unvollständigen Auskunft eine falsche.**
     *
     * @param  list<string>  $zeilen
     */
    private static function capped(string $provider, string $kopf, array $zeilen): string
    {
        $limit = self::LIMITS[$provider] ?? PHP_INT_MAX;
        $text = $kopf."\n".implode("\n", $zeilen);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $genommen = [];

        foreach ($zeilen as $i => $zeile) {
            $rest = count($zeilen) - $i;
            $schluss = sprintf("\n… und %d weitere", $rest);
            $versuch = $kopf."\n".implode("\n", [...$genommen, $zeile]).$schluss;

            if (mb_strlen($versuch) > $limit) {
                break;
            }

            $genommen[] = $zeile;
        }

        $fehlend = count($zeilen) - count($genommen);

        return $genommen === []
            // Selbst die erste Zeile passt nicht — dann bleibt der Kopf.
            ? mb_strimwidth($kopf.sprintf("\n… und %d weitere", $fehlend), 0, $limit, '…')
            : $kopf."\n".implode("\n", $genommen).sprintf("\n… und %d weitere", $fehlend);
    }
}
