<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cron\Occurrence;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Die gerechnete Fälligkeit — und die eine Regel, an der sie kippt.
 *
 * **Der Wächter über die ODER-Verknüpfung.** `crontab(5)`: Sind Tag des Monats
 * **und** Wochentag beide gesetzt, gilt ein Tag, wenn *eines von beiden* passt.
 * Das ist die einzige Stelle der ganzen Syntax, an der die Verknüpfung
 * wechselt, und eine Rechnung, die überall UND nimmt, ist an elf Zwölfteln aller
 * Zeitpläne richtig und an diesem einen still falsch.
 *
 * > **Eine Sonderregel, die nur bei einer von zwölf Kombinationen greift, wird
 * > von jedem Test gefunden, der sie prüft — und von keinem anderen.**
 *
 * Deshalb stehen hier drei Fälle nebeneinander und nicht einer: `13. ODER
 * Freitag`, `nur Freitag`, `nur der 13.`. Erst zu dritt zeigen sie, dass die
 * Verknüpfung wechselt — ein einzelner Fall wäre auch von einer Rechnung
 * erfüllt, die immer ODER nimmt.
 *
 * **Gerechnet wird gegen einen festen Zeitpunkt** und nicht gegen `now()`. Ein
 * Test, der die Uhr der Maschine liest, ist an einem Wochentag grün und am
 * nächsten rot, und niemand weiss warum.
 */
final class CronOccurrenceTest extends TestCase
{
    /** Donnerstag, der 13. August 2026, 10:30 UTC. */
    private const NOW = '2026-08-13 10:30:00';

    /**
     * @param  array<string,string>  $schedule
     *
     * @dataProvider schedules
     */
    public function test_the_next_occurrence_is_computed(array $schedule, ?string $expected): void
    {
        $next = Occurrence::next($schedule, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));

        self::assertSame($expected, $next?->format('Y-m-d H:i'));
    }

    /** @return array<string,array{array<string,string>,?string}> */
    public static function schedules(): array
    {
        $f = static fn (string $m, string $h, string $dom, string $mo, string $dow): array => [
            'minute' => $m, 'hour' => $h, 'day_of_month' => $dom, 'month' => $mo, 'day_of_week' => $dow,
        ];

        return [
            'jede Minute' => [$f('*', '*', '*', '*', '*'), '2026-08-13 10:31'],
            'taeglich um 03:15' => [$f('15', '3', '*', '*', '*'), '2026-08-14 03:15'],
            'heute noch um 22:00' => [$f('0', '22', '*', '*', '*'), '2026-08-13 22:00'],
            'alle fuenfzehn Minuten' => [$f('*/15', '*', '*', '*', '*'), '2026-08-13 10:45'],
            'werktags zur vollen Stunde' => [$f('0', '9-17', '*', '*', '1-5'), '2026-08-13 11:00'],

            // Sonntag darf 0 oder 7 heissen. Ohne die Angleichung fiele `7`
            // durch jedes Raster, und der Zeitplan wäre nie fällig.
            'Sonntag als 7' => [$f('0', '0', '*', '*', '7'), '2026-08-16 00:00'],
            'Sonntag als 0' => [$f('0', '0', '*', '*', '0'), '2026-08-16 00:00'],

            'nur der Erste' => [$f('0', '0', '1', '*', '*'), '2026-09-01 00:00'],

            // Der Schalttag ist bis zu vier Jahre entfernt — die Suche muss so
            // weit reichen, und die Grenze steht als MAX_DAYS im Code.
            'der 29. Februar' => [$f('0', '0', '29', '2', '*'), '2028-02-29 00:00'],

            // Die drei Fälle, um die es geht.
            'der 13. ODER Freitag' => [$f('0', '0', '13', '*', '5'), '2026-08-14 00:00'],
            'nur Freitag' => [$f('0', '0', '*', '*', '5'), '2026-08-14 00:00'],
            'nur der 13.' => [$f('0', '0', '13', '*', '*'), '2026-09-13 00:00'],

            // Und die Gegenprobe zur Suche selbst: Was es nie gibt, gibt `null`
            // und keine Endlosschleife.
            'den 30. Februar gibt es nicht' => [$f('0', '0', '30', '2', '*'), null],
        ];
    }

    /**
     * Der ODER-Fall, an der Stelle festgemacht, an der er sich von UND trennt.
     *
     * Der 13. August 2026 ist ein Donnerstag. Eine Rechnung mit UND müsste bis
     * zum **13. November** laufen — dem nächsten Freitag, der auf einen 13.
     * fällt. Dass hier der 14. August herauskommt, ist der ganze Unterschied.
     */
    public function test_day_of_month_and_weekday_are_joined_with_or(): void
    {
        $schedule = [
            'minute' => '0', 'hour' => '0', 'day_of_month' => '13',
            'month' => '*', 'day_of_week' => '5',
        ];

        $next = Occurrence::next($schedule, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));

        self::assertSame('2026-08-14 00:00', $next?->format('Y-m-d H:i'));
        self::assertNotSame('2026-11-13 00:00', $next?->format('Y-m-d H:i'));
    }

    /** Die Fälligkeit kommt in UTC zurück — so, wie dieses Panel speichert. */
    public function test_the_result_is_utc(): void
    {
        $next = Occurrence::next(
            ['minute' => '0', 'hour' => '0', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*'],
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );

        self::assertSame('UTC', $next?->getTimezone()->getName());
    }
}
