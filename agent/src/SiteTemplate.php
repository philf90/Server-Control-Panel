<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Acme\Trust;
use SrvPanel\Agent\Ops\PanelVhost;

/**
 * Der Server-Block einer Kundenwebsite.
 *
 * **Die Vorlage liegt im Agenten** — §4.2 des Plans: „Die Anwendung liefert
 * Struktur (Domain, DocumentRoot, PHP-Version, Zertifikatspfade), nicht Text."
 * Wer eine nginx-Konfiguration schreiben darf, darf über `root` jedes
 * Verzeichnis des Servers ausliefern; das ist bei der Panel-Vorlage schon so
 * begründet und gilt hier für jede einzelne Kundendomain.
 *
 * **Der Standardschutz steht in der Vorlage und nicht in einem Häkchen**
 * (§9 P3, letzter Spiegelstrich). Punktdateien, `.git`, `.env` und PHP in
 * Verzeichnissen, in die Besucher hochladen, sind nicht abschaltbar. Ein
 * Schutz, den man vergessen kann, ist bei tausend Abonnements ein Schutz, den
 * jemand vergessen hat.
 *
 * **Ohne PHP-Version wird `.php` nicht ausgeliefert, sondern verweigert.**
 * Das ist der Fehler, der bei jeder statischen Website teuer wird: Ohne
 * Handler liefert nginx die Datei als Text aus — mit Datenbankpasswort,
 * Schlüsseln und allem, was in einer PHP-Datei steht. `return 404` ist die
 * einzige richtige Antwort.
 *
 * Ausgelagert und öffentlich, damit beide Zustände und alle drei Sorten
 * (ausliefern, weiterleiten, gesperrt) geprüft werden können, ohne nginx zu
 * installieren — dieser Container hat keines.
 */
final class SiteTemplate
{
    /**
     * Wieviel nginx durchlässt, wenn nichts anderes gesagt wird.
     *
     * Der Wert hängt an `upload_max_filesize` und `post_max_size` der Domain:
     * Steht PHP auf 128M und nginx auf 1M, endet jeder Hochladevorgang mit
     * „413 Request Entity Too Large" — und die Einstellung, die der Kunde
     * gerade gesetzt hat, sieht aus, als täte sie nichts.
     */
    public const DEFAULT_BODY_MB = 64;

    /**
     * Der Server-Block — mit HTTPS, sobald ein Zertifikat dafür daliegt.
     *
     * **Die Pfade kommen nicht von aussen.** Der Agent fragt {@see Store} nach
     * dem Ablageort und sieht selbst nach, ob dort etwas liegt. Ein Pfad aus der
     * Anwendung wäre bei `ssl_certificate` dasselbe wie bei `root`: die
     * Erlaubnis, eine beliebige Datei des Servers zu benennen.
     *
     * **Ohne Zertifikat keine Weiterleitung.** Das ist der dritte Teil des
     * Abnahmekriteriums — ein Fehlschlag darf den laufenden Betrieb nicht
     * unterbrechen. Eine Domain, die auf HTTPS umleitet, bevor ein Zertifikat
     * da ist, ist eine Domain, die nicht mehr aufgeht; und genau das passiert,
     * wenn die Bestellung scheitert.
     */
    public static function render(Site $site, ?Store $store = null): string
    {
        $names = implode(' ', $site->serverNames());
        $header = self::header();
        $tls = ($store ?? new Store)->existing($site->domain);

        /*
         * **Die Prüfadresse steht vor der Fallunterscheidung, und das ist der
         * ganze Punkt.**
         *
         * Hier hätte fast eine Zeile je Zweig gestanden. `docs/32` nahm an,
         * die Vorlage trage HTTP-01 schon halb: Sie hört auf Port 80, und
         * `.well-known` ist vom Punktdatei-Schutz ausgenommen. Diese Ausnahme
         * steht aber nur im ausliefernden Zweig. Eine **Weiterleitung**
         * beantwortet jede Anfrage mit `return 302` und sucht nie eine Datei;
         * ein **gesperrtes** Abonnement antwortet mit 503. Beide hätten nie
         * ein Zertifikat bekommen — dauerhaft und ohne Fehlermeldung, weil die
         * Prüfung schlicht nicht findet, was sie sucht.
         *
         * Was strukturell nicht vergessen werden kann, muss später niemand
         * nachtragen: Der Block entsteht einmal, oberhalb von `$body`.
         */
        $challenge = HttpChallenge::nginxLocation();

        $body = match (true) {
            $site->suspended => self::suspended(),
            $site->redirectTarget !== null => self::redirect($site),
            default => self::serving($site),
        };

        // Mit Zertifikat beantwortet Port 80 nur noch die Prüfung und leitet
        // weiter; der Inhalt steht dann im 443er Block.
        $plain = $tls === null ? $body : self::toHttps();
        $secure = $tls === null ? '' : self::secure($site, $names, $tls, $body);

        return <<<CONF
        {$header}
        server {
            listen 80;
            listen [::]:80;

            server_name {$names};

            access_log {$site->accessLog()};
            error_log  {$site->errorLog()};

        {$challenge}

        {$plain}
        }
        {$secure}
        CONF;
    }

    /**
     * Alles ausser der Prüfadresse geht auf die gesicherte Verbindung.
     *
     * `301` und nicht `302`: Die Umstellung ist dauerhaft, und ein Browser, der
     * sie sich merkt, spart jedem Besucher den Umweg. Das ist auch der
     * Unterschied zu einer Weiterleitungsdomain, die `302` bekommt — die kann
     * der Kunde morgen wieder ändern.
     */
    private static function toHttps(): string
    {
        return <<<'CONF'
            location / {
                return 301 https://$host$request_uri;
            }
        CONF;
    }

    /**
     * Der Block, der wirklich ausliefert.
     *
     * **Kein `http2`, und das ist Absicht.** Die eigenständige Direktive gibt es
     * erst seit nginx 1.25.1; davor wird HTTP/2 als Parameter an `listen`
     * angehängt. Von den vier Zielplattformen bringen drei eine ältere Fassung
     * mit, und die falsche Schreibweise macht die Einrichtung unmöglich —
     * {@see PanelVhost} fragt dafür `nginx -v`. Diese Fallunterscheidung hier
     * nachzubauen, ohne sie auf allen vier Plattformen gesehen zu haben, wäre
     * dieselbe Wette, die schon einmal fast schiefging.
     * HTTP/2 kommt, wenn die Abfrage an einer Stelle steht, die beide Vorlagen
     * fragen.
     *
     * **Kein `ssl_stapling`.** Let's Encrypt hat OCSP eingestellt; eine
     * Direktive, die auf eine Adresse zeigt, die im Zertifikat nicht mehr steht,
     * ist eine Zeile ohne Wirkung. Für hochgeladene Zertifikate anderer
     * Aussteller wird sie wieder ein Thema — dann mit einer Bedingung, die das
     * Zertifikat liest, und nicht auf Verdacht.
     *
     * @param  array{certificate: string, key: string}  $tls
     */
    private static function secure(Site $site, string $names, array $tls, string $body): string
    {
        /*
         * **HSTS erst, wenn ein Browser dem Zertifikat trauen kann** — und
         * beide Hälften der Bedingung stehen dort, wo sie beantwortbar sind
         * ({@see Trust::hsts()}). `docs/27 §7` nennt das die Falle, die
         * aussperrt: Der Browser merkt sich ein Jahr, und danach lässt sich
         * auf diesem Host kein Zertifikatsfehler mehr wegklicken. Beim Panel
         * trifft das den Betreiber, bei einer Kundendomain jeden Besucher —
         * und der kann nichts dagegen tun.
         *
         * **Kein `includeSubDomains`.** Eine Subdomain ist in diesem Panel
         * eine eigene Domain mit eigenem Zertifikat. Die Erzwingung träfe sie,
         * bevor sie eines hat, und nähme sie damit vom Netz.
         */
        $strict = Trust::hsts($site->hsts, $tls['certificate'])
            ? "\n    # Erzwungenes HTTPS — das Zertifikat stammt von einer\n".
              "    # Zertifizierungsstelle, der Browser kann ihm also trauen.\n".
              '    add_header Strict-Transport-Security "max-age=31536000" always;'."\n"
            : "\n    # Kein erzwungenes HTTPS: Das Zertifikat ist entweder\n".
              "    # selbstsigniert, oder es kommt aus dem Testbetrieb, dessen\n".
              "    # Wurzel kein Browser kennt (docs/27 §7).\n";

        return <<<CONF

        server {
            listen 443 ssl;
            listen [::]:443 ssl;

            server_name {$names};

            access_log {$site->accessLog()};
            error_log  {$site->errorLog()};

            ssl_certificate     {$tls['certificate']};
            ssl_certificate_key {$tls['key']};
            ssl_protocols       TLSv1.2 TLSv1.3;
            ssl_prefer_server_ciphers off;
        {$strict}
        {$body}
        }

        CONF;
    }

    /**
     * Der Inhalt der Include-Datei mit den eigenen Direktiven.
     *
     * Sie entsteht auch dann, wenn niemand etwas eingetragen hat. Eine
     * `include`-Zeile auf eine fehlende Datei ist für nginx ein Fehler beim
     * Start — mit `*` am Ende wäre sie es nicht, aber dann fiele auch nicht
     * auf, wenn die Datei aus Versehen nicht geschrieben wurde.
     *
     * @param  list<string>  $directives
     */
    public static function includeFile(array $directives): string
    {
        $text = self::header();

        if ($directives === []) {
            return $text."# Keine eigenen Direktiven für diese Domain.\n";
        }

        foreach ($directives as $directive) {
            $text .= $directive."\n";
        }

        return $text;
    }

    private static function header(): string
    {
        return "# Von srvpanel-agentd erzeugt. Änderungen von Hand werden beim nächsten\n".
               "# Lauf überschrieben.\n";
    }

    /**
     * Der gesperrte Zustand.
     *
     * **503 und nicht 403.** Hier stand zuerst nichts — die Sperre eines
     * Abonnements setzte nur die Rechte der Wurzel auf `0750`, und was ein
     * Besucher dann sah, war ein nackter „403 Forbidden" von nginx. Das ist
     * die Antwort auf „du darfst nicht", nicht auf „diese Website ist gerade
     * nicht in Betrieb". 503 sagt zusätzlich jeder Suchmaschine, dass sie es
     * später wieder versuchen soll, statt die Seite aus dem Bestand zu
     * nehmen.
     *
     * Der Text kommt ohne Anführungszeichen, Apostroph und `$` aus: Alle drei
     * hätten in einer nginx-Zeichenkette eine eigene Bedeutung.
     */
    private static function suspended(): string
    {
        $page = '<!doctype html><html lang=de><head><meta charset=utf-8>'.
                '<meta name=viewport content=width=device-width,initial-scale=1>'.
                '<title>Vorübergehend nicht erreichbar</title></head><body>'.
                '<h1>Vorübergehend nicht erreichbar</h1>'.
                '<p>Diese Website ist zurzeit abgeschaltet. Bitte wenden Sie sich an den Betreiber.</p>'.
                '</body></html>';

        return <<<CONF
            # Dieses Abonnement ist gesperrt. Die Daten liegen unangetastet;
            # ausgeliefert wird nichts davon.
            default_type text/html;

            location / {
                return 503 '{$page}';
            }
        CONF;
    }

    private static function redirect(Site $site): string
    {
        return <<<CONF
            # Weiterleitung. nginx antwortet selbst und sucht keine Datei —
            # deshalb hat diese Domain weder DocumentRoot noch Handler.
            location / {
                return {$site->redirectCode} {$site->redirectTarget}\$request_uri;
            }
        CONF;
    }

    private static function serving(Site $site): string
    {
        $php = $site->phpVersion === null
            ? self::withoutPhp()
            : self::withPhp($site);

        $bodySize = self::bodySize($site);

        return <<<CONF
            root {$site->documentRootPath()};
            index index.php index.html index.htm;

            client_max_body_size {$bodySize}m;

            # Standardschutz — nicht abschaltbar.
            #
            # Punktdateien in einem Rutsch: `.git`, `.env`, `.htaccess`,
            # `.svn`. Ausgenommen bleibt `.well-known`: Die Prüfdatei von
            # ACME kommt seit P4 aus dem gemeinsamen Verzeichnis weiter
            # oben, aber im DocumentRoot liegen dort weitere Dateien, die
            # abgerufen werden sollen — `security.txt` etwa.
            location ~ /\\.(?!well-known/) {
                deny all;
                access_log off;
                log_not_found off;
            }

            # PHP in Verzeichnissen, in die hochgeladen wird, läuft nicht.
            # Ein Bild mit der Endung .php ist der kürzeste Weg von einem
            # Formular zu einer Shell.
            location ~* ^/(?:uploads?|files|media|assets|images|bilder|cache|temp|tmp)/.+\\.php\$ {
                return 404;
            }

        {$php}

            include {$site->includeFile()};
        CONF;
    }

    private static function withPhp(Site $site): string
    {
        $value = self::phpValue($site);

        return <<<CONF
            location / {
                    try_files \$uri \$uri/ /index.php?\$query_string;
                }

                location ~ \\.php\$ {
                    # **`try_files` zuerst, und das ist keine Feinheit.** Ohne
                    # diese Zeile führt eine Anfrage auf `/bild.jpg/schad.php`
                    # dazu, dass nginx die hochgeladene Datei an PHP übergibt.
                    try_files \$uri =404;

                    fastcgi_split_path_info ^(.+\\.php)(/.*)\$;
                    fastcgi_pass unix:{$site->socket()};
                    fastcgi_index index.php;

                    include fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                    fastcgi_param PATH_INFO \$fastcgi_path_info;{$value}

                    fastcgi_read_timeout 300s;
                }
        CONF;
    }

    private static function withoutPhp(): string
    {
        return <<<'CONF'
            location / {
                    try_files $uri $uri/ =404;
                }

                # Diese Domain hat keinen Handler. Ohne diese Zeile lieferte
                # nginx den Quelltext jeder PHP-Datei aus — samt allem, was
                # darin an Zugangsdaten steht.
                location ~ \.php$ {
                    return 404;
                }
        CONF;
    }

    /**
     * Die Einstellungen der Domain als `PHP_VALUE`.
     *
     * Mehrere Werte stehen durch Zeilenumbrüche getrennt in **einer**
     * Zeichenkette — so liest PHP-FPM sie. Was hier ankommt, ist durch
     * {@see PhpSettings} gegangen: Kein Wert kann ein Anführungszeichen oder
     * einen Umbruch enthalten und damit die Zeichenkette verlassen.
     */
    private static function phpValue(Site $site): string
    {
        if ($site->phpSettings === []) {
            return '';
        }

        $pairs = [];

        foreach ($site->phpSettings as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return "\n\n                    fastcgi_param PHP_VALUE \"".implode("\n", $pairs).'";';
    }

    /** Siehe {@see self::DEFAULT_BODY_MB}. */
    private static function bodySize(Site $site): int
    {
        $largest = self::DEFAULT_BODY_MB;

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $value = $site->phpSettings[$key] ?? null;

            if (is_string($value) && preg_match('/^(\d+)M$/D', $value, $match) === 1) {
                $largest = max($largest, (int) $match[1]);
            }
        }

        return $largest;
    }
}
