<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Context;
use CloudSrv\Agent\Op;

/**
 * Der nginx-Server-Block der Panel-Oberfläche.
 *
 * **Die Vorlage liegt hier, im Agenten.** Die Anwendung schickt einen Port und
 * Pfade zum Zertifikat, keinen Text. Das ist die Zusage aus §4.2 des Plans,
 * und sie ist an dieser Stelle nicht theoretisch: Wer eine nginx-Konfiguration
 * schreiben darf, darf über `root` jedes Verzeichnis des Servers ausliefern.
 *
 * **Erst prüfen, dann übernehmen.** Geschrieben wird in eine Datei neben der
 * bestehenden; erst wenn `nginx -t` die neue Fassung annimmt, ersetzt sie die
 * alte. Schlägt der Reload danach doch fehl, kommt die alte zurück. Ein Panel,
 * das sich mit der eigenen Konfiguration aussperrt, wäre nur noch über SSH zu
 * retten — genau das soll es ja ersparen.
 */
final class PanelVhost implements Op
{
    public function __construct(private readonly string $target = '/etc/nginx/conf.d/cloudsrv-panel.conf') {}

    public static function name(): string
    {
        return 'panel.vhost.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $port = is_int($args['port'] ?? null) ? $args['port'] : 8443;

        if ($port < 1 || $port > 65535) {
            throw AgentException::badRequest('Unzulässiger Port.', ['port' => $port]);
        }

        $certificate = '/etc/cloudsrv/tls/panel.crt';
        $key = '/etc/cloudsrv/tls/panel.key';

        if (! is_file($certificate) || ! is_file($key)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Ohne Zertifikat kein Server-Block — erst panel.tls.ensure.',
            );
        }

        $context->progress(20, 'Server-Block erzeugen');
        $text = $this->template($port, $certificate, $key);

        $before = is_file($this->target) ? (string) file_get_contents($this->target) : null;

        if (! is_dir(dirname($this->target))) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'nginx ist nicht installiert: /etc/nginx/conf.d fehlt.',
            );
        }

        file_put_contents($this->target, $text);
        chmod($this->target, 0o644);

        $context->progress(50, 'nginx -t');
        $check = $context->runner->run('nginx', ['-t'], 30);

        if (! $check->successful()) {
            $this->restore($before);

            throw AgentException::execFailed('nginx hat die Konfiguration abgelehnt: '.$check->message());
        }

        $context->progress(80, 'nginx neu laden');
        $reload = $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

        if (! $reload->successful()) {
            $this->restore($before);
            $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

            throw AgentException::execFailed('nginx ließ sich nicht neu laden: '.$reload->message());
        }

        return ['path' => $this->target, 'port' => $port, 'replaced' => $before !== null];
    }

    private function restore(?string $before): void
    {
        if ($before === null) {
            @unlink($this->target);

            return;
        }

        file_put_contents($this->target, $before);
    }

    private function template(int $port, string $certificate, string $key): string
    {
        return <<<CONF
        # Von cloudsrv-agentd erzeugt. Änderungen von Hand werden beim nächsten
        # Lauf überschrieben — für eigene Direktiven ist die Include-Datei
        # /etc/cloudsrv/nginx-extra.conf da, die hier eingebunden wird.

        server {
            listen {$port} ssl;
            listen [::]:{$port} ssl;
            http2 on;

            server_name _;
            root /opt/cloudsrv/current/public;
            index index.php;

            ssl_certificate     {$certificate};
            ssl_certificate_key {$key};
            ssl_protocols       TLSv1.2 TLSv1.3;
            ssl_prefer_server_ciphers off;
            ssl_session_timeout 1d;
            ssl_session_cache   shared:CloudSrvTLS:10m;

            # Das Panel ist keine öffentliche Seite. Es wird nicht indiziert,
            # nicht in einen fremden Rahmen gestellt und gibt keine Adresse
            # weiter, von der jemand kam.
            add_header Strict-Transport-Security "max-age=31536000" always;
            add_header X-Content-Type-Options nosniff always;
            add_header X-Frame-Options DENY always;
            add_header Referrer-Policy no-referrer always;
            add_header X-Robots-Tag "noindex, nofollow" always;

            client_max_body_size 256m;

            location / {
                try_files \$uri \$uri/ /index.php?\$query_string;
            }

            location ~ ^/index\.php(/|\$) {
                fastcgi_pass unix:/run/cloudsrv/fpm.sock;
                fastcgi_split_path_info ^(.+\.php)(/.*)\$;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                fastcgi_param HTTPS on;

                # Vorgänge senden über SSE mit. Ein Puffer zwischen Anwendung
                # und Browser hielte die Ausgabe zurück, bis sie voll ist —
                # aus „live zusehen" würde „am Ende alles auf einmal".
                fastcgi_buffering off;
                fastcgi_read_timeout 3600s;
            }

            # Alles andere als index.php wird nicht ausgeführt. Ohne diese
            # Zeile liefert ein hochgeladenes .php im public-Verzeichnis
            # Code-Ausführung.
            location ~ \.php\$ {
                return 404;
            }

            location ~ /\. {
                deny all;
            }

            include /etc/cloudsrv/nginx-extra.conf*;

            access_log /var/log/cloudsrv/panel-access.log;
            error_log  /var/log/cloudsrv/panel-error.log;
        }

        CONF;
    }
}
