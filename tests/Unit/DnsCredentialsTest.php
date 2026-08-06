<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\DnsCredentialForget;
use SrvPanel\Agent\Ops\DnsCredentialList;
use SrvPanel\Agent\Ops\DnsCredentialStore;
use SrvPanel\Agent\Runner;

/**
 * Wo ein DNS-Token liegt — und was den Agenten davon verlässt.
 *
 * **Es ist ein grösseres Geheimnis als das Panel-Passwort.** Wer es hat, kann
 * sich für die Domain jedes Zertifikat der Welt ausstellen lassen, auch
 * anderswo. Geprüft wird deshalb zweierlei: dass es dort liegt, wo nur root
 * hinkommt, und dass keine Antwort es je zurückgibt.
 *
 * **Und dass ein Profilname ein Dateiname sein darf.** Er wird zu einem Pfad in
 * einem Prozess mit Systemrechten; was hier durchginge, läge als
 * `../../etc/irgendwas` auf der Platte. Dieselbe Sorte Liste wie in
 * `SitePathTest`: nicht was gehen soll, sondern was auf keinen Fall gehen darf.
 */
final class DnsCredentialsTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            foreach (glob($this->root.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->root);
            $this->root = null;
        }

        parent::tearDown();
    }

    private function credentials(): Credentials
    {
        $this->root ??= sys_get_temp_dir().'/srvpanel-dns-'.bin2hex(random_bytes(6));

        return new Credentials($this->root);
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    public function test_the_token_lies_where_only_root_reaches_it(): void
    {
        $credentials = $this->credentials();
        $credentials->store('betrieb', Providers::HETZNER, ['token' => 'geheim-123']);

        $this->assertSame(0o700, (int) fileperms((string) $this->root) & 0o777);
        $this->assertSame(0o600, (int) fileperms($this->root.'/betrieb.json') & 0o777);
    }

    public function test_what_was_stored_comes_back_inside_the_agent(): void
    {
        $credentials = $this->credentials();
        $credentials->store('betrieb', Providers::HETZNER, ['token' => 'geheim-123']);

        $read = $credentials->read('betrieb');

        $this->assertSame(Providers::HETZNER, $read['provider']);
        $this->assertSame('geheim-123', $read['config']['token'] ?? null);
    }

    /**
     * Was gesagt werden darf: Anbieter und Zeitpunkt.
     *
     * **Kein Ausschnitt des Tokens**, auch nicht die letzten vier Zeichen: Bei
     * einem kurzen Token ist das ein spürbarer Teil davon, und der Gewinn wäre
     * eine Bequemlichkeit beim Wiedererkennen.
     */
    public function test_the_description_never_carries_the_token(): void
    {
        $credentials = $this->credentials();
        $credentials->store('betrieb', Providers::HETZNER, ['token' => 'geheim-123']);

        $described = $credentials->describe('betrieb');

        $this->assertSame(Providers::HETZNER, $described['provider'] ?? null);
        $this->assertStringNotContainsString('geheim', (string) json_encode($described));
    }

    /** @return list<array{0: string}> */
    public static function badNames(): array
    {
        return [
            ['../../etc/passwd'],
            ['BETRIEB/../x'],
            ['mit punkt.json'],
            [''],
            ['ä'],
            ['-faengt-mit-strich-an'],
            ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    #[DataProvider('badNames')]
    public function test_a_name_that_is_no_file_name_is_refused(string $profile): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Profilname');

        $this->credentials()->store($profile, Providers::HETZNER, []);
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Unbekannter DNS-Anbieter');

        $this->credentials()->store('betrieb', 'gandi', []);
    }

    public function test_a_profile_without_credentials_says_so(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Zugangsdaten');

        $this->credentials()->read('gibtsnicht');
    }

    /**
     * Und die Operationen geben nichts heraus, was jemandem nützt.
     *
     * Das ist der Durchgang, auf den es ankommt: Der Weg vom Token in den
     * Agenten ist einer, und zurück führt keiner.
     */
    public function test_no_operation_answers_with_the_token(): void
    {
        $credentials = $this->credentials();
        $context = $this->context();

        $stored = (new DnsCredentialStore($credentials))->execute([
            'profile' => 'abo-1042',
            'provider' => Providers::CLOUDFLARE,
            'config' => ['token' => 'streng-geheim'],
        ], $context);

        $this->assertSame('abo-1042', $stored['profile'] ?? null);
        $this->assertStringNotContainsString('streng-geheim', (string) json_encode($stored));

        $listed = (new DnsCredentialList($credentials))->execute([], $context);

        $this->assertStringNotContainsString('streng-geheim', (string) json_encode($listed));
        $this->assertSame(Providers::keys(), $listed['providers'] ?? null);
        $this->assertCount(1, $listed['profiles'] ?? []);
    }

    /** Es gibt einen Weg hinein, also gehört einer hinaus dazu. */
    public function test_a_profile_can_be_forgotten(): void
    {
        $credentials = $this->credentials();
        $credentials->store('betrieb', Providers::NETCUP, ['token' => 'x']);

        $operation = new DnsCredentialForget($credentials);
        $context = $this->context();

        $this->assertTrue($operation->execute(['profile' => 'betrieb'], $context)['removed'] ?? false);
        $this->assertSame([], $credentials->known());

        // Und ein zweites Mal ist kein Fehler, sondern ein „war schon weg".
        $this->assertFalse($operation->execute(['profile' => 'betrieb'], $context)['removed'] ?? true);
    }

    /**
     * Die Anbieter stehen fest — und zwar in dieser Reihenfolge.
     *
     * Sie ist eine Entscheidung des Betreibers vom 6. August 2026 und steht in
     * `docs/34 §6`. Ein sechster ist eine Änderung an dieser Datei, die jemand
     * liest, und kein Feld in einem Formular.
     */
    public function test_the_providers_are_the_agreed_ones(): void
    {
        $this->assertSame(
            ['rfc2136', 'hetzner', 'cloudflare', 'netcup', 'ipv64'],
            Providers::keys(),
        );
    }
}
