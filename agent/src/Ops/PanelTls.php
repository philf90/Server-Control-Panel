<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Selbstsigniertes Zertifikat für die Panel-Oberfläche.
 *
 * Es gibt das Panel beim ersten Start ohne Zertifikat nicht: Eine Anmeldung
 * über eine ungesicherte Verbindung wäre ein Passwort im Klartext auf dem
 * Weg. Das selbstsignierte Zertifikat ist die Notlösung dafür, dass beim
 * ersten Start noch kein Name auf diesen Server zeigt — abgelöst wird es in
 * P4 durch ACME.
 *
 * Erzeugt wird es mit der openssl-Erweiterung von PHP und nicht mit dem
 * Programm `openssl`: ein Programm weniger auf der Positivliste, und der
 * private Schlüssel geht nie durch eine Kommandozeile oder eine temporäre
 * Datei.
 */
final class PanelTls implements Op
{
    public function __construct(private readonly string $directory = '/etc/srvpanel/tls') {}

    public static function name(): string
    {
        return 'panel.tls.ensure';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $certificate = $this->directory.'/panel.crt';
        $key = $this->directory.'/panel.key';

        if (is_file($certificate) && is_file($key) && ! ($args['force'] ?? false)) {
            $parsed = openssl_x509_parse((string) file_get_contents($certificate));

            // Ein abgelaufenes Zertifikat wird ersetzt, ein gültiges nicht:
            // Jeder Tausch bedeutet für den Betreiber eine neue Warnung im
            // Browser, die er wegklicken muss.
            if (is_array($parsed) && ($parsed['validTo_time_t'] ?? 0) > time() + 86400 * 30) {
                return ['certificate' => $certificate, 'key' => $key, 'created' => false];
            }
        }

        if (! extension_loaded('openssl')) {
            throw AgentException::execFailed('Die PHP-Erweiterung openssl fehlt.');
        }

        $name = php_uname('n');
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($pair === false) {
            throw AgentException::execFailed('Schlüsselpaar ließ sich nicht erzeugen.');
        }

        $csr = openssl_csr_new([
            'commonName' => substr($name, 0, 64),
            'organizationName' => 'SrvPanel',
        ], $pair, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw AgentException::execFailed('Zertifikatsanforderung ließ sich nicht erzeugen.');
        }

        $x509 = openssl_csr_sign($csr, null, $pair, 825, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw AgentException::execFailed('Zertifikat ließ sich nicht signieren.');
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o750, true) && ! is_dir($this->directory)) {
            throw AgentException::execFailed('Verzeichnis für Zertifikate ließ sich nicht anlegen.');
        }

        openssl_x509_export($x509, $certificateText);
        openssl_pkey_export($pair, $keyText);

        file_put_contents($certificate, $certificateText);
        chmod($certificate, 0o644);

        // Der private Schlüssel gehört root allein. nginx liest ihn als
        // Masterprozess, und der läuft als root — die Worker brauchen ihn nicht.
        $temp = $key.'.neu';
        file_put_contents($temp, $keyText);
        chmod($temp, 0o600);
        rename($temp, $key);

        return ['certificate' => $certificate, 'key' => $key, 'created' => true, 'common_name' => $name];
    }
}
