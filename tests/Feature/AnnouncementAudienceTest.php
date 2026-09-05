<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Models\Account;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wer eine Ankündigung sieht — und wer nicht (A14, `docs/103 §2`,
 * Entscheidung 3).
 *
 * ## Beide Richtungen in einem Fall
 *
 * Ein Wächter, der nur prüft, dass das richtige Publikum sie **sieht**, ist
 * grün, sobald jeder alles sieht. Die stille Hälfte ist die andere: dass die
 * falschen sie **nicht** sehen. Sie steht deshalb in jedem Fall daneben und
 * nicht in einem eigenen, den man beim nächsten Publikum vergisst.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * ## Die Zuordnung kommt aus einer Stelle
 *
 * {@see AnnouncementAudience::of()} ist die einzige, die ein Konto einem
 * Publikum zuordnet, und sie fragt **beide Achsen** aus A9 — Typ und Rolle,
 * über {@see Account::isOperator()}. Ein Kundenkonto, das durch einen Fehler
 * `operator` trüge, ist damit trotzdem Kunde.
 *
 * ## Was er nicht kann
 *
 * Er misst die Abfrage und nicht die Tür. Dass ein Kunde die Verwaltungsseite
 * unter `/announcements` gar nicht erst erreicht, hält die Route über
 * `can:manage-settings` — geprüft von {@see RouteAuthorizationTest}, nicht
 * hier.
 */
final class AnnouncementAudienceTest extends TestCase
{
    use RefreshDatabase;

    private function jetzt(): Carbon
    {
        return Carbon::parse('2026-09-05 12:00', 'UTC');
    }

    /**
     * Die drei Publika mit einem Konto, das genau dazu gehört.
     *
     * @return iterable<string, array{AnnouncementAudience, string}>
     */
    public static function publika(): iterable
    {
        yield 'Betreiber' => [AnnouncementAudience::Operator, 'admin'];
        yield 'Administrator' => [AnnouncementAudience::Administrator, 'administrator'];
        yield 'Kunde' => [AnnouncementAudience::Customer, 'customer'];
    }

    private function konto(string $zustand): Account
    {
        return match ($zustand) {
            'admin' => Account::factory()->admin()->create(),
            'administrator' => Account::factory()->administrator()->create(),
            'customer' => Account::factory()->customer()->create(),
            'additional' => Account::factory()->additional()->create(),
            default => throw new \LogicException('Unbekannter Kontozustand: '.$zustand),
        };
    }

    #[DataProvider('publika')]
    public function test_an_account_maps_to_exactly_its_own_audience(
        AnnouncementAudience $erwartet,
        string $zustand,
    ): void {
        self::assertSame($erwartet, AnnouncementAudience::of($this->konto($zustand)));
    }

    #[DataProvider('publika')]
    public function test_it_reaches_its_audience_and_no_other(
        AnnouncementAudience $publikum,
        string $zustand,
    ): void {
        Announcement::factory()->forAudiences([$publikum])->create(['body' => 'Für '.$publikum->label()]);

        // Die eine Richtung: das gemeinte Publikum sieht sie.
        self::assertCount(
            1,
            Announcement::visibleTo($this->konto($zustand), $this->jetzt()),
            $publikum->label().' muss die eigene Ankündigung sehen.');

        // Und die stille: jedes andere sieht sie nicht.
        foreach (self::publika() as $name => [$anderes, $andererZustand]) {
            if ($anderes === $publikum) {
                continue;
            }

            self::assertCount(
                0,
                Announcement::visibleTo($this->konto($andererZustand), $this->jetzt()),
                $name.' darf die Ankündigung für '.$publikum->label().' nicht sehen.');
        }
    }

    /**
     * Ein Zusatzbenutzer ist ein Kunde.
     *
     * Er arbeitet in den Abonnements seines Kunden und sieht dieselben Seiten;
     * ein viertes Publikum müsste jede Ankündigung einzeln beantworten.
     */
    public function test_an_additional_user_counts_as_a_customer(): void
    {
        self::assertSame(AnnouncementAudience::Customer, AnnouncementAudience::of($this->konto('additional')));

        Announcement::factory()->forAudiences([AnnouncementAudience::Customer])->create();

        self::assertCount(1, Announcement::visibleTo($this->konto('additional'), $this->jetzt()));
    }

    /** Mehrere Publika gleichzeitig — die Wartungsmeldung an alle. */
    public function test_several_audiences_at_once(): void
    {
        Announcement::factory()->create();

        foreach (['admin', 'administrator', 'customer', 'additional'] as $zustand) {
            self::assertCount(
                1,
                Announcement::visibleTo($this->konto($zustand), $this->jetzt()),
                "$zustand muss eine Ankündigung an alle sehen.");
        }
    }

    /**
     * Ohne angemeldetes Konto gibt es kein Publikum — und keine Ankündigung.
     *
     * Die Anmeldeseite geht ausdrücklich einen **anderen** Weg
     * ({@see Announcement::onLoginPage()}), weil dort die Kategorie die Grenze
     * zieht und nicht das Publikum.
     */
    public function test_without_an_account_there_is_no_audience(): void
    {
        Announcement::factory()->create();

        self::assertCount(0, Announcement::visibleTo(null, $this->jetzt()));
    }

    /**
     * Die Anmeldeseite zeigt Störungen und sonst nichts.
     *
     * **Beide Richtungen, denn die stille Hälfte ist die, die zuviel zeigt.**
     * Was dort steht, steht vor jedem, der die Adresse kennt.
     */
    public function test_the_login_page_shows_incidents_only(): void
    {
        Announcement::factory()->incident()->create();
        Announcement::factory()->warning()->create();
        Announcement::factory()->create();

        $auf = Announcement::onLoginPage($this->jetzt());

        self::assertCount(1, $auf, 'Nur die Störung gehört auf die Anmeldeseite.');
        self::assertSame(AnnouncementCategory::Incident, $auf->first()?->category);
    }

    /**
     * Das Publikum spielt auf der Anmeldeseite keine Rolle.
     *
     * Wer nicht angemeldet ist, hat keins — eine Störung nur „für Kunden"
     * erschiene sonst dort nicht, obwohl sie die Kategorie hat, die dort
     * hingehört.
     */
    public function test_the_login_page_ignores_the_audience(): void
    {
        Announcement::factory()->incident()->forAudiences([AnnouncementAudience::Customer])->create();

        self::assertCount(1, Announcement::onLoginPage($this->jetzt()));
    }

    /** Auch auf der Anmeldeseite gilt das Fenster. */
    public function test_the_login_page_still_respects_the_window(): void
    {
        Announcement::factory()->incident()->create([
            'visible_from' => '2026-09-06 00:00:00',
            'visible_until' => null,
        ]);

        self::assertCount(0, Announcement::onLoginPage($this->jetzt()));
        self::assertCount(1, Announcement::onLoginPage(Carbon::parse('2026-09-06 01:00', 'UTC')));
    }
}
