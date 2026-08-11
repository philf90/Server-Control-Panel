<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Support\Audit\AuditQuery;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aus UTC wird eine Uhrzeit, die jemand lesen kann — und die Filter rechnen mit.
 *
 * **Der Anlass steht in `docs/40`.** Ein Eintrag um `12:31:26` im Protokoll war
 * UTC; der Betreiber las ihn auf einer Uhr, die zwei Stunden weiter ist.
 *
 * > **Ein Zeitstempel, den man falsch liest, ist schlimmer als keiner — er
 * > sieht aus wie eine Auskunft.**
 *
 * ## Die Grenze ist der Test, nicht die Mitte
 *
 * `docs/40 §3.2` sagt es genau: *Eine Prüfung, die nur mitten am Tag misst, ist
 * grün und beweist nichts.* Deshalb steht hier ein Eintrag, der in der
 * Anzeigezone am einen und in UTC am anderen Tag liegt.
 *
 * **Der Plan nennt dafür `23:30` Ortszeit, und das trifft es für `Europe/Berlin`
 * nicht.** Gemessen: `2026-08-11 23:30` Ortszeit ist `21:30` UTC — derselbe Tag.
 * Bei einem **positiven** Offset kippt nicht der Abend, sondern der frühe
 * Morgen: `2026-08-12 00:30` Ortszeit ist `2026-08-11 22:30` UTC. Für eine Zone
 * westlich von Greenwich wäre es umgekehrt.
 *
 * > **Ein Beispiel, das die Richtung nicht mitdenkt, prüft die falsche Grenze.**
 *
 * Geprüft werden deshalb beide Enden des Tages.
 */
final class ClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Clock::forget();
    }

    private function useZone(string $zone): void
    {
        Setting::query()->updateOrCreate(['key' => Clock::KEY], ['value' => ['zone' => $zone]]);

        Clock::forget();
    }

    public function test_without_a_setting_everything_stays_utc(): void
    {
        $this->assertSame('UTC', Clock::zone());

        $this->assertSame(
            '2026-08-11 12:31:26',
            Clock::display(CarbonImmutable::parse('2026-08-11 12:31:26', 'UTC')),
        );
    }

    /** Der Fall aus `docs/40 §1`, mit den Zahlen von damals. */
    public function test_a_stored_time_is_shown_in_the_configured_zone(): void
    {
        $this->useZone('Europe/Berlin');

        $this->assertSame(
            '2026-08-11 14:31:26',
            Clock::display(CarbonImmutable::parse('2026-08-11 12:31:26', 'UTC')),
            'Die Anzeige rechnet nicht um — dann steht dort UTC und sieht aus wie Ortszeit.',
        );

        // `null` bleibt `null`: „noch nie" ist etwas anderes als „vor langer Zeit".
        $this->assertNull(Clock::display(null));
    }

    /**
     * Die Filtergrenzen werden in der Anzeigezone gebildet und dann gedreht.
     *
     * Ohne das zeigt die Seite `14:31` und findet die Zeile nicht, wenn man nach
     * diesem Tag sucht — der Teil, der still bricht.
     */
    public function test_a_filter_boundary_is_turned_into_utc(): void
    {
        $this->useZone('Europe/Berlin');

        $this->assertSame('2026-08-10 22:00:00', Clock::boundaryToUtc('2026-08-11', end: false));
        $this->assertSame('2026-08-11 21:59:59', Clock::boundaryToUtc('2026-08-11', end: true));
    }

    /**
     * Und der Grenzfall, um den es geht: beide Enden des Tages.
     *
     * Ein Eintrag um `00:30` Ortszeit liegt in UTC am **Vortag**. Er muss beim
     * Filter auf seinen Ortszeit-Tag erscheinen und beim Filter auf den Vortag
     * nicht — sonst sucht der Filter in einem anderen Tag als dem, den die
     * Seite anzeigt.
     */
    public function test_an_entry_at_the_edge_of_the_day_is_found_on_its_local_day(): void
    {
        $this->useZone('Europe/Berlin');

        foreach ([
            'früher Morgen' => ['2026-08-11 22:30:00', '2026-08-12', '2026-08-11'],
            'später Abend' => ['2026-08-11 21:30:00', '2026-08-11', '2026-08-12'],
        ] as $fall => [$utc, $gehoertZu, $gehoertNichtZu]) {
            $this->assertTrue(
                $utc >= (string) Clock::boundaryToUtc($gehoertZu, end: false)
                && $utc <= (string) Clock::boundaryToUtc($gehoertZu, end: true),
                sprintf('%s: Der Eintrag %s UTC wird am %s nicht gefunden.', $fall, $utc, $gehoertZu),
            );

            $this->assertFalse(
                $utc >= (string) Clock::boundaryToUtc($gehoertNichtZu, end: false)
                && $utc <= (string) Clock::boundaryToUtc($gehoertNichtZu, end: true),
                sprintf('%s: Der Eintrag %s UTC wird am %s gefunden und gehört nicht dorthin.', $fall, $utc, $gehoertNichtZu),
            );
        }
    }

    /**
     * Eine unbekannte Zone wirft nicht, sie fällt zurück.
     *
     * `setTimezone()` würde bei einem Tippfehler mitten im Aufbau einer Seite
     * werfen — an achtzehn Stellen. Eine Anzeige in UTC ist ein kleiner Schaden;
     * eine Seite, die nicht mehr aufgeht, ist ein grosser. Verhindert wird der
     * Tippfehler beim Setzen.
     */
    public function test_an_unknown_zone_falls_back_instead_of_throwing(): void
    {
        $this->useZone('Erde/Nirgendwo');

        $this->assertSame('UTC', Clock::zone());
        $this->assertFalse(Clock::isValid('Erde/Nirgendwo'));
        $this->assertTrue(Clock::isValid('Europe/Berlin'));
    }

    /** Und was neben der Zeit steht, sagt welche Zone gemeint ist. */
    public function test_the_label_names_the_zone(): void
    {
        $this->useZone('Europe/Berlin');

        $label = Clock::label();

        $this->assertStringContainsString('UTC+', $label, 'Ohne den Versatz ist die Marke keine Auskunft.');

        $this->useZone('UTC');

        $this->assertSame('UTC', Clock::label());
    }

    /**
     * Und der Filter des Protokolls rechnet mit der Anzeige mit.
     *
     * **Die Hälfte, die still bricht.** Eine umgestellte Anzeige ohne
     * mitrechnenden Filter zeigt eine Zeile und findet sie nicht — der Zustand,
     * vor dem `docs/40 §3.2` warnt. Deshalb steht diese Prüfung neben der
     * Anzeige und nicht in einer eigenen Runde.
     */
    public function test_the_audit_filter_uses_the_same_zone_as_the_display(): void
    {
        $this->useZone('Europe/Berlin');

        // 22:30 UTC ist in Berlin bereits der nächste Tag, 00:30 Ortszeit.
        $event = AuditEvent::query()->create([
            'action' => 'auth.login',
            'result' => AuditResult::Success,
            'created_at' => CarbonImmutable::parse('2026-08-11 22:30:00', 'UTC'),
        ]);

        $shown = AuditQuery::toArrayRow($event->refresh());

        $this->assertSame('2026-08-12 00:30:00', $shown['created_at'], 'Die Anzeige rechnet nicht um.');

        $found = static fn (string $tag): int => AuditQuery::filter(
            AuditEvent::query(),
            ['from' => $tag, 'to' => $tag],
        )->count();

        $this->assertSame(1, $found('2026-08-12'), sprintf(
            "Die Seite zeigt %s und der Filter findet die Zeile an diesem Tag nicht.\n\n"
            .'Ein Filter, der eine andere Zeitrechnung benutzt als die Anzeige daneben, sucht in '
            .'einem anderen Tag als dem, den er zeigt.',
            $shown['created_at'],
        ));

        $this->assertSame(0, $found('2026-08-11'), 'Die Zeile wird an ihrem UTC-Tag gefunden statt an ihrem Ortszeit-Tag.');
    }
}
