<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Der Server-Block einer Kundenwebsite — geprüft ohne nginx.
 *
 * Dieser Container hat keinen Webserver (siehe CLAUDE.md), und die CI hat
 * einen. Was hier steht, muss deshalb an der Vorlage prüfbar sein und nicht
 * am laufenden Dienst: Der Standardschutz ist eine Eigenschaft des Textes.
 *
 * **Der Fall, der diesem Test seinen Wert gibt, ist der ohne PHP.** Eine
 * Domain ohne Handler, deren `.php`-Dateien nginx als Text ausliefert, ist
 * kein Schönheitsfehler — sie gibt Datenbankpasswörter heraus. Der Test steht
 * deshalb an erster Stelle.
 */
final class SiteTemplateTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function site(array $overrides = []): Site
    {
        return Site::fromArgs(array_merge([
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
            'php_version' => '8.4',
        ], $overrides));
    }

    public function test_without_php_no_php_file_is_delivered(): void
    {
        $config = SiteTemplate::render($this->site(['php_version' => null]));

        $this->assertStringContainsString('location ~ \.php$ {', $config);
        $this->assertStringContainsString('return 404;', $config);

        // Kein Handler heisst: kein fastcgi_pass, auf keinen Sockel.
        $this->assertStringNotContainsString('fastcgi_pass', $config);
    }

    public function test_the_default_protection_is_in_every_site(): void
    {
        foreach ([['php_version' => '8.4'], ['php_version' => null]] as $overrides) {
            $config = SiteTemplate::render($this->site($overrides));

            // Punktdateien: .git, .env, .htaccess in einem Ausdruck.
            $this->assertStringContainsString('location ~ /\.(?!well-known/)', $config);
            $this->assertStringContainsString('deny all;', $config);

            // PHP in Verzeichnissen, in die hochgeladen wird.
            $this->assertStringContainsString('uploads?|files|media', $config);
        }
    }

    /**
     * `.well-known` bleibt erreichbar.
     *
     * Ohne diese Ausnahme bekäme keine Domain je ein Zertifikat: Die
     * HTTP-01-Prüfung von ACME legt ihre Datei genau dort ab. Das fiele erst
     * in P4 auf, und dann an einer Stelle, an der niemand mehr an die
     * Punktdatei-Regel denkt.
     */
    public function test_well_known_is_exempt_from_the_dotfile_rule(): void
    {
        $this->assertStringContainsString(
            '(?!well-known/)',
            SiteTemplate::render($this->site()),
        );
    }

    public function test_php_is_only_run_for_files_that_exist(): void
    {
        $config = SiteTemplate::render($this->site());

        // Ohne `try_files $uri =404` führt /bild.jpg/schad.php dazu, dass
        // nginx die hochgeladene Datei an PHP übergibt.
        $this->assertStringContainsString('try_files $uri =404;', $config);

        // Und zwar **vor** dem Handler: Danach wäre die Anfrage längst
        // übergeben. Hier stand zuerst ein Ausdruck über beide Zeilen
        // zusammen — er blieb grün, als die Zeile zur Gegenprobe entfernt
        // wurde, weil er anderswo im Block ein Vorkommen fand.
        $this->assertLessThan(
            strpos($config, 'fastcgi_pass'),
            strpos($config, 'try_files $uri =404;'),
        );
    }

    public function test_the_socket_belongs_to_the_subscription_and_version(): void
    {
        $config = SiteTemplate::render($this->site());

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/srvpanel-p1001-8.4.sock;', $config);
    }

    public function test_domain_settings_travel_as_php_value(): void
    {
        $config = SiteTemplate::render($this->site([
            'php_settings' => ['memory_limit' => '256M', 'upload_max_filesize' => '128M'],
        ]));

        $this->assertStringContainsString('fastcgi_param PHP_VALUE "memory_limit=256M', $config);
        $this->assertStringContainsString('upload_max_filesize=128M";', $config);

        // nginx muss mindestens so viel durchlassen, wie PHP annehmen darf —
        // sonst endet jeder Hochladevorgang mit 413 und die gerade gesetzte
        // Einstellung sieht aus, als täte sie nichts.
        $this->assertStringContainsString('client_max_body_size 128m;', $config);
    }

    public function test_a_suspended_site_answers_503_and_delivers_nothing(): void
    {
        $config = SiteTemplate::render($this->site(['suspended' => true]));

        $this->assertStringContainsString('return 503', $config);

        // Kein Wurzelverzeichnis, kein Handler: Die Daten bleiben liegen und
        // werden von nichts ausgeliefert.
        $this->assertStringNotContainsString('root /var/www/vhosts', $config);
        $this->assertStringNotContainsString('fastcgi_pass', $config);
    }

    public function test_a_redirect_has_neither_root_nor_handler(): void
    {
        $config = SiteTemplate::render($this->site([
            'redirect_target' => 'https://ziel.de/pfad',
            'redirect_code' => 301,
        ]));

        $this->assertStringContainsString('return 301 https://ziel.de/pfad$request_uri;', $config);
        $this->assertStringNotContainsString('root /var/www/vhosts', $config);
        $this->assertStringNotContainsString('fastcgi_pass', $config);
    }

    public function test_aliases_stand_in_the_server_name(): void
    {
        $config = SiteTemplate::render($this->site([
            'aliases' => ['www.beispiel.de', 'beispiel.at', 'beispiel.de'],
        ]));

        // Der eigene Name kommt nicht doppelt vor — für nginx wäre das ein
        // „conflicting server name".
        $this->assertStringContainsString('server_name beispiel.de www.beispiel.de beispiel.at;', $config);
    }

    public function test_every_path_stays_inside_the_subscription(): void
    {
        $config = SiteTemplate::render($this->site(['document_root' => 'httpdocs/public']));

        $this->assertStringContainsString('root /var/www/vhosts/beispiel.de/httpdocs/public;', $config);
        $this->assertStringContainsString('access_log /var/www/vhosts/beispiel.de/logs/beispiel.de/access.log;', $config);
        $this->assertStringContainsString('include /var/www/vhosts/beispiel.de/conf/beispiel.de.include;', $config);
    }

    public function test_own_directives_land_in_the_include_file(): void
    {
        $text = SiteTemplate::includeFile(['client_max_body_size 512m;', 'autoindex off;']);

        $this->assertStringContainsString('client_max_body_size 512m;', $text);
        $this->assertStringContainsString('autoindex off;', $text);

        // Auch ohne Eintrag entsteht die Datei: Eine include-Zeile auf eine
        // fehlende Datei lässt nginx nicht starten.
        $this->assertNotSame('', SiteTemplate::includeFile([]));
    }
}
