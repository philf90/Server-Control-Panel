<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Models\Account;
use App\Support\Diagnose\Checks\QuotaOverrun;
use App\Support\Diagnose\FindingLog;
use App\Support\Plans\Quota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ein Hinweis am Kontingent, der eine Seite nennt, nennt die, auf der die
 * Überschreitung steht — B5, `docs/141 §0` Befund 6.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Am Traffic-Kontingent stand seit P1: *„Die Überschreitung erscheint in der
 * Übersicht."* Erschienen ist sie seit B5 auf der Seite „Diagnose" und nie in
 * der Übersicht. Den Satz liest der Betreiber im Formular eines Plans, und er
 * sucht dann dort, wohin er zeigt.
 *
 * > **Eine Zusage im Hinweistext ist eine Zusage.**
 *
 * ## Was er hält
 *
 * Für jedes Kontingent, das `QuotaOverrun` misst: Der Hinweis nennt eine Seite
 * so, wie das Menü sie nennt, und auf genau dieser Seite steht ein Befund über
 * die Überschreitung. Gefragt wird **durch die Tür** und nicht am Namen — ein
 * Wächter über den Namen allein hätte die alte Zeile durchgelassen, denn
 * „Übersicht" heisst ein Menüpunkt.
 *
 * ## Was er nicht kann
 *
 * Welche Kontingente die Prüfung misst, steht in `QuotaOverrun::overruns()` als
 * Code und in keiner Liste, die sich lesen liesse. Die drei hier stehen deshalb
 * mit ihrem Grund da, und jeder Grund wird gegen `QuotaOverrun::REASONS`
 * gehalten: Wer einen umbenennt, sieht es hier.
 */
final class QuotaHintTest extends TestCase
{
    use RefreshDatabase;

    /** Die Kontingente, die die Prüfung misst — mit dem Grund, den ihr Befund trägt. */
    private const GEMESSEN = [
        'disk_mb' => 'disk_over',
        'database_mb' => 'databases_over',
        'traffic_gb' => 'traffic_over',
    ];

    public function test_the_page_a_hint_names_shows_the_overrun(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/PanelLayout.vue'));
        $betreiber = Account::factory()->admin()->create();
        $geprueft = 0;

        foreach (self::GEMESSEN as $kontingent => $grund) {
            self::assertContains($grund, QuotaOverrun::REASONS[FindingCheck::QuotaExceeded->value], $grund.' gibt es nicht mehr.');

            $hinweis = Quota::from($kontingent)->hint();

            self::assertSame(1, preg_match('/unter „([^"]+)"/u', $hinweis, $seite),
                $kontingent.': Der Hinweis sagt nicht, wo die Überschreitung steht — „'.$hinweis.'"');

            self::assertSame(1, preg_match("/\\{\\s*name:\\s*'".preg_quote($seite[1], '/')."',\\s*href:\\s*'([^']+)'/u", $layout, $eintrag),
                $kontingent.': Der Hinweis nennt „'.$seite[1].'", und so heisst kein Menüpunkt.');

            $abo = 'hinweis-'.$kontingent.'.invalid';

            (new FindingLog)->replace(FindingCheck::QuotaExceeded, [
                ['subject' => $abo, 'reason' => $grund],
            ], Carbon::parse('2026-10-05 03:00:00'));

            $antwort = $this->actingAs($betreiber)->get($eintrag[1])->assertOk();

            self::assertStringContainsString($abo, (string) $antwort->getContent(),
                $kontingent.': Auf „'.$seite[1].'" steht die Überschreitung nicht — der Hinweis schickt den Betreiber an die falsche Stelle.');

            $geprueft++;
        }

        self::assertSame(3, $geprueft);
    }
}
