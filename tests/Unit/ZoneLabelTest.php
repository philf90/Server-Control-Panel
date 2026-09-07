<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Die Beschriftung einer **fremden** Zone (A11, `docs/106 §6`).
 *
 * ## Warum es diese Methode überhaupt gibt
 *
 * `label()` und `labelAt()` nageln beide auf {@see Clock::zone()}, also die
 * **Anzeige**zone. Die Zone des Servers ist eine andere Grösse — `docs/80`
 * verlangt sie *neben* der Anzeigezone, „weil die beiden sonst verwechselt
 * werden". Ohne einen dritten Weg stünde die Formel an der Aufrufstelle ein
 * zweites Mal.
 *
 * ## Der Fall, der den ersten Wurf umgeworfen hat
 *
 * Er prüfte mit `Clock::isValid()` — und `Etc/UTC`, der Wert, den
 * `timedatectl` auf einem frischen Server liefert, steht dort **nicht** drin.
 * Die Zone des Servers wäre damit ausgerechnet im häufigsten Fall unbeschriftet
 * geblieben.
 *
 * > **Ein Prüfer, der für ein Formular gebaut ist, ist für einen Wert vom
 * > Server der falsche — er kennt nur die Auswahl, die er anbietet.**
 *
 * ## Warum Januar **und** September
 *
 * Berlin heisst im Januar `CET` und im September `CEST`. Ein Fall, der nur
 * einen Zeitpunkt misst, wäre ein halbes Jahr grün und ein halbes Jahr rot —
 * dieselbe Vorsicht wie in `MaintenanceSeamTest` seit dem 4. September.
 *
 * Framework-frei bis auf `Clock`, das hier ohne Datenbank auskommt: Die
 * Anzeigezone wird nicht gefragt, und genau das ist die Regel.
 */
final class ZoneLabelTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    /** Ein Zeitpunkt in der Sommerzeit und einer davor — beide fest. */
    private const SOMMER = '2026-09-06T12:00:00Z';

    private const WINTER = '2026-01-15T12:00:00Z';

    /**
     * Die gemessene Tabelle aus `docs/81 §2.3r` M14, Zeile für Zeile.
     *
     * Alle drei Abkürzungsformen aus `docs/102 §3b` kommen darin vor:
     * Buchstaben (`CEST`), `+03`-Form (`-03`) und `+0845`.
     *
     * @return array<string,array{string,?string,?string}>
     */
    public static function zonen(): array
    {
        return [
            'Etc/UTC steht allein' => ['Etc/UTC', 'UTC', 'UTC'],
            'Berlin wechselt' => ['Europe/Berlin', 'CEST (UTC+02:00)', 'CET (UTC+01:00)'],
            'Kolkata mit halber Stunde' => ['Asia/Kolkata', 'IST (UTC+05:30)', 'IST (UTC+05:30)'],
            'Sao Paulo ohne Buchstaben' => ['America/Sao_Paulo', '-03 (UTC-03:00)', '-03 (UTC-03:00)'],
            'Eucla mit Dreiviertelstunde' => ['Australia/Eucla', '+0845 (UTC+08:45)', '+0845 (UTC+08:45)'],
            'ein Name, den es nicht gibt' => ['Erfunden/Nichts', null, null],
            'ein leerer Name' => ['', null, null],
        ];
    }

    #[DataProvider('zonen')]
    public function test_the_label_matches_the_measured_table(string $zone, ?string $sommer, ?string $winter): void
    {
        self::assertSame($sommer, Clock::describeZone($zone, CarbonImmutable::parse(self::SOMMER)), $zone.' im September');
        self::assertSame($winter, Clock::describeZone($zone, CarbonImmutable::parse(self::WINTER)), $zone.' im Januar');
    }

    /**
     * Der Zeitpunkt entscheidet, und zwar messbar.
     *
     * **Ohne diesen Fall wäre der obige auch mit einer Methode grün, die
     * `now()` einbaut** — solange man ihn im September fährt. Gemessen wird
     * deshalb der Unterschied selbst.
     */
    public function test_the_moment_is_an_argument(): void
    {
        self::assertNotSame(
            Clock::describeZone('Europe/Berlin', CarbonImmutable::parse(self::SOMMER)),
            Clock::describeZone('Europe/Berlin', CarbonImmutable::parse(self::WINTER)),
            'Januar und September ergeben dieselbe Beschriftung — dann hängt sie nicht am übergebenen Zeitpunkt.',
        );
    }

    /**
     * Die Anzeigezone kommt in dieser Methode nicht vor.
     *
     * Sie ist der Wert, den `label()` und `labelAt()` nehmen; wer sie hier
     * mitnähme, hätte die dritte Fassung derselben Falle gebaut.
     *
     * **Gemessen am Rumpf und nicht an der Wirkung, und das ist eine Grenze
     * und keine Bequemlichkeit.** `Clock::store()` schreibt in die Datenbank —
     * diese Klasse ist framework-frei und hat keine.
     *
     * Die **Wirkung** misst der Fall darüber trotzdem, und härter als
     * erwartet: Gemessen wirft `Clock::zone()` ohne Datenbank (`Call to a
     * member function connection() on null`) und fällt **nicht** auf `UTC`
     * zurück. Ein Leck machte damit jeden Fall des Datenlieferanten rot.
     *
     * > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist
     * > auch dann falsch, wenn der Handgriff daneben richtig ist.** Hier stand
     * > zuerst „fällt auf `UTC` zurück"; nachgemessen stimmt das nicht.
     *
     * Was der Fall darüber nicht sieht, ist ein Leck **nur im Rückfallzweig**
     * — und dafür steht dieser hier.
     *
     * Gelesen wird der Rumpf über Klammern und nicht die ganze Datei: `zone()`
     * steht dort achtzehnmal, und eine Zeichenkette irgendwo in der Datei sagt
     * über diese Methode nichts.
     */
    public function test_the_display_zone_does_not_leak_in(): void
    {
        $rumpf = $this->methodBody(
            $this->withoutComments((string) file_get_contents(__DIR__.'/../../app/Support/Time/Clock.php')),
            'public static function describeZone(',
        );

        self::assertStringNotContainsString('self::zone()', $rumpf, 'describeZone() fragt die Anzeigezone — dann beschriftet es die falsche.');
        self::assertStringContainsString('$zone', $rumpf, 'describeZone() benutzt sein Argument gar nicht.');
    }

    /**
     * Die Beschriftung steht einmal da, und alle drei Wege gehen hindurch.
     *
     * **Gemessen am Quelltext und nicht an der Wirkung**, weil zwei Fassungen,
     * die heute dasselbe ergeben, genau der Fall sind, den eine Wirkungsmessung
     * nicht sieht. Der Kopf von `Clock::describe()` sagt es selbst: Stünde sie
     * zweimal da, liefe die zweite irgendwann auseinander.
     */
    public function test_all_three_ways_go_through_the_one_formula(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(__DIR__.'/../../app/Support/Time/Clock.php'),
        );

        self::assertSame(
            1,
            substr_count($quelle, "sprintf('%s (UTC%s)'"),
            'Die Formel steht mehr als einmal da — dann ist eine davon die, die veraltet.',
        );

        self::assertSame(
            3,
            substr_count($quelle, 'self::describe('),
            'Es gehen nicht genau drei Wege durch describe() — label(), labelAt() und describeZone().',
        );
    }
}
