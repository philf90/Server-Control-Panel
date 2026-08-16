<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ssh\Chain;

/**
 * Die Kette, die OpenSSH prüft und `sshd -t` nicht.
 *
 * `docs/57 §8`: `ChrootDirectory` auf ein Verzeichnis, das es nicht gibt, auf
 * eines mit falschen Rechten, `AuthorizedKeysFile` auf eine fehlende Datei —
 * jedes Mal meldet der Prüfer des Dienstes `rc=0`. Und der Klient erfährt den
 * Grund nie: Er bekommt `Broken pipe` (`docs/50 §6`).
 *
 * **Dieser Wächter läuft ohne root.** Was sich ohne root nicht herstellen lässt
 * — ein Verzeichnis, das root gehört —, wird an einem gesucht, das es ohnehin
 * ist (`/usr`); was sich herstellen lässt, wird hergestellt.
 *
 * **Die Brüche dazu** (`tests/waechter-brechen.sh`): die Prüfung auf den
 * Eigentümer streichen; die Prüfung auf das Gruppenschreibrecht streichen; die
 * Kette bei `/var` statt bei `/` anfangen lassen.
 */
final class ChainTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/srvpanel-chain-'.getmypid();
        @mkdir($this->base.'/tief', 0o755, true);
    }

    protected function tearDown(): void
    {
        if (posix_geteuid() === 0) {
            @chown($this->base, 'root');
        }

        @rmdir($this->base.'/tief');
        @rmdir($this->base);
    }

    /**
     * Die Kette fängt bei `/` an.
     *
     * **Und das ist kein Schönheitsfehler, wenn sie es nicht tut.** Gemessen
     * (`docs/57 §9`): Ein gruppenschreibbares `/` weist die Anmeldung ab, und
     * der Server meldet dabei **nichts** über das Chroot — die Auskunft steht
     * eine Station früher, bei der Schlüsseldatei. Wer die Kette bei `/var`
     * anfangen lässt, weil „`/` gehört ja root", hat genau diesen Fall nicht
     * gemessen.
     */
    public function test_the_chain_starts_at_the_root(): void
    {
        $this->assertSame(
            ['/', '/var', '/var/www', '/var/www/vhosts', '/var/www/vhosts/beispiel.de'],
            Chain::components('/var/www/vhosts/beispiel.de'),
            'Die Kette lässt Glieder aus.',
        );
    }

    /** Ein Verzeichnis, das root gehört und nicht für andere schreibbar ist, taugt. */
    public function test_a_root_owned_directory_passes(): void
    {
        $kette = Chain::of('/usr');

        $this->assertSame(['/', '/usr'], array_column($kette, 'path'));

        foreach ($kette as $glied) {
            $this->assertTrue($glied['ok'], $glied['path'].' gilt als untauglich: '.$glied['reason']);
        }
    }

    /**
     * Ein Verzeichnis, das jemand anderem gehört, taugt nicht — und der Satz sagt, wem.
     *
     * „Stimmt nicht" schickte den Betreiber suchen; der Eigentümer steht in der
     * Begründung.
     */
    public function test_a_foreign_owner_is_named(): void
    {
        /*
         * **Kein Ausweichzweig für root.** Hier stand einmal „als root ist
         * dieser Fall nicht herstellbar" mit einem `assertTrue(true)` dahinter
         * — und damit war der Wächter in genau der Umgebung blind, in der der
         * Agent später läuft. Er ist herstellbar: `chown` auf `nobody`.
         *
         * > **Ein Zweig, der nichts prüft, ist kein Sonderfall, sondern ein
         * > Loch mit Begründung.**
         */
        if (posix_geteuid() === 0) {
            chown($this->base, 'nobody');
        }

        /*
         * **Gefragt wird nach dem Glied und nicht nach dem ersten Befund.**
         * Beim ersten Anlauf stand hier {@see Chain::firstProblem()}, und der
         * meldete `/tmp` — zu Recht, denn das ist `1777`. Die Behauptung sah
         * richtig aus und prüfte ein anderes Verzeichnis als gemeint.
         *
         * Nebenbei ist damit belegt, dass unter `/tmp` kein Chroot liegen kann.
         */
        $glieder = Chain::of($this->base);
        $glied = null;

        foreach ($glieder as $eintrag) {
            if ($eintrag['path'] === $this->base) {
                $glied = $eintrag;
            }
        }

        $this->assertNotNull($glied, 'Das Verzeichnis kommt in seiner eigenen Kette nicht vor.');
        $this->assertFalse($glied['ok'], 'Ein Verzeichnis, das nicht root gehört, gilt als tauglich.');
        $this->assertStringContainsString('nicht root', (string) $glied['reason']);
        $this->assertNotNull(Chain::firstProblem($glieder), 'Die Kette meldet keinen Befund.');
    }

    /** Und das **erste** klemmende Glied wird gemeldet, nicht das letzte. */
    public function test_the_first_problem_is_the_one_that_is_named(): void
    {
        $kette = [
            ['path' => '/', 'owner' => 'root', 'group' => 'root', 'mode' => '0755', 'ok' => true, 'reason' => ''],
            ['path' => '/a', 'owner' => 'p1', 'group' => 'p1', 'mode' => '0755', 'ok' => false, 'reason' => 'gehört p1 und nicht root'],
            ['path' => '/a/b', 'owner' => 'p1', 'group' => 'p1', 'mode' => '0777', 'ok' => false, 'reason' => 'ist für alle schreibbar'],
        ];

        $this->assertSame('/a', Chain::firstProblem($kette)['path'] ?? null);
    }

    /** Ein Verzeichnis, das es nicht gibt, ist ein Befund und kein Absturz. */
    public function test_a_missing_directory_is_a_finding(): void
    {
        $kette = Chain::of($this->base.'/gibt-es-nicht');
        $letztes = end($kette);

        $this->assertFalse($letztes['ok']);
        $this->assertSame('gibt es nicht', $letztes['reason']);
    }

    /** Schreibrecht für Gruppe oder Andere schliesst aus — beides einzeln. */
    public function test_a_writable_bit_is_enough_to_fail(): void
    {
        foreach ([0o775 => 'Gruppe', 0o757 => 'alle'] as $mode => $wer) {
            chmod($this->base.'/tief', $mode);

            $kette = Chain::of($this->base.'/tief');
            $letztes = end($kette);

            $this->assertFalse($letztes['ok'], sprintf('%04o gilt als tauglich.', $mode));
            $this->assertStringContainsString(
                'schreibbar',
                $letztes['reason'],
                sprintf('Bei %04o fehlt der Grund für %s.', $mode, $wer),
            );
        }

        chmod($this->base.'/tief', 0o755);
    }
}
