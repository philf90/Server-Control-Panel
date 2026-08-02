<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Guard;

/**
 * Der Angriffsdurchgang für die Prüfungen des Agenten.
 *
 * Er steht schon in P0, obwohl der Agent erst vier Operationen kennt: Die
 * Gewohnheit, jede neue Prüfung mit dem Versuch zu begleiten, sie zu umgehen,
 * entsteht nicht dadurch, dass man sie später vorschreibt.
 */
final class GuardTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function maliciousUnitNames(): array
    {
        return [
            ['../../etc/shadow'],
            ['/etc/passwd'],
            ['nginx.service; rm -rf /'],
            ['nginx.service && reboot'],
            ["nginx\0.service"],
            ['nginx service'],
            ['$(reboot)'],
            ['`reboot`'],
            ['..'],
            ['.hidden.service'],
            [''],
        ];
    }

    #[DataProvider('maliciousUnitNames')]
    public function test_rejects_invalid_unit_names(string $name): void
    {
        $this->expectException(AgentException::class);

        Guard::unitName($name);
    }

    public function test_allows_ordinary_unit_names(): void
    {
        foreach (['nginx.service', 'php8.3-fpm.service', 'srvpanel-agentd.service', 'getty@tty1.service'] as $name) {
            $this->assertSame($name, Guard::unitName($name));
        }
    }

    public function test_rejects_path_outside_root(): void
    {
        $root = sys_get_temp_dir().'/srvpanel-wurzel-'.bin2hex(random_bytes(4));
        mkdir($root);

        try {
            $this->expectException(AgentException::class);
            Guard::pathInside('/etc/passwd', [$root]);
        } finally {
            rmdir($root);
        }
    }

    public function test_rejects_symlink_escaping_root(): void
    {
        $root = sys_get_temp_dir().'/srvpanel-wurzel-'.bin2hex(random_bytes(4));
        mkdir($root);
        $link = $root.'/raus';
        symlink('/etc/passwd', $link);

        try {
            // Der Pfad liegt buchstäblich in der Wurzel. Erst das Auflösen
            // zeigt, dass er woanders hinzeigt — und genau deshalb wird vor
            // der Prüfung aufgelöst und nicht danach.
            $this->expectException(AgentException::class);
            Guard::pathInside($link, [$root]);
        } finally {
            @unlink($link);
            @rmdir($root);
        }
    }

    public function test_returns_resolved_path_inside_root(): void
    {
        $root = sys_get_temp_dir().'/srvpanel-wurzel-'.bin2hex(random_bytes(4));
        mkdir($root.'/tief', 0o755, true);
        file_put_contents($root.'/tief/datei.conf', "test\n");

        try {
            $result = Guard::pathInside($root.'/tief/../tief/datei.conf', [$root]);
            $this->assertSame(realpath($root.'/tief/datei.conf'), $result);
        } finally {
            @unlink($root.'/tief/datei.conf');
            @rmdir($root.'/tief');
            @rmdir($root);
        }
    }

    public function test_enum_accepts_only_known_values(): void
    {
        $this->assertSame('nginx', Guard::enum('nginx', ['nginx', 'sshd'], 'kind'));

        $this->expectException(AgentException::class);
        Guard::enum('bash', ['nginx', 'sshd'], 'kind');
    }
}
