<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Announcement;
use App\Support\Diagnose\Checks\MaintenanceWindow;
use App\Support\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Das Sichtbarkeitsfenster einer Ankündigung rechnet in UTC (A14,
 * `docs/103 §6`).
 *
 * ## Warum das ein eigener Wächter ist
 *
 * Der Betreiber tippt Ortszeit, die Spalte hält UTC, und der Vergleich läuft
 * gegen `now()`. Drei Zonen an einer Naht, und die Hälfte, die still bricht,
 * ist der Vergleich: Rechnet er in der Anzeigezone, ist die Ankündigung genau
 * **während ihres eigenen Fensters unsichtbar** und erscheint um den Versatz zu
 * früh.
 *
 * Gemessen vor dem Bau (`docs/81 §2.3q` M7), mit der Anzeigezone auf
 * `Europe/Berlin`:
 *
 * | jetzt | UTC-Vergleich | Ortszeit-Vergleich |
 * |---|---|---|
 * | 13:30 Ortszeit, **im** Fenster | ja | **nein** |
 * | 12:30, davor | nein | nein |
 * | 14:30, danach | nein | nein |
 *
 * ## Gemessen wird mit einem Versatz und mit zwei Zonen
 *
 * **Eine Prüfzone ohne Versatz liesse eine fehlende Umrechnung wie eine
 * gelungene aussehen** — in UTC sind beide Vergleiche gleich. Dasselbe hat
 * {@see MaintenanceRoundTripTest} seit dem 4. September gekostet.
 *
 * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
 * > misst nicht.**
 *
 * Die zweite Zone steht daneben, weil ein einzelner Versatz auch dann trägt,
 * wenn jemand ihn irgendwo fest einbaut.
 *
 * ## Was er nicht kann
 *
 * Er misst den Filter und nicht die Anzeige. Ob der Streifen dann auch **steht**
 * — also die Hülle aus M2 und die Zeilenklammer aus M8 —, sagen die Punkte 2
 * und 3 des Abnahmelaufs (`docs/103 §8`) und nicht dieser Wächter.
 */
final class AnnouncementWindowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Betreiber tippt ein Fenster in seiner Zone; abgelegt wird UTC.
     *
     * Genau der Weg, den die Verwaltungsseite geht — {@see Clock::minuteToUtc()}
     * und sonst nichts.
     */
    private function fenster(string $zone, string $vonLokal, string $bisLokal): Announcement
    {
        Clock::store($zone);
        Clock::forget();

        $von = Clock::minuteToUtc($vonLokal);
        $bis = Clock::minuteToUtc($bisLokal);
        self::assertIsString($von, 'Die Eingabe muss sich ablegen lassen.');
        self::assertIsString($bis, 'Die Eingabe muss sich ablegen lassen.');

        return Announcement::factory()->create([
            'visible_from' => $von,
            'visible_until' => $bis,
        ]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function zonen(): iterable
    {
        // Der Versatz ist der Prüfkörper. `UTC` steht hier bewusst nicht.
        yield 'Berlin im September (+02:00)' => ['Europe/Berlin', 2];
        yield 'Kolkata (+05:30)' => ['Asia/Kolkata', 5];
    }

    #[DataProvider('zonen')]
    public function test_an_announcement_is_visible_during_its_own_window(string $zone, int $versatz): void
    {
        $konto = Account::factory()->admin()->create();
        $this->fenster($zone, '2026-09-05 13:00', '2026-09-05 14:00');

        // 13:30 Ortszeit — mitten im Fenster. In UTC ist das 13:30 minus Versatz.
        $mitten = Carbon::parse('2026-09-05 13:30', $zone)->utc();

        self::assertCount(
            1,
            Announcement::visibleTo($konto, $mitten),
            'Mitten im eingetippten Fenster muss die Ankündigung stehen — '
            .'ein Filter in der Anzeigezone fände hier nichts.');

        // Die Gegenprobe, die den Versatz überhaupt zu einem Prüfkörper macht:
        // Ohne Umrechnung läge die Grenze um `$versatz` Stunden daneben.
        self::assertGreaterThan(0, $versatz, 'Eine Zone ohne Versatz misst hier nichts.');
    }

    #[DataProvider('zonen')]
    public function test_it_is_invisible_before_and_after(string $zone, int $versatz): void
    {
        $konto = Account::factory()->admin()->create();
        $this->fenster($zone, '2026-09-05 13:00', '2026-09-05 14:00');

        foreach (['12:30' => 'davor', '14:30' => 'danach'] as $uhr => $wann) {
            self::assertCount(
                0,
                Announcement::visibleTo($konto, Carbon::parse('2026-09-05 '.$uhr, $zone)->utc()),
                "Die Ankündigung darf $wann nicht stehen.");
        }

        self::assertGreaterThan(0, $versatz);
    }

    public function test_an_open_end_never_expires(): void
    {
        $konto = Account::factory()->admin()->create();
        Announcement::factory()->create(['visible_from' => null, 'visible_until' => null]);

        foreach (['2020-01-01 00:00', '2099-12-31 23:59'] as $irgendwann) {
            self::assertCount(
                1,
                Announcement::visibleTo($konto, Carbon::parse($irgendwann, 'UTC')),
                'Ohne Fenster steht sie, bis jemand sie löscht.');
        }
    }

    public function test_only_one_end_is_enough(): void
    {
        $konto = Account::factory()->admin()->create();

        Announcement::factory()->create([
            'visible_from' => '2026-09-05 12:00:00',
            'visible_until' => null,
        ]);

        self::assertCount(0, Announcement::visibleTo($konto, Carbon::parse('2026-09-05 11:59', 'UTC')));
        self::assertCount(1, Announcement::visibleTo($konto, Carbon::parse('2026-09-05 12:01', 'UTC')));
    }

    /**
     * Der Zeitpunkt ist ein Argument und nicht `now()`.
     *
     * Derselbe Grund wie bei {@see MaintenanceWindow}:
     *
     * > **Derselbe Bestand, zwei Läufe, zwei Ergebnisse** — wenn die Wanduhr
     * > mitmisst.
     */
    public function test_the_instant_is_an_argument(): void
    {
        $konto = Account::factory()->admin()->create();
        Announcement::factory()->create([
            'visible_from' => '2026-01-01 00:00:00',
            'visible_until' => '2026-01-02 00:00:00',
        ]);

        self::assertCount(1, Announcement::visibleTo($konto, Carbon::parse('2026-01-01 12:00', 'UTC')));
        self::assertCount(0, Announcement::visibleTo($konto, Carbon::parse('2026-06-01 12:00', 'UTC')));
    }
}
