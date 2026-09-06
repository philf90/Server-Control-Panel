<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemTime;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\TimeState;
use Tests\Support\WithoutPhpComments;

/**
 * Der Leser für `timedatectl show` (A11, `docs/106 §5`).
 *
 * ## Die Prüfkörper sind gemessen und nicht erfunden
 *
 * Sie stehen hier wörtlich so, wie `timedatectl` sie am 6. September 2026
 * gedruckt hat — gegen echtes systemd 255 in einer eigenen Namespace
 * (`docs/81 §2.3r` M5, M6, M8, M10). Wiederholt werden kann die Messung hier
 * nicht: Sie braucht einen laufenden Init, und der CI-Läufer hat keinen.
 *
 * > **Ein Prüfkörper aus dem Prüfling prüft den Prüfling gegen sich selbst.**
 *
 * ## Was diese Klasse festhält
 *
 * 1. **Gelesen wird nach Schlüssel und nicht nach Position.** Die Reihenfolge
 *    ist nirgends zugesagt; gemessen wird das an einer umgestellten Ausgabe und
 *    nicht an einem Blick in den Quelltext.
 * 2. **`rc != 0` ist ein Zustand und kein Wert.** Ohne systemd als PID 1 ist
 *    stdout leer und die Auskunft steht auf stderr — ein Leser, der nur stdout
 *    nimmt, schlösse daraus „keine Zone, NTP aus".
 * 3. **Ein unbekannter Wert wird nicht zu `false`.** Sonst meldete ein
 *    laufender Zeitdienst als nicht laufend.
 * 4. **Weder `TimeUSec` noch `Timezone` kommen in der Antwort vor.** Die Uhr
 *    des Servers ist die, unter der das Panel selbst läuft; die Zone
 *    beantwortet `App\Support\Cron\ServerZone` seit P6, und der zweite Leser
 *    derselben Quelle wäre der, der veraltet.
 * 5. **Die drei gemessenen Dienstzustände sind drei verschiedene Antworten.**
 *    „Kein Zeitdienst installiert" ist etwas anderes als „ausgeschaltet".
 */
final class TimeStateTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Kein Zeitdienst installiert. Gemessen (M6), in der gedruckten Reihenfolge.
     */
    private const OHNE_DIENST = "Timezone=Etc/UTC\n"
        ."LocalRTC=no\n"
        ."CanNTP=no\n"
        ."NTP=no\n"
        ."NTPSynchronized=no\n"
        ."TimeUSec=Sun 2026-09-06 19:50:28 UTC\n";

    /** Installiert, nicht eingeschaltet. Gemessen (M8). */
    private const AUS = "Timezone=Etc/UTC\n"
        ."LocalRTC=no\n"
        ."CanNTP=yes\n"
        ."NTP=no\n"
        ."NTPSynchronized=no\n"
        ."TimeUSec=Sun 2026-09-06 19:53:11 UTC\n";

    /** Eingeschaltet. Gemessen (M10). */
    private const AN = "Timezone=Etc/UTC\n"
        ."LocalRTC=no\n"
        ."CanNTP=yes\n"
        ."NTP=yes\n"
        ."NTPSynchronized=no\n"
        ."TimeUSec=Sun 2026-09-06 19:55:02 UTC\n";

    /** Die Auskunft ohne systemd als PID 1. Gemessen (M2, M3). */
    private const OHNE_SYSTEMD = 'System has not been booted with systemd as init system (PID 1). '
        ."Can't operate.\nFailed to connect to bus: Host is down\n";

    /**
     * Die drei gemessenen Zustände ergeben drei verschiedene Antworten.
     *
     * **Gemessen an der Wirkung und nicht an der Anwesenheit von `CanNTP` im
     * Quelltext.** Ein Leser, der die beiden Fahnen zusammenzieht, ist an
     * diesem Fall rot und an keinem anderen: Ohne Dienst und mit
     * ausgeschaltetem Dienst steht `NTP` beide Male auf `no`.
     *
     * > **Zwei Wahrheitswerte, die vier Zustände tragen, verlieren beim
     * > Zusammenziehen genau den Fall, der eine Meldung verdient.**
     */
    public function test_the_three_measured_service_states_are_three_answers(): void
    {
        $ohne = TimeState::read(new Result(0, self::OHNE_DIENST, ''));
        $aus = TimeState::read(new Result(0, self::AUS, ''));
        $an = TimeState::read(new Result(0, self::AN, ''));

        self::assertSame([false, false], [$ohne['can_ntp'], $ohne['ntp']]);
        self::assertSame([true, false], [$aus['can_ntp'], $aus['ntp']]);
        self::assertSame([true, true], [$an['can_ntp'], $an['ntp']]);

        self::assertNotSame($ohne, $aus, 'Ohne Dienst und ausgeschaltet sind derselbe Zustand — dann fehlt CanNTP.');
        self::assertNotSame($aus, $an);
    }

    /** `yes` und `no` werden zu Wahrheitswerten und nicht zu Zeichenketten. */
    public function test_yes_and_no_become_booleans(): void
    {
        $zustand = TimeState::read(new Result(0, self::AN, ''));

        self::assertTrue($zustand['readable']);
        self::assertSame(false, $zustand['local_rtc']);
        self::assertSame(false, $zustand['synchronized']);
    }

    /**
     * Gelesen wird nach Schlüssel und nicht nach Position.
     *
     * **Der Prüfkörper ist die gemessene Ausgabe rückwärts.** Ein Leser, der
     * die dritte Zeile nimmt, ist damit rot; einer, der nach Schlüssel liest,
     * gibt beide Male dasselbe.
     */
    public function test_the_reader_goes_by_key_and_not_by_position(): void
    {
        $rueckwaerts = implode("\n", array_reverse(explode("\n", trim(self::AUS))))."\n";

        self::assertSame(
            TimeState::read(new Result(0, self::AUS, '')),
            TimeState::read(new Result(0, $rueckwaerts, '')),
            'Die umgestellte Ausgabe ergibt einen anderen Zustand — dann zählt der Leser Zeilen.',
        );
    }

    /**
     * `rc != 0` gibt `readable: false` und keinen geratenen Wert.
     *
     * **Und stdout ist dabei leer** — die Auskunft steht auf stderr. Genau das
     * ist die Form, an der A1 Schritt 2 schon einmal bezahlt hat.
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
     * > tun".**
     */
    public function test_a_failed_call_is_a_state_and_not_a_value(): void
    {
        $zustand = TimeState::read(new Result(1, '', self::OHNE_SYSTEMD));

        self::assertFalse($zustand['readable']);
        self::assertSame('unreadable', $zustand['reason']);

        foreach (['can_ntp', 'ntp', 'synchronized', 'local_rtc'] as $feld) {
            self::assertArrayNotHasKey($feld, $zustand, sprintf(
                'Der Fehlerfall trägt %s — ein geratener Wert sieht aus wie ein gemessener.',
                $feld,
            ));
        }
    }

    /**
     * Ein unbekannter Wert wird nicht zu `false`.
     *
     * Gemessen sind ausschliesslich `yes` und `no`. Stünde dort eines Tages
     * `true`, machte ein Leser mit `=== 'yes'` daraus wortlos „ausgeschaltet"
     * — und meldete einen laufenden Zeitdienst als nicht laufend.
     *
     * @param  string  $ausgabe  eine Ausgabe, der ein gebrauchter Wert fehlt
     */
    #[DataProvider('unvollstaendig')]
    public function test_an_unknown_value_is_not_false(string $ausgabe): void
    {
        $zustand = TimeState::read(new Result(0, $ausgabe, ''));

        self::assertFalse($zustand['readable']);
        self::assertSame('incomplete', $zustand['reason']);
    }

    /** @return array<string,array{string}> */
    public static function unvollstaendig(): array
    {
        return [
            'NTP trägt einen unbekannten Wert' => [str_replace('NTP=no', 'NTP=true', self::AUS)],
            'CanNTP fehlt ganz' => [str_replace("CanNTP=yes\n", '', self::AUS)],
            'NTPSynchronized fehlt' => [str_replace("NTPSynchronized=no\n", '', self::AUS)],
            'LocalRTC ist leer' => [str_replace('LocalRTC=no', 'LocalRTC=', self::AUS)],
            'gar nichts' => [''],
        ];
    }

    /**
     * Uhr und Zone stehen in der Ausgabe und nicht in der Antwort.
     *
     * **Beide sind schon beantwortet**, und ein zweiter Weg zu einer Antwort,
     * die es gibt, ist die zweite Fassung derselben Regel — die zweite ist die,
     * die veraltet. Die Uhr gibt `now()`; die Zone gibt
     * `App\Support\Cron\ServerZone`, das denselben Symlink liest, dem auch
     * `timedatectl` folgt (`docs/81 §2.3r` M11).
     *
     * **Die Zone stand im ersten Wurf in der Antwort**, und gefangen hat es
     * `ServerZoneSourceTest` — den es seit P6 genau dafür gibt.
     *
     * > **Eine Messung, die nach dem Werkzeug sucht, findet die Frage nicht —
     * > sie war schon beantwortet, nur mit einem anderen Werkzeug.**
     */
    public function test_neither_clock_nor_zone_is_read(): void
    {
        self::assertStringContainsString('TimeUSec=', self::OHNE_DIENST);
        self::assertStringContainsString('Timezone=', self::OHNE_DIENST);

        $zustand = TimeState::read(new Result(0, self::OHNE_DIENST, ''));

        self::assertSame(
            ['readable', 'can_ntp', 'ntp', 'synchronized', 'local_rtc'],
            array_keys($zustand),
            'Die Antwort trägt ein Feld zuviel oder zuwenig.',
        );
    }

    /**
     * Jeder Grund, den der Leser aussprechen kann, steht in der geschlossenen Menge.
     *
     * **Gemessen an der Wirkung und nicht an einer Liste im Test.** Die Gründe
     * werden aus den Prüfkörpern gewonnen, die sie erzeugen; eine
     * ausgeschriebene Liste hier wäre die zweite Fassung von `REASONS`.
     */
    public function test_every_reason_it_speaks_is_in_the_closed_set(): void
    {
        $ausgesprochen = [TimeState::read(new Result(1, '', self::OHNE_SYSTEMD))['reason']];

        foreach (self::unvollstaendig() as $fall) {
            $ausgesprochen[] = TimeState::read(new Result(0, $fall[0], ''))['reason'];
        }

        foreach (array_unique($ausgesprochen) as $grund) {
            self::assertContains($grund, TimeState::REASONS, sprintf(
                'Der Leser spricht %s aus, und die geschlossene Menge kennt ihn nicht.',
                $grund,
            ));
        }

        self::assertCount(2, array_unique($ausgesprochen), 'Beide Gründe müssen vorkommen — sonst misst dieser Fall einen.');
    }

    /**
     * Die Operation ruft `timedatectl` und liest nicht die Dateien daneben.
     *
     * Der Kopf von {@see SystemTime} begründet das; hier
     * steht die Regel, die es hält. Sie hat ihre eigene Klasse in
     * `TimezoneFileTest` — dieser Fall hält nur die Naht zwischen Operation
     * und Leser, weil ein Aufruf mit einem anderen Unterbefehl eine andere
     * Ausgabe hätte und dieser Leser sie stumm als `incomplete` meldete.
     */
    public function test_the_operation_calls_show_and_nothing_else(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(__DIR__.'/../../agent/src/Ops/SystemTime.php'),
        );

        self::assertStringContainsString("run('timedatectl', ['show']", $quelle);
        self::assertStringNotContainsString('set-timezone', $quelle, 'A11 ist eine Anzeige (docs/106 §9).');
        self::assertStringNotContainsString('set-ntp', $quelle);
        self::assertStringNotContainsString('show-timesync', $quelle, 'show-timesync setzt den Dienst voraus, dessen Fehlen man wissen will (M7).');
    }
}
