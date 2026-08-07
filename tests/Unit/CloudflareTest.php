<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Cloudflare;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * Cloudflare — und die drei Stellen, an denen dieser Anbieter anders ist.
 *
 * **Erstens: gelöscht wird über eine Kennung, nicht über den Wert.** lego merkt
 * sie sich beim Anlegen in einer Ablage; wir suchen sie beim Abräumen. Der
 * Grund ist, dass `cleanup()` auch nach einem Fehlschlag läuft — dann ist die
 * Ablage leer, und lego bricht mit „unknown record ID" ab.
 *
 * **Zweitens: `success` zählt und nicht nur der HTTP-Code.** Cloudflare
 * antwortet auf einen abgelehnten Vorgang durchaus mit 200 und
 * `"success": false`.
 *
 * **Drittens: die Filter der Schnittstelle sind nicht auf Gross- und
 * Kleinschreibung bedacht, ein ACME-Prüfwert aber sehr wohl.** Deshalb wird der
 * Wert nach dem Suchen noch einmal Zeichen für Zeichen verglichen.
 */
final class CloudflareTest extends TestCase
{
    private function provider(ScriptedOutbound $http, string $token = 'geheim-token-123'): Cloudflare
    {
        return new Cloudflare($token, $http);
    }

    /**
     * Eine Seite Zonen, wie Cloudflare sie schickt.
     *
     * @param  array<string, string>  $zones  Name → Kennung
     */
    private static function zones(array $zones, int $totalPages = 1): Response
    {
        $result = [];

        foreach ($zones as $name => $id) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return ScriptedOutbound::json([
            'success' => true,
            'errors' => [],
            'result' => $result,
            'result_info' => ['page' => 1, 'total_pages' => $totalPages],
        ]);
    }

    /** @param  list<array<string, string>>  $entries */
    private static function records(array $entries): Response
    {
        return ScriptedOutbound::json(['success' => true, 'errors' => [], 'result' => $entries]);
    }

    private static function ok(): Response
    {
        return ScriptedOutbound::json(['success' => true, 'errors' => [], 'result' => ['id' => 'neu']]);
    }

    private static function failure(int $status, string $message, int $code = 0): Response
    {
        return ScriptedOutbound::json([
            'success' => false,
            'errors' => [['code' => $code, 'message' => $message]],
            'result' => null,
        ], $status);
    }

    public function test_the_zone_comes_from_the_account_and_the_name_is_whole(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1']), self::ok());

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertStringContainsString('/zones?page=1', $http->calls[0]['url']);
        $this->assertSame(Cloudflare::ENDPOINT.'/zones/zone-1/dns_records', $http->calls[1]['url']);

        $body = json_decode((string) $http->calls[1]['body'], true);

        // **Der volle Name, nicht der Präfix.** Das ist der Unterschied zu
        // Hetzner, und wer ihn übersieht, legt den Eintrag unter
        // `_acme-challenge.example.de.example.de` an.
        $this->assertSame('_acme-challenge.example.de', $body['name']);
        $this->assertSame('"wert"', $body['content']);
        $this->assertSame('TXT', $body['type']);
        $this->assertSame(Cloudflare::TTL, $body['ttl']);
    }

    /**
     * Gelöscht wird der eigene Eintrag — und nur der.
     *
     * Laufen zwei Bestellungen für dieselbe Zone, stehen zwei
     * `_acme-challenge`-Einträge unter demselben Namen. Wer nach dem Namen
     * allein löscht, räumt die Prüfung des anderen Vorgangs mit ab.
     */
    public function test_removing_deletes_only_the_matching_record(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([
                ['id' => 'rec-fremd', 'content' => '"ein-anderer"'],
                ['id' => 'rec-meiner', 'content' => '"genau-dieser"'],
            ]),
            self::ok(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $this->assertStringContainsString('type=TXT', $http->calls[1]['url']);
        $this->assertStringContainsString('name.exact=', $http->calls[1]['url']);

        $this->assertSame('DELETE', $http->calls[2]['method']);
        $this->assertStringEndsWith('/dns_records/rec-meiner', $http->calls[2]['url']);
        $this->assertCount(3, $http->calls, 'Der fremde Eintrag wurde mit angefasst.');
    }

    /**
     * Ein Wert, der nur anders geschrieben ist, ist ein anderer Wert.
     *
     * Cloudflares Filter sind ausdrücklich nicht auf Gross- und
     * Kleinschreibung bedacht; ein ACME-Prüfwert ist Base64 und damit sehr
     * wohl. Ohne den zweiten Vergleich löschte diese Anfrage einen fremden
     * Eintrag.
     */
    public function test_a_value_that_differs_only_in_case_is_not_deleted(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([['id' => 'rec-fast', 'content' => '"GENAU-DIESER"']]),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $this->assertCount(2, $http->calls);
    }

    /**
     * Und nichts zu finden ist kein Fehlschlag.
     *
     * `cleanup()` läuft auch, wenn die Bestellung vorher gescheitert ist — dann
     * gibt es den Eintrag gar nicht. Ein Fehler an dieser Stelle machte aus
     * einem Fehlschlag zwei.
     */
    public function test_nothing_to_remove_is_not_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1']), self::records([]));

        $this->provider($http)->remove('_acme-challenge.example.de', 'weg');

        $this->assertCount(2, $http->calls);
    }

    /** Ein abgelehnter Vorgang mit HTTP 200 ist abgelehnt. */
    public function test_success_false_counts_even_with_http_200(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            ScriptedOutbound::json([
                'success' => false,
                'errors' => [['code' => 81057, 'message' => 'Record already exists']],
                'result' => null,
            ]),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Record already exists (81057)');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** @return list<array{0: int}> */
    public static function refusals(): array
    {
        return [[401], [403]];
    }

    /**
     * Ein zurückgewiesenes Token nennt die Rechte, die es braucht.
     *
     * Ein Token ohne `Zone:Read` sieht keine Zone, eines ohne `DNS:Edit` darf
     * nichts schreiben — und beide Male sagt Cloudflare nur „Authentication
     * error". Wer das liest, hält das Token für falsch und legt ein neues an,
     * mit denselben Rechten.
     */
    #[DataProvider('refusals')]
    public function test_a_refused_token_names_the_permissions(int $status): void
    {
        $http = (new ScriptedOutbound)->on(self::failure($status, 'Authentication error', 10000));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone:Read und DNS:Edit');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_throttling_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(self::failure(429, 'zu schnell'));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('drosselt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** Ein Token, das keine Zone sieht, hat kein Rechteproblem mit der Zone, sondern mit Zone:Read. */
    public function test_an_empty_zone_list_names_the_missing_permission(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones([]));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone:Read');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_the_zones_are_read_across_pages(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['erste.de' => 'z0'], 2),
            self::zones(['example.de' => 'zone-2'], 2),
            self::ok(),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertStringContainsString('page=2', $http->calls[1]['url']);
        $this->assertStringContainsString('/zones/zone-2/', $http->calls[2]['url']);
    }

    /**
     * Und die Schleife hält an, wenn die Blätterauskunft nicht aufhört.
     *
     * Cloudflare nennt keine „nächste Seite", sondern die Gesamtzahl der
     * Seiten. Eine Auskunft, die dauernd mehr verspricht, als sie liefert,
     * hielte hier einen Prozess mit Systemrechten am Laufen — gemeldet wird der
     * Abbruch, nicht verschwiegen.
     */
    public function test_a_pagination_that_never_ends_is_reported(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1'], 99));

        try {
            $this->provider($http)->add('_acme-challenge.example.de', 'wert');
            $this->fail('Die Blätterschleife hat den Abbruch nicht gemeldet.');
        } catch (AgentException $exception) {
            $this->assertStringContainsString('hört nach', $exception->getMessage());
        }

        $seiten = array_filter($http->calls, static fn (array $call): bool => str_contains($call['url'], '/zones?'));

        $this->assertCount(Cloudflare::MAX_PAGES, $seiten);
    }

    public function test_a_name_outside_every_zone_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone');

        $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
    }

    /** Das Token geht als Kopfzeile hinaus — und der globale Schlüssel nirgends hin. */
    public function test_the_token_travels_as_a_header_and_no_global_key_is_sent(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1']), self::ok());

        $this->provider($http, 'streng-geheim')->add('_acme-challenge.example.de', 'wert');

        foreach ($http->calls as $call) {
            $this->assertContains('Authorization: Bearer streng-geheim', $call['headers']);
            $this->assertStringNotContainsString('streng-geheim', $call['url']);
            $this->assertStringNotContainsString('streng-geheim', (string) $call['body']);

            foreach ($call['headers'] as $header) {
                $this->assertStringStartsNotWith('X-Auth-', $header, 'Der globale Schlüssel wird hier nicht benutzt.');
            }
        }
    }

    public function test_the_zones_are_fetched_once(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::ok(),
            self::records([]),
        );

        $provider = $this->provider($http);
        $provider->add('_acme-challenge.example.de', 'wert');
        $provider->remove('_acme-challenge.example.de', 'wert');

        $fragen = array_filter($http->calls, static fn (array $call): bool => str_contains($call['url'], '/zones?page='));

        $this->assertCount(1, $fragen);
    }

    /** @return list<array{0: mixed}> */
    public static function badTokens(): array
    {
        return [[''], ['   '], ["mit\nZeilenumbruch"], ['kurz'], [null], [42]];
    }

    #[DataProvider('badTokens')]
    public function test_a_token_that_is_none_is_refused(mixed $token): void
    {
        $this->expectException(AgentException::class);

        Cloudflare::configure(['token' => $token]);
    }

    public function test_a_usable_token_is_kept_as_it_is(): void
    {
        $this->assertSame(['token' => 'abcdefgh12345'], Cloudflare::configure(['token' => '  abcdefgh12345  ']));
    }

    /**
     * Die Kontoadresse wird abgewiesen und nicht stillschweigend fallengelassen.
     *
     * Wer sie einträgt, will den globalen API-Schlüssel benutzen — den, der das
     * ganze Konto öffnet. Ihn zu ignorieren hiesse, etwas anderes entgegen-
     * zunehmen, als der Betreiber gemeint hat; die Abweisung käme dann von
     * Cloudflare, mit einem Satz, der den Grund nicht nennt.
     */
    public function test_an_account_address_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('nur ein API-Token');

        Cloudflare::configure(['token' => 'abcdefgh12345', 'email' => 'wer@example.de']);
    }
}
