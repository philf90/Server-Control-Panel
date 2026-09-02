<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FindingCheck;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Ops\SystemDiagnose;

/**
 * Die Naht zwischen dem, was der Agent ausspricht, und dem, was das Panel kennt.
 *
 * `FindingLog::replace()` wirft für einen Grund, den die Prüfung nicht kennt —
 * absichtlich, denn er kommt aus dem Code und nie von aussen. Aber der Code,
 * aus dem er kommt, ist hier der **Agent**, und der kennt den Katalog des
 * Panels nicht. Läuft das auseinander, wirft der Nachtlauf, und zwar nachts.
 *
 * Dieselbe Bauart wie `PhpSourceUriTest`: Eine Naht, die man nicht hält,
 * reisst still.
 *
 * Framework-frei — beide Seiten sind reine Aufzählungen.
 */
final class DiagnoseSeamTest extends TestCase
{
    public function test_every_reason_the_agent_speaks_is_known_to_the_panel(): void
    {
        $geprueft = 0;

        foreach (Verdict::REASONS as $key => $reasons) {
            $check = FindingCheck::from($key);

            foreach ($reasons as $reason) {
                $this->assertArrayHasKey($reason, $check->reasons(), sprintf(
                    'Der Agent spricht %s/%s aus, und das Panel kennt den Grund nicht — FindingLog würde nachts werfen.',
                    $key,
                    $reason,
                ));
                $geprueft++;
            }
        }

        $this->assertGreaterThanOrEqual(15, $geprueft);
    }

    /** Die Schlüssel, die die Operation annimmt, sind genau die, für die es Urteile gibt. */
    public function test_the_operation_accepts_exactly_the_keys_with_verdicts(): void
    {
        $a = SystemDiagnose::CHECKS;
        $b = array_keys(Verdict::REASONS);
        sort($a);
        sort($b);

        $this->assertSame($b, $a);
    }

    /** Und `unreachable` heisst auf beiden Seiten dasselbe. */
    public function test_unreachable_is_the_same_word_on_both_sides(): void
    {
        $this->assertSame(FindingCheck::UNREACHABLE, Verdict::UNREACHABLE);
    }
}
