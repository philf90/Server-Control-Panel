<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\PanelVhost;

/**
 * Der Server-Block muss auf allen vier Zielplattformen von nginx angenommen
 * werden — und die bringen vier verschiedene Fassungen mit.
 *
 * Die eigenständige `http2`-Direktive gibt es erst seit 1.25.1. Debian 12 hat
 * 1.22, Ubuntu 22.04 hat 1.18, Ubuntu 24.04 hat 1.24; nur Debian 13 ist neu
 * genug. Die neue Schreibweise blind hinzuschreiben, machte die Einrichtung
 * auf drei von vier Plattformen unmöglich — gefunden hat das der
 * Integrationslauf, nicht das Lesen der Vorlage.
 */
final class PanelVhostTest extends TestCase
{
    public function test_modern_nginx_gets_the_standalone_directive(): void
    {
        $config = (new PanelVhost)->template(8443, '/etc/srvpanel/tls/panel.crt', '/etc/srvpanel/tls/panel.key', true);

        $this->assertStringContainsString('listen 8443 ssl;', $config);
        $this->assertStringContainsString('http2 on;', $config);
        $this->assertStringNotContainsString('ssl http2;', $config);
    }

    public function test_older_nginx_gets_the_listen_parameter(): void
    {
        $config = (new PanelVhost)->template(8443, '/etc/srvpanel/tls/panel.crt', '/etc/srvpanel/tls/panel.key', false);

        $this->assertStringContainsString('listen 8443 ssl http2;', $config);
        $this->assertStringContainsString('listen [::]:8443 ssl http2;', $config);
        $this->assertStringNotContainsString('http2 on;', $config);
    }

    public function test_the_hardening_is_in_both_variants(): void
    {
        foreach ([true, false] as $modern) {
            $config = (new PanelVhost)->template(8443, '/tmp/a.crt', '/tmp/a.key', $modern);

            // Ohne diese Zeile liefert ein hochgeladenes .php im
            // public-Verzeichnis Codeausführung.
            $this->assertStringContainsString('location ~ \.php$ {', $config);
            $this->assertStringContainsString('return 404;', $config);

            $this->assertStringContainsString('X-Frame-Options DENY', $config);
            $this->assertStringContainsString('deny all;', $config);

            // Vorgänge senden über SSE mit; ein Puffer hielte die Ausgabe zurück.
            $this->assertStringContainsString('fastcgi_buffering off;', $config);

            // Die Include-Datei für Handarbeit bleibt eingebunden.
            $this->assertStringContainsString('/etc/srvpanel/nginx-extra.conf', $config);
        }
    }

    public function test_the_port_lands_in_the_configuration(): void
    {
        $config = (new PanelVhost)->template(9443, '/tmp/a.crt', '/tmp/a.key', true);

        $this->assertStringContainsString('listen 9443 ssl;', $config);
    }

    /**
     * HSTS erst, wenn ein Browser dem Zertifikat trauen kann.
     *
     * **Warum es diesen Test gibt.** Der Header stand bedingungslos in der
     * Vorlage, und docs/27 §7 nannte das eine Falle für P4 — sie hat früher
     * zugebissen. Wer das selbstsignierte Zertifikat in seinen Speicher
     * aufnimmt, hat eine vertraute Verbindung, der Browser merkt sich
     * `max-age=31536000`, und ab da lässt sich auf diesem Host kein
     * Zertifikatsfehler mehr wegklicken: kein „trotzdem fortfahren", keine
     * Ausnahme. Ein neu ausgestelltes Zertifikat sperrt den Betreiber dann aus
     * seinem eigenen Panel aus — genau das ist auf dem ersten echten Server
     * passiert, und der Ausweg war ein Inkognitofenster.
     *
     * Ein Jahr Erzwingung zu versprechen, während das Zertifikat sich jederzeit
     * ändern darf, ist kein Härtungsgewinn, sondern eine Zusage, die das Panel
     * nicht halten kann.
     */
    public function test_a_self_signed_certificate_gets_no_hsts(): void
    {
        $config = (new PanelVhost)->template(8443, '/tmp/a.crt', '/tmp/a.key', true, false);

        $this->assertStringNotContainsString('add_header Strict-Transport-Security', $config);

        // Und es steht dabei, warum — sonst trägt es der nächste wieder ein.
        $this->assertStringContainsString('selbstsigniert', $config);
    }

    public function test_a_certificate_from_an_authority_gets_hsts(): void
    {
        $config = (new PanelVhost)->template(8443, '/tmp/a.crt', '/tmp/a.key', true, true);

        $this->assertStringContainsString(
            'add_header Strict-Transport-Security "max-age=31536000" always;',
            $config,
        );
    }

    /**
     * Aussteller gleich Inhaber — und Unlesbares zählt als selbstsigniert.
     *
     * Wer aus einem Zertifikat, das er nicht lesen kann, auf eine
     * Zertifizierungsstelle schliesst, verspricht ein Jahr erzwungenes HTTPS
     * auf Verdacht. Das ist die Richtung, in der ein Irrtum aussperrt.
     */
    public function test_an_unreadable_certificate_counts_as_self_signed(): void
    {
        $this->assertTrue(PanelVhost::selfSigned(''));
        $this->assertTrue(PanelVhost::selfSigned('-----BEGIN CERTIFICATE-----\nkein Zertifikat\n'));
    }

    public function test_a_certificate_that_issued_itself_is_recognised(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->assertNotFalse($key, 'Ohne openssl-Erweiterung prüft dieser Test nichts.');

        $csr = openssl_csr_new(['commonName' => 'srvpanel.test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        // Von sich selbst signiert: kein zweites Argument, also ist der
        // Aussteller der Inhaber.
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);

        openssl_x509_export($certificate, $pem);

        $this->assertTrue(PanelVhost::selfSigned($pem));
    }
}
