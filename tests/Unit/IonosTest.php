<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Ionos;
use SrvPanel\Agent\Acme\Dns\Zones;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * IONOS — ein Feld, das in Wahrheit zwei ist.
 *
 * **Der Schlüssel hat die Form `<präfix>.<geheimnis>`**, und IONOS zeigt beide
 * Teile getrennt an. Wer nur den Präfix einträgt — er steht dort obenan —,
 * bekommt eine Abweisung, die von einem ungültigen Schlüssel spricht. Das ist
 * eine Prüfung, die beim Hinterlegen zwei Zeilen kostet und sonst eine
 * nächtliche Erneuerung.
 *
 * **`suffix` ist ein Suffix und kein Name.** Der Filter von IONOS liefert auch
 * `x.<name>` mit; was nicht genau dieser Name ist, gehört weder in die Liste,
 * die zurückgeschickt wird, noch in die Auswahl beim Löschen.
 */
final class IonosTest extends TestCase
{
    private const KEY = 'praefix.geheimnis';

    private function provider(ScriptedOutbound $http): Ionos
    {
        return Ionos::fromConfig(['api_key' => self::KEY], $http);
    }

    /** @param  array<string, string>  $zones  Name → Kennung */
    private static function zones(array $zones): Response
    {
        $list = [];

        foreach ($zones as $name => $id) {
            $list[] = ['id' => $id, 'name' => $name, 'type' => 'NATIVE'];
        }

        return ScriptedOutbound::json($list);
    }

    /** @param  list<array<string, string>>  $records */
    private static function records(array $records): Response
    {
        return ScriptedOutbound::json(['id' => 'z1', 'name' => 'example.de', 'records' => $records]);
    }

    /** Eine Antwort ohne Inhalt — so quittiert IONOS ein Ändern oder Löschen. */
    private static function empty(): Response
    {
        return new Response(200, ['content-type' => 'application/json'], '');
    }

    private static function failure(int $status, string $message, string $code = 'INVALID'): Response
    {
        return ScriptedOutbound::json(['errors' => [['code' => $code, 'message' => $message]]], $status);
    }

    /** @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call */
    private static function body(array $call): mixed
    {
        return json_decode((string) $call['body'], true);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function badKeys(): array
    {
        return [
            ['', 'fehlt'],
            ['   ', 'fehlt'],
            ["mit\nUmbruch", 'Kopfzeile'],
            ['kurz', 'Kopfzeile'],
            ['ohnepunkt12345', 'zwei Teilen'],
            ['.geheimnis123', 'zwei Teilen'],
            ['praefix12345.', 'zwei Teilen'],
            ['a.b.c12345678', 'zwei Teilen'],
        ];
    }

    /**
     * Ein halber Schlüssel wird beim Hinterlegen abgewiesen.
     *
     * Der häufigste Fall ist der Präfix allein: Er steht in der Oberfläche von
     * IONOS obenan und sieht aus wie der Schlüssel.
     */
    #[DataProvider('badKeys')]
    public function test_a_key_that_is_not_two_parts_is_refused(string $key, string $expected): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage($expected);

        Ionos::configure(['api_key' => $key]);
    }

    public function test_a_usable_key_is_kept_as_it_is(): void
    {
        $this->assertSame(['api_key' => self::KEY], Ionos::configure(['api_key' => '  '.self::KEY.'  ']));
    }

    public function test_a_write_asks_for_zones_reads_and_patches(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([]),
            self::empty(),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertStringEndsWith('/zones', $http->calls[0]['url']);
        $this->assertStringContainsString('/zones/zone-1?suffix=', $http->calls[1]['url']);
        $this->assertStringContainsString('recordType=TXT', $http->calls[1]['url']);
        $this->assertSame('PATCH', $http->calls[2]['method']);

        $records = self::body($http->calls[2]);

        $this->assertCount(1, $records);
        $this->assertSame('_acme-challenge.example.de', $records[0]['name']);
        $this->assertSame('TXT', $records[0]['type']);
        $this->assertSame(Ionos::TTL, $records[0]['ttl']);

        // **Ohne Anführungszeichen**, wie legos `Present` es schickt.
        $this->assertSame('wert', $records[0]['content']);
    }

    /**
     * Die vorhandenen Sätze zu diesem Namen gehen mit.
     *
     * **Hier folgen wir lego bewusst.** Ob `PATCH` die Sätze hinzufügt oder den
     * Bestand zu diesem Namen ersetzt, geht aus legos Code nicht hervor. Der
     * Weg, der unter beiden Lesarten richtig ist, ist dieser — und die Kosten
     * sind ein Lesen, das ohnehin gefiltert ist.
     */
    public function test_existing_records_for_the_same_name_are_sent_along(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([
                ['id' => 'r1', 'name' => '_acme-challenge.example.de', 'type' => 'TXT', 'content' => 'anderer'],
            ]),
            self::empty(),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $records = self::body($http->calls[2]);

        $this->assertCount(2, $records);
        $this->assertSame('r1', $records[0]['id']);
        $this->assertSame('wert', $records[1]['content']);
    }

    /**
     * `suffix` ist ein Suffix — was nur darauf endet, fällt raus.
     *
     * Ohne diesen Abgleich wanderte `x._acme-challenge.example.de` in die
     * Liste, die zurückgeschickt wird, und beim Löschen in die Auswahl.
     */
    public function test_a_name_that_merely_ends_the_same_is_dropped(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([
                ['id' => 'r1', 'name' => 'x._acme-challenge.example.de', 'type' => 'TXT', 'content' => 'fremd'],
                ['id' => 'r2', 'name' => '_acme-challenge.example.de', 'type' => 'TXT', 'content' => 'meiner'],
            ]),
            self::empty(),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $records = self::body($http->calls[2]);

        $this->assertCount(2, $records);
        $this->assertSame('r2', $records[0]['id']);
    }

    /** Gelöscht wird über die Kennung — und der Wert entscheidet, welche. */
    public function test_removing_deletes_only_the_matching_record(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([
                ['id' => 'r1', 'name' => '_acme-challenge.example.de', 'type' => 'TXT', 'content' => 'ein-anderer'],
                ['id' => 'r2', 'name' => '_acme-challenge.example.de', 'type' => 'TXT', 'content' => '"genau-dieser"'],
            ]),
            self::empty(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $this->assertCount(3, $http->calls);
        $this->assertSame('DELETE', $http->calls[2]['method']);
        $this->assertStringEndsWith('/records/r2', $http->calls[2]['url']);
    }

    /**
     * Und der Wert wird in beiden Formen wiedererkannt.
     *
     * legos `Present` schickt ihn nackt, sein `CleanUp` sucht ihn in
     * Anführungszeichen — eines von beidem stimmt nicht, und welches, hängt
     * daran, ob IONOS beim Ablegen umschreibt. Beide zu nehmen macht die Frage
     * für uns zu keiner.
     */
    public function test_both_forms_of_the_value_are_recognised(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([
                ['id' => 'r9', 'name' => '_acme-challenge.example.de', 'type' => 'TXT', 'content' => 'nackt'],
            ]),
            self::empty(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'nackt');

        $this->assertStringEndsWith('/records/r9', $http->calls[2]['url']);
    }

    /**
     * Nichts zu löschen ist kein Fehlschlag.
     *
     * lego wirft hier — und macht damit aus einem Fehlschlag zwei, denn
     * `cleanup()` läuft auch nach einer gescheiterten Bestellung.
     */
    public function test_nothing_to_remove_is_not_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'zone-1']), self::records([]));

        $this->provider($http)->remove('_acme-challenge.example.de', 'weg');

        $this->assertCount(2, $http->calls);
    }

    public function test_the_reason_carries_its_code(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([]),
            self::failure(400, 'Zone gesperrt', 'ZONE_LOCKED'),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Zone gesperrt (ZONE_LOCKED)');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** @return list<array{0: int}> */
    public static function refusals(): array
    {
        return [[401], [403]];
    }

    /** Ein zurückgewiesener Schlüssel nennt die Form, an der es meistens liegt. */
    #[DataProvider('refusals')]
    public function test_a_refused_key_names_its_shape(int $status): void
    {
        $http = (new ScriptedOutbound)->on(self::failure($status, 'unauthorized'));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Präfix und Geheimnis');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_throttling_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(self::failure(429, 'zu schnell'));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('drosselt');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_the_longest_matching_zone_wins(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'z1', 'kunde.example.de' => 'z2']),
            self::records([]),
            self::empty(),
        );

        $this->provider($http)->add('_acme-challenge.kunde.example.de', 'wert');

        $this->assertStringContainsString('/zones/z2', $http->calls[1]['url']);
    }

    /**
     * Und ein Name, der nur genauso endet, liegt nicht in der Zone.
     *
     * IONOS selbst vergleicht an dieser Stelle mit `strings.HasSuffix` —
     * `boesexample.de` endet auf `example.de` und gehört trotzdem jemand
     * anderem. {@see Zones} vergleicht beschriftungsweise.
     */
    public function test_a_name_that_merely_ends_like_a_zone_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::zones(['example.de' => 'z1']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone');

        $this->provider($http)->add('_acme-challenge.boesexample.de', 'wert');
    }

    public function test_the_key_travels_as_a_header(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([]),
            self::empty(),
        );

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        foreach ($http->calls as $call) {
            $this->assertContains('X-Api-Key: '.self::KEY, $call['headers']);
            $this->assertStringNotContainsString('geheimnis', $call['url']);
        }
    }

    public function test_the_zones_are_fetched_once(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::zones(['example.de' => 'zone-1']),
            self::records([]),
            self::empty(),
            self::records([]),
        );

        $provider = $this->provider($http);
        $provider->add('_acme-challenge.example.de', 'wert');
        $provider->remove('_acme-challenge.example.de', 'wert');

        $fragen = array_filter($http->calls, static fn (array $call): bool => str_ends_with($call['url'], '/zones'));

        $this->assertCount(1, $fragen);
    }
}
