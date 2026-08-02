<?php

declare(strict_types=1);

namespace Tests\Unit;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
    public static function boeseUnitNamen(): array
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

    #[DataProvider('boeseUnitNamen')]
    public function test_weist_unzulaessige_unit_namen_ab(string $name): void
    {
        $this->expectException(AgentException::class);

        Guard::unitName($name);
    }

    public function test_laesst_uebliche_unit_namen_durch(): void
    {
        foreach (['nginx.service', 'php8.3-fpm.service', 'cloudsrv-agentd.service', 'getty@tty1.service'] as $name) {
            $this->assertSame($name, Guard::unitName($name));
        }
    }

    public function test_pfad_ausserhalb_der_wurzel_wird_abgewiesen(): void
    {
        $wurzel = sys_get_temp_dir().'/cloudsrv-wurzel-'.bin2hex(random_bytes(4));
        mkdir($wurzel);

        try {
            $this->expectException(AgentException::class);
            Guard::pathInside('/etc/passwd', [$wurzel]);
        } finally {
            rmdir($wurzel);
        }
    }

    public function test_symlink_aus_der_wurzel_heraus_wird_abgewiesen(): void
    {
        $wurzel = sys_get_temp_dir().'/cloudsrv-wurzel-'.bin2hex(random_bytes(4));
        mkdir($wurzel);
        $link = $wurzel.'/raus';
        symlink('/etc/passwd', $link);

        try {
            // Der Pfad liegt buchstäblich in der Wurzel. Erst das Auflösen
            // zeigt, dass er woanders hinzeigt — und genau deshalb wird vor
            // der Prüfung aufgelöst und nicht danach.
            $this->expectException(AgentException::class);
            Guard::pathInside($link, [$wurzel]);
        } finally {
            @unlink($link);
            @rmdir($wurzel);
        }
    }

    public function test_pfad_in_der_wurzel_wird_aufgeloest_zurueckgegeben(): void
    {
        $wurzel = sys_get_temp_dir().'/cloudsrv-wurzel-'.bin2hex(random_bytes(4));
        mkdir($wurzel.'/tief', 0o755, true);
        file_put_contents($wurzel.'/tief/datei.conf', "test\n");

        try {
            $ergebnis = Guard::pathInside($wurzel.'/tief/../tief/datei.conf', [$wurzel]);
            $this->assertSame(realpath($wurzel.'/tief/datei.conf'), $ergebnis);
        } finally {
            @unlink($wurzel.'/tief/datei.conf');
            @rmdir($wurzel.'/tief');
            @rmdir($wurzel);
        }
    }

    public function test_enum_nimmt_nur_bekannte_werte(): void
    {
        $this->assertSame('nginx', Guard::enum('nginx', ['nginx', 'sshd'], 'art'));

        $this->expectException(AgentException::class);
        Guard::enum('bash', ['nginx', 'sshd'], 'art');
    }
}
