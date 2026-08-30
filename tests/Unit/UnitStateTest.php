<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Units;

/**
 * Der Leser für `systemctl show` — und die Regel, an der A2 hängt.
 *
 * ## Warum die Prüfkörper hier stehen und nicht gemessen werden
 *
 * Sie **sind** gemessen, am 30. August 2026 gegen echtes systemd 255 in einer
 * eigenen Namespace (`docs/89`), und stehen hier wörtlich so, wie `systemctl`
 * sie gedruckt hat — samt der Reihenfolge, in der die Zeilen kamen. Wiederholt
 * werden kann die Messung hier nicht: Sie braucht einen laufenden Init, und der
 * CI-Läufer hat keinen.
 *
 * > **Ein Prüfkörper aus dem Prüfling prüft den Prüfling gegen sich selbst.**
 *
 * ## Was diese Fälle festhalten
 *
 * 1. **Ein Timer beantwortet drei der neun alten Felder gar nicht.** Nicht
 *    leer — sie fehlen. Der alte Leser machte daraus `0`, `0` und `''`.
 * 2. **`ActiveState` trennt gesund und kaputt nicht.** Beide stehen auf
 *    `active`; wer daran eine Anzeige hängt, meldet einen Timer ohne Termin als
 *    gesund. Diese Klasse behauptet das nicht — sie misst es an zwei Zeilen
 *    desselben Laufs.
 * 3. **„Die Realtime-Spalte ist leer" heisst nicht „kein Termin".** Der gesunde
 *    monotone Timer — die Bauart der Panel-Timer nach einem Neustart — hat sie
 *    ebenfalls leer. Das ist der Fall, der die naheliegende Regel umwirft, und
 *    er ist der Grund, dass es diesen Wächter gibt.
 * 4. **Eine gemessene Null ist etwas anderes als ein fehlendes Feld.** Ein
 *    Dienst, der nicht läuft, hat `MainPID=0`; ein Timer hat keine PID.
 */
final class UnitStateTest extends TestCase
{
    /**
     * Ein laufender Dienst. Gemessen an `mess-laeuft.service`.
     *
     * Die Reihenfolge ist die gemessene: `systemctl show` sortiert nicht nach
     * der Reihenfolge in `--property=`.
     */
    private const DIENST = [
        'MainPID=126',
        'NRestarts=0',
        'ExecMainStartTimestamp=Sun 2026-08-30 18:35:34 UTC',
        'Id=mess-laeuft.service',
        'Description=Messdienst der laeuft',
        'LoadState=loaded',
        'ActiveState=active',
        'SubState=running',
        'UnitFileState=static',
    ];

    /** Ein gesunder Timer mit Kalendersockel. Gemessen an `probe-a.timer`. */
    private const TIMER_KALENDER = [
        'Unit=probe-a.service',
        'NextElapseUSecRealtime=Mon 2026-08-31 04:00:00 UTC',
        'NextElapseUSecMonotonic=0',
        'Id=probe-a.timer',
        'Description=A gesund mit Kalender',
        'LoadState=loaded',
        'ActiveState=active',
        'SubState=waiting',
        'UnitFileState=static',
    ];

    /**
     * Ein **gesunder** Timer mit monotonem Sockel. Gemessen an `mess-boot.timer`.
     *
     * Die Realtime-Spalte ist leer und der Timer ist in Ordnung — sein Termin
     * steht als Dauer daneben. Genau dieser Fall kehrt die Regel um.
     */
    private const TIMER_MONOTON = [
        'Unit=mess-dienst.service',
        'NextElapseUSecRealtime=',
        'NextElapseUSecMonotonic=1h 8min 10.136428s',
        'Id=mess-boot.timer',
        'Description=Timer mit Boot- und Aktivsockel wie srvpanel-cron',
        'LoadState=loaded',
        'ActiveState=active',
        'SubState=waiting',
        'UnitFileState=static',
    ];

    /** Ein Timer ohne nächsten Termin, aber `active`. Gemessen an `probe-b.timer`. */
    private const TIMER_OHNE_TERMIN = [
        'Unit=probe-b.service',
        'NextElapseUSecRealtime=',
        'NextElapseUSecMonotonic=infinity',
        'Id=probe-b.timer',
        'Description=B nur OnUnitActiveSec ohne je gelaufenen Dienst',
        'LoadState=loaded',
        'ActiveState=active',
        'SubState=elapsed',
        'UnitFileState=static',
    ];

    /** Ein von Hand gestoppter Timer. Gemessen an `probe-a.timer` nach `stop`. */
    private const TIMER_GESTOPPT = [
        'Unit=probe-a.service',
        'NextElapseUSecRealtime=',
        'NextElapseUSecMonotonic=infinity',
        'Id=probe-a.timer',
        'Description=A gesund mit Kalender',
        'LoadState=loaded',
        'ActiveState=inactive',
        'SubState=dead',
        'UnitFileState=static',
    ];

    /** Eine Unit, die es nicht gibt. Gemessen an `nicht-vorhanden.service`. */
    private const FEHLT = [
        'MainPID=0',
        'NRestarts=0',
        'ExecMainStartTimestamp=',
        'Id=nicht-vorhanden.service',
        'Description=nicht-vorhanden.service',
        'LoadState=not-found',
        'ActiveState=inactive',
        'SubState=dead',
        'UnitFileState=',
    ];

    public function test_a_timer_has_no_pid_no_restarts_and_no_start(): void
    {
        $zeile = Units::read('probe-a.timer', self::TIMER_KALENDER);

        $this->assertNull($zeile['pid'], 'Ein Timer hat keine PID — null und nicht 0.');
        $this->assertNull($zeile['restarts'], 'Ein Timer hat keinen Neustartzaehler.');
        $this->assertNull($zeile['since'], 'Ein Timer hat keinen Startzeitpunkt.');
    }

    /**
     * Die Gegenrichtung: Bei einem Dienst ist die Null gemessen und nicht
     * geraten. Ohne diesen Fall wäre `null` überall richtig.
     */
    public function test_a_service_reports_a_measured_zero(): void
    {
        $zeile = Units::read('mess-laeuft.service', self::DIENST);

        $this->assertSame(126, $zeile['pid']);
        $this->assertSame(0, $zeile['restarts'], 'Null Neustarts sind gemessen, nicht abwesend.');
        $this->assertSame('Sun 2026-08-30 18:35:34 UTC', $zeile['since']);
    }

    /**
     * Der Satz aus `CLAUDE.md`, hier als Messung an zwei Zeilen desselben Laufs.
     */
    public function test_active_state_does_not_separate_a_broken_timer(): void
    {
        $gesund = Units::read('probe-a.timer', self::TIMER_KALENDER);
        $kaputt = Units::read('probe-b.timer', self::TIMER_OHNE_TERMIN);

        $this->assertSame('active', $gesund['active_state']);
        $this->assertSame('active', $kaputt['active_state']);
        $this->assertNotSame(
            $gesund['has_next'],
            $kaputt['has_next'],
            'Wenn sich die beiden auch in has_next nicht unterscheiden, misst dieser Wächter nichts.',
        );
    }

    /**
     * @param  list<string>  $zeilen
     */
    #[DataProvider('termine')]
    public function test_the_pair_decides_whether_a_next_date_exists(array $zeilen, bool $erwartet, string $warum): void
    {
        $this->assertSame($erwartet, Units::read('t.timer', $zeilen)['has_next'], $warum);
    }

    /** @return array<string,array{list<string>,bool,string}> */
    public static function termine(): array
    {
        return [
            'Kalender: der Termin steht in der Realtime-Spalte' => [
                self::TIMER_KALENDER, true, 'Ein Zeitstempel in der Realtime-Spalte ist ein Termin.',
            ],
            'monoton: leere Realtime-Spalte und trotzdem gesund' => [
                self::TIMER_MONOTON, true, 'Eine Dauer in der monotonen Spalte ist ein Termin — auch bei leerer Realtime-Spalte.',
            ],
            'kein Anker: infinity' => [
                self::TIMER_OHNE_TERMIN, false, 'infinity heisst nie.',
            ],
            'gestoppt' => [
                self::TIMER_GESTOPPT, false, 'Ein gestoppter Timer hat keinen Termin.',
            ],
        ];
    }

    /**
     * `has_next` ist bei allem, was kein Timer ist, `null` und nicht `false`.
     *
     * „Hat keinen Termin" und „kann keinen haben" sind zwei Auskünfte, und die
     * Oberfläche darf die zweite nicht als Schaden zeigen.
     */
    public function test_only_a_timer_answers_the_question_at_all(): void
    {
        $this->assertNull(Units::read('mess-laeuft.service', self::DIENST)['has_next']);
        $this->assertNull(Units::read('mess-laeuft.service', self::DIENST)['triggers']);
        $this->assertSame('probe-a.service', Units::read('probe-a.timer', self::TIMER_KALENDER)['triggers']);
    }

    public function test_the_kind_comes_from_the_name(): void
    {
        $this->assertSame('service', Units::read('mess-laeuft.service', self::DIENST)['kind']);
        $this->assertSame('timer', Units::read('probe-a.timer', self::TIMER_KALENDER)['kind']);
        $this->assertSame('other', Units::read('ohne-endung', ['Id=ohne-endung'])['kind']);
    }

    public function test_a_missing_unit_is_reported_as_absent(): void
    {
        $zeile = Units::read('nicht-vorhanden.service', self::FEHLT);

        $this->assertFalse($zeile['present']);
        $this->assertSame(0, $zeile['pid'], 'Eine fehlende Unit beantwortet MainPID mit 0 — gemessen.');
        $this->assertNull($zeile['since'], 'Ein leerer Zeitstempel ist kein Zeitpunkt.');
    }

    /**
     * Eine `Description` darf ein Gleichheitszeichen enthalten, und
     * `systemctl show` maskiert nichts.
     */
    public function test_a_value_may_contain_the_separator(): void
    {
        $zeile = Units::read('x.service', ['Id=x.service', 'Description=a=b=c', 'LoadState=loaded']);

        $this->assertSame('a=b=c', $zeile['description']);
    }

    /**
     * Die Abfrage muss beide Timer-Felder mitnehmen — fehlte eines, stünde
     * `has_next` auf einer halben Auskunft, und zwar wortlos.
     */
    public function test_the_query_asks_for_both_elapse_fields(): void
    {
        $this->assertContains('NextElapseUSecRealtime', Units::FIELDS);
        $this->assertContains('NextElapseUSecMonotonic', Units::FIELDS);
        $this->assertContains('Unit', Units::FIELDS);
    }

    /**
     * Die Operation fragt `show` und nicht `list-timers`.
     *
     * Gemessen: Ein von Hand gestoppter Timer verschwindet aus `list-timers
     * --all` vollständig. Wer die Liste als Quelle nimmt, kann den Schaden, den
     * A2 zeigen soll, nicht mehr sehen.
     */
    public function test_the_operation_does_not_ask_the_timer_list(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../agent/src/Ops/ServiceStatus.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString("'show',", $quelle);
        $this->assertStringContainsString('Units::FIELDS', $quelle);
        $this->assertDoesNotMatchRegularExpression(
            "/'list-timers'/",
            $quelle,
            'Ein gestoppter Timer steht nicht in list-timers — die Operation darf ihn nicht von dort holen.',
        );
    }
}
