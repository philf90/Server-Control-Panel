<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Was in `share()` steht, steht dort als **Verschluss** — B4, `docs/129 §8`.
 *
 * Die Regel kommt aus einer Messung (`docs/103 §1` M5) und stand seit A14 als
 * Kommentar an drei einzelnen Einträgen. Was sie hielt, war die Aufmerksamkeit
 * dessen, der den nächsten schrieb:
 *
 * > **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
 * > stimmt, nur durch Glück getrennt.**
 *
 * ## Zwei Hälften, und die erste allein genügt nicht
 *
 * Gemessen wird die **Wirkung**: Eine Eigenschaft, die eine Antwort nicht
 * enthält, darf keine Abfrage kosten. Das ist die Hälfte, die etwas bedeutet.
 *
 * Daneben steht die **Form**, und zwar nicht als Zeichenkettensuche: `fn (` im
 * Text von `share()` zu suchen wäre grün, sobald es irgendwo steht — und in
 * dieser Datei steht es vier Mal innerhalb von `flash`. Gelesen werden deshalb
 * die Einträge der **obersten Ebene**, an der Klammertiefe abgezählt.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht.**
 *
 * Die Form ist nötig, weil die Wirkung nur die Einträge sieht, die heute
 * Abfragen kosten. Ein neuer fertiger Wert, der morgen eine kostet, ist heute
 * unsichtbar — und die Form sieht ihn sofort.
 */
final class SharedClosureTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

    /**
     * Die Kopfzeilen eines echten Inertia-Besuchs.
     *
     * Die Fassung kommt aus der Mittelschicht, die sie setzt: Eine fehlende
     * oder leere Kopfzeile gibt **409**, sobald jemand gebaut hat — und jede
     * Bilderrunde tut das.
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
     * Wie oft eine Anfrage fragt, ob das Konto ein Abonnement hat.
     *
     * **Der Prüfkörper ist ein Kunde und kein Admin**, und das ist der ganze
     * Fall: `has_active_subscription` beginnt mit `$account->isAdmin() ||`,
     * und bei einem Betreiber bricht die Auswertung davor ab. Gegen ihn
     * gemessen stünde auf beiden Seiten eine Null — also derselbe Wert, den
     * auch eine kaputte Messung liefert.
     *
     * @param  array<string, string>  $extra
     */
    private function sonden(array $extra): int
    {
        $konto = Account::factory()->customer()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $antwort = $this->actingAs($konto)->withHeaders($this->kopf() + $extra)->get('/');
        $antwort->getContent();

        self::assertSame(200, $antwort->getStatusCode(),
            'Ohne 200 misst dieser Fall nichts — eine 409 sähe aus wie „keine Abfrage".');

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains((string) $q['query'], 'exists(select * from "subscriptions"'),
        ));
    }

    public function test_a_full_visit_asks_the_probe(): void
    {
        self::assertSame(1, $this->sonden([]),
            'Der volle Besuch schickt `account` mit — also wird gefragt. Ohne diese Richtung wäre '
            .'die Messung daneben eine Null ohne Bedeutung.');
    }

    public function test_a_partial_reload_does_not(): void
    {
        self::assertSame(0, $this->sonden([
            'X-Inertia-Partial-Data' => 'subscriptions',
            'X-Inertia-Partial-Component' => 'CustomerOverview',
        ]), 'Beim partiellen Nachladen fehlt `account` in der Antwort — also darf es auch nichts '
            .'kosten. Bis zum 21. September 2026 kostete es eine Abfrage je Anfrage.');
    }

    /** Und die Eigenschaft fehlt dabei wirklich, statt leer dazustehen. */
    public function test_the_property_is_absent_and_not_empty(): void
    {
        $antwort = $this->actingAs(Account::factory()->customer()->create())
            ->withHeaders($this->kopf() + [
                'X-Inertia-Partial-Data' => 'subscriptions',
                'X-Inertia-Partial-Component' => 'CustomerOverview',
            ])->get('/');

        $props = json_decode((string) $antwort->getContent(), true)['props'] ?? [];

        self::assertIsArray($props);
        self::assertArrayNotHasKey('account', $props,
            'Eine leere Ablage und eine fehlende bedeuten für den Klienten zweierlei: Die leere '
            .'überschriebe seine eigene, und das Menü verlöre seine Einträge.');
    }

    /**
     * Und jeder Eintrag der obersten Ebene ist ein Verschluss.
     *
     * Gezählt wird an der Klammertiefe und nicht an einer Zeichenkette — `fn (`
     * steht innerhalb von `flash` vier Mal, und eine Suche über den Text wäre
     * grün, egal was daneben steht.
     */
    public function test_every_shared_entry_is_a_closure(): void
    {
        $eintraege = $this->topLevelEntries();

        self::assertGreaterThanOrEqual(10, count($eintraege),
            'Zehn Einträge sind gemessen. Findet dieser Leser weniger, greift er ins Leere und sein '
            .'Grün bedeutet nichts.');

        foreach ($eintraege as $name => $wert) {
            self::assertMatchesRegularExpression('/^(fn\s*\(|function\s*\()/', $wert, sprintf(
                '`%s` steht als fertiger Wert in `share()`. Er läuft damit bei jeder Anfrage — auch '
                .'bei einer, die ihn gar nicht mitschickt (`docs/103 §1` M5).',
                $name,
            ));
        }
    }

    /**
     * Die Einträge der obersten Ebene des geteilten Feldes.
     *
     * @return array<string, string>
     */
    private function topLevelEntries(): array
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/HandleInertiaRequests.php')
        );

        $marke = 'return array_merge(parent::share($request), [';
        $anfang = strpos($quelle, $marke);

        self::assertNotFalse($anfang,
            'Ohne diesen Anker liest dieser Wächter nichts — und eine leere Liste sähe aus wie eine '
            .'Datei ohne Fehler. Wer `share()` umbaut, zieht den Anker mit.');

        $i = $anfang + strlen($marke);
        $tiefe = 1;
        $name = null;
        $start = $i;
        $out = [];

        for ($ende = strlen($quelle); $i < $ende; $i++) {
            $c = $quelle[$i];

            if ($c === "'" && $tiefe === 1 && $name === null) {
                $schluss = strpos($quelle, "'", $i + 1);

                if ($schluss !== false && str_starts_with(substr($quelle, $schluss + 1), ' => ')) {
                    $name = substr($quelle, $i + 1, $schluss - $i - 1);
                    $i = $schluss + 4;
                    $start = $i;
                    $c = $quelle[$i];
                }
            }

            if (in_array($c, ['[', '(', '{'], true)) {
                $tiefe++;
            } elseif (in_array($c, [']', ')', '}'], true)) {
                $tiefe--;

                if ($tiefe === 0) {
                    break;
                }
            } elseif ($c === ',' && $tiefe === 1 && $name !== null) {
                $out[$name] = trim(substr($quelle, $start, $i - $start));
                $name = null;
            }
        }

        return $out;
    }
}
