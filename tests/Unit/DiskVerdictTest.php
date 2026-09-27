<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\DiskSpace;
use PHPUnit\Framework\TestCase;

/**
 * Wann „Platte voll" meldet — gemessen an der Wirkung von `DiskSpace::judge()`
 * (`docs/136 §4` und §6).
 *
 * ## Die Schwellen, der Rückweg und die zwei Stufen
 *
 * Entschieden hat der Betreiber am 27. September 2026: Warnung ab 85 %,
 * Störung ab 95 %, zurück erst fünf Punkte darunter, für Inodes dieselben
 * Zahlen. Jeder Fall hier steht neben seinem Gegenstück — eine Schwelle, die
 * nur von einer Seite gemessen ist, hält auch eine Prüfung ein, die immer
 * meldet.
 *
 * **Der Rückweg ist der Fall, für den es diesen Wächter gibt.** Ohne ihn
 * verschwände ein Befund bei 84,9 %, und eine Platte, die um die Schwelle
 * pendelt, stünde nie zehn Minuten am Stück da — sie meldete gar nicht.
 *
 * > **Eine Haltezeit ohne Rückweg macht aus einer Platte, die an der Grenze
 * > pendelt, eine, die nie meldet.**
 *
 * Gerechnet wird mit den Konstanten und nicht mit den Zahlen: Ändert der
 * Betreiber eine Schwelle, bleibt dieser Wächter richtig; ändert jemand den
 * Vergleich, wird er rot.
 */
final class DiskVerdictTest extends TestCase
{
    /**
     * Eine Platte aus `system.filesystems`.
     *
     * @param  array<string, mixed>|null  $inodes
     * @return array<string, mixed>
     */
    private static function platte(string $mount, float $prozent, ?array $inodes = null): array
    {
        return [
            'mount' => $mount,
            'device' => '/dev/vda',
            'type' => 'ext4',
            'total' => 1000,
            'free' => 100,
            'used' => 900,
            'percent' => $prozent,
            'inodes' => $inodes,
        ];
    }

    /**
     * Die Gründe eines Urteils, je Einhängepunkt.
     *
     * @param  list<mixed>  $platten
     * @param  list<array{subject: string, reason: string}>  $vorher
     * @return list<string>
     */
    private static function gruende(array $platten, array $vorher = []): array
    {
        return array_map(
            static fn (array $b): string => $b['subject'].' '.$b['reason'],
            DiskSpace::judge($platten, $vorher),
        );
    }

    public function test_below_the_warning_nothing_is_reported(): void
    {
        self::assertSame([], self::gruende([self::platte('/', DiskSpace::WARN_PERCENT - 0.1)]));
    }

    public function test_at_the_warning_it_is_tight(): void
    {
        self::assertSame(['/ space_tight'], self::gruende([self::platte('/', DiskSpace::WARN_PERCENT)]));
    }

    /**
     * **Wird aus einer Warnung eine Störung, bleibt die Warnung stehen.**
     * Andersherum hiesse der Aufstieg „Warnung behoben" — genau in dem
     * Augenblick, in dem es schlimmer wird.
     */
    public function test_at_the_failure_both_stand(): void
    {
        self::assertSame(
            ['/ space_tight', '/ space_full'],
            self::gruende([self::platte('/', DiskSpace::FAIL_PERCENT)]),
        );
        self::assertSame(
            ['/ space_tight'],
            self::gruende([self::platte('/', DiskSpace::FAIL_PERCENT - 0.1)]),
            'Unter der Störschwelle und ohne Befund vom vorigen Lauf ist es nur eng.',
        );
    }

    /** Der Rückweg: Eine Warnung vom vorigen Lauf bleibt bis fünf Punkte darunter. */
    public function test_a_warning_stays_until_it_falls_below_its_release(): void
    {
        $vorher = [['subject' => '/', 'reason' => 'space_tight']];
        $rueckweg = DiskSpace::WARN_PERCENT - DiskSpace::RELEASE_POINTS;

        self::assertSame(['/ space_tight'], self::gruende([self::platte('/', $rueckweg)], $vorher));
        self::assertSame([], self::gruende([self::platte('/', $rueckweg - 0.1)], $vorher));
    }

    /**
     * Die Gegenprobe zum Rückweg: **ohne** Befund vom vorigen Lauf bleibt es
     * still. Sonst hätte der Rückweg die Schwelle einfach verschoben.
     */
    public function test_without_a_previous_warning_the_release_band_is_silent(): void
    {
        self::assertSame([], self::gruende([self::platte('/', DiskSpace::WARN_PERCENT - 1)]));
    }

    public function test_a_failure_stays_until_it_falls_below_its_release(): void
    {
        $vorher = [['subject' => '/', 'reason' => 'space_tight'], ['subject' => '/', 'reason' => 'space_full']];
        $rueckweg = DiskSpace::FAIL_PERCENT - DiskSpace::RELEASE_POINTS;

        self::assertSame(['/ space_tight', '/ space_full'], self::gruende([self::platte('/', $rueckweg)], $vorher));
        self::assertSame(['/ space_tight'], self::gruende([self::platte('/', $rueckweg - 0.1)], $vorher),
            'Fällt die Platte unter den Rückweg der Störung, geht die Störung — die Warnung bleibt.');
    }

    /** Der Rückweg gehört dem Einhängepunkt und nicht der Prüfung. */
    public function test_the_release_belongs_to_its_mount(): void
    {
        $vorher = [['subject' => '/var/www', 'reason' => 'space_tight']];

        self::assertSame([], self::gruende([self::platte('/', DiskSpace::WARN_PERCENT - 1)], $vorher));
    }

    public function test_inodes_are_judged_with_the_same_numbers(): void
    {
        $inodes = ['total' => 128, 'free' => 0, 'percent' => 100.0];
        $befunde = DiskSpace::judge([self::platte('/', 7.5, $inodes)], []);

        self::assertSame(['inodes_tight', 'inodes_full'], array_column($befunde, 'reason'),
            'Die Platte ist für neue Dateien voll, während der Platz 7,5 % zeigt (docs/136 §3 M3).');
        self::assertStringContainsString('100,0 % der Inodes vergeben, 0 frei.', $befunde[0]['detail']);
    }

    /**
     * **Nicht gemessen ist nicht „0 %".** Ohne Inodezahl (btrfs, oder `stat`
     * ohne Antwort) gibt es über Inodes kein Urteil — und der Platz wird
     * trotzdem beurteilt.
     */
    public function test_without_inodes_only_the_space_is_judged(): void
    {
        self::assertSame(
            ['/ space_tight'],
            self::gruende([self::platte('/', DiskSpace::WARN_PERCENT, null)]),
        );
        self::assertSame(
            ['/ space_tight'],
            self::gruende([self::platte('/', DiskSpace::WARN_PERCENT, ['total' => 0, 'free' => 0])]),
        );
    }

    public function test_the_detail_carries_the_measured_number(): void
    {
        $befunde = DiskSpace::judge([self::platte('/srv', 91.64)], []);

        self::assertSame('91,6 % belegt.', $befunde[0]['detail']);
    }

    /** Eine Zeile ohne Einhängepunkt oder ohne Zahl ergibt nichts — und wirft nicht. */
    public function test_a_broken_row_is_skipped(): void
    {
        self::assertSame([], self::gruende([
            ['percent' => 99.0],
            ['mount' => '', 'percent' => 99.0],
            ['mount' => '/', 'percent' => 'viel'],
            'keine Zeile',
        ]));
    }
}
