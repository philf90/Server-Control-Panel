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

            $this->assertStringContainsString('Strict-Transport-Security', $config);
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
}
