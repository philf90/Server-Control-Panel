<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ein Timer, der regelmässig laufen soll, rechnet gegen die Uhr.
 *
 * **Gefunden am 19. August 2026 auf `cloudsrv24`, beim Vorbereiten der
 * Bilderrunde.** `srvpanel-cron.timer` meldete `active`, `NEXT` stand auf `-`,
 * und der letzte Lauf lag **22 Stunden** zurück. In der Zwischenzeit hat
 * niemand die Läufe der Cronjobs eingesammelt — und niemand hat es gemeldet,
 * weil ein Timer ohne Termin keinen Fehler auslöst.
 *
 * > **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
 * > abgeschaltet und sieht aus wie eingeschaltet.**
 *
 * Die Ursache ist die Bauart: `OnUnitActiveSec=` rechnet ab der **letzten
 * Aktivierung des Dienstes**, und `OnBootSec=` ab dem Start des Systems. Beide
 * sind monoton. Reisst die Kette einmal — ein `daemon-reload` beim
 * Paketwechsel, ein Neustart der Unit lange nach dem Booten —, dann liegt der
 * eine Sockel in der Vergangenheit und der andere hat keine Vorgeschichte, an
 * die er anknüpfen könnte.
 *
 * `OnCalendar=` hat dieses Problem nicht: Es rechnet gegen die Wanduhr und
 * findet seinen nächsten Termin ohne zu wissen, was vorher war.
 *
 * **Und `Persistent=true` war dabei eine Notiz, die wie eine Zusage aussah.**
 * Die Einstellung wirkt ausschliesslich auf `OnCalendar=`-Timer; in einer rein
 * monotonen Unit steht sie da und tut nichts. Zwei der drei Timer dieses
 * Projekts trugen sie so.
 *
 * > **Eine Einstellung, die für diese Bauart keine Bedeutung hat, liest sich
 * > wie eine Zusage und ist eine Notiz.**
 */
final class TimerRearmTest extends TestCase
{
    /**
     * Jede Timer-Unit dieses Projekts.
     *
     * **Aufgezählt und nicht aufgeschrieben.** Eine Liste nennt die, an die man
     * beim Schreiben gedacht hat; der nächste Timer stünde nicht darin und
     * dürfte die Regel brechen.
     *
     * @return array<string, array{string}>
     */
    public static function timers(): array
    {
        $gefunden = glob(dirname(__DIR__, 2).'/packaging/systemd/*.timer') ?: [];

        $saetze = [];

        foreach ($gefunden as $pfad) {
            $saetze[basename($pfad)] = [$pfad];
        }

        return $saetze;
    }

    /**
     * Der Wächter über die Aufzählung selbst.
     *
     * Findet das Muster nichts, verglichen sich null Timer zu „alle in
     * Ordnung". Die Untergrenze ist die eine Zahl, die das verhindert.
     */
    public function test_there_are_timers_to_check(): void
    {
        $this->assertGreaterThanOrEqual(
            3,
            count(self::timers()),
            'Der Ausdruck über packaging/systemd findet fast nichts — er trifft nicht mehr.',
        );
    }

    /**
     * Ein wiederkehrender Timer trägt `OnCalendar`.
     */
    #[DataProvider('timers')]
    public function test_a_repeating_timer_is_bound_to_the_clock(string $pfad): void
    {
        $unit = (string) file_get_contents($pfad);

        $this->assertStringContainsString(
            'OnCalendar=',
            $unit,
            implode("\n", [
                sprintf('%s wiederholt sich ohne OnCalendar.', basename($pfad)),
                'Ein rein monotoner Timer kann seinen naechsten Termin verlieren und',
                'bleibt dabei „active" — gemessen am 19. August 2026 auf cloudsrv24,',
                '22 Stunden ohne einen einzigen Lauf und ohne eine einzige Meldung.',
            ]),
        );
    }

    /**
     * `OnUnitActiveSec` ist nicht der einzige Sockel.
     *
     * Es darf danebenstehen — als zweiter Weg schadet es nicht. Allein trägt es
     * die Wiederholung nicht.
     */
    #[DataProvider('timers')]
    public function test_no_timer_rests_on_the_last_activation_alone(string $pfad): void
    {
        $unit = (string) file_get_contents($pfad);

        if (! str_contains($unit, 'OnUnitActiveSec=')) {
            $this->assertTrue(true, 'Ohne diesen Sockel stellt sich die Frage nicht.');

            return;
        }

        $this->assertStringContainsString(
            'OnCalendar=',
            $unit,
            sprintf('%s haengt allein an der letzten Aktivierung seines Dienstes.', basename($pfad)),
        );
    }

    /**
     * Und `Persistent=` steht nur dort, wo es etwas bewirkt.
     */
    #[DataProvider('timers')]
    public function test_persistent_is_only_claimed_where_it_works(string $pfad): void
    {
        $unit = (string) file_get_contents($pfad);

        if (! str_contains($unit, 'Persistent=true')) {
            $this->assertTrue(true, 'Ohne die Zusage stellt sich die Frage nicht.');

            return;
        }

        $this->assertStringContainsString(
            'OnCalendar=',
            $unit,
            implode("\n", [
                sprintf('%s verspricht Persistent=true ohne OnCalendar.', basename($pfad)),
                'Die Einstellung wirkt nur auf Kalender-Timer; hier steht sie da und',
                'tut nichts — eine Notiz, die sich wie eine Zusage liest.',
            ]),
        );
    }
}
