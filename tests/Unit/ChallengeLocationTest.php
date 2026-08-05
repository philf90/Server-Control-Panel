<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Kommt die Prüfung von ACME überall an, wo sie ankommen muss?
 *
 * **`docs/32` hat sich hier geirrt, und der Irrtum war teuer gewesen.** Dort
 * stand, die Kundenvorlage trage HTTP-01 schon halb: Sie hört auf Port 80, und
 * `.well-known` ist vom Punktdatei-Schutz ausgenommen. Diese Ausnahme steht
 * aber nur im **ausliefernden** Zweig. Eine Weiterleitung beantwortet jede
 * Anfrage mit `return 302` und sucht nie eine Datei; ein gesperrtes Abonnement
 * antwortet mit 503. Beide hätten nie ein Zertifikat bekommen — dauerhaft, und
 * ohne dass irgendwo etwas gemeldet hätte.
 *
 * Dazu die Oberfläche selbst: Sie hört auf 8443, die Prüfung fragt immer über
 * Port 80. Ohne eigenen Block bekäme ausgerechnet das Panel nie ein
 * Zertifikat, während jede Kundendomain eines bekommt.
 *
 * **Und die Stelle, die still danebengeht:** `root` gegen `alias`. `root`
 * hängt den ganzen Pfad aus der Adresse an — genau dorthin schreibt der Agent.
 * Mit `alias` suchte nginx zwei Ebenen höher, in einem Verzeichnis, in das nie
 * jemand schreibt, und die Prüfung scheiterte mit „unauthorized": einer
 * Meldung, in der von Pfaden nichts steht.
 *
 * Geprüft wird als Text, weil dieser Container kein nginx hat (CLAUDE.md) —
 * dieselbe Begründung wie bei {@see SiteTemplateTest}.
 */
final class ChallengeLocationTest extends TestCase
{
    /** @param  array<string, mixed>  $overrides */
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

    /** @return array<string, array{array<string, mixed>}> */
    public static function kindsOfSite(): array
    {
        return [
            'ausliefernd' => [[]],
            'weiterleitend' => [['document_root' => null, 'redirect_target' => 'https://ziel.de']],
            'gesperrt' => [['suspended' => true]],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('kindsOfSite')]
    public function test_every_kind_of_site_answers_the_challenge(array $overrides): void
    {
        $config = SiteTemplate::render($this->site($overrides));

        $this->assertStringContainsString('location ^~ '.HttpChallenge::PREFIX.'/ {', $config);
        $this->assertStringContainsString('root '.HttpChallenge::DIRECTORY.';', $config);
    }

    public function test_the_panel_answers_the_challenge_on_port_80(): void
    {
        $config = (new PanelVhost)->template(8443, '/tmp/a.crt', '/tmp/a.key', true, 'panel.example.de');

        $this->assertStringContainsString('listen 80;', $config);
        $this->assertStringContainsString('location ^~ '.HttpChallenge::PREFIX.'/ {', $config);

        // Mit dem Rechnernamen und nicht mit `_`: Ein `_` trifft keinen echten
        // Host-Header und wirkte nur als Vorgabeserver — der ist auf Port 80
        // längst vergeben, nginx liest `srvpanel-sites.conf` vorher.
        $this->assertStringContainsString('server_name panel.example.de;', $config);

        // Und alles andere geht auf die gesicherte Verbindung.
        $this->assertStringContainsString(
            'return 301 https://panel.example.de:8443$request_uri;',
            $config,
        );
    }

    /**
     * nginx sucht die Datei dort, wo der Agent sie hinlegt.
     *
     * Die beiden Hälften stehen an verschiedenen Stellen — der Ablageort in
     * {@see HttpChallenge::present()}, der Suchort in der Vorlage. Dass sie
     * zusammenpassen, prüft sonst nichts, und wenn sie es nicht tun, gibt es
     * keinen Fehler: nur eine Prüfung, die nichts findet.
     */
    public function test_nginx_looks_exactly_where_the_agent_writes(): void
    {
        $directory = sys_get_temp_dir().'/srvpanel-challenge-'.bin2hex(random_bytes(6));
        $token = 'GhFq1x8LwUvTnZ2mQ7cRb0Ay';

        (new HttpChallenge($directory))->present('beispiel.de', $token, $token.'.fingerabdruck');

        $written = $directory.HttpChallenge::PREFIX.'/'.$token;

        $this->assertFileExists($written, 'Der Agent legt die Prüfdatei woanders ab.');

        // Der Teil unterhalb des Verzeichnisses ist genau der Pfad aus der
        // Adresse — das ist die Voraussetzung dafür, dass `root` passt.
        $this->assertSame(HttpChallenge::PREFIX.'/'.$token, substr($written, strlen($directory)));

        // Und die Vorlage sagt `root` und nicht `alias`. Mit `alias` läge der
        // gesuchte Pfad zwei Ebenen höher als der geschriebene.
        $this->assertMatchesRegularExpression(
            '/location \^~ '.preg_quote(HttpChallenge::PREFIX, '/').'\/ \{\s+root /',
            SiteTemplate::render($this->site()),
        );

        @unlink($written);
        @rmdir(dirname($written));
        @rmdir(dirname($written, 2));
        @rmdir($directory);
    }

    /**
     * Der Punktdatei-Schutz bleibt — und greift trotzdem nicht dazwischen.
     *
     * `^~` schlägt jede Regex-Regel, also auch `location ~ /\.`. Ohne das
     * Zeichenpaar entschiede der Schutz über die Prüfadresse und verweigerte
     * sie; mit ihm bleibt beides nebeneinander richtig.
     */
    public function test_the_dotfile_protection_does_not_win_over_the_challenge(): void
    {
        $config = SiteTemplate::render($this->site());

        $this->assertStringContainsString('location ~ /\\.(?!well-known/) {', $config);
        $this->assertStringContainsString('location ^~ '.HttpChallenge::PREFIX.'/ {', $config);
    }
}
