<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\DnsProvider;
use SrvPanel\Agent\Acme\Dns\Exchange;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Acme\Dns\Rfc2136;
use SrvPanel\Agent\Acme\Dns\Tsig;
use SrvPanel\Agent\Acme\Dns\UpdateMessage;
use SrvPanel\Agent\Acme\DnsChallenge;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedExchange;

/**
 * Der erste echte DNS-Anbieter: RFC 2136 über TSIG.
 *
 * **Geprüft wird das Gesendete, nicht die Wirkung.** Ein Nameserver, der
 * Aktualisierungen entgegennimmt, gehört nicht in eine CI — und die Fälle, um
 * die es hier geht, liessen sich dort gar nicht bestellen: eine Antwort mit
 * `REFUSED`, eine mit falscher Kennung, eine ohne gültige Unterschrift. Das
 * Gegenstück auf dem Zielserver ist die Abnahme aus `docs/34 §10`.
 *
 * **Die Zonenliste ist der Punkt, auf den es hier ankommt.** Sie ist keine
 * Bequemlichkeit, sondern eine Positivliste: Ein Profil ändert genau die
 * Zonen, die der Betreiber hineingeschrieben hat. Ohne sie wäre der
 * Zonenname eine Grösse, die aus dem Namen der Domain folgt — und damit aus
 * etwas, das ein Kunde bestimmt.
 */
final class Rfc2136Test extends TestCase
{
    private const SECRET = 'ein-geheimnis-mit-genug-bytes';

    private const KEY = 'srvpanel-key';

    /** @return array<string, mixed> */
    private function config(mixed $zones = ['example.de']): array
    {
        return [
            'server' => '192.0.2.53',
            'port' => 5353,
            'zones' => $zones,
            'key_name' => self::KEY,
            'secret' => base64_encode(self::SECRET),
        ];
    }

    /** @param array<string, mixed>|null $config */
    private function provider(ScriptedExchange $exchange, ?array $config = null): Rfc2136
    {
        return Rfc2136::fromConfig($config ?? $this->config(), $exchange);
    }

    private function exchange(int $code = 0, bool $wrongId = false, bool $sign = true): ScriptedExchange
    {
        return new ScriptedExchange(self::KEY, self::SECRET, $code, $wrongId, $sign);
    }

    /** Was im Abschnitt der Änderungen steht — Typ, Klasse, Haltbarkeit, Wert. */
    private function update(string $message): string
    {
        return substr($message, 12);
    }

    public function test_an_entry_is_added_under_the_right_zone(): void
    {
        $exchange = $this->exchange();

        $this->provider($exchange)->add('_acme-challenge.example.de', 'der-wert');

        $this->assertCount(1, $exchange->sent);
        $this->assertSame(['server' => '192.0.2.53', 'port' => 5353], $exchange->to[0]);

        /** @var array<string, int> $header */
        $header = unpack('nid/nflags/nzo/npr/nup/nad', $exchange->sent[0]);

        $this->assertSame(UpdateMessage::OPCODE, $header['flags'], 'Aktualisierung und keine Frage.');
        $this->assertSame(1, $header['zo']);
        $this->assertSame(0, $header['pr'], 'Ohne Voraussetzungen.');
        $this->assertSame(1, $header['up']);
        $this->assertSame(1, $header['ad'], 'Der TSIG-Satz zählt mit.');

        $update = $this->update($exchange->sent[0]);

        // Die Zone steht vorn, der Satz dahinter — beide ausgeschrieben.
        $this->assertStringStartsWith("\x07example\x02de\x00".pack('n2', 6, 1), $update);
        $this->assertStringContainsString("\x0f_acme-challenge\x07example\x02de\x00", $update);
        $this->assertStringContainsString(pack('n2N', 16, 1, Rfc2136::TTL), $update);
        $this->assertStringContainsString(chr(8).'der-wert', $update);
    }

    /**
     * Abgeräumt wird genau ein Satz — mit Klasse `NONE` und nicht `ANY`.
     *
     * `ANY` räumt alles unter dem Namen ab, und damit die Prüfung eines
     * fremden Vorgangs mit: Zwei Bestellungen für dieselbe Zone stehen unter
     * demselben Namen, und das ist bei einem Platzhalter der Regelfall.
     */
    public function test_removing_names_the_one_record(): void
    {
        $exchange = $this->exchange();

        $this->provider($exchange)->remove('_acme-challenge.example.de', 'der-wert');

        $update = $this->update($exchange->sent[0]);

        $this->assertStringContainsString(pack('n2N', 16, UpdateMessage::CLASS_NONE, 0), $update);
        $this->assertStringNotContainsString(pack('n2N', 16, 255, 0), $update);
        $this->assertStringContainsString(chr(8).'der-wert', $update);
    }

    /**
     * Die längste passende Zone gewinnt.
     *
     * Wer `example.de` und `intern.example.de` mit demselben Schlüssel führt,
     * will für einen Namen in der zweiten auch die zweite genannt haben —
     * sonst antwortet der Nameserver mit `NOTZONE`.
     */
    public function test_the_longest_matching_zone_wins(): void
    {
        $exchange = $this->exchange();
        $config = $this->config(['example.de', 'intern.example.de']);

        $this->provider($exchange, $config)->add('_acme-challenge.intern.example.de', 'x');

        $this->assertStringStartsWith(
            "\x06intern\x07example\x02de\x00",
            $this->update($exchange->sent[0]),
        );
    }

    /**
     * Ein Name ausserhalb der hinterlegten Zonen wird nicht versucht.
     *
     * **Und `bösexample.de` endet auf `example.de`.** Verglichen wird deshalb
     * beschriftungsweise; ein Vergleich als Zeichenkette liesse hier eine
     * fremde Domain in eine Zone hinein, die jemand anderem gehört.
     */
    #[DataProvider('foreignNames')]
    public function test_a_name_outside_the_zones_is_refused(string $record): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone hinterlegt');

        $this->provider($this->exchange())->add($record, 'x');
    }

    /** @return list<array{0: string}> */
    public static function foreignNames(): array
    {
        return [
            ['_acme-challenge.fremde.de'],
            ['_acme-challenge.boesexample.de'],
            ['_acme-challenge.example.de.evil.test'],
        ];
    }

    /** Über TCP kann zwar wenig danebengehen — geprüft wird trotzdem. */
    public function test_an_answer_to_another_question_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('anderen Frage');

        $this->provider($this->exchange(wrongId: true))->add('_acme-challenge.example.de', 'x');
    }

    /**
     * Eine Antwort ohne gültige Unterschrift ist kein „in Ordnung".
     *
     * Sie zu glauben hiesse, der Zertifizierungsstelle „prüf jetzt" zu sagen,
     * während nichts in der Zone steht — und damit einen der fünf Fehlversuche
     * je Konto und Stunde zu verbrennen.
     */
    public function test_an_unsigned_answer_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine gültige Unterschrift');

        $this->provider($this->exchange(sign: false))->add('_acme-challenge.example.de', 'x');
    }

    /**
     * Und eine Ablehnung wird auf Deutsch weitergegeben.
     *
     * Ohne die Zuordnung stünde im Protokoll „Rückgabewert 9", und der
     * Betreiber suchte an der falschen Stelle.
     */
    public function test_a_refusal_says_what_it_was(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('REFUSED');

        $this->provider($this->exchange(code: 5))->add('_acme-challenge.example.de', 'x');
    }

    public function test_every_return_code_has_a_sentence(): void
    {
        foreach (range(0, 10) as $code) {
            $this->assertNotSame('', UpdateMessage::explain($code));
            $this->assertStringNotContainsString('Rückgabewert', UpdateMessage::explain($code));
        }

        $this->assertStringContainsString('Rückgabewert 23', UpdateMessage::explain(23));
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    public static function badConfigs(): array
    {
        $good = [
            'server' => '192.0.2.53',
            'zones' => ['example.de'],
            'key_name' => 'srvpanel-key',
            'secret' => 'Z2VoZWlt',
        ];

        return [
            [['server' => ''] + $good, 'Nameserver'],
            [['server' => 'a b;rm -rf /'] + $good, 'Nameserver'],
            [['port' => 0] + $good, 'Port'],
            [['port' => 70000] + $good, 'Port'],
            [['zones' => []] + $good, 'keine Zone'],
            [['key_name' => ''] + $good, 'TSIG-Schlüssels'],
            [['key_name' => '../schluessel'] + $good, 'TSIG-Schlüssels'],
            [['secret' => ''] + $good, 'Base64'],
            [['secret' => 'kein base64!!'] + $good, 'Base64'],
            [['algorithm' => 'hmac-md5'] + $good, 'TSIG-Verfahren'],
        ];
    }

    /**
     * Geprüft wird beim Hinterlegen und nicht beim Bestellen.
     *
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('badConfigs')]
    public function test_credentials_are_checked_when_they_are_stored(array $config, string $expected): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage($expected);

        Rfc2136::configure($config);
    }

    public function test_the_checked_form_is_what_gets_stored(): void
    {
        $checked = Rfc2136::configure([
            'server' => '192.0.2.53',
            'zones' => ['Example.DE.', 'example.de'],
            'key_name' => ' SrvPanel-Key ',
            'secret' => 'Z2VoZWlt',
        ]);

        // Ein zweites Mal dieselbe Zone ist keine zweite, und der Port kommt
        // aus der Voreinstellung.
        $this->assertSame(['example.de'], $checked['zones']);
        $this->assertSame(Rfc2136::PORT, $checked['port']);
        $this->assertSame('srvpanel-key', $checked['key_name']);
        $this->assertSame(Tsig::DEFAULT_ALGORITHM, $checked['algorithm']);
    }

    /**
     * Die Werkstatt: Jeder Schlüssel zeigt auf etwas — oder sagt, dass es fehlt.
     *
     * **Das ist der Wächter zu der Regel, die dieses Projekt trägt.** Ein
     * Anbieter, der in {@see Providers::keys()} steht und weder gebaut ist noch
     * in {@see Providers::PENDING}, wäre genau die Zeichenkette, die auf nichts
     * zeigt — und sie würde erst beim ersten Zertifikat auffallen.
     */
    public function test_every_provider_key_points_at_something(): void
    {
        foreach (Providers::keys() as $key) {
            if (in_array($key, Providers::PENDING, true)) {
                try {
                    Providers::make($key, []);
                    $this->fail($key.' steht als offen und lässt sich trotzdem bauen.');
                } catch (AgentException $exception) {
                    $this->assertStringContainsString('noch nicht umgesetzt', $exception->getMessage());
                }

                continue;
            }

            $this->assertNotSame([], Providers::configure($key, $this->config()));
            $this->assertInstanceOf(DnsProvider::class, Providers::make($key, $this->config()));
        }

        $this->assertSame([Providers::RFC2136], Providers::available());
    }

    /**
     * Und die Strecke aus Schritt 5 endet jetzt an einer Umsetzung.
     *
     * Der Name, den {@see DnsChallenge} bildet, muss der sein, für den dieser
     * Anbieter eine Zone findet — sonst zeigen die beiden Schritte
     * aneinander vorbei, und zwar erst auf dem Zielserver.
     */
    public function test_the_challenge_and_the_provider_agree_on_the_name(): void
    {
        $exchange = $this->exchange();
        $challenge = new DnsChallenge($this->provider($exchange));

        $challenge->present('*.example.de', 'ein-token', 'ein-token.der-daumenabdruck');

        $this->assertStringContainsString(
            "\x0f_acme-challenge\x07example\x02de\x00",
            $this->update($exchange->sent[0]),
        );

        $challenge->cleanup('*.example.de', 'ein-token');

        $this->assertCount(2, $exchange->sent);
        $this->assertStringContainsString(
            pack('n2N', 16, UpdateMessage::CLASS_NONE, 0),
            $this->update($exchange->sent[1]),
        );
    }

    /** Und die Schnittstelle ist die, die {@see DnsChallenge} erwartet. */
    public function test_the_exchange_is_the_seam_it_says_it_is(): void
    {
        $this->assertInstanceOf(Exchange::class, $this->exchange());
    }
}
