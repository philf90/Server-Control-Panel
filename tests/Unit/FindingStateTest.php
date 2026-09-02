<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DnsRecordState;
use App\Enums\FindingCheck;
use App\Enums\FindingState;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die vier Zustände eines Befundes (A10, `docs/98 §2`).
 *
 * **Der vierte ist der Grund, dass es diesen Wächter gibt.**
 * {@see FindingState::Unknown} heisst „die Messung hat nichts ergeben" und
 * nicht „es ist nichts". Ohne ihn gäbe ein Diagnoselauf bei totem Agenten
 * Entwarnung — der Fehler aus `docs/44`.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 *
 * Framework-frei bis auf die eine Frage an die Migration, und die geht an ihren
 * Text.
 */
final class FindingStateTest extends TestCase
{
    use WithoutPhpComments;

    public function test_there_are_four_states(): void
    {
        $this->assertSame(
            ['ok', 'warn', 'fail', 'unknown'],
            array_map(static fn (FindingState $s): string => $s->value, FindingState::cases()),
        );
    }

    /**
     * Jeder Zustand hat genau eine Marke, und `Unknown` trägt `neutral`.
     *
     * **Das ist die eigentliche Entscheidung**, und dieselbe wie bei
     * {@see DnsRecordState::badge()}: Ein rotes Signal behauptete,
     * es sei etwas kaputt, und schickte den Betreiber auf die Suche nach einem
     * Schaden, den niemand gemessen hat.
     */
    public function test_every_state_carries_its_own_badge(): void
    {
        $marken = array_map(static fn (FindingState $s): string => $s->badge(), FindingState::cases());

        $this->assertSame($marken, array_unique($marken), 'Zwei Zustände sehen gleich aus.');
        $this->assertSame([], array_diff($marken, ['ok', 'warn', 'critical', 'neutral']), 'Badge kennt nur diese vier Ränge.');
        $this->assertSame('neutral', FindingState::Unknown->badge(), '„Nicht gemessen" ist kein Zustand, sondern eine Abwesenheit.');
        $this->assertSame('critical', FindingState::Fail->badge());
    }

    /**
     * `Unknown` steht über `Warn` und unter `Fail`.
     *
     * Eine Prüfung, die nicht gelaufen ist, kann alles verbergen — auch ein
     * `Fail`; sie gehört weit nach oben. Sie steht trotzdem unter dem, was
     * gemessen kaputt ist: **Ein belegter Schaden geht einer Vermutung vor.**
     */
    public function test_the_order_puts_a_measured_failure_first(): void
    {
        $this->assertGreaterThan(FindingState::Unknown->rank(), FindingState::Fail->rank());
        $this->assertGreaterThan(FindingState::Warn->rank(), FindingState::Unknown->rank());
        $this->assertGreaterThan(FindingState::Ok->rank(), FindingState::Warn->rank());
    }

    /** Nur „in Ordnung" erzeugt keine Zeile — „nicht gemessen" schon. */
    public function test_only_ok_stays_silent(): void
    {
        foreach (FindingState::cases() as $state) {
            $this->assertSame(
                $state !== FindingState::Ok,
                $state->reportable(),
                sprintf('%s: „nicht gemessen" muss auf der Seite stehen, sonst sieht ein Ausfall aus wie Entwarnung.', $state->value),
            );
        }
    }

    /**
     * Kein Hinweis ausser dem von `Ok` behauptet, es sei etwas in Ordnung.
     *
     * Derselbe Satz wie bei {@see DnsRecordState::hint()}: Ein
     * Hinweis, der bei „nicht gemessen" Entwarnung gibt, ist die Fehlmeldung,
     * gegen die es den vierten Zustand überhaupt gibt.
     *
     * **Dieser Wächter liest Prosa, und das kann er nur stumpf.** Beim ersten
     * Lauf hat er zugebissen — an einem Satz, der die Wendung **verneinend**
     * benutzte („auch nicht, dass er in Ordnung wäre"). Der Satz war richtig
     * und der Wächter trotzdem nicht falsch: Verneinung kann er nicht sehen,
     * und sie ihm beizubringen hiesse, einen Parser für Deutsch zu bauen.
     * Geändert wurde deshalb der Satz und nicht die Regel.
     *
     * > **Ein Wächter über Prosa prüft den Wortlaut und nicht die Aussage.**
     * > Wer hier rot wird, sieht erst nach, ob sein Satz das Gegenteil sagt.
     */
    public function test_no_hint_but_the_first_gives_the_all_clear(): void
    {
        foreach (FindingState::cases() as $state) {
            $this->assertNotSame('', trim($state->label()), $state->value);
            $this->assertNotSame('', trim($state->hint()), $state->value);

            if ($state === FindingState::Ok) {
                continue;
            }

            $this->assertStringNotContainsStringIgnoringCase(
                'in Ordnung',
                $state->hint(),
                sprintf('Der Hinweis zu %s gibt Entwarnung.', $state->value),
            );
        }
    }

    /**
     * Die Schwere steht **nicht** in der Tabelle.
     *
     * Sie folgt aus `check` und `reason` ({@see FindingCheck::state()}).
     * Eine Spalte daneben wäre die zweite Fassung derselben Regel, und die
     * zweite ist die, die veraltet — dieselbe Überlegung, aus der
     * `Db\Session::CLIENT` seine Argumentliste nur einmal führt.
     *
     * **Gelesen wird der Text der Migration ohne seine Kommentare.** Dieser
     * Wächter suchte in seiner ersten Fassung nach der Zeichenkette `state` und
     * fand sie im Kommentar, der erklärt, warum es die Spalte nicht gibt —
     * derselbe Fehler wie bei `OutcomeTest` am 1. September:
     *
     * > **Ein Kommentar, der die entfernte Zeile zitiert, stellt sie für einen
     * > Wächter wieder her.**
     */
    public function test_the_table_has_no_column_for_the_state(): void
    {
        $pfad = dirname(__DIR__, 2).'/database/migrations/2026_09_02_120000_create_findings_table.php';

        $this->assertFileExists($pfad, 'Die Migration der Befunde fehlt — dieser Wächter misst dann nichts.');

        $text = $this->withoutComments((string) file_get_contents($pfad));

        // Untergrenze: ohne sie wäre der Wächter grün, sobald der Ausdruck
        // ins Leere läuft oder die Datei umzieht.
        $this->assertMatchesRegularExpression("/->string\('check'/", $text, 'Die Kennungsspalten stehen nicht mehr da — der Ausdruck misst nichts.');
        $this->assertMatchesRegularExpression("/->string\('reason'/", $text);

        $this->assertDoesNotMatchRegularExpression(
            "/->[a-zA-Z]+\('state'/",
            $text,
            "Die Tabelle führt eine Spalte für die Schwere. Sie folgt aus check und reason;\n".
            'eine Spalte daneben ist die zweite Fassung derselben Regel.',
        );
    }
}
