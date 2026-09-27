<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\OverviewController;
use App\Support\Diagnose\Catalog;
use App\Support\Diagnose\Checks\DiskSpace;
use App\Support\Notify\Notices;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Die Nähte von „Platte voll" zwischen Paketierung, Meldelauf und Übersicht
 * (`docs/136 §6`).
 *
 * ## Warum hier und nicht bei den Units
 *
 * `OneshotDeadlineTest` hält, dass jede Frist unter ihrem Takt liegt, und
 * `UnitCatalogTest`, dass jede Unit paketiert ist. Was keiner von beiden
 * sieht, sind drei Zusagen, die an zwei Dateien zugleich hängen:
 *
 * - **Die Haltezeit hängt am Takt.** Zehn Minuten heissen „drei Läufe
 *   hintereinander" nur, solange der Zeitgeber alle fünf Minuten feuert. Wer
 *   ihn auf eine Viertelstunde stellt, macht aus der Haltezeit eine, die ein
 *   einziger Lauf erfüllt — und aus einer Platte, die an der Grenze pendelt,
 *   eine Meldung je Pendel.
 * - **Die Unit meldet genau, was sie misst.** Ihre zweite Zeile beschränkt den
 *   Meldelauf; nennt sie einen anderen Schlüssel, meldet sie nie, und fehlt die
 *   Beschränkung, meldet sie die Befunde der Nacht vor der zweiten Nacht.
 * - **Die Übersicht färbt ab derselben Zahl, ab der gemeldet wird.**
 *
 * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
 * > die, die veraltet.**
 */
final class DiskCadenceTest extends TestCase
{
    private static function unit(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/packaging/systemd/'.$name);
    }

    /** Der Takt des Zeitgebers in Minuten, aus `OnCalendar=*:0/N`. */
    private static function takt(): int
    {
        preg_match_all('/^OnCalendar=\*:0\/(\d+)$/m', self::unit('srvpanel-disk.timer'), $treffer);

        self::assertCount(1, $treffer[1], 'Der Zeitgeber trägt nicht genau einen Kalender der Form *:0/N.');

        return (int) $treffer[1][0];
    }

    public function test_the_hold_spans_three_runs(): void
    {
        $takt = self::takt();

        self::assertGreaterThanOrEqual(
            2 * $takt,
            Notices::DISK_HOLD_MINUTES,
            sprintf('Bei %d Minuten Takt erfüllt eine Haltezeit von %d Minuten schon ein einziger Lauf.', $takt, Notices::DISK_HOLD_MINUTES),
        );
        self::assertLessThan(Notices::HOLD_HOURS * 60, Notices::DISK_HOLD_MINUTES,
            'Die Haltezeit der Platte ist länger als die der Nacht — dann bräuchte es den eigenen Lauf nicht.');
    }

    /**
     * Die Unit misst und meldet — und meldet genau die Schlüssel ihres Laufs.
     *
     * Die Schlüssel kommen aus `Catalog::DISK_CHECKS` und deren `REASONS` und
     * nicht aus einer Liste in diesem Test.
     */
    public function test_the_unit_reports_exactly_what_it_measures(): void
    {
        preg_match_all('/^ExecStart=(.+)$/m', self::unit('srvpanel-disk.service'), $zeilen);

        self::assertCount(2, $zeilen[1], 'Erst messen, dann melden — zwei Zeilen.');
        self::assertStringEndsWith('artisan srvpanel:disk', $zeilen[1][0]);
        self::assertMatchesRegularExpression('/artisan srvpanel:notices( --check=\S+)+$/D', $zeilen[1][1],
            'Ohne Beschränkung meldete die Unit alle fünf Minuten auch die Befunde der Nacht.');

        preg_match_all('/--check=(\S+)/', $zeilen[1][1], $genannt);

        $geschrieben = [];

        foreach (Catalog::DISK_CHECKS as $klasse) {
            /** @var array<string, list<string>> $reasons */
            $reasons = constant($klasse.'::REASONS');
            $geschrieben = [...$geschrieben, ...array_keys($reasons)];
        }

        sort($geschrieben);
        $gemeldet = $genannt[1];
        sort($gemeldet);

        self::assertNotSame([], $geschrieben);
        self::assertSame($geschrieben, $gemeldet, 'Die Unit meldet andere Schlüssel, als ihr Lauf schreibt.');
    }

    /**
     * Die Übersicht färbt ab der Warnschwelle der Prüfung — gemessen an der
     * Wirkung und auf beiden Seiten der Schwelle.
     */
    public function test_the_overview_colours_from_the_warning_of_the_check(): void
    {
        $controller = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        $methode = new ReflectionMethod($controller, 'filesystems');

        $zeilen = $methode->invoke($controller, ['ok' => true, 'error' => '', 'data' => ['filesystems' => [
            ['mount' => '/unter', 'device' => '/dev/vda', 'type' => 'ext4', 'total' => 1000, 'free' => 1, 'percent' => DiskSpace::WARN_PERCENT - 0.1],
            ['mount' => '/an', 'device' => '/dev/vdb', 'type' => 'ext4', 'total' => 1000, 'free' => 1, 'percent' => (float) DiskSpace::WARN_PERCENT],
        ]]]);

        self::assertSame([false, true], array_column($zeilen, 'tight'));
    }
}
