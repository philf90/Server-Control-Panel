<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\Units;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Catalog;

/**
 * Das Urteil des Nachtlaufs über eine Unit (A10 Schritt 5, `docs/98 §3 D`).
 *
 * ## Warum dieser Wächter die Seite mitliest
 *
 * Dieselbe Frage wird an zwei Stellen beantwortet: `useUnitState.ts` färbt die
 * Dienste-Seite, {@see Units::judge()} entscheidet, was nachts als Befund
 * dasteht. Beide können sich keinen Code teilen — das eine läuft im Browser,
 * das andere im Panel.
 *
 * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
 * > sondern eine widersprüchliche.** (`docs/91`, Befund 5)
 *
 * Zu belegen ist das nicht; zu **bemerken** schon. Der Schnappschuss unten
 * hält den Rumpf der Seite fest: Ändert ihn jemand, wird dieser Wächter rot und
 * verlangt eine Entscheidung, ob die Nacht mitzieht. Er behauptet keine
 * Gleichheit — die beiden dürfen auseinandergehen, nur nicht unbemerkt.
 *
 * ## Und warum sie auseinandergehen dürfen
 *
 * `activating` ist auf der Seite gelb und nachts kein Befund: Ein
 * `Type=oneshot`-Dienst steht während seines ganzen Laufs darauf, und der
 * Nachtlauf kann in dieselbe Stunde fallen wie `srvpanel-usage.timer`. Die
 * Seite zeigt den Augenblick, der Nachtlauf einen Zustand.
 *
 * Framework-frei.
 */
final class UnitVerdictTest extends TestCase
{
    /**
     * Der Rumpf von `rang()`, wie er am 2. September 2026 dastand.
     *
     * Ohne Einrückung und ohne Leerzeilen verglichen — dieser Wächter soll auf
     * eine geänderte **Regel** anspringen und nicht auf eine Umformatierung.
     */
    private const RANG = [
        "if (!unit.present) return 'neutral'",
        "if (ohneTermin(unit)) return 'critical'",
        "if (unit.active_state === 'active') return 'ok'",
        "if (unit.active_state === 'activating') return 'warn'",
        "if (wartet(unit)) return 'ok'",
        "return 'critical'",
    ];

    /** @return array<string, mixed> eine Zeile, wie `system.units.list` sie liefert */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'unit' => 'srvpanel-metrics.service',
            'kind' => 'service',
            'present' => true,
            'active_state' => 'active',
            'sub_state' => 'running',
            'own' => true,
            'scheduled' => false,
            'has_next' => null,
        ];
    }

    public function test_a_healthy_server_yields_nothing(): void
    {
        $verdict = Units::judge([
            $this->row(),
            $this->row(['unit' => 'srvpanel-cron.service', 'active_state' => 'inactive', 'sub_state' => 'dead', 'scheduled' => true]),
            $this->row(['unit' => 'srvpanel-cron.timer', 'kind' => 'timer', 'has_next' => true, 'scheduled' => null]),
            $this->row(['unit' => 'nginx.service', 'own' => false]),
        ]);

        $this->assertSame([], $verdict['state']);
        $this->assertSame([], $verdict['schedule'], 'Ein heiler Server meldet nichts — sonst liest den Lauf in zwei Wochen niemand mehr.');
    }

    /** Punkt 3 des Abnahmekriteriums: der gestoppte Timer, und zwar sofort. */
    public function test_a_stopped_timer_is_one_finding_and_not_two(): void
    {
        $verdict = Units::judge([
            $this->row(['unit' => 'srvpanel-tls.timer', 'kind' => 'timer', 'active_state' => 'inactive', 'sub_state' => 'dead', 'has_next' => false, 'scheduled' => null]),
        ]);

        $this->assertSame([['subject' => 'srvpanel-tls.timer', 'reason' => 'no_next', 'detail' => 'ActiveState=inactive SubState=dead']], $verdict['schedule']);
        $this->assertSame([], $verdict['state'], 'Derselbe Schaden steht zweimal da — einmal als Termin und einmal als Zustand.');
    }

    /** Ein Timer, der `active` meldet und keinen Termin hat: der Befund aus `docs/89`. */
    public function test_an_active_timer_without_a_date_is_a_finding(): void
    {
        $verdict = Units::judge([
            $this->row(['unit' => 'srvpanel-cron.timer', 'kind' => 'timer', 'has_next' => false, 'scheduled' => null]),
        ]);

        $this->assertSame('no_next', $verdict['schedule'][0]['reason']);
        $this->assertSame([], $verdict['state']);
    }

    /** Ein Dienst, den ein Timer startet, darf stillstehen — der Rest nicht. */
    public function test_a_service_a_timer_starts_may_stand_still(): void
    {
        $wartend = Units::judge([$this->row(['unit' => 'srvpanel-usage.service', 'active_state' => 'inactive', 'scheduled' => true])]);
        $this->assertSame([], $wartend['state']);

        $stehend = Units::judge([$this->row(['active_state' => 'inactive', 'scheduled' => false])]);
        $this->assertSame('inactive', $stehend['state'][0]['reason']);
        $this->assertSame('srvpanel-metrics.service', $stehend['state'][0]['subject'], 'srvpanel-metrics hat keinen Timer und Restart=always — es muss laufen.');
    }

    public function test_a_failed_service_is_told_apart_from_a_stopped_one(): void
    {
        $verdict = Units::judge([$this->row(['active_state' => 'failed', 'sub_state' => 'failed', 'scheduled' => true])]);

        $this->assertSame('failed', $verdict['state'][0]['reason'], 'Ein gescheiterter Dienst wird von seinem Timer nicht entschuldigt.');
    }

    /**
     * `activating` ist nachts kein Befund.
     *
     * Die vier `Type=oneshot`-Dienste dieses Pakets stehen während ihres Laufs
     * darauf, und `srvpanel-usage.timer` feuert alle fünfzehn Minuten.
     */
    public function test_a_service_that_is_starting_is_not_a_finding(): void
    {
        $verdict = Units::judge([$this->row(['unit' => 'srvpanel-usage.service', 'active_state' => 'activating', 'sub_state' => 'start', 'scheduled' => true])]);

        $this->assertSame([], $verdict['state']);
    }

    /**
     * Eine fremde Unit, die es nicht gibt, ist keine Auskunft — eine eigene schon.
     *
     * `Catalog::pick()` fällt auf den ersten Kandidaten zurück, wenn keiner
     * installiert ist: Auf einem Server ohne MariaDB kommt `mariadb.service`
     * mit `present: false` zurück, und ohne diese Unterscheidung stünde das
     * jede Nacht da.
     */
    public function test_a_foreign_unit_that_is_absent_is_not_a_finding(): void
    {
        $fremd = Units::judge([$this->row(['unit' => 'mariadb.service', 'own' => false, 'present' => false, 'active_state' => 'inactive'])]);
        $this->assertSame([], $fremd['state']);

        $eigen = Units::judge([$this->row(['present' => false, 'active_state' => 'inactive'])]);
        $this->assertSame('not_installed', $eigen['state'][0]['reason'], 'Eine eigene Unit, die dem System unbekannt ist, hat das Paket nicht ausgeliefert.');
    }

    /** Eine Zeile ohne Namen wird übergangen und wirft nicht. */
    public function test_a_row_without_a_name_is_skipped(): void
    {
        $verdict = Units::judge([['kind' => 'service'], 'kaputt', $this->row(['unit' => ''])]);

        $this->assertSame([], $verdict['state']);
        $this->assertSame([], $verdict['schedule']);
    }

    /** Die Gegenstände für den Fall, dass der Agent schweigt. */
    public function test_the_own_timers_come_from_the_catalogue(): void
    {
        $timers = Units::ownTimers();

        $this->assertNotSame([], $timers);
        $this->assertContains('srvpanel-tls.timer', $timers);
        $this->assertNotContains('srvpanel-tls.service', $timers);

        foreach ($timers as $timer) {
            $this->assertContains($timer, Catalog::OWN);
        }
    }

    /**
     * Die Seite urteilt weiter so, wie die Nacht es annimmt.
     *
     * Kein Beleg für Gleichheit — ein Stolperdraht. Wird er rot, ist zu
     * entscheiden, ob {@see Units::judge()} mitzieht.
     */
    public function test_the_page_still_judges_the_way_the_night_assumes(): void
    {
        $pfad = dirname(__DIR__, 2).'/resources/js/Composables/useUnitState.ts';
        $this->assertFileExists($pfad);

        $quelle = (string) file_get_contents($pfad);
        $anfang = strpos($quelle, 'export function rang(');
        $this->assertIsInt($anfang, 'Die Seite hat kein rang() mehr — dann urteilt sie woanders.');

        $ende = strpos($quelle, "\n}", $anfang);
        $this->assertIsInt($ende);

        $rumpf = preg_replace('/\s+/', ' ', substr($quelle, $anfang, $ende - $anfang)) ?? '';

        foreach (self::RANG as $zeile) {
            $this->assertStringContainsString($zeile, $rumpf, sprintf(
                "Die Dienste-Seite urteilt anders als am 2. September 2026.\n".
                "Fehlt: %s\n\n".
                'Das ist kein Fehler — es ist die Frage, ob der Nachtlauf mitzieht (Units::judge()).',
                $zeile,
            ));
        }
    }
}
