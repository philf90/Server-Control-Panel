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
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SystemFilesystems;
use SrvPanel\Agent\Runner;
use Tests\Support\WithoutPhpComments;

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
 * - **Die Haltezeit hängt am Takt.** Acht Minuten heissen „gemeldet beim
 *   dritten Lauf" nur, solange der Zeitgeber alle fünf Minuten feuert und auf
 *   die Sekunde genau. Wer ihn auf eine Viertelstunde stellt, lässt schon den
 *   zweiten melden; wer die Haltezeit auf zwei Takte legt, lässt den Zufall
 *   entscheiden, ob der dritte oder der vierte meldet.
 * - **Die Unit meldet genau, was sie misst.** Ihre zweite Zeile beschränkt den
 *   Meldelauf; nennt sie einen anderen Schlüssel, meldet sie nie, und fehlt die
 *   Beschränkung, meldet sie die Befunde der Nacht vor der zweiten Nacht.
 * - **Die Übersicht färbt ab derselben Zahl, ab der gemeldet wird** — den
 *   Balken für den Platz, und seit dem 27. September 2026 eine Zeile für die
 *   Inodes (`docs/137 §7`), die sie aus der Operation liest, die sie schreibt.
 *
 * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
 * > die, die veraltet.**
 */
final class DiskCadenceTest extends TestCase
{
    use WithoutPhpComments;

    private static function unit(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/packaging/systemd/'.$name);
    }

    /** Der Takt des Zeitgebers in Sekunden, aus `OnCalendar=*:0/N`. */
    private static function takt(): int
    {
        preg_match_all('/^OnCalendar=\*:0\/(\d+)$/m', self::unit('srvpanel-disk.timer'), $treffer);

        self::assertCount(1, $treffer[1], 'Der Zeitgeber trägt nicht genau einen Kalender der Form *:0/N.');

        return (int) $treffer[1][0] * 60;
    }

    /**
     * Eine Zeitspanne der Unit in Sekunden — oder die Vorgabe von systemd.
     *
     * Gelesen werden die Formen, die in unseren Units stehen: `30`, `30s`,
     * `1s`, `1min`. Eine andere Form ist ein Fehler dieses Tests und keine
     * Null — sonst sähe eine unlesbare Angabe aus wie keine Streuung.
     */
    private static function spanne(string $schluessel, int $vorgabe): int
    {
        if (preg_match('/^'.$schluessel.'=(.+)$/m', self::unit('srvpanel-disk.timer'), $treffer) !== 1) {
            return $vorgabe;
        }

        if (preg_match('/^(\d+)(s|sec|min|m)?$/D', trim($treffer[1]), $teile) !== 1) {
            self::fail(sprintf('%s=%s kann dieser Test nicht lesen.', $schluessel, $treffer[1]));
        }

        return (int) $teile[1] * (in_array($teile[2] ?? '', ['min', 'm'], true) ? 60 : 1);
    }

    /**
     * **Die Haltezeit liegt zwischen dem Abstand zweier und dreier Läufe.**
     *
     * Jeder Lauf kommt bis zu Streuung plus Genauigkeit nach seinem Termin —
     * `RandomizedDelaySec` und `AccuracySec`, deren Vorgabe systemd mit einer
     * Minute setzt (gemessen, `AccuracyUSec=1min`). Der zweite Lauf liegt damit
     * höchstens Takt plus Spiel nach dem ersten, der dritte mindestens zwei Takte
     * minus Spiel. Liegt die Haltezeit dazwischen, meldet **genau** der dritte —
     * so, wie der Betreiber es entschieden hat. Eine Haltezeit genau auf zwei
     * Takten, der erste Wurf, traf ihn nur manchmal.
     */
    public function test_the_hold_lies_between_two_and_three_runs(): void
    {
        $takt = self::takt();
        $spiel = self::spanne('RandomizedDelaySec', 0) + self::spanne('AccuracySec', 60);
        $halt = Notices::DISK_HOLD_MINUTES * 60;

        self::assertGreaterThan($takt + $spiel, $halt, sprintf(
            'Der zweite Lauf kommt bis zu %d s nach dem ersten — %d s Haltezeit erfüllt dann schon er.',
            $takt + $spiel,
            $halt,
        ));
        self::assertLessThan(2 * $takt - $spiel, $halt, sprintf(
            'Der dritte Lauf kommt schon %d s nach dem ersten — bei %d s Haltezeit meldet dann manchmal erst der vierte.',
            2 * $takt - $spiel,
            $halt,
        ));
        self::assertLessThan(Notices::HOLD_HOURS * 3600, $halt,
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

    /**
     * Die Inodes bekommen eine Zeile ab der Warnschwelle, in der Farbe des
     * Befunds — gemessen an der Wirkung, auf beiden Seiten beider Schwellen.
     *
     * Und „nicht gemessen" bleibt `null`: vfat führt keine Inodes, und eine
     * Null daraus wäre eine Zahl, die niemand gemessen hat.
     */
    public function test_the_overview_names_the_inodes_from_the_thresholds_of_the_check(): void
    {
        $platte = static fn (string $mount, mixed $inodes): array => [
            'mount' => $mount, 'device' => '/dev/vda', 'type' => 'ext4',
            'total' => 1000, 'free' => 500, 'percent' => 50.0, 'inodes' => $inodes,
        ];

        $zeilen = $this->overview([
            $platte('/unter', ['total' => 100, 'free' => 1, 'percent' => DiskSpace::WARN_PERCENT - 0.1]),
            $platte('/warn', ['total' => 100, 'free' => 1, 'percent' => (float) DiskSpace::WARN_PERCENT]),
            $platte('/knapp', ['total' => 100, 'free' => 1, 'percent' => DiskSpace::FAIL_PERCENT - 0.1]),
            $platte('/voll', ['total' => 100, 'free' => 0, 'percent' => (float) DiskSpace::FAIL_PERCENT]),
            $platte('/vfat', null),
            $platte('/ohne-zahl', ['total' => 100, 'free' => 1]),
        ]);

        self::assertSame([null, 'warn', 'warn', 'critical', null, null], array_column($zeilen, 'inodes_rank'));
        self::assertSame(
            [DiskSpace::WARN_PERCENT - 0.1, (float) DiskSpace::WARN_PERCENT, DiskSpace::FAIL_PERCENT - 0.1, (float) DiskSpace::FAIL_PERCENT, null, null],
            array_column($zeilen, 'inodes_percent'),
        );
    }

    /**
     * Und sie liest die Inodes aus der Operation, die sie schreibt.
     *
     * **Beide Hälften, weil jede allein grün bliebe.** Fragte die Übersicht
     * wieder `system.info`, stünde der Fall darüber weiter grün da — er füttert
     * die Rechnung selbst —, und die Zeile käme nie: Dort gibt es keine Inodes.
     * Deshalb läuft hier die echte Operation gegen ein echtes `stat -f`, und
     * ihre Antwort geht unverändert in die Übersicht.
     *
     * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut
     * > wie keine.**
     */
    public function test_the_overview_reads_the_inodes_the_agent_writes(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/OverviewController.php',
        ));

        self::assertSame(
            1,
            preg_match("/'filesystems'\\s*=>\\s*fn\\s*\\(\\)\\s*:\\s*array\\s*=>[^\\n]*'system\\.filesystems'/", $quelle),
            'Die Dateisysteme der Übersicht kommen nicht aus system.filesystems — dort allein stehen die Inodes.',
        );

        $proc = sys_get_temp_dir().'/uebersicht-'.bin2hex(random_bytes(4));
        mkdir($proc, 0o755, true);
        file_put_contents($proc.'/mounts', "/dev/sda1 {$proc} ext4 rw 0 0\n");

        try {
            $journal = new Journal('/dev/null');
            $antwort = (new SystemFilesystems($proc))->execute(
                [],
                new Context(new Runner($journal), $journal, static function (array $line): void {}),
            );
        } finally {
            @unlink($proc.'/mounts');
            @rmdir($proc);
        }

        $zeilen = $this->overview($antwort['filesystems']);

        self::assertCount(1, $zeilen);
        self::assertIsFloat($zeilen[0]['inodes_percent'], 'Die Inodes aus stat -f sind in der Übersicht nicht angekommen.');
    }

    /**
     * Die Mail nennt die Läufe, die die Zeitgeber fahren.
     *
     * **Befund 1 aus `docs/137 §7`:** Die Regel in der Mail an den Betreiber
     * sprach nur von der Nacht, und die Mail über eine volle Platte kam am
     * Abend. Seit sie beide Takte nennt, steht dort eine zweite Fassung von
     * Zeitgeber und Haltezeit — als Wort in einem Satz. Ohne diesen Fall liefe
     * sie auseinander, sobald jemand einen Zeitgeber umstellt.
     *
     * Gerechnet wird der meldende Lauf wie im Fall über die Haltezeit: Sie
     * liegt zwischen zwei Terminen, also meldet der Lauf nach dem Termin, den
     * sie überschreitet.
     */
    public function test_the_mail_counts_the_runs_the_timers_drive(): void
    {
        $woerter = [2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 10 => 'zehn', 15 => 'fünfzehn'];

        $takt = self::takt();
        $platte = intdiv(Notices::DISK_HOLD_MINUTES * 60, $takt) + 2;

        self::assertSame(1, preg_match('/^OnCalendar=daily$/m', self::unit('srvpanel-diagnose.timer')),
            'Der Nachtlauf läuft nicht mehr täglich — dann stimmt „Nächte" in der Mail nicht mehr.');
        $naechte = intdiv(Notices::HOLD_HOURS * 60, 24 * 60) + 2;

        foreach ([$platte, intdiv($takt, 60), $naechte] as $zahl) {
            self::assertArrayHasKey($zahl, $woerter, $zahl.' kennt dieser Test nicht als Wort.');
        }

        $vorlage = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/mail/diagnose.blade.php');
        $text = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\{\{--.*?--\}\}/s', '', $vorlage));

        self::assertStringContainsString(
            sprintf('%s Läufe im Abstand von %s Minuten', $woerter[$platte], $woerter[intdiv($takt, 60)]),
            $text,
            'Die Mail nennt für die Platte andere Läufe, als Zeitgeber und Haltezeit ergeben.',
        );
        self::assertStringContainsString(
            sprintf('%s Nächte', $woerter[$naechte]),
            $text,
            'Die Mail nennt für die Nacht andere Läufe, als Zeitgeber und Haltezeit ergeben.',
        );
    }

    /**
     * Die Zeilen, die die Übersicht aus einer Antwort des Agenten macht.
     *
     * @param  array<mixed>  $platten
     * @return list<array<string, mixed>>
     */
    private function overview(array $platten): array
    {
        $controller = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();

        /** @var list<array<string, mixed>> */
        return (new ReflectionMethod($controller, 'filesystems'))
            ->invoke($controller, ['ok' => true, 'error' => '', 'data' => ['filesystems' => $platten]]);
    }
}
