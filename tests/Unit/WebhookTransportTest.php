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
        ];

        foreach ($erwartet as $provider => $schluessel) {
            $http = new ScriptedOutbound;
            $http->on(ScriptedOutbound::json(['ok' => true]));

            $target = $this->target($http);
            $target->store('https://hooks.example.org/x', null, $provider);

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
        foreach ([Providers::SLACK, Providers::DISCORD] as $provider) {
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
        foreach ([Providers::SLACK, Providers::DISCORD] as $provider) {
            $http = new ScriptedOutbound;
            $http->on(ScriptedOutbound::json(['ok' => true]));

            $target = $this->target($http);
            $target->store('https://hooks.example.org/x', null, $provider);
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
        $this->expectException(AgentException::class);
        $this->target()->store('https://hooks.example.org/x', null, 'telegram');
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
