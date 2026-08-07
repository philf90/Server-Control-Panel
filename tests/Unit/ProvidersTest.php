<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\DnsProvider;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\AgentException;

/**
 * Die Werkstatt der DNS-Anbieter: Jeder Schlüssel zeigt auf etwas.
 *
 * **Das ist der Wächter zu der Regel, die dieses Projekt trägt.** Ein Anbieter,
 * der in {@see Providers::keys()} steht und weder gebaut ist noch in
 * {@see Providers::WITHHELD}, wäre genau die Zeichenkette, die auf nichts zeigt
 * — und sie würde erst beim ersten Zertifikat auffallen.
 *
 * **Warum er hier steht und nicht mehr in `Rfc2136Test`.** Dort ist er
 * entstanden, weil RFC 2136 der einzige gebaute Anbieter war; mit IPv64.net kam
 * der zweite, und der Test fiel — er hielt jedem Anbieter denselben Satz
 * Zugangsdaten hin, und die eines TSIG-Schlüssels sind für ein Token keine.
 * Genau das ist der Punkt: Ein anbieterübergreifender Wächter, der im Test
 * eines einzelnen Anbieters wohnt, erbt dessen Annahmen. Die sechs, die noch
 * kommen, hätten ihn jedes Mal wieder gebrochen.
 */
final class ProvidersTest extends TestCase
{
    /**
     * Ein Satz Zugangsdaten je gebautem Anbieter — die Form, nicht die Gültigkeit.
     *
     * Sie stehen hier zusammen, weil dieser Test sie zusammen braucht: Wer
     * einen Anbieter baut, trägt eine Zeile ein, und wer es vergisst, bekommt
     * den Satz weiter unten zu lesen statt einen Zugriff auf einen fehlenden
     * Schlüssel.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CONFIGS = [
        Providers::RFC2136 => [
            'server' => '192.0.2.53',
            'port' => 5353,
            'zones' => ['example.de'],
            'key_name' => 'srvpanel-key',
            'secret' => 'Z2VoZWlt',
        ],
        Providers::IPV64 => [
            'token' => 'ein-token-mit-genug-zeichen',
        ],
        Providers::HETZNER => [
            'token' => 'ein-token-mit-genug-zeichen',
        ],
        Providers::CLOUDFLARE => [
            'token' => 'ein-token-mit-genug-zeichen',
        ],
        Providers::NETCUP => [
            'customer_number' => '12345',
            'api_key' => 'schluessel-abc',
            'api_password' => 'passwort-xyz',
            'zones' => ['example.de'],
        ],
        Providers::IONOS => [
            'api_key' => 'praefix.geheimnis',
        ],
        Providers::DESEC => [
            'token' => 'ein-token-mit-genug-zeichen',
        ],
        Providers::INWX => [
            'username' => 'wer',
            'password' => 'geheim',
        ],
    ];

    public function test_every_provider_key_points_at_something(): void
    {
        foreach (Providers::keys() as $key) {
            // **Gefragt wird gegen `available()` und nicht gegen `WITHHELD`.**
            // Beides ist dieselbe Aussage, aber diese Form bleibt richtig, auch
            // wenn die Liste der Zurückgehaltenen einmal leer ist — ein
            // `in_array` gegen eine leere Konstante ist ein Zweig, den nichts
            // erreichen kann, und PHPStan sagt das auch so.
            if (! in_array($key, Providers::available(), true)) {
                try {
                    Providers::make($key, []);
                    $this->fail($key.' wird nicht angeboten und lässt sich trotzdem bauen.');
                } catch (AgentException $exception) {
                    $this->assertStringContainsString('wird nicht angeboten', $exception->getMessage());

                    // **Und der Grund steht dabei.** Eine Abweisung ohne ihn
                    // lässt den Betreiber auf einen Anbieter warten, der nicht
                    // kommt.
                    $this->assertNotSame('', (string) Providers::reason($key));
                }

                continue;
            }

            $this->assertArrayHasKey($key, self::CONFIGS, sprintf(
                'Für %s gibt es eine Umsetzung, aber in diesem Test keine Zugangsdaten — dann bleibt der '.
                'Anbieter ungeprüft, und das fiele erst beim ersten Zertifikat auf.',
                $key,
            ));

            $this->assertNotSame([], Providers::configure($key, self::CONFIGS[$key]));
            $this->assertInstanceOf(DnsProvider::class, Providers::make($key, self::CONFIGS[$key]));
        }

        // **Die Liste steht hier wörtlich und nicht als Abzug von WITHHELD.**
        // Sonst prüfte der Test seine eigene Rechnung: Fällt ein Schlüssel aus
        // WITHHELD, ohne dass es die Umsetzung gibt, wäre er in beiden Listen
        // gleichzeitig richtig. Wer einen Anbieter baut oder zurückzieht, ändert
        // diese Zeile — und das ist der Punkt.
        //
        // INWX fehlt hier, obwohl er gebaut ist: Er wird nicht angeboten
        // (`docs/34 §11`). Der Unterschied zu `DnsCredentialsTest` ist genau
        // dieser — dort steht, was beschlossen ist, hier, was angeboten wird.
        $this->assertSame(
            [
                Providers::RFC2136,
                Providers::IPV64,
                Providers::HETZNER,
                Providers::CLOUDFLARE,
                Providers::NETCUP,
                Providers::IONOS,
                Providers::DESEC,
            ],
            Providers::available(),
        );
    }
}
