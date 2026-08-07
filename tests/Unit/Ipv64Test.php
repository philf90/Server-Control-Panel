<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Ipv64;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * IPv64.net — und die Frage, an der sich die Zonenauflösung entscheidet.
 *
 * **Warum dieser Anbieter der erste der vier ist.** Bei ihm ist die Zone
 * häufig selbst eine Unterdomain: `meinname.ipv64.de` ist eine ganze Zone.
 * Jede Regel, die sie aus dem Namen errechnet, liegt hier irgendwann falsch —
 * und zwar still, denn ein Eintrag in der falschen Zone ist kein Fehler, er
 * wird nur nie gefunden.
 *
 * Geprüft wird deshalb vor allem: Es wird **gefragt**.
 */
final class Ipv64Test extends TestCase
{
    private function provider(ScriptedOutbound $http, string $token = 'geheim-token-123'): Ipv64
    {
        return new Ipv64($token, $http);
    }

    public function test_the_zone_comes_from_the_account_and_not_from_the_name(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['cloudlab24.ipv64.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $this->provider($http)->add('_acme-challenge.cloudlab24.ipv64.de', 'wert');

        $this->assertStringContainsString('get_domains', $http->calls[0]['url']);
        $this->assertSame('GET', $http->calls[0]['method']);

        $fields = $http->fieldsOf(1);

        // Die Zone hat **drei** Bestandteile, der Präfix einen. Wer „die
        // registrierbare Domain" nimmt, schriebe hier `ipv64.de` hinein.
        $this->assertSame('cloudlab24.ipv64.de', $fields['add_record']);
        $this->assertSame('_acme-challenge', $fields['praefix']);
        $this->assertSame('TXT', $fields['type']);
        $this->assertSame('wert', $fields['content']);
    }

    /**
     * Und eine eigene Domain mit zwei Bestandteilen geht genauso.
     *
     * Das ist der Fall, an dem die Regel von lego bricht: Dessen `splitDomain`
     * nimmt die letzten **drei** Bestandteile. Für `example.de` als Zone käme
     * dabei `_acme-challenge.example.de` heraus — also der Name selbst.
     */
    public function test_a_two_label_zone_works_the_same(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $fields = $http->fieldsOf(1);

        $this->assertSame('example.de', $fields['add_record']);
        $this->assertSame('_acme-challenge', $fields['praefix']);
    }

    /** Die längste passende Zone gewinnt — sonst landet der Eintrag eine Ebene zu hoch. */
    public function test_the_longest_matching_zone_wins(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de', 'kunde.example.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $this->provider($http)->add('_acme-challenge.kunde.example.de', 'wert');

        $this->assertSame('kunde.example.de', $http->fieldsOf(1)['add_record']);
    }

    public function test_a_name_outside_every_zone_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(ScriptedOutbound::domains(['example.de']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone');

        $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
    }

    /**
     * Beim Löschen geht der Wert mit.
     *
     * Laufen zwei Bestellungen für dieselbe Zone, stehen zwei
     * `_acme-challenge`-Einträge nebeneinander. Wer nur nach dem Namen löscht,
     * räumt die Prüfung des anderen Vorgangs mit ab — und der scheitert dann
     * an einer Ursache, die nirgends steht.
     */
    public function test_removing_names_the_value(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $this->assertSame('DELETE', $http->calls[1]['method']);

        $fields = $http->fieldsOf(1);

        $this->assertSame('example.de', $fields['del_record']);
        $this->assertSame('genau-dieser', $fields['content']);
    }

    /** Das Token geht als Kopfzeile hinaus — und nur dorthin. */
    public function test_the_token_travels_as_a_header(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $this->provider($http, 'streng-geheim')->add('_acme-challenge.example.de', 'wert');

        foreach ($http->calls as $call) {
            $this->assertContains('Authorization: Bearer streng-geheim', $call['headers']);
            $this->assertStringNotContainsString('streng-geheim', $call['url']);
            $this->assertStringNotContainsString('streng-geheim', (string) $call['body']);
        }
    }

    /**
     * `null` ist ein Fehlschlag und kein leeres Ergebnis.
     *
     * Der Anbieter antwortet in diesem Fall mit dem vier Zeichen langen Rumpf
     * `null`, und `json_decode` macht daraus brav ein PHP-`null`. Wer das als
     * „nichts gefunden" liest, hält einen Fehlschlag für einen Normalfall —
     * lego behandelt es ausdrücklich als Fehler, und das ist der Grund.
     */
    public function test_the_word_null_is_not_an_answer(): void
    {
        $http = (new ScriptedOutbound)->on(ScriptedOutbound::json('null'));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Auskunft');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** Drosselung sagt, dass sie eine ist — sonst wird ein Vorgang sinnlos wiederholt. */
    public function test_throttling_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(ScriptedOutbound::json(['info' => 'zu schnell'], 429));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('drosselt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /**
     * Die Begründung steht je nach Aufruf in einem anderen Feld.
     *
     * `info` trägt dann ein allgemeines Wort. Wer nur `info` liest, bekommt
     * „Nope" und weiss nichts.
     */
    public function test_the_reason_is_read_from_the_field_that_carries_it(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de']),
            ScriptedOutbound::json(['info' => 'Nope', 'add_record' => 'Zone nicht gefunden'], 400),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone nicht gefunden');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** Die Zonen werden einmal geholt und nicht je Aufruf. */
    public function test_the_zones_are_fetched_once(): void
    {
        $http = (new ScriptedOutbound)->on(
            ScriptedOutbound::domains(['example.de']),
            ScriptedOutbound::json(['info' => 'success']),
        );

        $provider = $this->provider($http);
        $provider->add('_acme-challenge.example.de', 'wert');
        $provider->remove('_acme-challenge.example.de', 'wert');

        $fragen = array_filter($http->calls, static fn (array $call): bool => str_contains($call['url'], 'get_domains'));

        $this->assertCount(1, $fragen, 'Der Anbieter drosselt — jede Frage zu viel zählt.');
    }

    /** @return list<array{0: mixed}> */
    public static function badTokens(): array
    {
        return [[''], ['   '], ["mit\nZeilenumbruch"], ['kurz'], [null], [42]];
    }

    /**
     * Ein Token, das in keiner Kopfzeile stehen darf, wird beim Hinterlegen
     * abgewiesen — nicht erst, wenn eine Erneuerung nachts scheitert.
     *
     * Der Zeilenumbruch ist der teure Fall: Er hängte eine zweite Kopfzeile an
     * jede Anfrage dieses Anbieters.
     *
     * @param  mixed  $token
     */
    #[DataProvider('badTokens')]
    public function test_a_token_that_is_none_is_refused($token): void
    {
        $this->expectException(AgentException::class);

        Ipv64::configure(['token' => $token]);
    }

    public function test_a_usable_token_is_kept_as_it_is(): void
    {
        $this->assertSame(['token' => 'abcdefgh12345'], Ipv64::configure(['token' => '  abcdefgh12345  ']));
    }
}
