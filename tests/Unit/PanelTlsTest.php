<?php

declare(strict_types=1);

namespace Tests\Unit;

use CloudSrv\Agent\Context;
use CloudSrv\Agent\Journal;
use CloudSrv\Agent\Ops\PanelTls;
use CloudSrv\Agent\Runner;
use PHPUnit\Framework\TestCase;

final class PanelTlsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (! extension_loaded('openssl')) {
            $this->markTestSkipped('Der Test braucht die openssl-Erweiterung.');
        }

        $this->directory = sys_get_temp_dir().'/cloudsrv-tls-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (['panel.crt', 'panel.key'] as $name) {
            @unlink($this->directory.'/'.$name);
        }

        @rmdir($this->directory);
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    public function test_creates_a_usable_certificate(): void
    {
        $result = (new PanelTls($this->directory))->execute([], $this->context());

        $this->assertTrue($result['created']);
        $this->assertFileExists($result['certificate']);
        $this->assertFileExists($result['key']);

        $parsed = openssl_x509_parse((string) file_get_contents($result['certificate']));

        $this->assertIsArray($parsed);
        $this->assertGreaterThan(time(), $parsed['validTo_time_t']);
        $this->assertTrue(openssl_x509_check_private_key(
            (string) file_get_contents($result['certificate']),
            (string) file_get_contents($result['key']),
        ));
    }

    public function test_the_private_key_belongs_to_root_alone(): void
    {
        $result = (new PanelTls($this->directory))->execute([], $this->context());

        $this->assertSame(0o600, fileperms($result['key']) & 0o777);
        $this->assertSame(0o644, fileperms($result['certificate']) & 0o777);
    }

    public function test_a_second_run_keeps_the_valid_certificate(): void
    {
        $op = new PanelTls($this->directory);
        $first = $op->execute([], $this->context());
        $inhalt = file_get_contents($first['certificate']);

        $second = $op->execute([], $this->context());

        // Jeder Tausch bedeutet für den Betreiber eine neue Warnung im Browser.
        // Ein gültiges Zertifikat wird deshalb nicht angefasst.
        $this->assertFalse($second['created']);
        $this->assertSame($inhalt, file_get_contents($second['certificate']));
    }
}
