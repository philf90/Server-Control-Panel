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

    /** Der Selbstgehostete fürs Telefon: der Rumpf **ist** der Text. */
    public const NTFY = 'ntfy';

    /** Und der zweite davon: JSON, und die Marke steht in der Adresse. */
    public const GOTIFY = 'gotify';

    /** Der erste Empfänger mit einem zweiten Feld: ohne Chat kein Ziel. */
    public const TELEGRAM = 'telegram';

    /**
     * Was auf der Seite steht.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::GENERIC => 'Eigener Empfänger (JSON)',
        self::SLACK => 'Slack',
        self::DISCORD => 'Discord',
        self::NTFY => 'ntfy',
        self::GOTIFY => 'Gotify',
        self::TELEGRAM => 'Telegram',
    ];

    /**
     * Was neben der Liste stehen muss, damit jemand den richtigen Eintrag
     * findet.
     *
     * **Mattermost und Rocket.Chat stehen hier und nicht in {@see LABELS}**,
     * weil sie keinen eigenen Eintrag brauchen: Beide nehmen Slacks
     * `{"text": …}` an. Ein vierter und fünfter Schlüssel, die dieselbe Form
     * erzeugen, wären drei Fassungen derselben Regel — und die zweite ist die,
     * die veraltet.
     *
     * > **Zwei Schlüssel, die denselben Rumpf erzeugen, sind ein Schlüssel und
     * > ein Hinweis.**
     *
     * **Der Hinweis wird gezeigt, bevor jemand wählt**, und nicht erst
     * danach: Wer „Mattermost" sucht, findet es in der Liste nicht und geht,
     * bevor er den Eintrag „Slack" auswählt.
     *
     * > **Ein Hinweis, der erst nach der Entscheidung erscheint, hilft dem
     * > nicht, der ihn zum Entscheiden braucht.**
     *
     * **Hergeleitet und nicht gemessen.** Beide Dienste sagen in ihrer
     * Dokumentation zu, Slacks Eingangshaken zu nehmen; dieser Container
     * erreicht keinen von beiden. `docs/133` nennt es als Punkt für den
     * Abnahmelauf — bis dahin steht hier eine Zusage aus zweiter Hand.
     *
     * > **Wissen aus zweiter Hand sieht aus wie Wissen.**
     *
     * @var array<string, string>
     */
    public const HINTS = [
        self::SLACK => 'Mattermost und Rocket.Chat nehmen dieselbe Form an wie Slack — '.
            'für sie ist dieser Eintrag der richtige.',
        self::NTFY => 'Bei ntfy ist die Adresse die des Themas, etwa '.
            'https://ntfy.sh/mein-thema.',
        self::GOTIFY => 'Bei Gotify gehört die Marke in die Adresse, etwa '.
            'https://gotify.example.org/message?token=… — ein eigenes Feld dafür gibt es nicht.',
        self::TELEGRAM => 'Bei Telegram ist die Adresse die des Bots und endet auf /sendMessage; '.
            'der Chat steht im Feld darunter.',
    ];

    /**
     * Was ein Empfänger ausser Adresse und Geheimnis braucht.
     *
     * **Telegram ist der erste, der etwas braucht.** Die Marke des Bots steht
     * in der Adresse, der Chat aber nicht — `sendMessage` will ihn im Rumpf,
     * und ohne ihn antwortet die Schnittstelle mit `400`. Ein Feld, das nur
     * für einen Empfänger gilt, ist deshalb kein Sonderfall, sondern die Form,
     * die der nächste erbt.
     *
     * **Hier stehen die Schlüssel und nicht ihre Beschriftungen.** Wie ein Feld
     * auf der Seite heisst, ist eine Frage der Oberfläche, und die Oberfläche
     * liegt im Panel — dieselbe Trennung wie zwischen Kanalschlüssel und
     * Menüpunkt. `NoticeFieldTest` hält beide Richtungen aneinander: Was hier
     * steht, hat auf der Seite ein Feld, und was dort steht, hat hier einen
     * Schlüssel.
     *
     * > **Ein Feld, das der Agent verlangt und die Seite nicht zeigt, ist ein
     * > Formular, das man nicht abschicken kann.**
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        self::TELEGRAM => ['chat_id'],
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
     * **Und alle drei zählen Zeichen, auch wo der Empfänger Bytes zählt.**
     * ntfy.sh nimmt 4096 **Bytes**; gedeckelt wird hier in Zeichen, und ein
     * Zeichen dieses Textes ist höchstens drei Bytes lang — mehr kommt nicht
     * vor, weil die Oberfläche keine Emoji führt (`docs/19 §3a`) und die
     * längsten Zeichen der Gedankenstrich, der Aufzählungspunkt und die
     * Auslassung sind. 1300 × 3 liegt unter 4096, und
     * `WebhookTransportTest::test_the_ntfy_body_stays_under_its_byte_limit`
     * rechnet es am gebauten Rumpf nach statt es zu glauben.
     *
     * > **Zwei Einheiten in einer Liste sind eine Liste, aus der man die
     * > falsche liest.**
     *
     * **Gotify steht nicht hier.** Eine Grenze ist dort nicht dokumentiert,
     * und eine erfundene wäre keine Grenze des Empfängers, sondern eine
     * Kürzung ohne Grund.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        self::SLACK => 40000,
        self::DISCORD => 2000,
        self::NTFY => 1300,
        self::TELEGRAM => 4096,
    ];

    /** Wieviel vom Wortlaut eines Werkzeugs in eine Zeile passt. */
    private const DETAIL_MAX = 200;

    /**
     * Jedes Feld, das irgendein Empfänger braucht — flach und ohne Doppel.
     *
     * **Es gibt sie, weil zwei Stellen dieselbe Frage anders stellen.** Der
     * Controller baut seine Prüfregeln je Empfänger, ein Wächter über die
     * deutschen Feldnamen braucht die blosse Liste. Beide aus
     * {@see self::FIELDS} abzuleiten ist eine Zeile; eine zweite Aufzählung
     * wäre die, die beim nächsten Feld vergessen wird.
     *
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        $felder = [];

        foreach (self::FIELDS as $eintrag) {
            foreach ($eintrag as $feld) {
                $felder[$feld] = true;
            }
        }

        return array_keys($felder);
    }

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
     * Die zusätzlichen Angaben prüfen, ohne etwas abzulegen.
     *
     * **Nach dem Vorbild von {@see DnsProviders::configure()}**: Was abgelegt
     * wird, ist die **geprüfte** Fassung und nicht die rohe. Ein Feld, das
     * hier durchginge, fiele sonst erst in der Nacht auf, in der etwas zu
     * melden wäre.
     *
     * **Gelesen wird über eine Positivliste.** Was {@see self::FIELDS} nennt,
     * kommt durch; alles andere wird abgewiesen und nicht stillschweigend
     * abgelegt. Ein Feld, das die Datei trägt und niemand liest, ist von aussen
     * nicht von einem zu unterscheiden, das es nicht gibt — und beim nächsten
     * Umbau erklärt es niemand mehr.
     *
     * > **Eine Liste dessen, was nicht hinein darf, ist beim nächsten Feld
     * > unvollständig, und niemandem fällt es auf.**
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function configure(string $provider, mixed $config): array
    {
        $roh = is_array($config) ? $config : [];
        $felder = self::FIELDS[$provider] ?? [];

        if ($felder === []) {
            if ($roh !== []) {
                throw AgentException::badRequest(sprintf(
                    '%s kennt keine weiteren Angaben.',
                    self::LABELS[$provider] ?? $provider,
                ), ['provider' => $provider, 'given' => array_keys($roh)]);
            }

            return [];
        }

        $fremd = array_diff(array_keys($roh), $felder);

        if ($fremd !== []) {
            throw AgentException::badRequest(sprintf(
                '%s kennt diese Angabe nicht.',
                self::LABELS[$provider] ?? $provider,
            ), ['provider' => $provider, 'unknown' => array_values($fremd)]);
        }

        $geprueft = [];

        foreach ($felder as $feld) {
            $wert = $roh[$feld] ?? null;
            $wert = is_string($wert) || is_int($wert) ? trim((string) $wert) : '';

            if ($wert === '') {
                throw AgentException::badRequest(sprintf(
                    'Für %s fehlt eine Angabe.',
                    self::LABELS[$provider] ?? $provider,
                ), ['provider' => $provider, 'missing' => $feld]);
            }

            $geprueft[$feld] = $wert;
        }

        return $geprueft;
    }

    /**
     * Der Rumpf einer Meldung, in der Form, die dieser Anbieter annimmt.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $config  Was {@see self::configure()} geprüft hat
     */
    public static function body(string $provider, string $server, string $at, array $event, array $config = []): string
    {
        /*
         * **Bei ntfy ist der Rumpf der Text und keine Hülle darum.** Wer an
         * die Adresse eines Themas schreibt, schickt die Nachricht selbst;
         * ein JSON-Objekt käme dort als Nachricht mit geschweiften Klammern
         * an. Deshalb steht diese Verzweigung vor dem `match` und nicht darin
         * — was danach kommt, wird kodiert.
         */
        if ($provider === self::NTFY) {
            return self::text($provider, $server, $event);
        }

        $fields = match ($provider) {
            self::SLACK => ['text' => self::text($provider, $server, $event)],
            self::DISCORD => ['content' => self::text($provider, $server, $event)],

            /*
             * **Gotify trennt Titel und Nachricht**, und beides steht ohnehin
             * schon getrennt da: der Kopf und die Zeilen. Ihn aus dem fertigen
             * Text wieder herauszuschneiden wäre die zweite Fassung einer
             * Zusammensetzung, die eine Zeile weiter oben stattfindet.
             */
            self::GOTIFY => self::gotify($server, $event),

            /*
             * **Der Chat steht im Rumpf und nicht in der Adresse.** Telegrams
             * `sendMessage` nimmt ihn als Feld; die Marke des Bots steht im
             * Pfad. Fehlt er, antwortet die Schnittstelle mit `400` — und
             * deshalb wird er beim Hinterlegen verlangt und nicht hier.
             */
            self::TELEGRAM => [
                'chat_id' => $config['chat_id'] ?? '',
                'text' => self::text($provider, $server, $event),
            ],

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
     * **Drei Arten von Ereignis, und die dritte kam am 21. September 2026
     * dazu:** `test` die Probezustellung, `findings` ein Befund,
     * `resolved` seine Entwarnung. Ein unbekanntes `kind` wird wie `findings`
     * gelesen — es trägt dieselben Zeilen, und ein Wurf an dieser Stelle
     * machte aus einer Meldung, die der Empfänger verstanden hätte, gar keine.
     *
     * @param  array<string, mixed>  $event
     */
    private static function text(string $provider, string $server, array $event): string
    {
        [$kopf, $zeilen] = self::parts($server, $event);

        return $zeilen === [] ? $kopf : self::capped($provider, $kopf, $zeilen);
    }

    /**
     * Kopf und Zeilen — getrennt, weil ein Empfänger sie getrennt will.
     *
     * Gotify trägt einen Titel neben der Nachricht; ihn aus dem fertigen Text
     * wieder herauszuschneiden wäre die zweite Fassung dieser
     * Zusammensetzung.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: list<string>}
     */
    private static function parts(string $server, array $event): array
    {
        $findings = is_array($event['findings'] ?? null) ? $event['findings'] : [];
        $kind = is_string($event['kind'] ?? null) ? $event['kind'] : '';

        if ($kind === 'test') {
            return [sprintf('%s — Probezustellung von SrvPanel.', $server), []];
        }

        $zeilen = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $zeilen[] = self::line($finding);
        }

        if ($zeilen === []) {
            return [sprintf('%s — eine Meldung ohne Befund.', $server), []];
        }

        /*
         * **Der Gegenstand steht am Ereignis und nicht am Befund.** Der
         * Webhook bündelt je Gegenstand ({@see \App\Support\Notify\WebhookChannel}),
         * also teilen sich alle Zeilen einer Meldung denselben — und ihn je
         * Zeile zu wiederholen hiesse, ihn so oft hinzuschreiben, wie es
         * Gründe gibt.
         */
        $ort = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';

        return [self::head($kind, $server, $ort), $zeilen];
    }

    /**
     * Der Rumpf für Gotify: Titel, Nachricht, Rang.
     *
     * **Der Rang ist eine Zahl von 0 bis 10 und steht im Rumpf**, nicht in
     * einer Kopfzeile — dort führt Gotify ihn nicht. Eine Entwarnung ist
     * leiser als eine Meldung, und das ist der ganze Unterschied, den dieser
     * Agent behaupten kann: Wie dringend ein Befund ist, weiss er nicht.
     *
     * > **Ein Rang, den man nicht begründen kann, ist eine Zahl und kein
     * > Urteil.**
     *
     * **Und kein Deckel.** {@see self::LIMITS} führt Gotify nicht, weil dort
     * keine Grenze dokumentiert ist; eine erfundene wäre keine Grenze des
     * Empfängers, sondern eine Kürzung ohne Grund.
     *
     * @param  array<string, mixed>  $event
     * @return array{title: string, message: string, priority: int}
     */
    private static function gotify(string $server, array $event): array
    {
        [$kopf, $zeilen] = self::parts($server, $event);

        return [
            'title' => $kopf,
            'message' => $zeilen === [] ? $kopf : implode("\n", $zeilen),
            'priority' => self::quiet($event) ? 2 : 5,
        ];
    }

    /**
     * Ist diese Meldung die leise Sorte?
     *
     * Eine Entwarnung und eine Probezustellung sind beide keine Nachricht, für
     * die jemand nachts aufstehen soll.
     *
     * > **Eine Entwarnung, die genauso laut ist wie die Meldung, verdoppelt
     * > den Lärm, statt ihn zu beenden.**
     *
     * @param  array<string, mixed>  $event
     */
    private static function quiet(array $event): bool
    {
        return in_array($event['kind'] ?? null, ['resolved', 'test'], true);
    }

    /**
     * Die Kopfzeilen, die dieser Empfänger braucht.
     *
     * **Sie standen bis zum 21. September 2026 fest in {@see Delivery}** — mit
     * `Content-Type: application/json`, und das war richtig, solange jeder
     * Empfänger JSON wollte. ntfy nimmt den Text selbst; eine feste Kopfzeile
     * behauptete dort eine Form, die der Rumpf nicht hat.
     *
     * > **Eine Kopfzeile, die die Form des Rumpfes nennt, gehört dorthin, wo
     * > die Form entschieden wird.**
     *
     * **`Priority` steht hier und `Title` nicht.** Kopfzeilen tragen kein
     * UTF-8; `low` ist ASCII, „Der Dienst läuft nicht." ist es nicht — und die
     * erste Zeile des Rumpfes sagt ohnehin, worum es geht.
     *
     * > **Eine Angabe, die nur in ASCII reisen darf, nimmt keinen Satz mit.**
     *
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    public static function headers(string $provider, array $event): array
    {
        if ($provider !== self::NTFY) {
            return ['Content-Type: application/json', 'Accept: application/json'];
        }

        $headers = ['Content-Type: text/plain; charset=utf-8'];

        if (self::quiet($event)) {
            /*
             * Ohne Angabe gilt ntfys Vorgabe `default`. Eine Kopfzeile, die
             * sie wiederholt, wäre eine zweite Fassung davon — und sie
             * veraltete, sobald ntfy die Vorgabe ändert.
             */
            $headers[] = 'Priority: low';
        }

        return $headers;
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
