<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SftpAccess;

/**
 * Ohne `/run/sshd` prüft `sshd -t` nichts — auch nicht die eigene Datei.
 *
 * **Der Fund aus Punkt 9 des Abnahmelaufs** (`docs/59`, Befund 16). Bei
 * angehaltenem Dienst meldete `sshd -t` auf `cloudsrv24`
 * `Missing privilege separation directory: /run/sshd` mit `rc=255`. Das
 * Verzeichnis legt die Unit an (`RuntimeDirectory=sshd`), und systemd räumt es
 * beim Anhalten weg.
 *
 * > **Eine Prüfung, die die Umgebung des Prüfers braucht, prüft nicht nur den
 * > Prüfling.**
 *
 * Das trifft genau den Zustand, für den `SftpAccess::reload()` den Zweig „läuft
 * nicht ist kein Fehlschlag" hat — der Zweig kam nie zum Zug, weil die Prüfung
 * davor liegt und abbricht.
 *
 * Gemessen gegen OpenSSH 9.6p1: fehlt es, `rc=255`; ist es `0777`, ebenfalls
 * `rc=255` mit anderem Wortlaut; mit `0755` läuft es durch.
 */
final class SftpRuntimeDirTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/srvpanel-runtime-'.getmypid();
        $this->remove();
    }

    protected function tearDown(): void
    {
        $this->remove();
    }

    private function remove(): void
    {
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    private function mode(): int
    {
        clearstatcache(true, $this->dir);

        return ((int) fileperms($this->dir)) & 0o7777;
    }

    /** Fehlt es, wird es angelegt — und zwar so, wie sshd es verlangt. */
    public function test_a_missing_directory_is_created(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);

        SftpAccess::ensureRuntime($this->dir);

        $this->assertDirectoryExists($this->dir);
        $this->assertSame(0o755, $this->mode());
    }

    /**
     * Ist es schreibbar für Gruppe oder Andere, wird es zurechtgerückt.
     *
     * Gemessen: `0777` lässt `sshd -t` mit `rc=255` scheitern, und zwar mit
     * einem anderen Satz als beim Fehlen — beide Male aus einem Grund, der mit
     * der geprüften Datei nichts zu tun hat.
     */
    public function test_a_writable_directory_is_corrected(): void
    {
        mkdir($this->dir, 0o777, true);
        chmod($this->dir, 0o777);

        SftpAccess::ensureRuntime($this->dir);

        $this->assertSame(0o755, $this->mode());
    }

    /**
     * Und ein taugliches Verzeichnis wird nicht angefasst.
     *
     * Die Gegenprobe: Ohne sie hiesse „es stimmt hinterher" auch, dass jedes
     * Verzeichnis des Systems neue Rechte bekommt, weil wir vorbeikommen.
     */
    public function test_a_sound_directory_is_left_alone(): void
    {
        mkdir($this->dir, 0o750, true);
        chmod($this->dir, 0o750);

        SftpAccess::ensureRuntime($this->dir);

        $this->assertSame(0o750, $this->mode());
    }

    /**
     * Und der Aufruf steht **vor** der Prüfung des Kandidaten.
     *
     * Der Fehler war nicht, dass das Verzeichnis fehlte — der Fehler war, dass
     * niemand danach sah, bevor `sshd -t` lief. Eine Reihenfolge, die nur im
     * Kopf des Autors steht, ist beim nächsten Umbau weg.
     */
    public function test_the_directory_is_ensured_before_the_check(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/SftpAccess.php');

        // Ohne Kommentare: Die Begründung nennt beide Namen.
        $source = (string) preg_replace(['#/\*.*?\*/#su', '#//[^\n]*#'], '', $source);

        $ensure = strpos($source, 'self::ensureRuntime()');
        $check = strpos($source, "'-t', '-f'");

        $this->assertNotFalse($ensure, 'ensureRuntime() wird in execute() nicht aufgerufen.');
        $this->assertNotFalse($check, 'Der Aufruf von sshd -t ist nicht zu finden.');
        $this->assertLessThan(
            $check,
            $ensure,
            'ensureRuntime() steht hinter der Prüfung des Kandidaten — dann läuft sshd -t weiter '
            .'in eine Umgebung, die es nicht gibt.',
        );
    }
}
