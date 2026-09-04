<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Acme\Trust;
use SrvPanel\Agent\Diagnose\Statements;
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
     * Was diese Vorlage in **jeder** ihrer Formen zusagt (A10 Schritt 4).
     *
     * Die Bestandsdiagnose fragt die Datei auf dem Datenträger, ob diese
     * Anweisungen noch **als Anweisung** dastehen — nicht als Zeichenkette
     * (`docs/81 §2.3o` M3, M21). Was hier steht, muss in der gesperrten, der
     * weiterleitenden und der ausliefernden Form gleichermassen vorkommen;
     * `PromiseReachTest` hält die Liste gegen jede Form in beide Richtungen.
     * Wer eine Anweisung ergänzt, die in allen Formen steht, trägt sie hier
     * nach — sonst meldet der Wächter, dass die Zusage kleiner ist als die
     * Vorlage.
     *
     * **`root` und `default_type` stehen darin, weil die Prüfadresse sie
     * trägt** — der Block oberhalb der Fallunterscheidung, der jeder Domain
     * ihr Zertifikat ermöglicht. `deny`, `try_files` und `include` stehen
     * **nicht** darin: Sie gehören der ausliefernden Form allein.
     *
     * **Und `return` steht darin, weil jede Form eines hat** — die
     * ausliefernde den Standardschutz (`return 404` für PHP in einem
     * Upload-Verzeichnis), die gesperrte ihre 503, die weiterleitende ihren
     * Code, die mit Zertifikat ihre 301 auf HTTPS. Es ist der Eintrag, den
     * der Wächter in die Liste gesetzt hat und nicht der Entwurf: Der erste
     * Wurf zählte acht Anweisungen, die Schnittmenge neun.
     *
     * **Seit A12 stehen vier weitere darin** — `set`, `if`, `error_page` und
     * `add_header` kommen aus der Wache des Wartungsmodus
     * ({@see Maintenance::nginxGuard()}), und die steht in jedem Server-Block
     * jeder Form. Sie sind damit Teil der Schnittmenge und nicht eine
     * Eigenschaft einer einzelnen Form.
     *
     * @var list<string>
     */
    public const PROMISED = ['server', 'listen', 'server_name', 'access_log', 'error_log', 'location', 'root', 'default_type', 'return', 'set', 'if', 'error_page', 'add_header'];

    /** Die vier Formen, die {@see self::render()} unterscheidet. */
    public const FORM_SUSPENDED = 'suspended';

    public const FORM_REDIRECT = 'redirect';

    public const FORM_PHP = 'php';

    public const FORM_STATIC = 'static';

    /**
     * Was jede **einzelne** Form zusagt (A10, 3. September 2026).
     *
     * ## Warum die Schnittmenge zu klein war
     *
     * {@see self::PROMISED} ist die Schnittmenge aller Formen, und sie war die
     * vorsichtige Wahl: zu gross gefasst meldete der Nachtlauf jede heile
     * Weiterleitungsdomain als kaputt. Der Preis dafür ist im Abnahmelauf
     * fällig geworden (`docs/99 §5`, `cloudsrv24`): Von den fünfundzwanzig
     * Anweisungen einer PHP-Domain deckte die Schnittmenge **elf**, und die
     * einzige Stelle, an der `nginx -t` einen fehlenden Semikolon still
     * durchlässt, kostet eine der vierzehn anderen — `client_max_body_size`,
     * verschluckt von `index`.
     *
     * > **Eine Zusage über neun Anweisungen sagt über die siebzehn daneben
     * > nichts — und die stille Form des Schadens traf genau eine davon.**
     *
     * Die Antwort ist nicht eine grössere Schnittmenge, sondern **keine**: Die
     * Form ist bekannt, wenn die Datei geschrieben wird, und sie ist bekannt,
     * wenn sie geprüft wird. Gefragt wird deshalb je Form.
     *
     * ## Gemessen und nicht aufgezählt
     *
     * Jede dieser Listen ist die Ausgabe von {@see Statements::heads()} über
     * das Rendering ihrer Form; `PromiseReachTest` rechnet sie in beide
     * Richtungen nach und wird rot, sobald die Vorlage eine Anweisung ergänzt
     * oder verliert. Dass `suspended` und `redirect` dieselben neun führen,
     * steht hier zweimal da, weil es zwei Messungen sind, die
     * übereinstimmen — und nicht ein Eintrag, der für beide gilt.
     *
     * @var array<string, list<string>>
     */
    public const PROMISED_BY_FORM = [
        self::FORM_SUSPENDED => ['access_log', 'add_header', 'default_type', 'error_log', 'error_page', 'if', 'listen', 'location', 'return', 'root', 'server', 'server_name', 'set'],
        self::FORM_REDIRECT => ['access_log', 'add_header', 'default_type', 'error_log', 'error_page', 'if', 'listen', 'location', 'return', 'root', 'server', 'server_name', 'set'],
        self::FORM_PHP => ['access_log', 'add_header', 'client_max_body_size', 'default_type', 'deny', 'error_log', 'error_page', 'fastcgi_index', 'fastcgi_param', 'fastcgi_pass', 'fastcgi_read_timeout', 'fastcgi_split_path_info', 'if', 'include', 'index', 'listen', 'location', 'log_not_found', 'return', 'root', 'server', 'server_name', 'set', 'try_files'],
        self::FORM_STATIC => ['access_log', 'add_header', 'client_max_body_size', 'default_type', 'deny', 'error_log', 'error_page', 'if', 'include', 'index', 'listen', 'location', 'log_not_found', 'return', 'root', 'server', 'server_name', 'set', 'try_files'],
    ];

    /**
     * Was ein Zertifikat dazulegt — und zwar in **jeder** Form.
     *
     * Gemessen am 3. September 2026: Der Block auf 443 nimmt vier Anweisungen
     * dazu und **keine** weg. Port 80 verliert seinen Inhalt an den
     * gesicherten Block und bekommt {@see self::toHttps()}, dessen `location`
     * und `return` jede Form ohnehin führt.
     *
     * **`add_header` steht bewusst nicht darin.** Es erscheint nur, wenn
     * {@see Trust::hsts()} zustimmt, und das hängt am **Inhalt** des
     * Zertifikats: Ein selbstsigniertes bekommt kein HSTS. Gemessen mit einem
     * Wegwerf-Zertifikat einer Autorität ist `add_header` der einzige
     * Unterschied zwischen HSTS an und aus.
     *
     * > **Eine Anweisung, deren Anwesenheit von einem Wert und nicht von der
     * > Form abhängt, ist keine Zusage der Form.**
     *
     * @var list<string>
     */
    public const PROMISED_WITH_TLS = ['ssl_certificate', 'ssl_certificate_key', 'ssl_prefer_server_ciphers', 'ssl_protocols'];

    /**
     * Die Zusage für eine Form — mit Zertifikat oder ohne.
     *
     * **Eine unbekannte Form fällt auf die Schnittmenge zurück und nicht auf
     * die grösste Liste.** Der Fehler fällt damit zur sicheren Seite: Eine
     * Zusage, die zu klein ist, meldet einen Schaden zu wenig; eine, die zu
     * gross ist, meldet jede Nacht jede heile Domain.
     *
     * @return list<string>
     */
    public static function promised(?string $form, bool $tls): array
    {
        $base = self::PROMISED_BY_FORM[$form] ?? self::PROMISED;

        return $tls ? array_values(array_unique([...$base, ...self::PROMISED_WITH_TLS])) : $base;
    }

    /**
     * Der Server-Block — mit HTTPS, sobald ein Zertifikat dafür daliegt.
     *
     * **Welches Zertifikat, sagt das Panel — wo es liegt, weiss der Agent.**
     * `Site::$certificate` ist ein Name und kein Pfad; den Ablageort baut
     * {@see Store} daraus, und ob dort etwas liegt, sieht der Agent selbst
     * nach. Ein Pfad aus der Anwendung wäre bei `ssl_certificate` dasselbe wie
     * bei `root`: die Erlaubnis, eine beliebige Datei des Servers zu benennen.
     *
     * **Nennt das Panel keines, wird keines ausgeliefert** — auch dann nicht,
     * wenn unter dem Namen der Domain etwas läge. Bis zum zweiten Wurf von P4
     * war es andersherum, und damit entschied das Dateisystem darüber, was
     * nginx vorweist (`docs/34 §2.1`).
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
        $tls = $site->certificate === null
            ? null
            : ($store ?? new Store)->existing($site->certificate);

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

        /*
         * **Die Wache steht in beiden Server-Blöcken und auf Serverebene.**
         *
         * Auf Serverebene, weil ein `if` in `location /` die verschachtelte
         * PHP-`location` nicht abdeckt — gemessen (`docs/81 §2.3p` M25):
         * statische Dateien 503, PHP weiter bedient. In beiden Blöcken, weil
         * ohne Zertifikat der Port-80-Block der Inhaltsblock ist und mit
         * Zertifikat der auf 443.
         *
         * Ihre Begründung Zeile für Zeile steht in {@see Maintenance}.
         */
        $wartung = Maintenance::nginxGuard($site->maintenanceUntil);

        $body = match (self::formOf($site)) {
            self::FORM_SUSPENDED => self::suspended(),
            self::FORM_REDIRECT => self::redirect($site),
            default => self::serving($site),
        };

        // Mit Zertifikat beantwortet Port 80 nur noch die Prüfung und leitet
        // weiter; der Inhalt steht dann im 443er Block.
        $plain = $tls === null ? $body : self::toHttps();
        $secure = $tls === null ? '' : self::secure($site, $names, $tls, $body, $wartung);

        return <<<CONF
        {$header}
        server {
            listen 80;
            listen [::]:80;

            server_name {$names};

            access_log {$site->accessLog()};
            error_log  {$site->errorLog()};

        {$wartung}

        {$challenge}

        {$plain}
        }
        {$secure}
        CONF;
    }

    /**
     * Welche Form diese Domain bekommt.
     *
     * **Die Reihenfolge ist tragend und steht deshalb hier und nicht zweimal.**
     * Eine gesperrte Domain mit Weiterleitung wird gesperrt ausgeliefert, nicht
     * weitergeleitet — die Sperre gewinnt. {@see self::render()} fragt diese
     * Methode, und die Bestandsdiagnose fragt sie über das Panel ebenfalls; ein
     * zweites `match` daneben wäre die Fassung, die beim nächsten Zustand
     * vergessen wird.
     *
     * @return self::FORM_*
     */
    public static function formOf(Site $site): string
    {
        return match (true) {
            $site->suspended => self::FORM_SUSPENDED,
            $site->redirectTarget !== null => self::FORM_REDIRECT,
            $site->phpVersion !== null => self::FORM_PHP,
            default => self::FORM_STATIC,
        };
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
    private static function secure(Site $site, string $names, array $tls, string $body, string $wartung): string
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
        {$wartung}

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
