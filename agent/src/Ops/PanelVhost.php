<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Trust;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;

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
    public function __construct(private readonly string $target = '/etc/nginx/conf.d/srvpanel.conf') {}

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

        $certificate = '/etc/srvpanel/tls/panel.crt';
        $key = '/etc/srvpanel/tls/panel.key';

        if (! is_file($certificate) || ! is_file($key)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Ohne Zertifikat kein Server-Block — erst panel.tls.ensure.',
            );
        }

        $context->progress(20, 'Server-Block erzeugen');
        $text = $this->template(
            $port,
            $certificate,
            $key,
            $this->modernHttp2($context),
            // Die einzige Stelle, die den Rechnernamen beantwortet — siehe
            // `HostnameSourceTest`.
            Names::host(),
            ! Trust::selfSigned((string) file_get_contents($certificate)),
        );

        $before = is_file($this->target) ? (string) file_get_contents($this->target) : null;

        // Das Verzeichnis wird hier **nicht** angelegt, obwohl NginxApply das
        // könnte: Fehlt es, ist nicht die Datei das Problem, sondern der
        // fehlende Webserver — und ein stillschweigend angelegtes
        // /etc/nginx/conf.d verschöbe diese Auskunft auf den Reload.
        if (! is_dir(dirname($this->target))) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'nginx ist nicht installiert: /etc/nginx/conf.d fehlt.',
            );
        }

        // Schreiben, prüfen, neu laden, im Fehlerfall zurück — der Ablauf
        // steht seit P3 in NginxApply, weil ihn jede Kundendomain ebenfalls
        // braucht. Er stand zuerst hier; abgeschrieben wäre er beim zweiten
        // Mal um die Zeile mit dem Zurück ärmer gewesen.
        NginxApply::commit($context, [$this->target => $text]);

        return ['path' => $this->target, 'port' => $port, 'replaced' => $before !== null];
    }

    /**
     * Kennt dieses nginx die eigenständige http2-Direktive?
     *
     * Sie gibt es erst seit 1.25.1. Davor wird HTTP/2 als Parameter an listen
     * angehängt. Von den vier Zielplattformen bringen drei eine ältere Fassung
     * mit — Debian 12 hat 1.22, Ubuntu 22.04 sogar 1.18. Ein Panel, das die
     * neue Schreibweise blind hinschreibt, ist auf dreien davon nicht
     * einrichtbar.
     *
     * Bei unlesbarer Auskunft gilt die alte Schreibweise: Sie wird von neuen
     * Fassungen noch angenommen (mit Hinweis), die neue von alten gar nicht.
     */
    private function modernHttp2(Context $context): bool
    {
        try {
            $result = $context->runner->run('nginx', ['-v'], 10);
        } catch (AgentException) {
            return false;
        }

        // nginx schreibt die Version nach stderr, nicht nach stdout.
        if (! preg_match('#nginx/(\d+)\.(\d+)\.(\d+)#', $result->stderr.$result->stdout, $match)) {
            return false;
        }

        $version = [(int) $match[1], (int) $match[2], (int) $match[3]];

        return $version >= [1, 25, 1];
    }

    /**
     * Die Vorlage. Öffentlich, damit beide Schreibweisen prüfbar sind, ohne
     * nginx zu installieren — der Unterschied zwischen ihnen ist genau der
     * Fehler, der eine Einrichtung auf drei von vier Plattformen verhindert
     * hätte.
     */
    public function template(
        int $port,
        string $certificate,
        string $key,
        bool $modernHttp2,
        string $hostname,
        bool $hsts = true,
    ): string {
        $listen = $modernHttp2
            ? "listen {$port} ssl;\n    listen [::]:{$port} ssl;\n    http2 on;"
            : "listen {$port} ssl http2;\n    listen [::]:{$port} ssl http2;";

        // Derselbe Block wie in der Kundenvorlage, und aus derselben Quelle:
        // Ablageort und ausliefernde Zeile dürfen nicht auseinanderlaufen.
        $challenge = HttpChallenge::nginxLocation();

        /*
         * **HSTS erst, wenn ein Browser dem Zertifikat überhaupt trauen kann.**
         *
         * Der Header stand hier bedingungslos, und docs/27 §7 nannte das eine
         * Falle für P4 — sie hat früher zugebissen. Wer das selbstsignierte
         * Zertifikat in seinen Speicher aufnimmt, hat damit eine vertraute
         * Verbindung, der Browser merkt sich `max-age=31536000`, und ab da
         * lässt sich auf diesem Host **kein Zertifikatsfehler mehr
         * wegklicken**: kein „trotzdem fortfahren", keine Ausnahme. Ein neu
         * ausgestelltes Zertifikat — anderer Name, andere Seriennummer — sperrt
         * den Betreiber dann aus seinem eigenen Panel aus, und der einzige Weg
         * zurück führt über die Einstellungen des Browsers.
         *
         * Ein Jahr Erzwingung zu versprechen, während das Zertifikat sich
         * jederzeit ändern darf, ist kein Härtungsgewinn, sondern eine Zusage,
         * die das Panel nicht halten kann. Sobald in P4 ein Zertifikat von
         * Let's Encrypt gilt, ändert sich die Lage: Dann ist der Header
         * richtig, und `panel.vhost.apply` schreibt ihn von selbst hin — die
         * Bedingung dafür ist das Zertifikat und keine Einstellung.
         */
        $strict = $hsts
            ? "\n            # Erzwungenes HTTPS — das Zertifikat stammt von einer\n".
              "            # Zertifizierungsstelle, der Browser kann ihm also trauen.\n".
              '            add_header Strict-Transport-Security "max-age=31536000" always;'
            : "\n            # Kein Strict-Transport-Security: Das Zertifikat ist\n".
              "            # selbstsigniert. Ein Jahr Erzwingung würde einen späteren\n".
              '            # Zertifikatswechsel unwegklickbar machen (docs/27 §7).';

        return <<<CONF
        # Von srvpanel-agentd erzeugt. Änderungen von Hand werden beim nächsten
        # Lauf überschrieben — für eigene Direktiven ist die Include-Datei
        # /etc/srvpanel/nginx-extra.conf da, die hier eingebunden wird.

        server {
            {$listen}

            server_name _;
            root /opt/srvpanel/current/public;
            index index.php;

            ssl_certificate     {$certificate};
            ssl_certificate_key {$key};
            ssl_protocols       TLSv1.2 TLSv1.3;
            ssl_prefer_server_ciphers off;
            ssl_session_timeout 1d;
            ssl_session_cache   shared:SrvPanelTLS:10m;

            # Das Panel ist keine öffentliche Seite. Es wird nicht indiziert,
            # nicht in einen fremden Rahmen gestellt und gibt keine Adresse
            # weiter, von der jemand kam.
        {$strict}
            add_header X-Content-Type-Options nosniff always;
            add_header X-Frame-Options DENY always;
            add_header Referrer-Policy no-referrer always;
            add_header X-Robots-Tag "noindex, nofollow" always;

            client_max_body_size 256m;

            location / {
                try_files \$uri \$uri/ /index.php?\$query_string;
            }

            location ~ ^/index\.php(/|\$) {
                fastcgi_pass unix:/run/srvpanel/fpm.sock;
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

            include /etc/srvpanel/nginx-extra.conf*;

            access_log /var/log/srvpanel/panel-access.log;
            error_log  /var/log/srvpanel/panel-error.log;
        }

        # **Port 80, und zwar nur für zwei Dinge.**
        #
        # Das Panel hört auf {$port} und sonst nirgends. Die Prüfung von ACME
        # fragt aber immer über Port 80 — ohne diesen Block bekäme ausgerechnet
        # die Oberfläche nie ein Zertifikat, während jede Kundendomain eines
        # bekommt.
        #
        # **Mit dem Rechnernamen und nicht mit `_`.** Ein `server_name _;`
        # trifft keinen echten Host-Header; er wirkte nur als Vorgabeserver,
        # und der ist auf Port 80 längst vergeben — nginx liest
        # `conf.d/srvpanel-sites.conf` vor dieser Datei. Der Block hinge also
        # da und beantwortete nichts.
        server {
            listen 80;
            listen [::]:80;

            server_name {$hostname};

        {$challenge}

            # Alles andere gehört auf die gesicherte Verbindung. Das Ziel steht
            # als fester Name da und nicht als \$host: Dieser Block antwortet
            # ohnehin nur unter diesem einen Namen, und ein weitergereichter
            # Host-Header wäre eine Adresse, die der Aufrufer bestimmt.
            location / {
                return 301 https://{$hostname}:{$port}\$request_uri;
            }

            access_log /var/log/srvpanel/panel-access.log;
            error_log  /var/log/srvpanel/panel-error.log;
        }

        CONF;
    }
}
