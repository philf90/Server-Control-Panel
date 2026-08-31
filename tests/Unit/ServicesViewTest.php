<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Die Dienste-Seite — und die eine Regel, um die es dort geht.
 *
 * **Ein Timer ohne nächsten Termin meldet `active`.** Gemessen gegen systemd 255
 * (`docs/89 §3`): Der gesunde und der kaputte Timer sind an `ActiveState` nicht
 * zu unterscheiden. Wer die Farbe daran hängt, malt beide grün — und das
 * Abnahmekriterium von A2 ist genau, dass man den Schaden sieht.
 *
 * Gehalten wird das am Quelltext und nicht an einer Aufnahme: Ein Bild sagt,
 * wie es an einem Tag aussah; dieser Wächter sagt, dass die Entscheidung noch
 * dort steht, wo sie hingehört.
 */
final class ServicesViewTest extends TestCase
{
    use WithoutMarkupComments;

    private const SEITE = 'resources/js/Pages/Services/Index.vue';

    private const CONTROLLER = 'app/Http/Controllers/ServicesController.php';

    private static function quelle(string $pfad): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        self::assertIsString($inhalt, $pfad.' ist nicht lesbar.');

        return $inhalt;
    }

    /**
     * Die Farbe folgt dem Termin und nicht dem Zustand von systemd.
     *
     * Geprüft wird die **Reihenfolge**: `has_next` muss vor `active_state`
     * stehen. Stünde es danach, träfe der `active`-Zweig zuerst, und der
     * kaputte Timer wäre grün — der Ausdruck wäre trotzdem da, und ein Wächter,
     * der nur nach dem Wort sucht, bliebe still.
     */
    public function test_the_colour_of_a_timer_follows_its_next_date(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $termin = strpos($quelle, 'has_next === false');
        $zustand = strpos($quelle, "active_state === 'active'");

        $this->assertIsInt($termin, 'Die Seite fragt den nächsten Termin gar nicht.');
        $this->assertIsInt($zustand, 'Die Seite fragt den Zustand von systemd gar nicht.');
        $this->assertLessThan(
            $zustand,
            $termin,
            'Der Zustand wird vor dem Termin gefragt — dann ist ein Timer ohne Termin grün.',
        );
    }

    /**
     * Der Schaden steht als Satz da und nicht als Zahl.
     *
     * `docs/81 §A2`: „ohne dass man die Zahl deuten muss".
     */
    public function test_a_timer_without_a_date_is_named_in_words(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertStringContainsString('kein nächster Termin', $quelle);
        $this->assertStringContainsString('meldet', $quelle, 'Die Meldung oben sagt nicht, was daran falsch ist.');
    }

    /**
     * „Kein Termin" und „Termin unbekannt" sind zwei Auskünfte.
     *
     * Das erste ist ein Schaden, das zweite eine Lücke im Messmittel — auf einem
     * System, dessen `systemctl` kein JSON kann, fehlt das Datum, und der Timer
     * ist trotzdem gesund. Dieselbe Zelle für beides machte aus jeder Lücke
     * einen Befund.
     */
    public function test_a_missing_date_is_not_the_same_as_no_date(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertStringContainsString("'unbekannt'", $quelle);
        $this->assertStringContainsString("'—'", $quelle);
    }

    /**
     * Der Termin wird auf dem Server zu Text.
     *
     * `toLocaleString` im Browser nähme die Zone des Betrachters; die
     * Anzeigezone dieses Panels steht in den Einstellungen (`docs/40`), und
     * `Clock` ist die einzige Stelle, die daraus eine Anzeige macht.
     */
    public function test_the_date_is_formatted_on_the_server(): void
    {
        $this->assertStringContainsString('Clock::display', self::quelle(self::CONTROLLER));
        $this->assertStringNotContainsString(
            'toLocaleString',
            $this->withoutMarkupComments(self::quelle(self::SEITE)),
            'Die Seite rechnet die Zeit selbst — dann entscheidet die Zone des Betrachters.',
        );
    }

    /**
     * Dienste und Timer stehen getrennt.
     *
     * Ein Timer hat keine PID, keinen Neustartzähler und keinen Startzeitpunkt.
     * In einer gemeinsamen Tabelle stünden bei ihm drei Spalten leer und eine
     * bei allen anderen.
     */
    public function test_services_and_timers_are_two_sections(): void
    {
        $controller = self::quelle(self::CONTROLLER);

        $this->assertStringContainsString("'service'", $controller);
        $this->assertStringContainsString("'timer'", $controller);

        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertSame(
            2,
            substr_count($quelle, '<Section'),
            'Es sind nicht mehr genau zwei Bereiche — dann prüft die Trennung hier nichts.',
        );
    }

    /**
     * Eine leere Liste heisst nicht „nichts installiert".
     *
     * Ohne `live` wäre ein schweigender Agent von einem Server ohne Dienste
     * nicht zu unterscheiden — derselbe Unterschied, den `null` und `0` im
     * Leser machen.
     */
    public function test_a_silent_agent_is_told_apart_from_an_empty_server(): void
    {
        $this->assertStringContainsString("'live' =>", self::quelle(self::CONTROLLER));
        $this->assertStringContainsString('v-if="!live"', $this->withoutMarkupComments(self::quelle(self::SEITE)));
    }
}
