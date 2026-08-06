<?php

declare(strict_types=1);

namespace Tests\Unit;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Bundle;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\CertificateUpload;
use SrvPanel\Agent\Runner;

/**
 * Ein hochgeladenes Zertifikat — und die Fälle, die abgewiesen gehören.
 *
 * **Warum jeder einzelne davon einen eigenen Durchgang hat.** Ein Zertifikat,
 * das nginx nicht laden kann, nimmt beim nächsten Reload *alle* Websites
 * dieses Servers mit, nicht nur die, für die es gedacht war. Und die
 * unangenehmeren Fälle sind die, die durchgehen und trotzdem falsch sind: Eine
 * falsch sortierte Kette verzeihen Browser unterschiedlich — der Betreiber
 * sieht eine Seite, die bei ihm aufgeht, und der Kunde eine Warnung.
 *
 * **Die Zertifikate entstehen im Test und liegen nicht im Repo.** Ein
 * eingecheckter privater Schlüssel — und sei es ein erfundener — ist ein
 * Fundstück für jeden Scanner und eine Erklärung, die man immer wieder abgeben
 * muss. Erzeugt wird mit den openssl-Funktionen von PHP; das `openssl`-Programm
 * wird dafür nicht gebraucht.
 */
final class CertificateUploadTest extends TestCase
{
    private ?string $workspace = null;

    /** @var array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate}|null */
    private ?array $authority = null;

    /** @var array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate}|null */
    private ?array $leaf = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null) {
            foreach (glob($this->workspace.'/*/*') ?: [] as $file) {
                @unlink($file);
            }

            foreach (glob($this->workspace.'/*') ?: [] as $entry) {
                is_dir($entry) ? @rmdir($entry) : @unlink($entry);
            }

            @rmdir($this->workspace);
            $this->workspace = null;
        }

        parent::tearDown();
    }

    /**
     * Ein Wegwerfverzeichnis samt openssl-Konfiguration.
     *
     * Die Konfiguration ist der Grund, warum das hier steht: Ohne einen
     * Abschnitt mit `subjectAltName` entsteht ein Zertifikat, das keinen Namen
     * nennt — und genau das ist einer der Fälle, die abgewiesen gehören.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $this->workspace ??= sys_get_temp_dir().'/srvpanel-upload-'.bin2hex(random_bytes(6));

        if (! is_dir($this->workspace)) {
            mkdir($this->workspace, 0o700, true);
        }

        $path = $this->workspace.'/openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, implode("\n", [
                '[req]',
                'distinguished_name = dn',
                '[dn]',
                '[v3_ca]',
                'basicConstraints = critical,CA:TRUE',
                'keyUsage = critical,keyCertSign',
                '[v3_leaf]',
                'basicConstraints = critical,CA:FALSE',
                'subjectAltName = DNS:example.de,DNS:www.example.de',
                '[v3_nameless]',
                'basicConstraints = critical,CA:FALSE',
                '',
            ]));
        }

        return ['config' => $path, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
    }

    /** @return array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate} */
    private function ca(): array
    {
        if ($this->authority !== null) {
            return $this->authority;
        }

        $config = $this->config();
        $key = $this->newKey();
        $csr = $this->newRequest('Test CA', $key);
        $cert = openssl_csr_sign($csr, null, $key, 3650, $config + ['x509_extensions' => 'v3_ca']);

        $this->assertInstanceOf(OpenSSLCertificate::class, $cert);

        return $this->authority = ['key' => $key, 'cert' => $cert];
    }

    /** @return array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate} */
    private function leaf(): array
    {
        return $this->leaf ??= $this->signed('example.de');
    }

    /** @return array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate} */
    private function signed(string $commonName, string $extensions = 'v3_leaf'): array
    {
        $ca = $this->ca();
        $key = $this->newKey();
        $csr = $this->newRequest($commonName, $key);

        $cert = openssl_csr_sign($csr, $ca['cert'], $ca['key'], 30, $this->config() + ['x509_extensions' => $extensions]);

        $this->assertInstanceOf(OpenSSLCertificate::class, $cert);

        return ['key' => $key, 'cert' => $cert];
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($this->config());

        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function newRequest(string $commonName, OpenSSLAsymmetricKey $key): OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $this->config());

        $this->assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);

        return $csr;
    }

    private function pem(OpenSSLCertificate $certificate): string
    {
        $out = '';
        openssl_x509_export($certificate, $out);

        return (string) $out;
    }

    private function privateKey(OpenSSLAsymmetricKey $key, ?string $password = null): string
    {
        $out = '';
        openssl_pkey_export($key, $out, $password, $this->config());

        return (string) $out;
    }

    /** Die übliche Kette: erst das eigene, dann das ausstellende. */
    private function chain(): string
    {
        return $this->pem($this->leaf()['cert'])."\n".$this->pem($this->ca()['cert']);
    }

    public function test_a_valid_chain_is_read_completely(): void
    {
        $bundle = Bundle::from($this->chain(), $this->privateKey($this->leaf()['key']));

        $this->assertSame(['example.de', 'www.example.de'], $bundle->names);
        $this->assertSame('Test CA', $bundle->issuer);
        $this->assertGreaterThan(time(), $bundle->notAfter);
        $this->assertLessThanOrEqual(time(), $bundle->notBefore);
    }

    public function test_a_key_that_belongs_to_another_certificate_is_refused(): void
    {
        $chain = $this->chain();
        $other = $this->signed('andere.de');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('gehört nicht zu diesem Zertifikat');

        Bundle::from($chain, $this->privateKey($other['key']));
    }

    /**
     * Die falsch sortierte Kette — der Fall, den Browser unterschiedlich
     * verzeihen und Mobilgeräte gar nicht.
     */
    public function test_a_chain_in_the_wrong_order_is_refused(): void
    {
        $verkehrt = $this->pem($this->ca()['cert'])."\n".$this->pem($this->leaf()['cert']);
        $key = $this->privateKey($this->ca()['key']);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('nicht in der richtigen Reihenfolge');

        Bundle::from($verkehrt, $key);
    }

    /** nginx fragt beim Start nach dem Passwort — und niemand ist da. */
    public function test_a_key_with_a_password_is_refused(): void
    {
        $chain = $this->chain();
        $key = $this->privateKey($this->leaf()['key'], 'geheim');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('trägt er ein Passwort');

        Bundle::from($chain, $key);
    }

    /** Ohne subjectAltName deckt es nichts — der CommonName zählt seit 2017 nicht. */
    public function test_a_certificate_without_names_is_refused(): void
    {
        $ohne = $this->signed('ohne-san.de', 'v3_nameless');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keinen Namen im subjectAltName');

        Bundle::from($this->pem($ohne['cert']), $this->privateKey($ohne['key']));
    }

    public function test_an_expired_certificate_is_refused(): void
    {
        $chain = $this->chain();
        $key = $this->privateKey($this->leaf()['key']);
        $bundle = Bundle::from($chain, $key);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('ist abgelaufen');

        Bundle::from($chain, $key, $bundle->notAfter + 1);
    }

    public function test_a_certificate_that_is_not_valid_yet_is_refused(): void
    {
        $chain = $this->chain();
        $key = $this->privateKey($this->leaf()['key']);
        $bundle = Bundle::from($chain, $key);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('gilt erst später');

        Bundle::from($chain, $key, $bundle->notBefore - 1);
    }

    public function test_something_that_is_not_a_certificate_is_refused(): void
    {
        $key = $this->privateKey($this->leaf()['key']);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('kein Zertifikat im PEM-Format');

        Bundle::from('guten tag', $key);
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $key = $this->privateKey($this->leaf()['key']);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('grösser als');

        Bundle::from(str_repeat('x', Bundle::MAX_CHAIN_BYTES + 1), $key);
    }

    /**
     * Und abgelegt wird getrennt von dem, was bestellt wurde.
     *
     * **Sonst überschriebe eines das andere.** Der Schlüssel im Ablageort
     * entsteht aus dem ersten Namen, und ein hochgeladenes Zertifikat für
     * `example.de` hätte denselben wie ein bestelltes. Welches gerade dort
     * liegt, hinge davon ab, was zuletzt lief.
     */
    public function test_it_is_stored_apart_from_what_was_ordered(): void
    {
        $chain = $this->chain();
        $key = $this->privateKey($this->leaf()['key']);

        $root = ((string) $this->workspace).'/certs';
        mkdir($root, 0o750, true);

        $journal = new Journal('/dev/null');
        $context = new Context(new Runner($journal), $journal, static function (array $line): void {});

        $result = (new CertificateUpload(new Store($root)))
            ->execute(['certificate' => $chain, 'private_key' => $key], $context);

        $this->assertSame('_uploaded.example.de', $result['storage_name'] ?? null);
        $this->assertFileExists($root.'/_uploaded.example.de/fullchain.pem');

        // Der Schlüssel gehört root allein — nginx liest ihn als Masterprozess.
        $this->assertSame(0o600, (int) fileperms($root.'/_uploaded.example.de/privkey.pem') & 0o777);

        // Und er geht nicht zurück: Zurück kommt, was auch jeder Browser sieht.
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($result));
        $this->assertSame(['example.de', 'www.example.de'], $result['names'] ?? null);
    }
}
