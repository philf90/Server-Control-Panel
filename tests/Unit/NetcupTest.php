<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Netcup;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedOutbound;

/**
 * netcup — der erste Anbieter mit einer Sitzung, und der erste ohne Token.
 *
 * **Die Sitzung ist der Grund, warum dieser Test die Reihenfolge prüft und
 * nicht nur den Inhalt.** An- und abgemeldet wird je Vorgang; bleibt das
 * Abmelden aus, häufen sich Sitzungen bei einem fremden Anbieter an, und zwar
 * genau dann, wenn etwas schiefging. Umgekehrt darf ein gescheitertes Abmelden
 * einen gesetzten Eintrag nicht zum Fehlschlag machen — sonst wird ein Vorgang
 * wiederholt, der durchgelaufen ist.
 *
 * **Und geschrieben wird nur der eine Satz.** lego liest an dieser Stelle die
 * ganze Zone, hängt an und schickt alles zurück. Das ist ein
 * Lesen-Ändern-Schreiben über den Bestand eines Kunden; dass es unnötig ist,
 * zeigt legos eigenes `CleanUp`, das genau einen Satz schickt.
 */
final class NetcupTest extends TestCase
{
    /** @param  array<string, mixed>  $more */
    private function provider(ScriptedOutbound $http, array $more = []): Netcup
    {
        return Netcup::fromConfig($more + [
            'customer_number' => '12345',
            'api_key' => 'schluessel-abc',
            'api_password' => 'passwort-xyz',
            'zones' => ['example.de'],
        ], $http);
    }

    /** @param  array<string, mixed>  $responsedata */
    private static function ok(array $responsedata = []): Response
    {
        return ScriptedOutbound::json([
            'status' => 'success',
            'statuscode' => 2000,
            'shortmessage' => 'ok',
            'longmessage' => '',
            'responsedata' => $responsedata,
        ]);
    }

    private static function login(string $session = 'sitzung-1'): Response
    {
        return self::ok(['apisessionid' => $session]);
    }

    /** @param  list<array<string, string>>  $records */
    private static function records(array $records): Response
    {
        return self::ok(['dnsrecords' => $records]);
    }

    private static function failure(string $long, int $code = 4013): Response
    {
        return ScriptedOutbound::json([
            'status' => 'error',
            'statuscode' => $code,
            'shortmessage' => 'Validation Error',
            'longmessage' => $long,
        ]);
    }

    /** @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call */
    private static function action(array $call): string
    {
        $body = json_decode((string) $call['body'], true);

        return is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : '?';
    }

    /**
     * @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call
     * @return array<string, mixed>
     */
    private static function param(array $call): array
    {
        $body = json_decode((string) $call['body'], true);

        return is_array($body) && is_array($body['param'] ?? null) ? $body['param'] : [];
    }

    public function test_a_write_is_login_write_logout(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::ok(), self::ok());

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertSame(
            ['login', 'updateDnsRecords', 'logout'],
            array_map(self::action(...), $http->calls),
        );

        $this->assertSame('sitzung-1', self::param($http->calls[1])['apisessionid']);

        // Das Passwort geht **nur** in die Anmeldung. In jedem weiteren Rumpf
        // stünde es ohne Not ein zweites Mal auf der Leitung.
        $this->assertSame('passwort-xyz', self::param($http->calls[0])['apipassword']);
        $this->assertArrayNotHasKey('apipassword', self::param($http->calls[1]));
    }

    /**
     * Geschrieben wird ein Satz — nicht die Zone.
     *
     * lego liest hier alle Einträge, hängt den neuen an und schickt alles
     * zurück. Für ein Panel, das fremde Zonen anfasst, ist das der teure Weg:
     * Geht beim Lesen etwas schief oder ändert jemand dazwischen etwas, steht
     * der Bestand eines Kunden auf dem Spiel.
     */
    public function test_only_the_one_record_is_written(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::ok(), self::ok());

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertNotContains('infoDnsRecords', array_map(self::action(...), $http->calls));

        $records = self::param($http->calls[1])['dnsrecordset']['dnsrecords'];

        $this->assertCount(1, $records);
        $this->assertSame('_acme-challenge', $records[0]['hostname']);
        $this->assertSame('TXT', $records[0]['type']);

        // **Ohne Anführungszeichen.** netcup nimmt den nackten Wert — anders als
        // Hetzner und Cloudflare, die die character-string-Form erwarten.
        $this->assertSame('wert', $records[0]['destination']);
        $this->assertArrayNotHasKey('id', $records[0]);
    }

    /**
     * Gelöscht wird der eigene Satz — und dazu zählt der Name.
     *
     * lego vergleicht beim Suchen nur Wert und Art. Stehen zwei Prüfeinträge
     * mit demselben Wert unter verschiedenen Namen, ist das der falsche Satz.
     *
     * **Der fremde Name steht vor dem eigenen, und das ist der ganze Test.**
     * Bis zum 10. August 2026 stand er dahinter — und damit prüfte hier nichts
     * mehr den Namen: `find()` gibt den ersten Treffer zurück, und der erste
     * Treffer war auch ohne Namensabgleich der richtige. Der Bruch, der die
     * Zeile aus `Netcup.php` entfernt, lief grün durch.
     *
     * > **Ein Test, dessen Beispiel in der richtigen Reihenfolge steht, prüft
     * > die Reihenfolge und nicht die Regel.**
     */
    public function test_removing_matches_name_and_value(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::records([
                ['id' => '1', 'hostname' => '_acme-challenge', 'type' => 'TXT', 'destination' => 'ein-anderer'],
                ['id' => '2', 'hostname' => 'www', 'type' => 'TXT', 'destination' => 'genau-dieser'],
                ['id' => '3', 'hostname' => '_acme-challenge', 'type' => 'TXT', 'destination' => 'genau-dieser'],
            ]),
            self::ok(),
            self::ok(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'genau-dieser');

        $records = self::param($http->calls[2])['dnsrecordset']['dnsrecords'];

        $this->assertCount(1, $records);
        $this->assertSame('3', $records[0]['id'], 'Gelöscht wurde der Satz unter dem fremden Namen.');
        $this->assertTrue($records[0]['deleterecord']);
    }

    /** Nichts zu löschen ist kein Fehlschlag — `remove()` läuft auch nach einem. */
    public function test_nothing_to_remove_is_not_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::records([]), self::ok());

        $this->provider($http)->remove('_acme-challenge.example.de', 'weg');

        $this->assertSame(['login', 'infoDnsRecords', 'logout'], array_map(self::action(...), $http->calls));
    }

    /**
     * Ein gescheitertes Abmelden macht aus einem gesetzten Eintrag keinen Fehlschlag.
     *
     * Sonst würde ein Vorgang wiederholt, der durchgelaufen ist — und bei
     * Let's Encrypt zählt jeder Fehlversuch für alle Kunden dieses Servers.
     */
    public function test_a_failed_logout_does_not_fail_the_operation(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::ok(), self::failure('Session invalid', 4001));

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertCount(3, $http->calls);
    }

    /**
     * Und abgemeldet wird auch, wenn der Zugriff dazwischen scheitert.
     *
     * Sonst bliebe genau dann eine Sitzung liegen, wenn etwas schiefging.
     */
    public function test_the_session_is_closed_after_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::failure('Zone nicht gefunden'), self::ok());

        try {
            $this->provider($http)->add('_acme-challenge.example.de', 'wert');
            $this->fail('Der Fehlschlag wurde nicht weitergegeben.');
        } catch (AgentException $exception) {
            $this->assertStringContainsString('Zone nicht gefunden (4013)', $exception->getMessage());
        }

        $this->assertSame('logout', self::action($http->calls[2]));
    }

    /** netcup antwortet auf einen Fehlschlag mit HTTP 200 — der Zustand steht im Rumpf. */
    public function test_the_status_field_counts_not_the_http_code(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::failure('Kein Zugriff', 5011));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Kein Zugriff (5011)');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_a_login_without_a_session_id_is_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::ok([]));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Sitzungskennung');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /**
     * Ein Name ausserhalb der hinterlegten Zonen wird gar nicht erst versucht.
     *
     * Das ist der Sinn der Positivliste: kein Aufruf, keine Anmeldung, kein
     * verbrauchter Fehlversuch.
     */
    public function test_a_name_outside_the_zones_costs_no_call(): void
    {
        $http = new ScriptedOutbound;

        try {
            $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
            $this->fail('Der fremde Name wurde nicht abgewiesen.');
        } catch (AgentException $exception) {
            $this->assertStringContainsString('keine Zone hinterlegt', $exception->getMessage());
        }

        $this->assertSame([], $http->calls);
    }

    public function test_the_longest_stored_zone_wins(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::ok(), self::ok());

        $this->provider($http, ['zones' => ['example.de', 'kunde.example.de']])
            ->add('_acme-challenge.kunde.example.de', 'wert');

        $param = self::param($http->calls[1]);

        $this->assertSame('kunde.example.de', $param['domainname']);
        $this->assertSame('_acme-challenge', $param['dnsrecordset']['dnsrecords'][0]['hostname']);
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    public static function badConfigs(): array
    {
        return [
            [['customer_number' => ''], 'Kundennummer'],
            [['customer_number' => 'abc'], 'Kundennummer'],
            [['customer_number' => '12 345'], 'Kundennummer'],
            [['api_key' => ''], 'API-Schlüssel'],
            [['api_key' => "mit\nUmbruch"], 'API-Schlüssel'],
            [['api_password' => ''], 'API-Passwort'],
            [['api_password' => "mit\tTab"], 'API-Passwort'],
            [['zones' => []], 'keine Zone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('badConfigs')]
    public function test_credentials_are_checked_when_they_are_stored(array $config, string $expected): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage($expected);

        Netcup::configure($config + [
            'customer_number' => '12345',
            'api_key' => 'schluessel-abc',
            'api_password' => 'passwort-xyz',
            'zones' => ['example.de'],
        ]);
    }

    public function test_the_checked_form_is_what_gets_stored(): void
    {
        $checked = Netcup::configure([
            'customer_number' => '12345',
            'api_key' => 'schluessel-abc',
            'api_password' => 'passwort-xyz',
            'zones' => ['Example.DE.', 'example.de'],
        ]);

        $this->assertSame(['example.de'], $checked['zones']);
        $this->assertSame('12345', $checked['customer_number']);
    }
}
