<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Desec;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * deSEC — der Anbieter, der die Zonenfrage selbst beantwortet.
 *
 * **`owns_qname` ist die beste Auskunft der sieben.** Alle anderen nennen ihre
 * Zonen, und dieses Panel sucht die längste passende heraus; deSEC nimmt den
 * vollen Namen und antwortet mit der zuständigen Domain.
 *
 * **deSEC führt RRsets, keine einzelnen Sätze.** Alle TXT-Werte zu einem Namen
 * sind ein Gegenstand mit einer Liste — Lesen-Ändern-Schreiben ist hier keine
 * Bequemlichkeit, sondern die Form der Schnittstelle. Darum prüft dieser Test
 * vor allem, was mit den Werten passiert, die **nicht** uns gehören.
 */
final class DesecTest extends TestCase
{
    private const TOKEN = 'geheim-token-123';

    private function provider(ScriptedOutbound $http): Desec
    {
        return Desec::fromConfig(['token' => self::TOKEN], $http);
    }

    /** @param  list<string>  $names */
    private static function domains(array $names): Response
    {
        return ScriptedOutbound::json(array_map(static fn (string $name): array => ['name' => $name], $names));
    }

    /** @param  list<string>  $records */
    private static function rrset(array $records): Response
    {
        return ScriptedOutbound::json([
            'domain' => 'example.de',
            'subname' => '_acme-challenge',
            'type' => 'TXT',
            'records' => $records,
            'ttl' => 3600,
        ]);
    }

    /** Es gibt noch keinen RRset für diesen Namen. */
    private static function missing(): Response
    {
        return ScriptedOutbound::json(['detail' => 'Not found.'], 404);
    }

    /** So quittiert deSEC einen RRset, der durch das Leeren verschwindet. */
    private static function gone(): Response
    {
        return new Response(204, [], '');
    }

    /** @param  array<string, mixed>  $body */
    private static function failure(int $status, array $body): Response
    {
        return ScriptedOutbound::json($body, $status);
    }

    /** @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call */
    private static function body(array $call): mixed
    {
        return json_decode((string) $call['body'], true);
    }

    public function test_the_domain_comes_from_the_provider_not_from_the_name(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::missing(),
            ScriptedOutbound::json(['records' => ['"wert"']], 201),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        // **Eine Anfrage statt einer Liste.** deSEC bekommt den vollen Namen
        // und nennt die zuständige Domain; die Regel „die längste Zone gewinnt"
        // stellt sich hier gar nicht.
        $this->assertStringContainsString('owns_qname=', $http->calls[0]['url']);
        $this->assertStringContainsString('_acme-challenge.example.de', $http->calls[0]['url']);
        $this->assertStringEndsWith('/domains/example.de/rrsets/_acme-challenge/TXT/', $http->calls[1]['url']);
    }

    /** Gibt es noch keinen RRset, wird er angelegt und nicht geändert. */
    public function test_a_missing_rrset_is_created(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::missing(),
            ScriptedOutbound::json(['records' => ['"wert"']], 201),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertSame('POST', $http->calls[2]['method']);
        $this->assertStringEndsWith('/domains/example.de/rrsets/', $http->calls[2]['url']);

        $body = self::body($http->calls[2]);

        $this->assertSame('_acme-challenge', $body['subname']);
        $this->assertSame('TXT', $body['type']);
        $this->assertSame(['"wert"'], $body['records']);
        $this->assertSame(Desec::TTL, $body['ttl']);
    }

    /**
     * Und ein vorhandener RRset bekommt den Wert dazu — er wird nicht ersetzt.
     *
     * Läuft eine zweite Bestellung für dieselbe Zone, steht ihr Wert schon in
     * dieser Liste. Sie zu überschreiben nähme ihr die Prüfung weg.
     */
    public function test_an_existing_rrset_gets_the_value_appended(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset(['"fremder-wert"']),
            self::rrset(['"fremder-wert"', '"meiner"']),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'meiner');

        $this->assertSame('PATCH', $http->calls[2]['method']);
        $this->assertSame(['"fremder-wert"', '"meiner"'], self::body($http->calls[2])['records']);
    }

    /** Derselbe Wert ein zweites Mal wird nicht doppelt geschrieben. */
    public function test_a_value_that_is_already_there_is_not_written_twice(): void
    {
        $http = (new ScriptedOutbound)->on(self::domains(['example.de']), self::rrset(['"schon-da"']));

        $this->provider($http)->add('_acme-challenge.example.de', 'schon-da');

        $this->assertCount(2, $http->calls);
    }

    /** Beim Abräumen fällt nur der eigene Wert heraus. */
    public function test_removing_takes_out_only_the_own_value(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset(['"fremder"', '"meiner"']),
            self::rrset(['"fremder"']),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'meiner');

        $this->assertSame(['"fremder"'], self::body($http->calls[2])['records']);
    }

    /**
     * Der letzte Wert löscht den RRset, und `204` ist dafür der Erfolg.
     *
     * Wer nur `200` gelten lässt, macht aus dem Normalfall am Ende jeder
     * Bestellung einen Fehlschlag.
     */
    public function test_emptying_the_rrset_is_a_success(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset(['"meiner"']),
            self::gone(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'meiner');

        $this->assertSame([], self::body($http->calls[2])['records']);
        $this->assertCount(3, $http->calls);
    }

    /** Ein nackt abgelegter Wert wird ebenso erkannt. */
    public function test_an_unquoted_value_is_recognised(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset(['nackt']),
            self::gone(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'nackt');

        $this->assertSame([], self::body($http->calls[2])['records']);
    }

    /** Kein RRset heisst: nichts zu tun — `remove()` läuft auch nach einem Fehlschlag. */
    public function test_a_missing_rrset_is_nothing_to_remove(): void
    {
        $http = (new ScriptedOutbound)->on(self::domains(['example.de']), self::missing());

        $this->provider($http)->remove('_acme-challenge.example.de', 'weg');

        $this->assertCount(2, $http->calls);
    }

    /** Und eine Liste ohne den eigenen Wert wird nicht angefasst. */
    public function test_a_list_without_the_own_value_is_left_alone(): void
    {
        $http = (new ScriptedOutbound)->on(self::domains(['example.de']), self::rrset(['"fremder"']));

        $this->provider($http)->remove('_acme-challenge.example.de', 'meiner');

        $this->assertCount(2, $http->calls);
    }

    public function test_a_name_without_a_responsible_domain_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::domains([]));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Domain');

        $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
    }

    /** Ist der Name die Domain selbst, heisst er bei deSEC `@`. */
    public function test_the_domain_itself_becomes_the_apex(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['_acme-challenge.example.de']),
            self::missing(),
            ScriptedOutbound::json([], 201),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertSame(Desec::APEX, self::body($http->calls[2])['subname']);
    }

    /**
     * Eine Prüfung, die an einem Feld hängt, nennt das Feld.
     *
     * deSEC antwortet darauf mit einer Ablage aus Feldnamen und Sätzen und
     * nicht mit `detail`. Wer nur `detail` liest, bekommt „HTTP 400".
     */
    public function test_a_field_error_names_the_field(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset([]),
            self::failure(400, ['records' => ['Ensure this field has no more than 4092 characters.']]),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('records: Ensure this field');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_a_plain_error_names_its_detail(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::rrset([]),
            self::failure(400, ['detail' => 'Zone gesperrt']),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone gesperrt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** @return list<array{0: int}> */
    public static function refusals(): array
    {
        return [[401], [403]];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_token_names_the_permission(int $status): void
    {
        $http = (new ScriptedOutbound)->on(self::failure($status, ['detail' => 'Invalid token.']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Schreibrecht');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_throttling_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(self::failure(429, ['detail' => 'zu schnell']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('drosselt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_the_token_travels_as_a_header(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::domains(['example.de']),
            self::missing(),
            ScriptedOutbound::json([], 201),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        foreach ($http->calls as $call) {
            $this->assertContains('Authorization: Token '.self::TOKEN, $call['headers']);
            $this->assertStringNotContainsString(self::TOKEN, $call['url']);
            $this->assertStringNotContainsString(self::TOKEN, (string) $call['body']);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    public static function badTokens(): array
    {
        return [['', 'fehlt'], ['   ', 'fehlt'], ["mit\nUmbruch", 'Kopfzeile'], ['kurz', 'Kopfzeile']];
    }

    #[DataProvider('badTokens')]
    public function test_a_token_that_is_none_is_refused(string $token, string $expected): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage($expected);

        Desec::configure(['token' => $token]);
    }

    public function test_a_usable_token_is_kept_as_it_is(): void
    {
        $this->assertSame(['token' => 'abcdefgh12345'], Desec::configure(['token' => '  abcdefgh12345  ']));
    }
}
