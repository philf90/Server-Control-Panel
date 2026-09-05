<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die Ankündigungen kommen als **Verschluss** in die geteilte Nutzlast
 * (A14, `docs/103 §7`).
 *
 * ## Gemessen wird die Wirkung und nicht das Wort
 *
 * Ein Wächter, der `fn () =>` als Zeichenkette in `share()` sucht, ist grün,
 * sobald es irgendwo in der Datei steht — und in dieser Datei steht es dreimal,
 * für `flash`. Dieses Repo hat diesen Fehler oft genug bezahlt:
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht.**
 *
 * Gezählt werden deshalb die **Abfragen** unter beiden Anfragearten. Gemessen
 * vor dem Bau (`docs/81 §2.3q` M5):
 *
 * | | Eigenschaften | davon Sonde |
 * |---|---|---|
 * | voller Besuch | 16 | 2 (fertiger Wert **und** Verschluss) |
 * | `only: ['tiles']` | 2 | 1 (nur der fertige Wert) |
 *
 * > **Ein fertiger Wert in `share()` läuft bei jeder Anfrage, auch bei einer,
 * > die ihn gar nicht mitschickt. Ein Verschluss läuft nur, wenn er gesendet
 * > wird.**
 *
 * ## Je Anfrageart eine eigene Methode
 *
 * `Inertia\ResponseFactory` ist ein Singleton; zwei Anfragen in **einer**
 * Testmethode teilen sich seinen Zustand, und die zweite wertet den Verschluss
 * nicht mehr aus. Das hat die Messrunde eine halbe Stunde gekostet:
 *
 * > **Ein Prüfstand, der mehrere Anfragen in einem Prozess fährt, misst den
 * > Zustand, den die vorige hinterlassen hat — php-fpm tut das nicht.**
 */
final class AnnouncementShareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Kopfzeilen eines echten Inertia-Besuchs.
     *
     * **Die Fassung kommt aus der Mittelschicht, die sie setzt.** Eine fehlende
     * oder leere Kopfzeile gibt **409**, sobald jemand gebaut hat — und jede
     * Bilderrunde tut das. `PreviousUrlTest` hat genau das am 26. August
     * gekostet.
     *
     * @return array<string, string>
     */
    private function kopf(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        ];
    }

    /**
     * Die Abfragen, die eine Anfrage auf die Ankündigungen absetzt.
     *
     * @param  array<string, string>  $extra
     */
    private function abfragen(array $extra): int
    {
        $konto = Account::factory()->admin()->create();
        Announcement::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $antwort = $this->actingAs($konto)->withHeaders($this->kopf() + $extra)->get('/');
        $antwort->getContent();

        self::assertSame(200, $antwort->getStatusCode(),
            'Ohne 200 misst dieser Fall nichts — eine 409 sähe aus wie „keine Abfrage".');

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains((string) $q['query'], 'from "announcements"'),
        ));
    }

    public function test_a_full_visit_asks_for_them(): void
    {
        self::assertSame(1, $this->abfragen([]),
            'Der volle Besuch schickt die Ankündigungen mit — also wird gefragt. '
            .'Ohne diese Richtung wäre die Messung daneben eine Null ohne Bedeutung.');
    }

    public function test_a_partial_reload_does_not(): void
    {
        self::assertSame(0, $this->abfragen([
            'X-Inertia-Partial-Data' => 'tiles',
            'X-Inertia-Partial-Component' => 'Overview',
        ]), 'Beim partiellen Nachladen wird die Eigenschaft nicht gesendet — also darf sie '
            .'auch nicht berechnet werden. Die Übersicht lädt alle dreissig Sekunden nach.');
    }

    /**
     * Und der Streifen verschwindet dabei nicht.
     *
     * Gemessen am echten Panel (`docs/81 §2.3q` M6): Der Server schickt beim
     * partiellen Nachladen von sechzehn Eigenschaften zwei, der Klient hält die
     * übrigen. Hier steht die Serverseite davon — dass `announcements` zu den
     * nicht gesendeten gehört und nicht etwa als leere Liste **überschrieben**
     * wird.
     *
     * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
     * > beiden.**
     */
    public function test_it_is_absent_and_not_empty_on_a_partial_reload(): void
    {
        $konto = Account::factory()->admin()->create();
        Announcement::factory()->create();

        $antwort = $this->actingAs($konto)->withHeaders($this->kopf() + [
            'X-Inertia-Partial-Data' => 'tiles',
            'X-Inertia-Partial-Component' => 'Overview',
        ])->get('/');

        $props = json_decode((string) $antwort->getContent(), true)['props'] ?? [];

        self::assertIsArray($props);
        self::assertArrayNotHasKey('announcements', $props,
            'Die Eigenschaft fehlt beim partiellen Nachladen — sie steht nicht als leere Liste da. '
            .'Wäre sie leer, überschriebe der Klient seine eigene und der Streifen verschwände.');
    }
}
