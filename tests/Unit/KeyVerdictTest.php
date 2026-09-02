<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Keys;

/**
 * Der Signaturschlüssel der eigenen Paketquelle — der schlechteste zählt.
 *
 * Ein abgelaufener Schlüssel heisst: Kein Update kommt mehr an, und zwar
 * wortlos — `apt-get update` meldet eine Quelle, die es nicht mehr prüfen
 * kann, mit `W:` und arbeitet mit den alten Listen weiter (M5 aus A1). Ein
 * Nachtlauf, der das dreissig Tage vorher sagt, ist der Grund für `expiring`.
 *
 * Framework-frei; {@see Verdict::key()} rechnet über {@see Keys::state()}.
 */
final class KeyVerdictTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    public function test_no_key_at_all_is_missing(): void
    {
        $this->assertSame('missing', Verdict::key([], self::NOW));
    }

    public function test_a_key_that_never_expires_is_fine(): void
    {
        $this->assertNull(Verdict::key([['expires' => null]], self::NOW));
    }

    public function test_a_key_with_years_left_is_fine(): void
    {
        $this->assertNull(Verdict::key([['expires' => self::NOW + 400 * self::DAY]], self::NOW));
    }

    public function test_a_key_within_thirty_days_is_expiring(): void
    {
        $this->assertSame('expiring', Verdict::key([['expires' => self::NOW + 12 * self::DAY]], self::NOW));
    }

    public function test_an_expired_key_is_expired(): void
    {
        $this->assertSame('expired', Verdict::key([['expires' => self::NOW - self::DAY]], self::NOW));
    }

    /**
     * Der schlechteste zählt — in beide Richtungen.
     *
     * Ein gültiger Schlüssel neben einem abgelaufenen macht das Update nicht
     * unmöglich; gemeldet wird der abgelaufene trotzdem, bevor er der letzte
     * ist. Und ein bald ablaufender neben einem ewigen ist `expiring`, nicht
     * „in Ordnung".
     */
    public function test_the_worst_key_decides(): void
    {
        $this->assertSame('expired', Verdict::key([
            ['expires' => null],
            ['expires' => self::NOW - self::DAY],
            ['expires' => self::NOW + 12 * self::DAY],
        ], self::NOW));

        $this->assertSame('expiring', Verdict::key([
            ['expires' => null],
            ['expires' => self::NOW + 12 * self::DAY],
        ], self::NOW));
    }
}
