<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use App\Support\Web\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was der Betreiber eintippt, kommt in seiner Zeitzone zurück (A12).
 *
 * ## Die Anforderung im Wortlaut
 *
 * „Die eingegebene Uhrzeit sollte der eingestellten Zeitzone entsprechen."
 * Abgelegt wird UTC — ein Zeitpunkt ist ein Zeitpunkt —, angezeigt wird
 * Ortszeit. Zwischen beiden steht {@see Clock} und sonst nichts.
 *
 * **Gemessen wird mit einem Versatz und nicht mit `UTC`.** Eine Zone ohne
 * Versatz liesse jede fehlende Umrechnung wie eine gelungene aussehen:
 *
 * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
 * > misst nicht.**
 *
 * ## Was er nicht kann
 *
 * Er misst die Anzeige und nicht den vollen Weg durch `POST /maintenance`: Der
 * geht über {@see MaintenanceMode}, und das ruft den Agenten
 * über einen Socket, den es hier nicht gibt. Die Gegenrichtung — eingetippt,
 * gespeichert, wieder gelesen — steht deshalb als Punkt 2 des Abnahmelaufs in
 * `docs/102 §5` und nicht als Zusage hier.
 */
final class MaintenanceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** Die abgelegte Form: das, was die Steuerung aus der Eingabe macht. */
    private function eintippen(string $zone, string $datum, string $zeit): string
    {
        Clock::store($zone);

        $utc = Clock::minuteToUtc($datum.' '.$zeit);
        self::assertIsString($utc);

        app(Settings::class)->saveMaintenance(true, $utc);

        return $utc;
    }

    private function betreiber(): Account
    {
        return Account::factory()->admin()->create();
    }

    public function test_what_was_typed_comes_back_in_the_display_zone(): void
    {
        $utc = $this->eintippen('Europe/Berlin', '2026-09-04', '16:00');

        // Der Beleg, dass überhaupt gedreht wurde: abgelegt steht etwas anderes.
        self::assertSame('2026-09-04 14:00:00', $utc);

        $this->actingAs($this->betreiber())
            ->get('/maintenance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maintenance/Index')
                ->where('maintenance.until_date', '2026-09-04')
                ->where('maintenance.until_time', '16:00')
                ->where('maintenance.zone', 'CEST (UTC+02:00)')
                ->etc());
    }

    /**
     * Dieselbe Ablage, eine andere Zone — und die Anzeige geht mit.
     *
     * Das ist die Aussage, die eine einzelne Zone nicht treffen kann: Nicht der
     * Wert entscheidet, was dasteht, sondern die Einstellung.
     */
    public function test_the_same_instant_reads_differently_in_another_zone(): void
    {
        $utc = $this->eintippen('Europe/Berlin', '2026-09-04', '16:00');

        Clock::store('America/New_York');
        app(Settings::class)->saveMaintenance(true, $utc);

        $this->actingAs($this->betreiber())
            ->get('/maintenance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('maintenance.until_date', '2026-09-04')
                ->where('maintenance.until_time', '10:00')
                ->where('maintenance.zone', 'EDT (UTC-04:00)')
                ->etc());
    }

    /** Über Mitternacht — dort wechselt auch das Datum, nicht nur die Stunde. */
    public function test_the_date_moves_with_the_hour(): void
    {
        $this->eintippen('Europe/Berlin', '2026-09-04', '01:00');

        $this->actingAs($this->betreiber())
            ->get('/maintenance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('maintenance.until_date', '2026-09-04')
                ->where('maintenance.until_time', '01:00')
                ->etc());
    }

    /** Ohne Angabe bleiben beide Felder leer — und nicht eines von beiden. */
    public function test_without_an_end_time_both_fields_stay_empty(): void
    {
        Clock::store('Europe/Berlin');
        app(Settings::class)->saveMaintenance(false, null);

        $this->actingAs($this->betreiber())
            ->get('/maintenance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('maintenance.until_date', '')
                ->where('maintenance.until_time', '')
                ->etc());
    }
}
