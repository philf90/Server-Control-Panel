<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\DnsChallenge;
use SrvPanel\Agent\AgentException;
use Tests\Support\RecordingDnsProvider;
use Tests\Support\ScriptedLookup;

/**
 * DNS-01 — und die eine Frage, die HTTP-01 nicht kennt.
 *
 * **`ready()` ist hier der ganze Punkt.** Bei HTTP-01 liegt die Datei, sobald
 * sie geschrieben ist. Ein TXT-Eintrag ist dagegen nicht da, weil die API des
 * Anbieters „ok" gesagt hat, sondern erst, wenn die autoritativen Nameserver
 * ihn ausliefern. Wer der Zertifizierungsstelle zu früh sagt „prüf jetzt",
 * verbrennt einen der fünf Fehlversuche je Konto und Stunde — und die gelten
 * für jeden Kunden dieses Servers, nicht nur für den einen.
 */
final class DnsChallengeTest extends TestCase
{
    private const AUTHORIZATION = 'token.thumb';

    /** Derselbe Wert, unabhängig gerechnet — RFC 8555 §8.4. */
    private function expected(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::AUTHORIZATION, true)), '+/', '-_'), '=');
    }

    public function test_the_value_follows_the_standard(): void
    {
        $this->assertSame($this->expected(), DnsChallenge::value(self::AUTHORIZATION));

        // Base64url ohne Auffüllzeichen: Ein `=` im TXT-Satz nimmt mancher
        // Anbieter gar nicht erst an.
        $this->assertStringNotContainsString('=', DnsChallenge::value(self::AUTHORIZATION));
    }

    /**
     * Der Stern gehört in die Bestellung und nicht in den Namen der Prüfung.
     *
     * Für `*.example.de` heisst der Eintrag `_acme-challenge.example.de` —
     * derselbe wie für `example.de`. Wer den Stern stehen lässt, legt einen
     * Eintrag an, den niemand abfragt.
     */
    public function test_a_wildcard_asks_under_the_zone(): void
    {
        $this->assertSame('_acme-challenge.example.de', DnsChallenge::record('*.example.de'));
        $this->assertSame('_acme-challenge.example.de', DnsChallenge::record('Example.DE'));
    }

    public function test_a_name_that_is_none_is_refused(): void
    {
        $this->expectException(AgentException::class);

        DnsChallenge::record('*.beispiel');
    }

    public function test_presenting_asks_the_provider_for_the_right_record(): void
    {
        $provider = new RecordingDnsProvider;
        $challenge = new DnsChallenge($provider, new ScriptedLookup([]));

        $challenge->present('*.example.de', 'token', self::AUTHORIZATION);

        $this->assertSame([['_acme-challenge.example.de', $this->expected()]], $provider->added);
    }

    /**
     * Erst wenn **alle** autoritativen Server den Wert ausliefern.
     *
     * Welchen die Zertifizierungsstelle fragt, weiss niemand; sie fragt sogar
     * aus mehreren Netzen zugleich. Ein Wert, den nur die Hälfte der Server
     * kennt, ist eine Prüfung, die manchmal gelingt — und das ist die
     * unangenehmste Sorte Fehler.
     */
    public function test_it_waits_until_every_nameserver_serves_the_value(): void
    {
        $wert = $this->expected();

        $vollstaendig = new DnsChallenge(new RecordingDnsProvider, new ScriptedLookup(
            ['1.1.1.1', '2.2.2.2'],
            ['1.1.1.1' => [$wert], '2.2.2.2' => [$wert]],
        ));

        $halb = new DnsChallenge(new RecordingDnsProvider, new ScriptedLookup(
            ['1.1.1.1', '2.2.2.2'],
            ['1.1.1.1' => [$wert]],
        ));

        $this->assertTrue($vollstaendig->ready('*.example.de', 'token', self::AUTHORIZATION));
        $this->assertFalse($halb->ready('*.example.de', 'token', self::AUTHORIZATION));
    }

    /**
     * Und ohne Auskunft über die Nameserver wird gewartet, nicht abgebrochen.
     *
     * Auch die NS-Auskunft kann gerade fehlen. Eine Ausnahme daraus zu machen
     * hiesse, eine Bestellung an einem Schluckauf des Auflösers scheitern zu
     * lassen.
     */
    public function test_without_nameservers_it_is_not_ready(): void
    {
        $challenge = new DnsChallenge(new RecordingDnsProvider, new ScriptedLookup([]));

        $this->assertFalse($challenge->ready('example.de', 'token', self::AUTHORIZATION));
    }

    /**
     * Abgeräumt wird genau der eigene Eintrag.
     *
     * **Mehrere Bestellungen für dieselbe Zone können gleichzeitig laufen**,
     * und dann stehen zwei `_acme-challenge`-Einträge nebeneinander. Wer nur
     * nach dem Namen löscht, räumt die Prüfung eines anderen Vorgangs mit ab —
     * und der scheitert an einer Ursache, die nirgends steht.
     */
    public function test_cleanup_removes_exactly_what_was_presented(): void
    {
        $provider = new RecordingDnsProvider;
        $challenge = new DnsChallenge($provider, new ScriptedLookup([]));

        $challenge->present('*.example.de', 'token', self::AUTHORIZATION);
        $challenge->cleanup('*.example.de', 'token');

        $this->assertSame([['_acme-challenge.example.de', $this->expected()]], $provider->removed);

        // Und ein zweites Abräumen fasst nichts mehr an.
        $challenge->cleanup('*.example.de', 'token');

        $this->assertCount(1, $provider->removed);
    }

    /** Was nie hingelegt wurde, wird auch nicht abgeräumt. */
    public function test_cleanup_without_present_does_nothing(): void
    {
        $provider = new RecordingDnsProvider;
        $challenge = new DnsChallenge($provider, new ScriptedLookup([]));

        $challenge->cleanup('example.de', 'unbekannt');

        $this->assertSame([], $provider->removed);
    }

    public function test_the_type_is_the_one_the_authority_names(): void
    {
        $challenge = new DnsChallenge(new RecordingDnsProvider, new ScriptedLookup([]));

        $this->assertSame('dns-01', $challenge->type());
    }
}
