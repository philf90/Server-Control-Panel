<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\DocumentRoot;
use SrvPanel\Agent\Site;

/**
 * Der Pfadausbruch — Angriffsdurchgang gegen die Stelle, die Pfade baut.
 *
 * {@see Site} ist in P3 die einzige Stelle, an der aus Angaben des Panels
 * Dateipfade werden. Was sie durchlässt, landet als `root` in einer
 * nginx-Konfiguration, als `open_basedir` in einem FPM-Pool und als Ziel eines
 * `rm` als root. Deshalb steht hier dieselbe Sorte Liste wie in
 * {@see GuardTest}: nicht was gehen soll, sondern was auf keinen Fall gehen
 * darf.
 */
final class SitePathTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function args(array $overrides = []): array
    {
        return array_merge([
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
        ], $overrides);
    }

    public function test_every_path_is_built_below_the_subscription(): void
    {
        $site = Site::fromArgs($this->args(['document_root' => 'shop/public', 'php_version' => '8.4']));

        $root = '/var/www/vhosts/beispiel.de';

        $this->assertSame($root, $site->subscriptionRoot());
        $this->assertSame($root.'/shop/public', $site->documentRootPath());
        $this->assertSame($root.'/logs/beispiel.de', $site->logDir());
        $this->assertSame($root.'/conf/beispiel.de.include', $site->includeFile());
        $this->assertSame('/etc/nginx/srvpanel.d/beispiel.de.conf', $site->confFile());
        $this->assertSame('/run/php/srvpanel-p1001-8.4.sock', $site->socket());
    }

    /** @return list<array{0:string}> */
    public static function escapes(): array
    {
        return [
            ['/etc/nginx'],
            ['../../etc'],
            ['httpdocs/../../../etc'],
            ['..'],
            ['./httpdocs'],
            ['httpdocs/'],
            ['httpdocs//shop'],
            ['.ssh'],
            ['.env'],
            ['logs'],
            ['conf'],
            ['tmp'],
            ['mail'],
            ["httpdocs\nroot /etc;"],
            ['httpdocs;'],
            ['a/b/c/d/e/f/g/h/i'],
            [''],
        ];
    }

    #[DataProvider('escapes')]
    public function test_rejects_document_root(string $documentRoot): void
    {
        $this->expectException(AgentException::class);

        Site::fromArgs($this->args(['document_root' => $documentRoot]));
    }

    /**
     * Der Name des Abonnements ist der erste Bestandteil jedes Pfades.
     *
     * Er geht durch dieselbe Prüfung wie in `subscription.provision`. Ohne sie
     * wäre `../../etc` als Abonnementname der kürzeste Weg aus dem
     * Vhost-Verzeichnis heraus — und zwar in jeden Pfad dieser Klasse auf
     * einmal.
     */
    public function test_rejects_a_subscription_name_that_is_a_path(): void
    {
        foreach (['../andere', '/etc', 'a/b', '..', 'ABO'] as $name) {
            try {
                Site::fromArgs($this->args(['subscription' => $name]));
                $this->fail(sprintf('%s hätte abgewiesen werden müssen.', $name));
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_a_system_user_that_is_not_a_subscription_user(): void
    {
        foreach (['root', 'www-data', 'srvpanel', 'p12', '../p1001'] as $user) {
            try {
                Site::fromArgs($this->args(['user' => $user]));
                $this->fail(sprintf('%s hätte abgewiesen werden müssen.', $user));
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<array{0:string}> */
    public static function badRedirects(): array
    {
        return [
            ['javascript:alert(1)'],
            ['file:///etc/passwd'],
            ['//ziel.de'],
            ['https://ziel.de/pfad";return 200 "x'],
            ["https://ziel.de/\npfad"],
            ['https://ziel.de/$document_root'],
            ['https://localhost/'],
            ['https://192.168.0.1/'],
            ['ftp://ziel.de/'],
        ];
    }

    #[DataProvider('badRedirects')]
    public function test_rejects_redirect_target(string $target): void
    {
        $this->expectException(AgentException::class);

        Site::fromArgs($this->args(['redirect_target' => $target]));
    }

    public function test_a_redirect_has_no_document_root_and_no_handler(): void
    {
        $site = Site::fromArgs($this->args([
            'redirect_target' => 'https://ziel.de/',
            'php_version' => '8.4',
            'document_root' => 'httpdocs',
        ]));

        $this->assertNull($site->documentRootPath());
        $this->assertNull($site->phpVersion);
        $this->assertNull($site->socket());
        $this->assertSame('https://ziel.de/', $site->redirectTarget);
    }

    public function test_the_redirect_code_is_301_or_302(): void
    {
        $this->assertSame(302, Site::fromArgs($this->args(['redirect_target' => 'https://ziel.de/']))->redirectCode);

        $this->expectException(AgentException::class);

        Site::fromArgs($this->args(['redirect_target' => 'https://ziel.de/', 'redirect_code' => 307]));
    }

    /** Ein Alias geht durch dieselbe Namensprüfung wie die Domain selbst. */
    public function test_rejects_a_bad_alias(): void
    {
        $this->expectException(AgentException::class);

        Site::fromArgs($this->args(['aliases' => ['*.beispiel.de']]));
    }

    public function test_the_reserved_directories_come_from_the_scheme(): void
    {
        // Dieselbe Prüfung wie im Panel, und dieselbe Quelle: Wächst das
        // Verzeichnisschema, wächst diese Liste mit.
        $this->assertFalse(DocumentRoot::valid('logs'));
        $this->assertTrue(DocumentRoot::valid('httpdocs'));
        $this->assertSame('httpdocs', DocumentRoot::forDomain('beispiel.de', true));
        $this->assertSame('beispiel.de', DocumentRoot::forDomain('beispiel.de', false));
    }
}
