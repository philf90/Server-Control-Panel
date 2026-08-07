<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Inwx;
use SrvPanel\Agent\Acme\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Totp;
use Tests\Support\ScriptedOutbound;

/**
 * INWX — der teuerste der acht.
 *
 * **Der Punkt, an dem sich dieser Anbieter entscheidet, ist der TAN.** INWX
 * nimmt denselben kein zweites Mal; lego wartet deshalb notfalls dreissig
 * Sekunden auf den nächsten Zeitschritt. Hier wird stattdessen **einmal je
 * Bestellung** angemeldet — Anlegen und Abräumen benutzen dieselbe Instanz.
 * Dieser Test prüft genau das: eine Anmeldung, ein `unlock`, ein `logout`.
 *
 * Ein Schlaf im Agenten wäre eine halbe Minute, in der ein Prozess mit
 * Systemrechten nichts tut und sein Zeitlimit näherkommt.
 */
final class InwxTest extends TestCase
{
    /** Das Geheimnis aus RFC 6238 Anhang B — damit der TAN nachrechenbar ist. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @param  array<string, mixed>  $more */
    private function provider(ScriptedOutbound $http, array $more = []): Inwx
    {
        return Inwx::fromConfig($more + ['username' => 'wer', 'password' => 'geheim'], $http);
    }

    private static function member(string $name, string $type, string $value): string
    {
        return '<member><name>'.$name.'</name><value><'.$type.'>'.$value.'</'.$type.'></value></member>';
    }

    /** @param  array<string, string>  $headers */
    private static function ok(string $resData = '', int $code = 1000, array $headers = []): Response
    {
        $inner = self::member('code', 'int', (string) $code).
            self::member('msg', 'string', 'Command completed successfully');

        if ($resData !== '') {
            $inner .= '<member><name>resData</name><value><struct>'.$resData.'</struct></value></member>';
        }

        return new Response(200, $headers, '<?xml version="1.0"?><methodResponse><params><param><value><struct>'.
            $inner.'</struct></value></param></params></methodResponse>');
    }

    private static function login(bool $tfa = false): Response
    {
        return self::ok(
            $tfa ? self::member('tfa', 'string', 'GOOGLE-AUTH') : self::member('customerId', 'int', '1'),
            1000,
            ['set-cookie' => 'domrobot=abc123; path=/; secure; HttpOnly'],
        );
    }

    /** @param  list<string>  $names */
    private static function zones(array $names): Response
    {
        $entries = '';

        foreach ($names as $name) {
            $entries .= '<value><struct>'.self::member('domain', 'string', $name).'</struct></value>';
        }

        return self::ok(
            self::member('count', 'int', (string) count($names)).
            '<member><name>domains</name><value><array><data>'.$entries.'</data></array></value></member>',
        );
    }

    /** @param  list<array{0: int, 1: string, 2: string}>  $records */
    private static function records(array $records): Response
    {
        $entries = '';

        foreach ($records as [$id, $name, $content]) {
            $entries .= '<value><struct>'.
                self::member('id', 'int', (string) $id).
                self::member('name', 'string', $name).
                self::member('content', 'string', $content).
                '</struct></value>';
        }

        return self::ok('<member><name>record</name><value><array><data>'.$entries.'</data></array></value></member>');
    }

    private static function failure(string $message, int $code = 2302, string $reason = ''): Response
    {
        $inner = self::member('code', 'int', (string) $code).self::member('msg', 'string', $message);

        if ($reason !== '') {
            $inner .= self::member('reason', 'string', $reason);
        }

        return new Response(200, [], '<?xml version="1.0"?><methodResponse><params><param><value><struct>'.
            $inner.'</struct></value></param></params></methodResponse>');
    }

    /** @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call */
    private static function method(array $call): string
    {
        preg_match('#<methodName>([^<]+)</methodName>#', (string) $call['body'], $found);

        return $found[1] ?? '?';
    }

    /** @param  array{method: string, url: string, headers: list<string>, body: ?string}  $call */
    private static function param(array $call, string $name): ?string
    {
        $pattern = '#<name>'.preg_quote($name, '#').'</name><value><(?:string|int)>([^<]*)</#';

        preg_match($pattern, (string) $call['body'], $found);

        return $found[1] ?? null;
    }

    /**
     * @param  list<array{method: string, url: string, headers: list<string>, body: ?string}>  $calls
     * @return list<string>
     */
    private static function methods(array $calls): array
    {
        return array_map(self::method(...), $calls);
    }

    public function test_a_write_logs_in_asks_for_zones_and_creates(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::zones(['example.de']), self::ok());

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertSame(
            ['account.login', 'nameserver.list', 'nameserver.createRecord'],
            self::methods($http->calls),
        );

        $this->assertSame('example.de', self::param($http->calls[2], 'domain'));
        $this->assertSame('_acme-challenge', self::param($http->calls[2], 'name'));
        $this->assertSame('wert', self::param($http->calls[2], 'content'));
    }

    /** Das Cookie kommt aus der Anmeldung und geht danach überall mit — vorher nirgends. */
    public function test_the_session_cookie_travels_after_the_login(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::zones(['example.de']), self::ok());

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertNotContains('Cookie: domrobot=abc123', $http->calls[0]['headers']);
        $this->assertContains('Cookie: domrobot=abc123', $http->calls[1]['headers']);

        // Das Passwort steht nur im Rumpf der Anmeldung.
        $this->assertStringNotContainsString('geheim', (string) $http->calls[2]['body']);
    }

    /**
     * Anlegen und Abräumen teilen sich **eine** Anmeldung.
     *
     * Das ist der Grund, warum hier nicht wie bei netcup je Aufruf an- und
     * abgemeldet wird: INWX nimmt denselben TAN kein zweites Mal, und zwei
     * Anmeldungen im selben Zeitschritt hätten denselben.
     */
    public function test_one_login_carries_the_whole_order(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::zones(['example.de']),
            self::ok(),
            self::records([[7, '_acme-challenge.example.de', 'wert']]),
            self::ok(),
            self::ok(),
        );

        $provider = $this->provider($http);
        $provider->add('_acme-challenge.example.de', 'wert');
        $provider->remove('_acme-challenge.example.de', 'wert');

        $methods = self::methods($http->calls);

        $this->assertCount(1, array_keys($methods, 'account.login', true));
        $this->assertCount(1, array_keys($methods, 'nameserver.list', true));
        $this->assertSame('account.logout', end($methods));
        $this->assertSame('7', self::param($http->calls[4], 'id'));
    }

    /**
     * Beim Suchen zählt der Name mit — und zwar hier und nicht nur im Filter.
     *
     * **Dieser Fall ist beim Bauen aufgefallen.** Der Kommentar versprach den
     * Abgleich, der Code machte ihn nicht: Gefiltert wurde allein über den
     * Parameter, den INWX bekommt. Dieselbe Lehre wie bei IONOS — was ein
     * Anbieter als Filter versteht, ist seine Sache; was gelöscht wird, ist
     * unsere.
     */
    public function test_removing_matches_the_name_as_well(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::zones(['example.de']),
            self::records([[9, 'www.example.de', 'wert']]),
            self::ok(),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'wert');

        $this->assertNotContains('nameserver.deleteRecord', self::methods($http->calls));
    }

    /**
     * Ein gesichertes Konto wird entsperrt, und der TAN stimmt.
     *
     * Gerechnet wird gegen {@see Totp} — dieselbe Stelle, die den zweiten
     * Faktor der Anmeldung rechnet. Eine zweite Umsetzung erzeugte Codes, die
     * manchmal stimmen.
     */
    public function test_a_secured_account_is_unlocked_with_a_matching_tan(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(true), self::ok(), self::zones(['example.de']), self::ok());

        $this->provider($http, ['shared_secret' => self::SECRET])->add('_acme-challenge.example.de', 'wert');

        $methods = self::methods($http->calls);

        $this->assertSame('account.unlock', $methods[1]);
        $this->assertCount(1, array_keys($methods, 'account.unlock', true));
        $this->assertSame(
            Totp::codeAt(self::SECRET, intdiv(time(), Totp::PERIOD)),
            self::param($http->calls[1], 'tan'),
        );
    }

    /** Und ohne zweiten Faktor wird nicht entsperrt. */
    public function test_an_account_without_a_second_factor_is_not_unlocked(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::zones(['example.de']), self::ok());

        $this->provider($http, ['shared_secret' => self::SECRET])->add('_acme-challenge.example.de', 'wert');

        $this->assertNotContains('account.unlock', self::methods($http->calls));
    }

    /** Fehlt das Geheimnis bei einem gesicherten Konto, sagt die Meldung genau das. */
    public function test_a_secured_account_without_a_secret_says_so(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(true));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('gemeinsame Geheimnis');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_a_login_without_a_session_is_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(new Response(
            200,
            [],
            '<?xml version="1.0"?><methodResponse><params><param><value><struct>'.
            self::member('code', 'int', '1000').'</struct></value></param></params></methodResponse>',
        ));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Sitzung');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** „Object exists" beim Anlegen ist kein Fehlschlag — der Eintrag steht dann schon da. */
    public function test_an_existing_object_is_not_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::zones(['example.de']), self::failure('Object exists'));

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');

        $this->assertCount(3, $http->calls);
    }

    /** Ein anderer Fehler dagegen schon, mit Begründung und Nummer. */
    public function test_another_failure_carries_its_reason_and_code(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::zones(['example.de']),
            self::failure('Object does not exist', 2303, 'Domain nicht im Konto'),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Domain nicht im Konto (2303)');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    public function test_a_name_outside_every_zone_is_refused(): void
    {
        $http = (new ScriptedOutbound)->on(self::login(), self::zones(['example.de']));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zone');

        $this->provider($http)->add('_acme-challenge.fremd.de', 'wert');
    }

    /**
     * Eine abgeschnittene Zonenliste wird gemeldet und nicht verschwiegen.
     *
     * Sonst fehlte still die gesuchte Zone, und die Meldung spräche von einem
     * Namen ausserhalb aller Zonen — ein Grund, der nicht stimmt.
     */
    public function test_a_truncated_zone_list_is_reported(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::ok(
                self::member('count', 'int', '5000').
                '<member><name>domains</name><value><array><data><value><struct>'.
                self::member('domain', 'string', 'example.de').
                '</struct></value></data></array></value></member>',
            ),
        );

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('geholt wurden');

        $this->provider($http)->add('_acme-challenge.example.de', 'wert');
    }

    /** Ein gescheitertes Abmelden macht aus einem abgeräumten Eintrag keinen Fehlschlag. */
    public function test_a_failed_logout_does_not_fail_the_operation(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::zones(['example.de']),
            self::records([]),
            self::failure('Session invalid', 2200),
        );

        $this->provider($http)->remove('_acme-challenge.example.de', 'weg');

        $this->assertCount(4, $http->calls);
    }

    /** Und abgemeldet wird auch, wenn der Zugriff dazwischen scheitert. */
    public function test_the_session_is_closed_after_a_failure(): void
    {
        $http = (new ScriptedOutbound)->on(
            self::login(),
            self::zones(['example.de']),
            self::failure('Object does not exist', 2303),
            self::ok(),
        );

        try {
            $this->provider($http)->remove('_acme-challenge.example.de', 'wert');
            $this->fail('Der Fehlschlag wurde nicht weitergegeben.');
        } catch (AgentException) {
            $methods = self::methods($http->calls);

            $this->assertSame('account.logout', end($methods));
        }
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    public static function badConfigs(): array
    {
        return [
            [['username' => ''], 'Benutzername'],
            [['password' => ''], 'Passwort'],
            [['password' => '   '], 'Passwort'],
            [['username' => "mit\nUmbruch"], 'Steuerzeichen'],
            [['password' => "mit\tTab"], 'Steuerzeichen'],
            [['shared_secret' => 'nicht base32!!!!'], 'Base32'],
            [['shared_secret' => 'ZUKURZ'], 'Base32'],
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

        Inwx::configure($config + ['username' => 'wer', 'password' => 'geheim']);
    }

    /** Das gemeinsame Geheimnis ist freiwillig — nur ein gesichertes Konto braucht es. */
    public function test_the_shared_secret_is_optional(): void
    {
        $this->assertSame('', Inwx::configure(['username' => 'wer', 'password' => 'geheim'])['shared_secret']);
    }
}
