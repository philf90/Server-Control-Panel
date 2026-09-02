<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Result;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Die Quota wird aus beiden Werkzeugen beurteilt — und die dritte Zeile ist `fail`.
 *
 * **Die Prüfkörper sind die gemessenen Ausgaben** aus `docs/81 §2.3o` M10 und
 * M11, Byte für Byte: die drei Zustände auf dem Wegwerf-ext4 im Loop und der
 * Einhängepunkt ohne Option. Die dritte Zeile — Quotadatei da, Quota aus — ist
 * der Zustand, den das Panel bis A10 als Entwarnung gelesen hat.
 *
 * > **Ein Leseversuch belegt, dass etwas zu lesen war — nicht, dass es gilt.**
 */
final class QuotaVerdictTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    /** M10: mit gesetzter Option antwortet `quotaon -p` auf **stdout**. */
    private const QUOTAON_OFF = "group quota on /mnt/messquota (/dev/loop0) is off\nuser quota on /mnt/messquota (/dev/loop0) is off\nproject quota on /mnt/messquota (/dev/loop0) is off\n";

    private const QUOTAON_ON = "group quota on /mnt/messquota (/dev/loop0) is off\nuser quota on /mnt/messquota (/dev/loop0) is on\nproject quota on /mnt/messquota (/dev/loop0) is off\n";

    /** M10: ohne Option antwortet es auf **stderr** — und mit rc 0. */
    private const QUOTAON_NO_OPTION = "quotaon: Mountpoint (or device) / not found or has no quota enabled.\n";

    private const REPQUOTA_NO_FILE = "repquota: Cannot open quotafile /mnt/messquota/aquota.user: No such file or directory\nrepquota: Not all specified mountpoints are using quota.\n";

    private const REPQUOTA_TABLE = "*** Report for user quotas on device /dev/loop0\nBlock grace time: 7days; Inode grace time: 7days\n                        Space limits                File limits\nUser            used    soft    hard  grace    used  soft  hard  grace\n----------------------------------------------------------------------\nroot      --     20K      0K      0K              2     0     0       \n";

    /**
     * @return array<string, array{0: Result, 1: Result, 2: null|string}>
     */
    public static function measured(): array
    {
        return [
            // Zustand 2 — der Fall von cloudsrv24 (docs/41)
            'Option gesetzt, Quotadatei fehlt' => [new Result(0, self::QUOTAON_OFF, ''), new Result(1, '', self::REPQUOTA_NO_FILE), 'off'],
            // Zustand 3 — die dritte Zeile
            'Quotadatei da, Quota aus' => [new Result(0, self::QUOTAON_OFF, ''), new Result(0, self::REPQUOTA_TABLE, ''), 'not_enforced'],
            // Zustand 1 — keine Option; die Antwort steht auf stderr
            'Einhängepunkt ohne usrquota' => [new Result(0, '', self::QUOTAON_NO_OPTION), new Result(1, '', "repquota: Mountpoint (or device) / not found or has no quota enabled.\n"), 'off'],
            // der gesunde Fall — hier nicht herstellbar (M12), aus der Symmetrie
            'Quota an' => [new Result(0, self::QUOTAON_ON, ''), new Result(0, self::REPQUOTA_TABLE, ''), null],
            'Quota an, Leseversuch scheitert' => [new Result(0, self::QUOTAON_ON, ''), new Result(1, '', 'repquota: irgendwas'), 'unreachable'],
            'quotaon sagt nichts Lesbares' => [new Result(0, '', ''), new Result(0, self::REPQUOTA_TABLE, ''), 'unreachable'],
        ];
    }

    #[DataProvider('measured')]
    public function test_every_measured_pair_gets_its_verdict(Result $quotaon, Result $repquota, ?string $expected): void
    {
        $this->assertSame($expected, Verdict::quota($quotaon, $repquota));
    }

    /**
     * Der Rückgabewert von `quotaon -p` entscheidet nichts.
     *
     * M10: Er ist in jedem gemessenen Zustand `0`. Ein Urteil, das an ihm
     * hinge, hinge an einer Zahl ohne Bedeutung — gemessen an der Wirkung: mit
     * `rc=1` und demselben Wortlaut fällt dasselbe Urteil.
     */
    public function test_the_return_code_of_quotaon_decides_nothing(): void
    {
        $this->assertSame('not_enforced', Verdict::quota(new Result(1, self::QUOTAON_OFF, ''), new Result(0, self::REPQUOTA_TABLE, '')));
        $this->assertNull(Verdict::quota(new Result(1, self::QUOTAON_ON, ''), new Result(0, self::REPQUOTA_TABLE, '')));
    }

    /**
     * Gelesen werden beide Kanäle — der Kanal wechselt mit dem Zustand (M10).
     *
     * Derselbe Wortlaut auf stderr statt stdout ergibt dasselbe Urteil.
     */
    public function test_both_channels_are_read(): void
    {
        $this->assertSame('off', Verdict::quota(new Result(0, '', self::QUOTAON_OFF), new Result(1, '', self::REPQUOTA_NO_FILE)));
        $this->assertSame('off', Verdict::quota(new Result(0, self::QUOTAON_NO_OPTION, ''), new Result(1, '', '')));
    }

    /** Und die Gruppenquota daneben täuscht nicht: gefragt ist die Benutzerquota. */
    public function test_only_the_user_quota_counts(): void
    {
        $nurGruppe = "group quota on /x (/dev/y) is on\nuser quota on /x (/dev/y) is off\n";

        $this->assertSame('not_enforced', Verdict::quota(new Result(0, $nurGruppe, ''), new Result(0, self::REPQUOTA_TABLE, '')));
    }

    /** Das Urteil liest den Rückgabewert von quotaon auch im Quelltext nicht. */
    public function test_the_source_does_not_ask_quotaon_for_its_code(): void
    {
        $source = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Diagnose/Verdict.php'));
        $body = $this->methodBody($source, 'public static function quota(');

        $this->assertStringContainsString('$repquota->successful()', $body, 'Der Rumpf fragt repquota nicht — der Wächter hat die falsche Methode.');
        $this->assertStringNotContainsString('$quotaon->code', $body);
        $this->assertStringNotContainsString('$quotaon->successful()', $body);
    }
}
