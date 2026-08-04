<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SubscriptionRemove;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\Runner;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * §8.7: „Abo löschen entfernt alles restlos, geprüft durch einen Test, der
 * hinterher das Dateisystem absucht."
 *
 * **Bis P3 war das Kriterium leicht zu erfüllen** — alles zu einem Abonnement
 * lag unter `/var/www/vhosts/<abo>`, und der Baumlauf nahm es mit. Mit den
 * Websites liegen drei Dinge ausserhalb: der Server-Block in
 * `/etc/nginx/srvpanel.d`, der FPM-Pool in `/etc/php/<version>/fpm/pool.d`,
 * die Rotation in `/etc/logrotate.d`. Keines davon sieht der Baumlauf.
 *
 * Der Test sucht danach ab — in einem Sandkasten, denn ein Test, der
 * `/etc/nginx` anfasst, ist keiner, der zweimal läuft.
 */
final class SubscriptionCleanupTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/srvpanel-cleanup-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox.'/nginx', 0o755, true);
        mkdir($this->sandbox.'/logrotate', 0o755, true);

        foreach (PhpVersions::CATALOG as $version) {
            mkdir(PhpVersions::poolDir($version, $this->sandbox.'/php'), 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->sandbox);

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        foreach (glob($path.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $child) {
            is_dir($child) ? $this->remove($child) : @unlink($child);
        }

        @rmdir($path);
    }

    private function op(): SubscriptionRemove
    {
        return new SubscriptionRemove(
            $this->sandbox.'/nginx',
            $this->sandbox.'/logrotate',
            $this->sandbox.'/php',
        );
    }

    /**
     * Die Aufräumung ist privat — geprüft wird ihre Wirkung, nicht ihre
     * Sichtbarkeit. Dasselbe Vorgehen wie in {@see SubscriptionRemoveTest}.
     *
     * @return array<string, list<string>>
     */
    private function cleanup(string $name, string $user): array
    {
        $method = new ReflectionMethod(SubscriptionRemove::class, 'removeConfiguration');

        $context = new Context(
            new Runner(new Journal($this->sandbox.'/agent.log')),
            new Journal($this->sandbox.'/agent.log'),
            static function (array $frame): void {},
        );

        /** @var array<string, list<string>> $result */
        $result = $method->invoke($this->op(), $context, $name, $user, '/var/www/vhosts/'.$name);

        return $result;
    }

    /** @param list<string> $domains */
    private function seed(string $name, string $user, array $domains, string $version = '8.4'): void
    {
        foreach ($domains as $domain) {
            // Der echte Server-Block, nicht eine Nachbildung: Was der Test
            // sucht, muss der sein, den `web.site.apply` schreibt.
            file_put_contents(
                $this->sandbox.'/nginx/'.$domain.'.conf',
                SiteTemplate::render(Site::fromArgs([
                    'subscription' => $name,
                    'user' => $user,
                    'domain' => $domain,
                    'document_root' => 'httpdocs',
                    'php_version' => $version,
                ])),
            );
        }

        file_put_contents(PhpVersions::poolFile($version, $user, $this->sandbox.'/php'), "[{$user}]\n");
        file_put_contents($this->sandbox.'/logrotate/srvpanel-'.$name, "# rotation\n");
    }

    public function test_nothing_of_the_subscription_is_left_behind(): void
    {
        $this->seed('beispiel.de', 'p1001', ['beispiel.de', 'zweite.de', 'shop.beispiel.de']);

        $removed = $this->cleanup('beispiel.de', 'p1001');

        $this->assertCount(3, $removed['sites']);
        $this->assertCount(1, $removed['pools']);
        $this->assertCount(1, $removed['logrotate']);

        // Und die Gegenprobe über das Dateisystem — die Frage, die §8.7 stellt.
        $this->assertSame([], glob($this->sandbox.'/nginx/*.conf') ?: []);
        $this->assertSame([], glob($this->sandbox.'/php/*/fpm/pool.d/*.conf') ?: []);
        $this->assertSame([], glob($this->sandbox.'/logrotate/*') ?: []);
    }

    /**
     * Und nichts von einem anderen Abonnement.
     *
     * Das ist die Hälfte, an der ein Aufräumen scheitert, ohne dass es
     * auffällt: Es räumt zu viel. Ein Server-Block, der jemand anderem gehört,
     * ist danach fort, und dessen Website antwortet nicht mehr.
     */
    public function test_a_foreign_subscription_is_untouched(): void
    {
        $this->seed('beispiel.de', 'p1001', ['beispiel.de']);
        $this->seed('anderes.de', 'p1002', ['anderes.de', 'noch-eins.de'], '8.3');

        $this->cleanup('beispiel.de', 'p1001');

        $this->assertFileDoesNotExist($this->sandbox.'/nginx/beispiel.de.conf');

        $this->assertFileExists($this->sandbox.'/nginx/anderes.de.conf');
        $this->assertFileExists($this->sandbox.'/nginx/noch-eins.de.conf');
        $this->assertFileExists(PhpVersions::poolFile('8.3', 'p1002', $this->sandbox.'/php'));
        $this->assertFileExists($this->sandbox.'/logrotate/srvpanel-anderes.de');
    }

    /**
     * Ein Name, der Anfang eines anderen ist.
     *
     * `beispiel.de` und `beispiel.de.alt` — der Pfad des einen steckt im Pfad
     * des anderen. Ohne den Schrägstrich im Vergleich nähme der Rückbau des
     * ersten die Blöcke des zweiten mit.
     */
    public function test_a_name_that_starts_another_one_is_untouched(): void
    {
        $this->seed('beispiel.de', 'p1001', ['beispiel.de']);
        $this->seed('beispiel.de.alt', 'p1003', ['alt.beispiel-zwei.de'], '8.3');

        $this->cleanup('beispiel.de', 'p1001');

        $this->assertFileExists($this->sandbox.'/nginx/alt.beispiel-zwei.de.conf');
        $this->assertFileExists($this->sandbox.'/logrotate/srvpanel-beispiel.de.alt');
    }

    /**
     * Wiederholbar: Ein zweiter Lauf findet nichts mehr und scheitert nicht.
     *
     * Dieselbe Bedingung wie beim Verzeichnisbaum — ohne sie hinge ein
     * abgebrochener Rückbau für immer, weil sein zweiter Versuch an dem
     * scheitert, was der erste schon geschafft hat.
     */
    public function test_a_second_run_finds_nothing(): void
    {
        $this->seed('beispiel.de', 'p1001', ['beispiel.de']);

        $this->cleanup('beispiel.de', 'p1001');
        $zweiter = $this->cleanup('beispiel.de', 'p1001');

        $this->assertSame(['sites' => [], 'pools' => [], 'logrotate' => []], $zweiter);
    }

    /**
     * Auch der Block, den niemand mehr auf der Rechnung hat.
     *
     * Er entsteht, wenn ein früherer Lauf abgebrochen ist: Die Domain steht im
     * Panel nicht mehr, ihre Datei liegt noch. Deshalb wird gesucht und nicht
     * eine übergebene Liste abgearbeitet — die wäre genau hier unvollständig.
     */
    public function test_an_orphaned_block_is_found_too(): void
    {
        $this->seed('beispiel.de', 'p1001', ['beispiel.de']);

        // Eine Datei, die keine Domain des Panels mehr kennt, die aber auf das
        // Verzeichnis des Abonnements zeigt.
        file_put_contents(
            $this->sandbox.'/nginx/vergessen.de.conf',
            "server {\n    access_log /var/www/vhosts/beispiel.de/logs/vergessen.de/access.log;\n}\n",
        );

        $removed = $this->cleanup('beispiel.de', 'p1001');

        $this->assertCount(2, $removed['sites']);
        $this->assertSame([], glob($this->sandbox.'/nginx/*.conf') ?: []);
    }
}
