<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\PanelCertificate;
use SrvPanel\Agent\Acme\Store;

/**
 * Welches Zertifikat liefert die Oberfläche aus?
 *
 * **Die Frage hat seit P4 zwei mögliche Antworten**, und drei Stellen stellen
 * sie: der Server-Block, die Zertifikatsseite und die Erneuerung. Liefe eine
 * davon anders, zeigte das Panel ein Zertifikat an, das der Browser nie
 * bekommt — ein Fehler, den niemand meldet, weil beides plausibel aussieht.
 *
 * **Und der kurze Rechnername gehört dazu.** `Store` nimmt nur Domainnamen an;
 * auf einem Server, der schlicht `cloudsrv24` heisst, fliegt die Frage nach dem
 * Ablageort. Hier muss sie „es gibt keines" heissen und nicht „der Server-Block
 * lässt sich nicht schreiben" — sonst nimmt ein kurzer Hostname das ganze Panel
 * mit.
 */
final class PanelCertificateTest extends TestCase
{
    private string $store = '';

    private string $panel = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().'/srvpanel-store-'.bin2hex(random_bytes(6));
        $this->panel = sys_get_temp_dir().'/srvpanel-tls-'.bin2hex(random_bytes(6));

        mkdir($this->store.'/panel.example.de', 0o750, true);
        mkdir($this->panel, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->store.'/panel.example.de', $this->store, $this->panel] as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    /** @return array{certificate: string, key: string, acme: bool} */
    private function current(string $host = 'panel.example.de'): array
    {
        return PanelCertificate::current($this->panel, new Store($this->store), $host);
    }

    public function test_without_an_acme_certificate_the_self_signed_one_serves(): void
    {
        $tls = $this->current();

        $this->assertFalse($tls['acme']);
        $this->assertSame($this->panel.'/panel.crt', $tls['certificate']);
        $this->assertSame($this->panel.'/panel.key', $tls['key']);
    }

    public function test_a_certificate_from_an_authority_wins(): void
    {
        file_put_contents($this->store.'/panel.example.de/fullchain.pem', "kette\n");
        file_put_contents($this->store.'/panel.example.de/privkey.pem', "schlüssel\n");

        $tls = $this->current();

        $this->assertTrue($tls['acme']);
        $this->assertSame($this->store.'/panel.example.de/fullchain.pem', $tls['certificate']);

        // Das selbstsignierte bleibt liegen — es ist der Rückweg, wenn unter
        // diesem Namen nichts mehr steht.
        $this->assertFileExists($this->panel);
    }

    /**
     * Ein halbes Zertifikat zählt nicht.
     *
     * `ssl_certificate` ohne `ssl_certificate_key` lässt nginx nicht starten.
     * Hier wäre das besonders unangenehm: Es beträfe die Oberfläche selbst.
     */
    public function test_half_a_certificate_falls_back(): void
    {
        file_put_contents($this->store.'/panel.example.de/fullchain.pem', "kette\n");

        $this->assertFalse($this->current()['acme']);
    }

    public function test_a_short_hostname_falls_back_instead_of_throwing(): void
    {
        $this->assertFalse($this->current('cloudsrv24')['acme']);
    }
}
