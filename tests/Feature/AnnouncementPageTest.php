<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnnouncementCategory;
use App\Models\Account;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Die Verwaltungsseite gehört dem Betreiber — und der Streifen auf der
 * Anmeldeseite trägt nur Störungen (A14, `docs/103 §5` und `§4.4`).
 *
 * ## Warum `operate-server` und nicht `manage-settings`
 *
 * Der Plan hat das einmal andersherum gesagt, mit der Begründung „eine
 * Ankündigung dreht nichts am Server, sie ist Text in einer Tabelle". Sie ordnet
 * nach dem, was der Griff **anfasst**; `docs/20 §6.1` ordnet nach dem, was er
 * **bewirkt** — kritisch ist unter anderem, was „alle Kunden mitnimmt".
 *
 * > **Eine Fähigkeit bemisst sich nicht daran, was ein Griff anfasst, sondern
 * > daran, wen er erreicht.**
 *
 * ## Beide Richtungen, und die zweite ist die stille
 *
 * Dass der Betreiber die Seite erreicht, ist die laute Hälfte — sie fällt beim
 * ersten Klick auf. Dass ein Administrator sie **nicht** erreicht, fällt nie
 * auf, solange niemand einen anlegt.
 */
final class AnnouncementPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die drei Griffe dieser Seite.
     *
     * Als Datenlieferant und nicht als drei Testmethoden: Eine vierte Route
     * bekäme sonst leicht keinen Fall, und das fiele niemandem auf.
     *
     * **`{id}` und nicht `1`, und das ist bezahlt.** Der erste Wurf schrieb
     * `/announcements/1`; die Modellbindung läuft **vor** `can:`, also gab eine
     * Kennung, die es nicht gibt, **404** statt 403 — und beim Betreiber wurde
     * daraus ein falsches Grün, weil 404 ≠ 403 ist.
     *
     * > **Eine Gegenprobe, die an einer anderen Hürde scheitert als der
     * > gemeinten, hat die gemeinte nicht geprüft.**
     *
     * @return iterable<string, array{string, string}>
     */
    public static function griffe(): iterable
    {
        yield 'ansehen' => ['get', '/announcements'];
        yield 'anlegen' => ['post', '/announcements'];
        yield 'entfernen' => ['delete', '/announcements/{id}'];
    }

    /** Die Adresse mit einer Kennung, die es wirklich gibt. */
    private function pfad(string $muster): string
    {
        return str_replace('{id}', (string) Announcement::factory()->create()->id, $muster);
    }

    #[DataProvider('griffe')]
    public function test_an_administrator_is_turned_away(string $verb, string $pfad): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->{$verb}($this->pfad($pfad))
            ->assertForbidden();
    }

    #[DataProvider('griffe')]
    public function test_a_customer_is_turned_away(string $verb, string $pfad): void
    {
        $this->actingAs(Account::factory()->customer()->create())
            ->{$verb}($this->pfad($pfad))
            ->assertForbidden();
    }

    /**
     * Und der Betreiber kommt durch.
     *
     * **Gemessen wird „nicht 403" und nicht „200".** Ein `post` ohne Rumpf
     * scheitert an der Prüfung, und das ist richtig — geprüft wird hier die
     * Tür und nicht das Formular. Dieselbe Unterscheidung wie in
     * {@see InspectOnlyTest}.
     */
    #[DataProvider('griffe')]
    public function test_the_operator_gets_through(string $verb, string $pfad): void
    {
        $antwort = $this->actingAs(Account::factory()->admin()->create())->{$verb}($this->pfad($pfad));

        // Die Gegenprobe zur Falle oben: Eine 404 wäre hier kein Durchkommen,
        // sondern eine Kennung, die es nicht gibt.
        self::assertNotSame(404, $antwort->getStatusCode(),
            'Die Kennung muss es geben — sonst misst dieser Fall die Modellbindung und nicht die Tür.');

        self::assertNotSame(403, $antwort->getStatusCode(),
            "Der Betreiber muss $verb $pfad erreichen.");
    }

    /** Die Seite trägt, was sie zeigen soll. */
    public function test_the_page_carries_its_rows(): void
    {
        Announcement::factory()->incident()->create();

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Announcements/Index')
                ->has('announcements', 1)
                ->where('announcements.0.rank', 'Störung')
                ->where('announcements.0.badge', 'critical')
                ->etc());
    }

    /**
     * Die Anmeldeseite trägt Störungen — und nur die.
     *
     * **Beide Richtungen, denn die stille Hälfte ist die, die zuviel zeigt.**
     *
     * > **Was auf der Anmeldeseite steht, steht vor jedem, der die Adresse
     * > kennt.**
     */
    public function test_the_login_page_carries_incidents_only(): void
    {
        Announcement::factory()->incident()->create();
        Announcement::factory()->warning()->create();
        Announcement::factory()->create();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
                ->has('incidents', 1)
                ->where('incidents.0.badge', AnnouncementCategory::Incident->badge())
                ->etc());
    }

    /**
     * Auch ohne Störung steht die Eigenschaft da — als leere Liste.
     *
     * Ohne sie wäre `incidents` auf der Seite `undefined`, und `v-for` darüber
     * bräche. Der Rückfall gehört an die Grenze und nicht in die Vorlage.
     */
    public function test_the_login_page_always_carries_the_key(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('incidents', 0)->etc());
    }
}
