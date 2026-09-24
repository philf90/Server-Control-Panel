<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Curl;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Notify\Delivery;
use SrvPanel\Agent\Notify\Providers;
use SrvPanel\Agent\Notify\Target;
use Tests\Support\ScriptedOutbound;

/**
 * Der Weg des Webhooks nach draussen — B1, `docs/129 §7`.
 *
 * ## Gemessen an der Wirkung und nicht am Quelltext
 *
 * Ein Wächter, der in {@see Curl} nach `CURLOPT_TIMEOUT`
 * sucht, sagt, dass die Zeile dasteht — nicht, dass dieser Weg sie benutzt. Was
 * hier geprüft wird, geht deshalb durch {@see Target} und {@see Delivery}
 * hindurch und liest, was **am Draht** ankommt: Methode, Kopfzeilen, Rumpf.
 *
 * > **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen, nicht
 * > dass sie zusammen etwas tun.**
 *
 * ## Und jede Richtung einzeln
 *
 * „Eine `http`-Adresse wird abgewiesen" allein erfüllte auch eine Ablage, die
 * **jede** Adresse abweist; „die Signatur steht in der Kopfzeile" allein auch
 * eine, die immer eine setzt. Jeder Fall steht deshalb neben seinem
 * Gegenstück.
 */
final class WebhookTransportTest extends TestCase
{
    private const DIENST = 'srvpanel-metrics.service';

    private string $verzeichnis = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->verzeichnis = sys_get_temp_dir().'/srvpanel-notify-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->verzeichnis.'/*') ?: [] as $datei) {
            @unlink($datei);
        }

        @rmdir($this->verzeichnis);

        parent::tearDown();
    }

    private function target(ScriptedOutbound $http = new ScriptedOutbound): Target
    {
        return new Target($this->verzeichnis, $http);
    }

    /**
     * Grenze 1: Nach draussen geht nur https — und zwar schon beim
     * Hinterlegen.
     *
     * **Geprüft wird beim Hinterlegen und nicht beim Melden.** Was hier
     * durchginge, fiele sonst erst in der Nacht auf, in der etwas zu melden
     * wäre. Und `http://127.0.0.1:…` ist kein erfundener Fall: Er ist der
     * Grund, aus dem der Webhook überhaupt im Agenten liegt.
     */
    public function test_only_https_reaches_the_store(): void
    {
        foreach (['http://hooks.example.org/x', 'http://127.0.0.1:9200/x', 'ftp://example.org/x', 'hooks.example.org'] as $adresse) {
            try {
                $this->target()->store($adresse, null);
                self::fail('Diese Adresse hätte nicht durchgehen dürfen: '.$adresse);
            } catch (AgentException $e) {
                self::assertStringContainsString('https', $e->getMessage());
            }
        }

        self::assertFalse($this->target()->exists(), 'Eine abgewiesene Adresse legt auch keine Datei an.');
    }

    /** Die Gegenrichtung — ohne sie wäre eine Ablage grün, die alles abweist. */
    public function test_an_https_address_is_stored(): void
    {
        $target = $this->target();
        $target->store('https://hooks.example.org/dienste/abc', null);

        self::assertTrue($target->exists());
        self::assertSame('hooks.example.org', $target->describe()['host'] ?? null);
    }

    /**
     * Die Datei liegt 0600 in einem 0700-Verzeichnis.
     *
     * Dieselbe Zusage wie bei den Zugangsdaten des DNS-Anbieters, und derselbe
     * Grund: Was darin steht, berechtigt zur Zustellung.
     */
    public function test_the_file_is_readable_by_root_alone(): void
    {
        $this->target()->store('https://hooks.example.org/x', 'geheimnis-mit-genug-zeichen');

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->verzeichnis.'/'.Target::FILE)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->verzeichnis)), -4));
    }

    /**
     * **Weder Adresse noch Geheimnis kommen zurück.**
     *
     * Gemessen an der **ganzen** Antwort und nicht an einzelnen Schlüsseln: Ein
     * Wächter, der `secret` und `url` abfragt, bliebe grün, sobald jemand ein
     * drittes Feld hinzufügt, das den Pfad mitträgt.
     *
     * > **Gelesen wird über eine Positivliste und nicht über eine Sperrliste.**
     * > Eine Liste dessen, was *nicht* hinaus darf, ist beim nächsten Feld
     * > unvollständig, und niemandem fällt es auf.
     */
    public function test_neither_address_nor_secret_comes_back(): void
    {
        $target = $this->target();
        $target->store('https://hooks.example.org/dienste/T0PS3CR3T-pfad', 'geheimnis-mit-genug-zeichen');

        $antwort = (string) json_encode($target->describe());

        self::assertStringNotContainsString('T0PS3CR3T', $antwort, 'Der Pfad einer Eingangshaken-Adresse ist das Geheimnis.');
        self::assertStringNotContainsString('geheimnis-mit-genug-zeichen', $antwort);
        self::assertStringNotContainsString('geheimnis-mit', $antwort, 'Auch kein Ausschnitt — bei einem kurzen Geheimnis ist das ein spürbarer Teil davon.');

        // Und die Gegenrichtung: Etwas kommt zurück, sonst prüfte der Fall nichts.
        self::assertSame('hooks.example.org', $target->describe()['host'] ?? null);
        self::assertTrue($target->describe()['signed'] ?? false);

        /*
         * **Und die Schlüsselmenge steht fest.** Der Fall darüber sucht drei
         * Zeichenketten; ein viertes Feld, das jemand der Ablage hinzufügt,
         * reiste an ihm vorbei. Die Positivliste in `describe()` ist genau
         * dagegen geschrieben, und hier steht sie noch einmal als Zusage.
         */
        self::assertSame(['host', 'provider', 'stored_at', 'signed'], array_keys($target->describe() ?? []));
    }

    /** Ein zu kurzes Geheimnis ist keines — und gar keines ist erlaubt. */
    public function test_a_secret_is_long_enough_or_absent(): void
    {
        $this->expectException(AgentException::class);
        $this->target()->store('https://hooks.example.org/x', str_repeat('a', Target::SECRET_MIN - 1));
    }

    public function test_no_secret_at_all_is_allowed(): void
    {
        $target = $this->target();
        $target->store('https://hooks.example.org/x', null);

        self::assertFalse($target->describe()['signed'] ?? true, 'Bei Slack und Discord trägt die Adresse alles.');
    }

    /**
     * Die Meldung geht als `POST` mit JSON an die hinterlegte Adresse.
     *
     * **Und die Adresse kommt aus der Ablage und nicht aus dem Aufruf** — das
     * ist der ganze Grund, aus dem diese Operation im Agenten liegt.
     */
    public function test_the_delivery_posts_json_to_the_stored_address(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/dienste/abc', null);

        (new Delivery($target, $http))->send(['kind' => 'test']);

        self::assertCount(1, $http->calls);
        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame('https://hooks.example.org/dienste/abc', $http->calls[0]['url']);
        self::assertContains('Content-Type: application/json', $http->calls[0]['headers']);
    }

    /**
     * Der Absender steht im Rumpf, und das Panel kann ihn nicht überschreiben.
     *
     * Ein Feld `server` in der Meldung des Panels darf den gestempelten
     * Absender nicht ersetzen — sonst wäre die Herkunft eine Angabe des
     * Absenders über sich selbst.
     */
    public function test_the_sender_is_stamped_and_not_taken_from_the_payload(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null);

        (new Delivery($target, $http))->send(['server' => 'fremder.example.net', 'kind' => 'findings']);

        $rumpf = json_decode((string) $http->calls[0]['body'], true);

        self::assertIsArray($rumpf);
        self::assertNotSame('fremder.example.net', $rumpf['server'] ?? null);
        self::assertSame('fremder.example.net', $rumpf['event']['server'] ?? null, 'Was das Panel schickt, steht unter `event`.');
        self::assertArrayHasKey('at', $rumpf);
    }

    /**
     * Die Signatur geht über Zeitpunkt **und** Rumpf.
     *
     * **Nachgerechnet und nicht auf Anwesenheit geprüft.** Eine Kopfzeile, die
     * dasteht, sagt nichts darüber, worüber sie gebildet wurde — und wer den
     * Zeitstempel weglässt, bekommt eine Signatur, die morgen noch gilt.
     *
     * > **Eine Signatur ohne Zeitstempel beglaubigt den Inhalt und nicht den
     * > Augenblick.**
     */
    public function test_the_signature_covers_the_timestamp_and_the_body(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $geheim = 'geheimnis-mit-genug-zeichen';
        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', $geheim);

        (new Delivery($target, $http))->send(['kind' => 'test']);

        $kopf = null;

        foreach ($http->calls[0]['headers'] as $zeile) {
            if (str_starts_with($zeile, Delivery::SIGNATURE_HEADER.': ')) {
                $kopf = substr($zeile, strlen(Delivery::SIGNATURE_HEADER) + 2);
            }
        }

        self::assertIsString($kopf, 'Mit hinterlegtem Geheimnis trägt jede Meldung eine Signatur.');
        self::assertSame(1, preg_match('/\At=(\d+),v1=([0-9a-f]{64})\z/D', $kopf, $teile));

        $rumpf = (string) $http->calls[0]['body'];

        self::assertSame(
            hash_hmac('sha256', $teile[1].'.'.$rumpf, $geheim),
            $teile[2],
            'Nachgerechnet über „<t>.<rumpf>" — genau das, was der Empfänger tut.',
        );

        // Die Gegenprobe: derselbe Rumpf ohne den Zeitpunkt ergibt etwas anderes.
        self::assertNotSame(
            hash_hmac('sha256', $rumpf, $geheim),
            $teile[2],
            'Ohne den Zeitstempel im signierten Material gälte dieselbe Meldung morgen noch.',
        );
    }

    /** Und ohne Geheimnis steht keine Signatur da — sonst prüfte der Fall darüber nichts. */
    public function test_without_a_secret_there_is_no_signature(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null);

        (new Delivery($target, $http))->send(['kind' => 'test']);

        foreach ($http->calls[0]['headers'] as $zeile) {
            self::assertStringNotContainsString(Delivery::SIGNATURE_HEADER, $zeile);
        }
    }

    /**
     * Eine abgewiesene Meldung ist ein Fehlschlag und kein Erfolg.
     *
     * Ohne diesen Fall meldete das Panel „zuletzt erfolgreich zugestellt" für
     * ein Ziel, das jede Meldung mit `500` beantwortet.
     */
    public function test_a_rejected_delivery_throws(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['error' => 'nope'], 500));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null);

        $this->expectException(AgentException::class);
        (new Delivery($target, $http))->send(['kind' => 'test']);
    }

    /** Ohne hinterlegtes Ziel wird nichts zugestellt — und nichts erfunden. */
    public function test_without_a_target_nothing_is_delivered(): void
    {
        $http = new ScriptedOutbound;

        $this->expectException(AgentException::class);
        (new Delivery($this->target($http), $http))->send(['kind' => 'test']);
    }

    /**
     * Jeder Empfänger bekommt die Form, die er annimmt.
     *
     * **Das ist der ganze Grund, aus dem es {@see Providers} gibt.** Slack
     * verlangt `text`, Discord `content`, und beide weisen alles andere mit
     * `400` ab. Gemessen wird der Rumpf am Draht und nicht die Verzweigung im
     * Quelltext: Ein Wächter über den Zweig sagt, dass die Teile
     * zusammenpassen, nicht dass sie zusammen etwas tun.
     */
    public function test_each_receiver_gets_the_shape_it_accepts(): void
    {
        $erwartet = [
            Providers::GENERIC => ['server', 'at', 'event'],
            Providers::SLACK => ['text'],
            Providers::DISCORD => ['content'],
            Providers::GOTIFY => ['title', 'message', 'priority'],
            Providers::TELEGRAM => ['chat_id', 'text'],
        ];

        /*
         * **Und die Liste ist vollständig.** Ein Empfänger, den jemand
         * hinzufügt, ohne die Form seines Rumpfes hier zu nennen, ist einer,
         * dessen Rumpf niemand prüft — und der Fall darüber bliebe grün.
         * ntfy steht nicht in der Tabelle, weil es gar keinen JSON-Rumpf hat;
         * {@see self::test_ntfy_gets_the_text_itself()} misst ihn.
         */
        $abgedeckt = [...array_keys($erwartet), Providers::NTFY];
        $alle = array_keys(Providers::LABELS);
        sort($abgedeckt);
        sort($alle);

        self::assertSame($alle, $abgedeckt, 'Für diesen Empfänger sagt kein Fall, welche Form sein Rumpf hat.');

        foreach ($erwartet as $provider => $schluessel) {
            $http = new ScriptedOutbound;
            $http->on(ScriptedOutbound::json(['ok' => true]));

            $target = $this->target($http);
            $target->store('https://hooks.example.org/x', null, $provider, $this->konfig($provider));

            (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => 'p1000', 'findings' => [
                ['label' => 'Der Dienst läuft nicht.', 'detail' => 'ActiveState=inactive'],
            ]]);

            $rumpf = json_decode((string) $http->calls[0]['body'], true);

            self::assertIsArray($rumpf);
            self::assertSame($schluessel, array_keys($rumpf), $provider.' schickt die falsche Form.');
        }
    }

    /**
     * Der Text nennt den Absender und den Gegenstand — und den Wortlaut.
     *
     * Ohne diesen Fall erfüllte auch ein `text`, der leer ist, den Fall
     * darüber: Die Schlüssel stimmten, und der Kanal bekäme nichts zu lesen.
     */
    public function test_the_text_names_sender_subject_and_wording(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null, Providers::SLACK);

        (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => 'srvpanel-metrics.service', 'findings' => [
            ['label' => 'Der Dienst läuft nicht.', 'detail' => 'ActiveState=inactive'],
        ]]);

        $text = (string) (json_decode((string) $http->calls[0]['body'], true)['text'] ?? '');

        self::assertStringContainsString('srvpanel-metrics.service', $text);
        self::assertStringContainsString('Der Dienst läuft nicht.', $text);
        self::assertStringContainsString('ActiveState=inactive', $text);
        self::assertStringContainsString(Names::host(), $text, 'Ein Kanal, in dem drei Server melden, braucht die Herkunft.');
    }

    /**
     * Eine Entwarnung ist im Text als eine zu erkennen — und eine Meldung nicht.
     *
     * **Beide Richtungen in einem Fall.** „Der Text enthält das Wort behoben"
     * allein erfüllte auch eine Fassung, die es immer hinschreibt; dann stünde
     * es über jedem toten Dienst.
     *
     * **Und es steht vor dem Gegenstand.** In einem Kanal, in dem Meldungen und
     * Entwarnungen untereinander stehen, liest jemand die Zeilenanfänge; ein
     * Wort hinter einem langen Namen steht auf dem Telefon in der nächsten
     * Zeile.
     */
    public function test_a_resolution_is_recognisable_and_a_finding_is_not(): void
    {
        $zeilen = [['label' => 'Der Dienst läuft nicht.', 'detail' => 'ActiveState=inactive']];

        $behoben = $this->textOf(['kind' => 'resolved', 'subject' => self::DIENST, 'findings' => $zeilen]);
        $offen = $this->textOf(['kind' => 'findings', 'subject' => self::DIENST, 'findings' => $zeilen]);

        self::assertStringContainsString('behoben', $behoben);
        self::assertStringNotContainsString('behoben', $offen,
            'Ein Wort, das über jeder Meldung steht, unterscheidet nichts.');

        self::assertLessThan(
            (int) strpos($behoben, self::DIENST),
            (int) strpos($behoben, 'behoben'),
            'Ein Unterschied, der am Ende einer Zeile steht, ist auf einer schmalen Anzeige keiner.',
        );

        // Und der Satz des Befundes steht weiter da — sonst wäre die
        // Entwarnung eine Meldung ohne Gegenstand.
        self::assertStringContainsString('Der Dienst läuft nicht.', $behoben);
    }

    /**
     * Der eigene Empfänger bekommt die Art der Meldung unverfälscht.
     *
     * **Er ist der, der sie auswerten soll.** Ein Vorfallsystem ordnet die
     * Entwarnung über `kind` dem offenen Vorfall zu; ginge sie als `findings`
     * hinaus, machte sie einen zweiten auf.
     */
    public function test_the_own_receiver_sees_which_kind_it_is(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null, Providers::GENERIC);

        (new Delivery($target, $http))->send(['kind' => 'resolved', 'subject' => self::DIENST, 'findings' => []]);

        $rumpf = json_decode((string) $http->calls[0]['body'], true);

        self::assertIsArray($rumpf);
        self::assertSame('resolved', $rumpf['event']['kind'] ?? null);
    }

    /**
     * Bei ntfy ist der Rumpf der Text — ohne Hülle.
     *
     * **Wer an die Adresse eines Themas schreibt, schickt die Nachricht
     * selbst.** Ein JSON-Objekt käme dort als Nachricht mit geschweiften
     * Klammern an, und das sähe im Telefon aus wie eine kaputte Meldung.
     *
     * Gemessen in beide Richtungen: Der Rumpf ist kein JSON **und** er trägt,
     * was drinstehen soll.
     */
    public function test_ntfy_gets_the_text_itself(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://ntfy.sh/mein-thema', null, Providers::NTFY);

        (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => self::DIENST, 'findings' => [
            ['label' => 'Der Dienst läuft nicht.', 'detail' => 'ActiveState=inactive'],
        ]]);

        $rumpf = (string) $http->calls[0]['body'];

        self::assertNull(json_decode($rumpf, true), 'ntfy bekommt keinen JSON-Rumpf, sondern den Text.');
        self::assertStringContainsString(self::DIENST, $rumpf);
        self::assertStringContainsString('Der Dienst läuft nicht.', $rumpf);
        self::assertStringContainsString(Names::host(), $rumpf);
    }

    /**
     * Die Kopfzeile sagt, was der Rumpf ist.
     *
     * **Gemessen an beidem zugleich und nicht an einer Tabelle.** Ein Wächter
     * über „ntfy bekommt `text/plain`" bliebe grün, wenn der Rumpf zu JSON
     * würde; hier wird der Rumpf angesehen und die Kopfzeile daran gehalten.
     *
     * > **Eine Kopfzeile, die die Form des Rumpfes nennt, ist nur zusammen mit
     * > dem Rumpf eine Aussage.**
     */
    public function test_the_content_type_says_what_the_body_is(): void
    {
        $arten = [];

        foreach (array_keys(Providers::LABELS) as $provider) {
            $http = new ScriptedOutbound;
            $http->on(ScriptedOutbound::json(['ok' => true]));

            $target = $this->target($http);
            $target->store('https://hooks.example.org/x', null, $provider, $this->konfig($provider));

            (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => self::DIENST, 'findings' => [
                ['label' => 'Der Dienst läuft nicht.', 'detail' => null],
            ]]);

            $rumpf = (string) $http->calls[0]['body'];
            $typ = '';

            foreach ($http->calls[0]['headers'] as $zeile) {
                if (stripos($zeile, 'Content-Type:') === 0) {
                    $typ = strtolower(trim(substr($zeile, strlen('Content-Type:'))));
                }
            }

            $istJson = is_array(json_decode($rumpf, true));
            $arten[$istJson ? 'json' : 'text'] = true;

            self::assertStringStartsWith(
                $istJson ? 'application/json' : 'text/plain',
                $typ,
                $provider.' nennt eine Form, die sein Rumpf nicht hat.',
            );
        }

        // Ohne diese beiden Zeilen wäre der Fall auch dann grün, wenn jeder
        // Empfänger dieselbe Form bekäme — und dann prüfte er nichts.
        self::assertArrayHasKey('json', $arten);
        self::assertArrayHasKey('text', $arten);
    }

    /**
     * Und der Rumpf für ntfy bleibt unter dessen Grenze — in **Bytes**.
     *
     * **Gedeckelt wird in Zeichen, abgewiesen wird nach Bytes.** `LIMITS`
     * führt 1300 Zeichen mit der Begründung, dass kein Zeichen dieses Textes
     * länger als drei Bytes ist. Dieser Fall rechnet es nach, statt es zu
     * glauben — mit einem Prüfkörper aus lauter Drei-Byte-Zeichen.
     *
     * > **Eine Grenze, die man aus einer anderen Einheit herleitet, ist eine
     * > Vermutung, bis jemand in der Einheit misst, in der abgewiesen wird.**
     */
    public function test_the_ntfy_body_stays_under_its_byte_limit(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://ntfy.sh/mein-thema', null, Providers::NTFY);

        $findings = [];

        for ($i = 0; $i < 80; $i++) {
            // „—" ist U+2014 und drei Bytes lang; mehr kostet in diesem Panel
            // kein Zeichen, weil die Oberfläche keine Emoji führt.
            $findings[] = ['label' => str_repeat('—', 40), 'detail' => str_repeat('…', 40)];
        }

        (new Delivery($target, $http))->send([
            'kind' => 'findings',
            'subject' => str_repeat('—', 20),
            'findings' => $findings,
        ]);

        $rumpf = (string) $http->calls[0]['body'];

        self::assertLessThanOrEqual(4096, strlen($rumpf), 'ntfy.sh weist alles darüber ab.');

        // Und die Gegenprobe: Der Prüfkörper ist wirklich der teure Fall.
        self::assertGreaterThan(mb_strlen($rumpf), strlen($rumpf));
        self::assertStringContainsString('weitere', $rumpf);
    }

    /**
     * Eine Entwarnung ist leiser als eine Meldung.
     *
     * **Beide Empfänger tragen den Rang an einer anderen Stelle**, und das ist
     * der Grund für diesen Fall: ntfy in einer Kopfzeile, Gotify im Rumpf. Ein
     * Wächter über eine von beiden sagte über die andere nichts.
     *
     * > **Eine Entwarnung, die genauso laut ist wie die Meldung, verdoppelt
     * > den Lärm, statt ihn zu beenden.**
     */
    public function test_a_clearing_message_is_quieter_than_a_finding(): void
    {
        $zeilen = [['label' => 'Der Dienst läuft nicht.', 'detail' => null]];

        $laut = $this->call(Providers::NTFY, ['kind' => 'findings', 'subject' => self::DIENST, 'findings' => $zeilen]);
        $leise = $this->call(Providers::NTFY, ['kind' => 'resolved', 'subject' => self::DIENST, 'findings' => $zeilen]);

        self::assertNotContains('Priority: low', $laut['headers']);
        self::assertContains('Priority: low', $leise['headers'], 'Ohne Angabe gilt ntfys Vorgabe, und die ist nicht leise.');

        $laut = $this->call(Providers::GOTIFY, ['kind' => 'findings', 'subject' => self::DIENST, 'findings' => $zeilen]);
        $leise = $this->call(Providers::GOTIFY, ['kind' => 'resolved', 'subject' => self::DIENST, 'findings' => $zeilen]);

        $rang = static fn (array $anruf): int => (int) (json_decode((string) $anruf['body'], true)['priority'] ?? -1);

        self::assertGreaterThan($rang($leise), $rang($laut), 'Bei Gotify steht der Rang im Rumpf.');
        self::assertGreaterThanOrEqual(0, $rang($leise), 'Ein fehlender Rang sähe hier aus wie ein leiser.');
    }

    /**
     * Eine Zustellung fahren und mitlesen, was am Draht ankam.
     *
     * @param  array<string, mixed>  $event
     * @return array{headers: list<string>, body: string}
     */
    private function call(string $provider, array $event): array
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null, $provider, $this->konfig($provider));

        (new Delivery($target, $http))->send($event);

        return ['headers' => $http->calls[0]['headers'], 'body' => (string) $http->calls[0]['body']];
    }

    /**
     * Die zusätzlichen Angaben, die ein Empfänger zum Hinterlegen braucht.
     *
     * **Abgeleitet aus {@see Providers::FIELDS} und nicht aufgezählt.** Ein
     * Fall, der über alle Empfänger läuft, scheitert sonst an dem, der als
     * nächster ein Feld bekommt — und der Fehlschlag sähe aus wie ein Befund
     * am Prüfling.
     *
     * @return array<string, string>
     */
    private function konfig(string $provider): array
    {
        // Der Wert ist beliebig; geprüft wird heute nur, dass er dasteht.
        return array_fill_keys(Providers::FIELDS[$provider] ?? [], '-1001234567890');
    }

    /**
     * Die Empfänger, bei denen niemand eine Signatur nachrechnet.
     *
     * **Abgeleitet und nicht aufgezählt.** Eine Liste hier wäre die zweite
     * Fassung von {@see Providers::signs()} — und der nächste Empfänger stünde
     * nicht darin, ohne dass etwas rot würde.
     *
     * @return list<string>
     */
    private function ohneSignatur(): array
    {
        $ohne = array_values(array_filter(
            array_keys(Providers::LABELS),
            static fn (string $provider): bool => ! Providers::signs($provider),
        ));

        self::assertGreaterThanOrEqual(2, count($ohne), 'Es werden kaum Empfänger gefunden — dann prüft der Fall nichts.');

        return $ohne;
    }

    /**
     * Den Text einer Meldung an Slack lesen.
     *
     * **Durch {@see Delivery} hindurch und nicht über {@see Providers::body()}.**
     * Ein Aufruf des Rumpfbauers prüfte, dass der Rumpf stimmt — nicht, dass
     * dieser Weg ihn benutzt.
     *
     * @param  array<string, mixed>  $event
     */
    private function textOf(array $event): string
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null, Providers::SLACK);

        (new Delivery($target, $http))->send($event);

        return (string) (json_decode((string) $http->calls[0]['body'], true)['text'] ?? '');
    }

    /**
     * Die hinterlegte Angabe kommt am Draht an.
     *
     * **Das ist die zweite Hälfte der Naht.** Dass der Chat in der Datei
     * steht, sagt noch nicht, dass die Meldung ihn trägt — und genau
     * dazwischen ist am 21. September 2026 der Empfänger verlorengegangen.
     *
     * > **Ein Wert, der abgelegt ist, wird zu einer Auskunft erst durch die
     * > Stelle, die ihn liest.**
     */
    public function test_the_stored_setting_reaches_the_wire(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store(
            'https://api.telegram.org/bot123:ABC/sendMessage',
            null,
            Providers::TELEGRAM,
            ['chat_id' => '-1001234567890'],
        );

        (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => self::DIENST, 'findings' => [
            ['label' => 'Der Dienst läuft nicht.', 'detail' => null],
        ]]);

        $rumpf = json_decode((string) $http->calls[0]['body'], true);

        self::assertIsArray($rumpf);
        self::assertSame('-1001234567890', $rumpf['chat_id'] ?? null);
        self::assertStringContainsString('Der Dienst läuft nicht.', (string) ($rumpf['text'] ?? ''));
    }

    /**
     * Was ein Empfänger braucht, wird beim Hinterlegen verlangt.
     *
     * **Und nicht beim Melden.** Ein fehlender Chat fiele sonst erst in der
     * Nacht auf, in der etwas zu melden wäre — und dann sieht der Betreiber
     * einen stillen Server und keine Ursache. Derselbe Grund, aus dem die
     * Adresse hier geprüft wird.
     *
     * Gemessen über **alle** Empfänger mit Feldern und nicht über Telegram
     * allein: Der nächste erbt die Regel, ohne dass jemand diesen Fall anfasst.
     */
    public function test_a_receiver_that_needs_a_field_does_not_get_stored_without_it(): void
    {
        $mitFeldern = array_keys(Providers::FIELDS);

        self::assertNotSame([], $mitFeldern, 'Ohne einen Empfänger mit Feldern prüft dieser Fall nichts.');

        foreach ($mitFeldern as $provider) {
            try {
                $this->target()->store('https://hooks.example.org/x', null, $provider, []);
                self::fail($provider.' wurde ohne seine Angaben hinterlegt.');
            } catch (AgentException $e) {
                self::assertStringContainsString('fehlt', $e->getMessage());
            }

            // Die Gegenrichtung — sonst wäre eine Ablage grün, die alles abweist.
            $target = $this->target();
            $target->store('https://hooks.example.org/x', null, $provider, $this->konfig($provider));

            self::assertSame($provider, $target->describe()['provider'] ?? null);
        }
    }

    /**
     * Und eine Angabe, die der Empfänger nicht kennt, kommt gar nicht erst in
     * die Datei.
     *
     * **Gelesen wird über eine Positivliste.** Ein Feld, das die Ablage trägt
     * und niemand liest, ist von aussen nicht von einem zu unterscheiden, das
     * es nicht gibt — und beim nächsten Umbau erklärt es niemand mehr.
     *
     * Beide Fälle: ein fremder Schlüssel bei einem Empfänger mit Feldern, und
     * überhaupt eine Angabe bei einem ohne.
     */
    public function test_a_setting_the_receiver_does_not_know_is_refused(): void
    {
        $mitFeldern = array_keys(Providers::FIELDS)[0];

        try {
            $this->target()->store(
                'https://hooks.example.org/x',
                null,
                $mitFeldern,
                [...$this->konfig($mitFeldern), 'erfunden' => 'x'],
            );
            self::fail('Eine unbekannte Angabe wurde hinterlegt.');
        } catch (AgentException $e) {
            self::assertStringContainsString('kennt diese Angabe nicht', $e->getMessage());
        }

        try {
            $this->target()->store('https://hooks.example.org/x', null, Providers::SLACK, ['chat_id' => '1']);
            self::fail('Slack hat eine Angabe angenommen, die es nicht kennt.');
        } catch (AgentException $e) {
            self::assertStringContainsString('keine weiteren Angaben', $e->getMessage());
        }

        // Und die Gegenrichtung: ohne Angaben geht Slack durch.
        $target = $this->target();
        $target->store('https://hooks.example.org/x', null, Providers::SLACK);

        self::assertSame(Providers::SLACK, $target->describe()['provider'] ?? null);
    }

    /**
     * Ein Geheimnis wird dort abgewiesen, wo niemand es nachrechnet.
     *
     * **Abgewiesen und nicht weggelassen.** Wer es einträgt, erwartet eine
     * beglaubigte Meldung; stillschweigend verworfen stünde auf der Seite
     * „signiert: nein" neben einem Feld, das man gerade ausgefüllt hat.
     *
     * > **Eine Beglaubigung, die der Empfänger nicht prüft, ist keine
     * > Beglaubigung, sondern eine Beschriftung.**
     */
    public function test_a_secret_is_refused_where_nobody_checks_it(): void
    {
        foreach ($this->ohneSignatur() as $provider) {
            try {
                $this->target()->store('https://hooks.example.org/x', 'geheimnis-mit-genug-zeichen', $provider);
                self::fail($provider.' hat ein Geheimnis angenommen, das niemand prüft.');
            } catch (AgentException $e) {
                self::assertStringContainsString('signiert', $e->getMessage());
            }
        }

        // Die Gegenrichtung — sonst wäre eine Ablage grün, die jedes Geheimnis abweist.
        $target = $this->target();
        $target->store('https://hooks.example.org/x', 'geheimnis-mit-genug-zeichen', Providers::GENERIC);
        self::assertTrue($target->describe()['signed'] ?? false);
    }

    /** Und nur der eigene Empfänger bekommt die Kopfzeile. */
    public function test_only_the_own_receiver_is_signed(): void
    {
        foreach ($this->ohneSignatur() as $provider) {
            $http = new ScriptedOutbound;
            $http->on(ScriptedOutbound::json(['ok' => true]));

            $target = $this->target($http);
            $target->store('https://hooks.example.org/x', null, $provider, $this->konfig($provider));
            (new Delivery($target, $http))->send(['kind' => 'test']);

            foreach ($http->calls[0]['headers'] as $zeile) {
                self::assertStringNotContainsString(Delivery::SIGNATURE_HEADER, $zeile);
            }
        }
    }

    /**
     * Ein unbekannter Empfänger wird abgewiesen und nicht auf den Standard
     * zurückgeführt.
     *
     * Wer sich vertippt, bekäme sonst wortlos die JSON-Form und wunderte sich
     * über ein `400` von Slack.
     */
    public function test_an_unknown_receiver_is_refused(): void
    {
        /*
         * **Der Prüfkörper hiess bis zum 21. September 2026 `telegram`** — und
         * an dem Tag kam Telegram dazu. Der Fall blieb grün, aber aus einem
         * anderen Grund: Er scheiterte an der fehlenden Angabe statt am
         * unbekannten Empfänger, und sein Eingriff biss nicht mehr.
         *
         * > **Ein Prüfkörper, der einen Zustand behauptet, statt ihn zu
         * > prüfen, hört auf zu messen, sobald jemand den Zustand herstellt —
         * > und sagt es nicht.**
         *
         * Deshalb ein Name, den niemand baut, **und** die Zusicherung
         * daneben: Gibt es ihn doch, bricht der Fall laut ab.
         */
        $erfunden = 'receiverThatIsGone';

        self::assertArrayNotHasKey($erfunden, Providers::LABELS,
            'Diesen Empfänger gibt es — dann misst dieser Fall nicht mehr die Abweisung.');

        try {
            $this->target()->store('https://hooks.example.org/x', null, $erfunden);
            self::fail('Ein unbekannter Empfänger wurde hinterlegt.');
        } catch (AgentException $e) {
            // Und am Wortlaut, weil `store()` aus mehreren Gründen wirft.
            self::assertStringContainsString('kennt der Agent nicht', $e->getMessage());
        }
    }

    /**
     * Ein Ziel aus der Zeit vor dieser Liste bleibt zustellbar.
     *
     * **Der Rückfall gilt fürs Lesen und nicht fürs Schreiben.** Eine Datei
     * ohne `provider` bekam damals die Form, die heute `generic` heisst; ein
     * Wurf an dieser Stelle machte aus einem hinterlegten Ziel ein unlesbares.
     */
    public function test_a_target_from_before_the_list_still_delivers(): void
    {
        @mkdir($this->verzeichnis, 0o700, true);
        file_put_contents(
            $this->verzeichnis.'/'.Target::FILE,
            (string) json_encode(['url' => 'https://hooks.example.org/x', 'secret' => null, 'stored_at' => 1]),
        );

        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);

        self::assertSame(Providers::GENERIC, $target->describe()['provider'] ?? null);

        (new Delivery($target, $http))->send(['kind' => 'test']);

        $rumpf = json_decode((string) $http->calls[0]['body'], true);

        self::assertIsArray($rumpf);
        self::assertSame(['server', 'at', 'event'], array_keys($rumpf));
    }

    /**
     * Der Text bleibt unter der Grenze des Empfängers — und sagt, was fehlt.
     *
     * **Die Grenze ist die des Empfängers und keine gewählte.** Discord weist
     * ein `content` über 2000 Zeichen ab; ein Deckel darüber verschöbe den
     * Fehlschlag bloss ans andere Ende der Leitung.
     *
     * > **Ein Deckel, der nicht sagt, dass er gegriffen hat, macht aus einer
     * > unvollständigen Auskunft eine falsche.**
     */
    public function test_the_text_stays_under_what_the_receiver_takes(): void
    {
        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        $target = $this->target($http);
        $target->store('https://hooks.example.org/x', null, Providers::DISCORD);

        $findings = [];

        for ($i = 0; $i < 60; $i++) {
            $findings[] = ['label' => 'Befund Nummer '.$i.' mit einem ausführlichen Satz.', 'detail' => str_repeat('x', 180)];
        }

        (new Delivery($target, $http))->send(['kind' => 'findings', 'subject' => 'p1000', 'findings' => $findings]);

        $text = (string) (json_decode((string) $http->calls[0]['body'], true)['content'] ?? '');

        self::assertLessThanOrEqual(2000, mb_strlen($text), 'Discord weist alles darüber ab.');
        self::assertMatchesRegularExpression('/… und \d+ weitere$/D', $text);

        // Und die Gegenrichtung: Was passt, wird nicht gekürzt. Ein zweites
        // Drehbuch statt eines geleerten — `calls` zurückzusetzen hiesse, die
        // Mitschrift zu verlieren, an der der Fall darüber hängt.
        $zweiter = new ScriptedOutbound;
        $zweiter->on(ScriptedOutbound::json(['ok' => true]));

        (new Delivery($target, $zweiter))->send(['kind' => 'findings', 'subject' => 'p1000', 'findings' => [
            ['label' => 'Ein einzelner Befund.', 'detail' => 'kurz'],
        ]]);

        $kurz = (string) (json_decode((string) $zweiter->calls[0]['body'], true)['content'] ?? '');

        self::assertStringNotContainsString('weitere', $kurz);
        self::assertStringContainsString('Ein einzelner Befund.', $kurz);
    }

    /**
     * Eine von Hand geänderte Ablage wird trotzdem nicht signiert.
     *
     * **Ohne diesen Fall ist der Zweig in {@see Delivery::send()}
     * unerreichbar.** {@see Target::store()} lässt die Verbindung aus
     * Slack-Empfänger und Geheimnis gar nicht erst zu, und eine Datei aus der
     * Zeit vor der Liste trägt keinen Empfänger — also `generic`. Erreichbar
     * ist er nur so: Die Datei gehört root, und root kann sie ändern.
     *
     * > **Ein Zweig, den man durch die Tür nicht erreicht, ist keine Zusage,
     * > bis jemand den Zustand herstellt, den es wirklich gibt.**
     */
    public function test_a_hand_edited_target_is_still_not_signed(): void
    {
        @mkdir($this->verzeichnis, 0o700, true);
        file_put_contents($this->verzeichnis.'/'.Target::FILE, (string) json_encode([
            'url' => 'https://hooks.example.org/x',
            'provider' => Providers::SLACK,
            'secret' => 'geheimnis-mit-genug-zeichen',
            'stored_at' => 1,
        ]));

        $http = new ScriptedOutbound;
        $http->on(ScriptedOutbound::json(['ok' => true]));

        (new Delivery($this->target($http), $http))->send(['kind' => 'test']);

        foreach ($http->calls[0]['headers'] as $zeile) {
            self::assertStringNotContainsString(Delivery::SIGNATURE_HEADER, $zeile);
        }

        // Die Gegenrichtung: derselbe Handgriff mit `generic` signiert sehr wohl.
        file_put_contents($this->verzeichnis.'/'.Target::FILE, (string) json_encode([
            'url' => 'https://hooks.example.org/x',
            'provider' => Providers::GENERIC,
            'secret' => 'geheimnis-mit-genug-zeichen',
            'stored_at' => 1,
        ]));

        $zweiter = new ScriptedOutbound;
        $zweiter->on(ScriptedOutbound::json(['ok' => true]));
        (new Delivery($this->target($zweiter), $zweiter))->send(['kind' => 'test']);

        self::assertNotSame([], array_filter(
            $zweiter->calls[0]['headers'],
            static fn (string $z): bool => str_starts_with($z, Delivery::SIGNATURE_HEADER.': '),
        ));
    }

    /**
     * Der Empfänger kommt zurück — er ist kein Geheimnis.
     *
     * Die Gegenrichtung zu
     * {@see self::test_neither_address_nor_secret_comes_back()}: Eine Antwort,
     * die **gar nichts** sagt, erfüllte jenen Fall auch.
     */
    public function test_the_receiver_is_not_a_secret(): void
    {
        $target = $this->target();
        $target->store('https://hooks.example.org/x', null, Providers::SLACK);

        self::assertSame(Providers::SLACK, $target->describe()['provider'] ?? null);
    }
}
