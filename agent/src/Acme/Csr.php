<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\PanelTls;

/**
 * Schlüssel und Zertifikatsanforderung für eine Liste von Namen.
 *
 * **Der subjectAltName ist der einzige Ort, an dem die Namen zählen.** Der
 * CommonName steht nur noch aus Gewohnheit darin; Chrome liest ihn seit 2017
 * nicht mehr, Firefox und Safari ebenso wenig. Dieselbe Lektion steht in
 * `docs/27 §2` — dort hat sie ein Zertifikat unbrauchbar gemacht, das
 * technisch in Ordnung war.
 *
 * **Die Erweiterung braucht eine Konfigurationsdatei.** Die openssl-Erweiterung
 * von PHP nimmt einen subjectAltName nicht als Parameter entgegen; sie liest
 * ihn aus einem Abschnitt einer `.cnf`. Die Datei enthält ausschliesslich
 * Namen, kein Schlüsselmaterial, und wird danach gelöscht. Sie liegt in
 * `sys_get_temp_dir()`, und das ist hier kein gemeinsames `/tmp`:
 * `srvpanel-agentd.service` läuft mit `PrivateTmp=yes`.
 *
 * **RSA 2048 wie in {@see PanelTls}.** ECDSA wäre kleiner und schneller, und es
 * spricht wenig dagegen — aber der Schlüsseltyp des Zertifikats ist nicht, was
 * P4 zu belegen hat, und zwei Typen nebeneinander wären eine Variable mehr in
 * jeder Fehlersuche. Der Wechsel ist später eine Zeile.
 *
 * **{@see PanelTls} schreibt eine ähnliche Datei** für den selbstsignierten
 * Weg — dort mit `x509_extensions`, weil es zusätzlich selbst unterschreibt.
 * Zusammengelegt werden die beiden in Schritt 4, wenn das Zertifikat der
 * Oberfläche über ACME kommt. Vorher wäre es ein Umbau an einer Stelle, die
 * gerade grün abgenommen ist.
 */
final class Csr
{
    /**
     * @param  list<string>  $names
     * @return array{key: string, der: string} Schlüssel als PEM, Anforderung als DER
     */
    public static function create(array $names): array
    {
        if ($names === []) {
            throw AgentException::badRequest('Ohne Namen keine Zertifikatsanforderung.');
        }

        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($pair === false) {
            throw AgentException::execFailed('Das Schlüsselpaar ließ sich nicht erzeugen.');
        }

        $config = self::writeConfig($names);

        try {
            $csr = openssl_csr_new(
                ['commonName' => substr($names[0], 0, 64)],
                $pair,
                ['digest_alg' => 'sha256', 'config' => $config, 'req_extensions' => 'srvpanel'],
            );

            if ($csr === false) {
                throw AgentException::execFailed('Die Zertifikatsanforderung ließ sich nicht erzeugen.');
            }

            $pem = '';

            if (! openssl_csr_export($csr, $pem)) {
                throw AgentException::execFailed('Die Zertifikatsanforderung ließ sich nicht ausgeben.');
            }
        } finally {
            @unlink($config);
        }

        $key = '';

        if (! openssl_pkey_export($pair, $key)) {
            throw AgentException::execFailed('Der Schlüssel ließ sich nicht ausgeben.');
        }

        return ['key' => $key, 'der' => self::der($pem)];
    }

    /**
     * PEM zu DER.
     *
     * ACME schickt die Anforderung als base64url über DER und nicht als PEM —
     * Kopfzeile, Fusszeile und Umbrüche gehören nicht hinein.
     */
    private static function der(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE REQUEST-----|\s+/', '', $pem);

        if ($body === null) {
            throw AgentException::execFailed('Die Zertifikatsanforderung ließ sich nicht umwandeln.');
        }

        $der = base64_decode($body, true);

        if ($der === false) {
            throw AgentException::execFailed('Die Zertifikatsanforderung ist nicht lesbar.');
        }

        return $der;
    }

    /** @param  list<string>  $names */
    private static function writeConfig(array $names): string
    {
        $alt = [];
        $index = 0;

        foreach ($names as $name) {
            $alt[] = 'DNS.'.(++$index).' = '.$name;
        }

        $text = "[req]\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = srvpanel\n\n"
            ."[srvpanel]\n"
            ."basicConstraints = critical, CA:FALSE\n"
            ."keyUsage = critical, digitalSignature, keyEncipherment\n"
            ."extendedKeyUsage = serverAuth\n"
            ."subjectAltName = @alt\n\n"
            ."[alt]\n".implode("\n", $alt)."\n";

        // Der Name ist zufällig — dieselbe Gewohnheit wie in PanelTls: Ein
        // vorhersagbarer Pfad, den ein root-Prozess schreibt, ist anderswo
        // teuer, auch wenn er es hier hinter PrivateTmp nicht wäre.
        $path = sys_get_temp_dir().'/srvpanel-csr-'.bin2hex(random_bytes(8)).'.cnf';

        if (@file_put_contents($path, $text) === false) {
            throw AgentException::execFailed('Die Konfiguration für openssl ließ sich nicht schreiben.');
        }

        chmod($path, 0o600);

        return $path;
    }
}
