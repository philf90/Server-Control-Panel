<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Acme\DnsChallenge;
use SrvPanel\Agent\Acme\HttpChallenge;

/**
 * Jeder Anbieter sagt selbst, wie lange er braucht.
 *
 * **Warum das ein eigener Wächter ist.** Bis zum 7. August 2026 wartete jede
 * Bestellung dieselben 120 Sekunden. Das ist kürzer, als lego für drei der acht
 * Anbieter für nötig hält — netcup und IONOS bekommen dort 900 Sekunden, INWX
 * 360 —, und eine Bestellung, die zu früh aufgibt, verbrennt einen der fünf
 * Fehlversuche je Konto und Stunde. **Die gelten für jeden Kunden dieses
 * Servers**, nicht nur für den, dessen Domain gerade dran war.
 *
 * **Der Fehler wäre still und teuer.** Eine Zahl, die um den Faktor sieben zu
 * klein ist, sieht im Code aus wie jede andere Zahl; sie fällt erst auf, wenn
 * ein Kunde sagt, dass seine Bestellung „immer" scheitert — und dann als
 * Zufall, weil sie manchmal durchgeht.
 *
 * Geprüft wird deshalb jede Zahl einzeln gegen `docs/34 §11`, und dass die
 * Prüfung sie durchreicht — als Text und nicht als Marke, denn daraus machte
 * der Formatierer einen Import, und dann hinge dieser Test an `Acme\Order`,
 * nur wegen eines Kommentars.
 */
final class PatienceTest extends TestCase
{
    /**
     * Die Zahlen aus `docs/34 §11` — Frist und Abstand, in Sekunden.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function expected(): array
    {
        return [
            [Providers::RFC2136, 60, 2],
            [Providers::IPV64, 60, 2],
            [Providers::HETZNER, 60, 2],
            [Providers::CLOUDFLARE, 120, 2],
            [Providers::NETCUP, 900, 30],
            [Providers::IONOS, 900, 2],
            [Providers::INWX, 360, 2],
            [Providers::DESEC, 120, 4],
        ];
    }

    /**
     * Zugangsdaten, die durchgehen — der Anbieter muss gebaut werden können,
     * damit er nach seiner Geduld gefragt werden kann.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function configs(): array
    {
        return [
            Providers::RFC2136 => [
                'server' => '192.0.2.53',
                'zones' => ['example.de'],
                'key_name' => 'srvpanel-key',
                'secret' => 'Z2VoZWlt',
            ],
            Providers::IPV64 => ['token' => 'ein-token-mit-genug-zeichen'],
            Providers::HETZNER => ['token' => 'ein-token-mit-genug-zeichen'],
            Providers::CLOUDFLARE => ['token' => 'ein-token-mit-genug-zeichen'],
            Providers::NETCUP => [
                'customer_number' => '12345',
                'api_key' => 'schluessel-abc',
                'api_password' => 'passwort-xyz',
                'zones' => ['example.de'],
            ],
            Providers::IONOS => ['api_key' => 'praefix.geheimnis'],
            Providers::INWX => ['username' => 'wer', 'password' => 'geheim'],
            Providers::DESEC => ['token' => 'ein-token-mit-genug-zeichen'],
        ];
    }

    /**
     * Jeder Anbieter nennt genau die Zahl aus dem Plan.
     *
     * **Und die Prüfung reicht sie durch**, denn eine Zahl, die im Anbieter
     * steht und bei `Order` nicht ankommt, ist keine.
     */
    #[DataProvider('expected')]
    public function test_every_provider_names_its_own_patience(string $key, int $seconds, int $interval): void
    {
        $provider = Providers::make($key, self::configs()[$key]);

        $this->assertSame($seconds, $provider->patience()->seconds, sprintf(
            'Die Frist für %s weicht von `docs/34 §11` ab.',
            $key,
        ));

        $this->assertSame($interval, $provider->patience()->interval, sprintf(
            'Der Abstand für %s weicht von `docs/34 §11` ab.',
            $key,
        ));

        $this->assertSame($seconds, (new DnsChallenge($provider))->patience()->seconds);
    }

    /**
     * Und jeder gebaute Anbieter kommt in dieser Liste vor.
     *
     * Ohne diese Richtung bekäme ein neunter Anbieter seine Zahl nie geprüft —
     * der Wächter liefe über acht Einträge und meldete Grün.
     */
    public function test_every_built_provider_is_listed(): void
    {
        $listed = array_map(static fn (array $row): string => (string) $row[0], self::expected());

        $this->assertSame(Providers::keys(), $listed);
    }

    /**
     * HTTP-01 wartet kurz, und das ist der Unterschied, um den es geht.
     *
     * Die Prüfdatei liegt, sobald sie geschrieben ist; ein TXT-Eintrag braucht
     * Minuten. Beides mit derselben Zahl zu bedienen war der Zustand, den diese
     * Runde beendet hat.
     */
    public function test_the_file_based_challenge_waits_in_seconds(): void
    {
        $this->assertLessThan(60, (new HttpChallenge)->patience()->seconds);
    }
}
