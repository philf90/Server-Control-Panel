<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Ops\PanelTls;
use SrvPanel\Agent\Runner;

final class PanelTlsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (! extension_loaded('openssl')) {
            $this->markTestSkipped('Der Test braucht die openssl-Erweiterung.');
        }

        $this->directory = sys_get_temp_dir().'/srvpanel-tls-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
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

    public function test_the_certificate_carries_a_subject_alt_name(): void
    {
        /*
         * **Ohne SAN wäre das Zertifikat wertlos.** Chrome liest den
         * CommonName seit 2017 nicht mehr, Firefox und Safari ebenso wenig:
         * Der Browser meldete nicht „unbekannter Aussteller", sondern „der
         * Name passt nicht" — und daran ändert auch die Aufnahme in den
         * eigenen Zertifikatsspeicher nichts.
         */
        $result = (new PanelTls($this->directory))->execute([], $this->context());
        $parsed = openssl_x509_parse((string) file_get_contents($result['certificate']));

        $this->assertIsArray($parsed);

        $names = Names::fromCertificate($parsed);

        $this->assertContains(php_uname('n'), $names['dns'], 'Der Hostname fehlt im subjectAltName.');
        $this->assertContains('localhost', $names['dns']);

        // Nach der Einrichtung ruft man das Panel über die IP auf — genau die
        // stand vorher nirgends im Zertifikat.
        $this->assertContains('127.0.0.1', $names['ip'], 'Die Loopback-Adresse fehlt.');
    }

    public function test_it_is_a_server_certificate_and_not_an_authority(): void
    {
        /*
         * Ein selbstsigniertes Zertifikat, das gleichzeitig eine
         * Zertifizierungsstelle sein darf, ist ein Generalschlüssel: Wer den
         * privaten Schlüssel dieses Servers erbeutet, stellt damit
         * Zertifikate für *jeden* Namen aus, die jede Maschine akzeptiert,
         * die dieses eine Zertifikat einmal aufgenommen hat.
         */
        $result = (new PanelTls($this->directory))->execute([], $this->context());
        $parsed = openssl_x509_parse((string) file_get_contents($result['certificate']));

        $this->assertIsArray($parsed);
        $this->assertStringContainsString('CA:FALSE', (string) ($parsed['extensions']['basicConstraints'] ?? ''));
        $this->assertStringContainsString(
            'TLS Web Server Authentication',
            (string) ($parsed['extensions']['extendedKeyUsage'] ?? ''),
        );
    }

    public function test_two_certificates_do_not_share_a_serial_number(): void
    {
        // Zwei selbstsignierte Zertifikate desselben Rechners hätten sonst
        // denselben Aussteller *und* dieselbe Seriennummer — für einen
        // Zertifikatsspeicher sind das zwei Fassungen desselben Zertifikats.
        $op = new PanelTls($this->directory);

        $first = openssl_x509_parse((string) file_get_contents(
            $op->execute([], $this->context())['certificate']
        ));
        $second = openssl_x509_parse((string) file_get_contents(
            $op->execute(['force' => true], $this->context())['certificate']
        ));

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertNotSame($first['serialNumber'], $second['serialNumber']);
    }

    public function test_nothing_but_the_certificate_stays_behind(): void
    {
        // Die Erweiterungen brauchen eine Konfigurationsdatei für openssl.
        // Sie enthält nur Namen und kein Schlüsselmaterial — liegenbleiben
        // darf sie trotzdem nicht.
        (new PanelTls($this->directory))->execute([], $this->context());

        $übrig = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));

        sort($übrig);

        $this->assertSame(['panel.crt', 'panel.key'], $übrig);
    }

    public function test_a_certificate_close_to_expiry_is_replaced(): void
    {
        $op = new PanelTls($this->directory);
        $result = $op->execute([], $this->context());
        $pem = (string) file_get_contents($result['certificate']);

        $names = ['dns' => [php_uname('n'), 'localhost'], 'ip' => ['127.0.0.1']];

        // Frisch: kein Grund zu erneuern.
        $this->assertNull($op->renewalReason($pem, $names));

        // Der Rechner heisst anders als damals — ein Zertifikat auf einen
        // alten Hostnamen ist auf diesem Server so brauchbar wie keines.
        $reason = $op->renewalReason($pem, ['dns' => ['anders', 'localhost'], 'ip' => ['127.0.0.1']]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('anders', $reason);
    }

    public function test_a_new_address_alone_does_not_reissue(): void
    {
        /*
         * Auf einem Server mit Docker oder libvirt kommen und gehen Adressen.
         * Erneuerte das Zertifikat sich deswegen, bekäme der Betreiber jede
         * Woche eine neue Warnung im Browser — für eine Adresse, unter der er
         * das Panel vielleicht nie aufruft.
         */
        $op = new PanelTls($this->directory);
        $pem = (string) file_get_contents($op->execute([], $this->context())['certificate']);

        $this->assertNull($op->renewalReason($pem, [
            'dns' => [php_uname('n')],
            'ip' => ['127.0.0.1', '10.11.12.13'],
        ]));
    }

    public function test_a_certificate_outside_the_served_directory_leaves_nginx_alone(): void
    {
        /*
         * **Die Bedingung, die in der CI aufgefallen ist.** Der Reload hing
         * zuerst allein daran, ob es das Programm nginx gibt — und das ist die
         * falsche Frage: Diese Operation kann in ein anderes Verzeichnis
         * schreiben, der Server-Block zeigt aber fest auf `/etc/srvpanel/tls`.
         * Auf dem Läufer ist nginx installiert, und der Test prüfte damit die
         * Systemkonfiguration als unprivilegiertes Konto: neun Fehlschläge auf
         * einen Schlag. Auf einer Maschine ohne nginx lief es durch.
         *
         * Dieser Test greift nur dort, wo nginx installiert ist — genau dort,
         * wo es schiefging.
         */
        $result = (new PanelTls($this->directory))->execute([], $this->context());

        $this->assertNotSame(PanelTls::DIRECTORY, $this->directory);
        $this->assertFalse($result['reloaded'], 'Ein Zertifikat woanders lädt keinen laufenden Webserver neu.');
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
