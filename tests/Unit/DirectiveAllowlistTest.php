<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Directives;

/**
 * Der Angriffsdurchgang gegen die eigenen nginx-Direktiven.
 *
 * Das ist die einzige Stelle in P3, an der Text eines Kunden in einer Datei
 * landet, die als root gelesen wird. §4.2 lässt sie ausdrücklich zu — „gegen
 * eine Positivliste erlaubter Direktiven geprüft" — und dieser Test ist die
 * Gegenprobe zu diesem Halbsatz.
 *
 * Die Liste unten ist nach dem gebaut, was ein Angreifer tatsächlich
 * versucht: die Zeile beenden und eine zweite anhängen, einen Block öffnen,
 * `root` oder `alias` unterbringen, über `include` eine eigene Datei
 * nachladen, den Rest mit `#` auskommentieren.
 */
final class DirectiveAllowlistTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function accepted(): array
    {
        return [
            ['client_max_body_size 512m;'],
            ['autoindex off;'],
            ['charset utf-8;'],
            ['expires 30d;'],
            ['add_header X-Frame-Options SAMEORIGIN;'],
            ['add_header Strict-Transport-Security "max-age=63072000";'],
            ['error_page 404 /404.html;'],
            ['gzip on;'],
            ['limit_rate 512k;'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepts(string $line): void
    {
        $this->assertSame($line, Directives::one($line, 'd'));
    }

    /** @return list<array{0:string}> */
    public static function rejected(): array
    {
        return [
            // Nicht auf der Positivliste — und jede davon aus einem Grund.
            ['root /etc;'],                                  // liefert fremde Verzeichnisse aus
            ['alias /var/www/vhosts/anderes-abo/httpdocs/;'], // dasselbe, nur eleganter
            ['include /etc/passwd;'],                        // lädt nach, was es will
            ['fastcgi_pass unix:/run/php/srvpanel-p1002-8.4.sock;'], // fremder Pool
            ['proxy_pass http://127.0.0.1:22;'],             // der Server als Sprungbrett
            ['user root;'],                                  // ausserhalb von server{} ohnehin, aber nein
            ['ssl_certificate /etc/srvpanel/tls/panel.key;'],

            // Eine zweite Anweisung anhängen.
            ["autoindex on;\nroot /etc;"],
            ["autoindex on;\r\nroot /etc;"],
            ['autoindex on; root /etc;'],
            ['autoindex on;root /etc;'],

            // Einen Block öffnen.
            ['location / { root /etc; }'],
            ['autoindex on; } server { listen 81;'],

            // Den Rest auskommentieren.
            ['autoindex on; # root /etc;'],
            ['# autoindex on;'],

            // Befehlszeichen und Formfehler.
            ['autoindex `reboot`;'],
            ['autoindex on'],
            ['AUTOINDEX on;'],
            [';'],
            [''],
            ['   '],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $line): void
    {
        $this->expectException(AgentException::class);

        Directives::one($line, 'd');
    }

    public function test_the_number_is_limited(): void
    {
        $many = array_fill(0, Directives::MAX_COUNT + 1, 'autoindex off;');

        $this->expectException(AgentException::class);

        Directives::check($many);
    }

    public function test_a_single_directive_is_limited_in_length(): void
    {
        $this->expectException(AgentException::class);

        Directives::one('add_header X-Test '.str_repeat('a', Directives::MAX_LENGTH).';', 'd');
    }

    /**
     * Die Namen, die niemals auf die Liste dürfen.
     *
     * Der Test steht nicht neben den abgewiesenen Zeilen oben, sondern für
     * sich: Er prüft die **Liste** und nicht die Prüfung. Wer eine Direktive
     * ergänzt, die einen Pfad oder einen Empfänger bestimmt, bekommt hier
     * einen roten Lauf — auch dann, wenn seine eigene Zeile syntaktisch
     * einwandfrei ist.
     */
    public function test_no_path_or_upstream_directive_is_on_the_list(): void
    {
        $forbidden = [
            'root', 'alias', 'include', 'fastcgi_pass', 'proxy_pass', 'uwsgi_pass',
            'scgi_pass', 'grpc_pass', 'user', 'ssl_certificate', 'ssl_certificate_key',
            'access_log', 'error_log', 'try_files', 'perl', 'lua_code_cache',
        ];

        foreach ($forbidden as $name) {
            $this->assertNotContains(
                $name,
                Directives::ALLOWED,
                sprintf('%s bestimmt einen Pfad oder einen Empfänger und gehört nicht auf die Positivliste.', $name),
            );
        }
    }

    public function test_null_and_empty_are_no_directives(): void
    {
        $this->assertSame([], Directives::check(null));
        $this->assertSame([], Directives::check([]));
    }
}
