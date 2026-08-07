<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Hetzner;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * Hetzner — und die drei Stellen, an denen dieser Anbieter anders ist.
 *
 * **Erstens: es gibt ihn zweimal.** Die alte DNS-Konsole und die Cloud-API
 * machen dasselbe mit verschiedenen Kopfzeilen, und ein Token der einen gilt
 * bei der anderen nicht. An der Form lassen sie sich nicht auseinanderhalten —
 * also muss die Abweisung es sagen.
 *
 * **Zweitens: der Schreibvorgang ist beim Antworten nicht fertig.** Die
 * Cloud-API antwortet mit einer Action, die auf `running` stehen kann. Wer
 * `running` für einen Fehlschlag hält, bricht jeden zweiten Vorgang ab; wer
 * `error` für einen Erfolg hält, wartet danach zwei Minuten auf einen Eintrag,
 * den niemand mehr anlegt.
 *
 * **Drittens: der Wert steht in Anführungszeichen.** Eine Zonendatei schreibt
 * TXT so, und die Schnittstelle nimmt es wörtlich.
 */
final class HetznerTest extends TestCase
{
    private function provider(ScriptedOutbound $http, string $token = 'geheim-token-123'): Hetzner
    {
        return new Hetzner($token, $http);
    }

    /**
     * Eine Seite Zonen, wie die Cloud-API sie schickt.
     *
     * @param  list<string>  $names
     */
    private static function zones(array $names, ?int $next = null): Response
    {
        return ScriptedOutbound::json([
            'zones' => array_map(static fn (string $name): array => ['id' => 1, 'name' => $name], $names),
            'meta' => ['pagination' => ['page' => 1, 'next_page' => $next]],
        ]);
    }

    /** Eine Action, wie sie auf einen Schreibvorgang folgt. */
    private static function action(string $status, ?string $message = null): Response
    {
        $action = ['id' => 42, 'status' => $status];

        if ($message !== null) {
            $action['error'] = ['code' => 'invalid_input', 'message' => $message];
        }

        return ScriptedOutbound::json(['action' => $action]);
    }

    public function test_the_zone_comes_from_the_project_and_the_name_is_relative(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de']),
            self::action('success'),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertStringContainsString('/zones?page=1', $http->calls[0]['url']);
        $this->assertSame('GET', $http->calls[0]['method']);

        // **Der Name der RRSet ist der Präfix, nicht der volle Name.** Wer den
        // vollen schickt, legt den Eintrag unter
        // `_acme-challenge.example.de.example.de` an — angenommen wird das,
        // gefunden wird es nie.
        $this->assertSame(
            Hetzner::ENDPOINT.'/zones/example.de/rrsets/_acme-challenge/TXT/actions/add_records',
            $http->calls[1]['url'],
        );
        $this->assertSame('POST', $http->calls[1]['method']);
    }

    /** Der Wert geht in Anführungszeichen hinaus, und der TTL steht dabei. */
    public function test_the_value_is_quoted(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']), self::action('success'));

        $this->provider($http)->add('_acme-challenge.example.de', 'der-wert');

        $body = json_decode((string) $http->calls[1]['body'], true);

        $this->assertSame('"der-wert"', $body['records'][0]['value']);
        $this->assertSame(Hetzner::TTL, $body['ttl']);
    }

    /**
     * Ein Wert, der eine Fluchtregel bräuchte, wird abgewiesen.
     *
     * Ein ACME-Prüfwert ist Base64 ohne Polster und enthält weder
     * Anführungszeichen noch Rückstrich. Was beides doch enthält, halb richtig
     * zu verpacken, hiesse eine eigene kleine Sprache zu schreiben — mit
     * eigenen Fehlern, die erst beim Ausstellen auffallen.
     */
    public function test_a_value_that_would_need_escaping_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('lässt sich nicht als TXT-Eintrag schreiben');

        $this->provider($http)->add('_acme-challenge.example.de', 'mit"zitat');
    }

    /** Beim Löschen geht der Wert mit — sonst fällt die Prüfung eines anderen Vorgangs mit. */
    public function test_removing_names_the_value(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']), self::action('success'));

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $this->assertStringEndsWith('/actions/remove_records', $http->calls[1]['url']);

        $body = json_decode((string) $http->calls[1]['body'], true);

        $this->assertSame('"genau-dieser"', $body['records'][0]['value']);
    }

    /**
     * `running` ist kein Fehlschlag.
     *
     * Der Auftrag läuft noch, und ob der Eintrag ausgeliefert wird, beantwortet
     * ohnehin erst die Abfrage der autoritativen Nameserver. Wer hier abbricht,
     * bricht jeden zweiten Vorgang ab.
     */
    public function test_a_running_action_is_not_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']), self::action('running'));

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertCount(2, $http->calls);
    }

    /** `error` dagegen schon — und die Begründung steht im Auftrag, nicht im Status. */
    public function test_a_failed_action_says_why(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de']),
            self::action('error', 'Die Zone ist geschützt'),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Die Zone ist geschützt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /**
     * Ein zurückgewiesenes Token nennt die andere Schnittstelle.
     *
     * Das ist der teuerste Fall dieses Anbieters: Die Antwort sagt nur
     * „unauthorized", und wer das liest, sucht den Fehler beim Token statt bei
     * der Schnittstelle — und trägt dasselbe Token noch einmal ein.
     *
     * @param  int  $status
     */
    #[DataProvider('refusals')]
    public function test_a_refused_token_names_the_other_api(int $status): void
    {
        $http = (new ScriptedOutbound)->on(ScriptedOutbound::json(['error' => ['message' => 'unauthorized']], $status));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('dns.hetzner.com');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** @return list<array{0: int}> */
    public static function refusals(): array
    {
        return [[401], [403]];
    }

    /** Drosselung sagt, dass sie eine ist. */
    public function test_throttling_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(ScriptedOutbound::json(['error' => ['message' => 'zu schnell']], 429));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('drosselt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** Die Begründung kommt mit ihrer Kennung — danach sucht jemand in der Dokumentation. */
    public function test_the_reason_carries_its_code(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de']),
            ScriptedOutbound::json(['error' => ['code' => 'not_found', 'message' => 'Zone nicht gefunden']], 404),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone nicht gefunden (not_found)');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /**
     * Über mehrere Seiten wird geblättert.
     *
     * Ein Projekt mit mehr als fünfzig Zonen ist bei einem Hoster der Normalfall
     * — und wer nur die erste Seite liest, meldet für die einundfünfzigste
     * Zone „führt keine Zone", obwohl sie dasteht.
     */
    public function test_the_zones_are_read_across_pages(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['erste.de'], 2),
            self::zones(['example.de']),
            self::action('success'),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertStringContainsString('page=1', $http->calls[0]['url']);
        $this->assertStringContainsString('page=2', $http->calls[1]['url']);
        $this->assertStringContainsString('/rrsets/_acme-challenge/', $http->calls[2]['url']);
    }

    /**
     * Und die Schleife hält an, wenn die Auskunft im Kreis zeigt.
     *
     * **Dieser Fall ist beim Bauen wirklich aufgetreten.** Die erste Fassung
     * der Schleife verglich die Seitennummer mit der Obergrenze statt die
     * Runden zu zählen; ein `next_page`, das auf die laufende Seite zurückzeigt,
     * hielt die Bedingung damit für immer erfüllt. Gefunden hat es kein Test,
     * sondern eine Wegwerfprobe, die nicht zurückkam — ein Test hätte an dieser
     * Stelle die CI blockiert statt rot zu werden.
     *
     * Gemeldet wird der Abbruch und nicht verschwiegen: Wer hier still aufhört,
     * sagt gleich darauf „für diesen Namen keine Zone" und nennt damit einen
     * Grund, der nicht stimmt.
     */
    public function test_a_pagination_that_points_in_circles_is_reported(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de'], 1));

        try {
            $this->provider($http)->add('_acme-challenge.example.de', 'wert');
            $this->fail('Die Blätterschleife hat den Kreis nicht gemeldet.');
        } catch (AgentException $exception) {
            $this->assertStringContainsString('hört nach', $exception->getMessage());
        }

        $seiten = array_filter($http->calls, static fn (array $call): bool => str_contains($call['url'], '/zones?'));

        $this->assertCount(Hetzner::MAX_PAGES, $seiten, 'Es wurde nicht genau bis zur Obergrenze geblättert.');
    }

    public function test_a_name_outside_every_zone_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone');

        $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
    }

    /** Das Token geht als Kopfzeile hinaus — und nur dorthin. */
    public function test_the_token_travels_as_a_header(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']), self::action('success'));

        $this->provider($http, 'streng-geheim')->add('_acme-challenge.example.de', 'wert');

        foreach ($http->calls as $call) {
            $this->assertContains('Authorization: Bearer streng-geheim', $call['headers']);
            $this->assertStringNotContainsString('streng-geheim', $call['url']);
            $this->assertStringNotContainsString('streng-geheim', (string) $call['body']);
        }
    }

    /** Die Zonen werden einmal geholt und nicht je Aufruf. */
    public function test_the_zones_are_fetched_once(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de']), self::action('success'));

        $provider = $this->provider($http);
        $provider->add('_acme-challenge.example.de', 'wert');
        $provider->remove('_acme-challenge.example.de', 'wert');

        $fragen = array_filter($http->calls, static fn (array $call): bool => str_contains($call['url'], '/zones?'));

        $this->assertCount(1, $fragen);
    }

    /** @return list<array{0: mixed}> */
    public static function badTokens(): array
    {
        return [[''], ['   '], ["mit\nZeilenumbruch"], ['kurz'], [null], [42]];
    }

    /**
     * @param  mixed  $token
     */
    #[DataProvider('badTokens')]
    public function test_a_token_that_is_none_is_refused($token): void
    {
        $this->expectException(AgentException::class);

        Hetzner::configure(['token' => $token]);
    }

    public function test_a_usable_token_is_kept_as_it_is(): void
    {
        $this->assertSame(['token' => 'abcdefgh12345'], Hetzner::configure(['token' => '  abcdefgh12345  ']));
    }
}
