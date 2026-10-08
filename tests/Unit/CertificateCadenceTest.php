<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\Certificates;
use App\Support\Tls\CertificateRenewal;
use PHPUnit\Framework\TestCase;

/**
 * Die Schwelle für Let's Encrypt hängt an zwei Zeitgebern (B9, `docs/142 §6`,
 * Frage 2).
 *
 * ## Was die Zahl verspricht
 *
 * Ein Zertifikat von Let's Encrypt wird ab {@see CertificateRenewal::LEAD_DAYS}
 * Tagen Restlaufzeit erneuert, und zwar in der Nacht, in der `srvpanel-tls`
 * danach zum ersten Mal läuft. Die Diagnose warnt erst
 * {@see Certificates::RENEWAL_NIGHTS} Nächte später. **Eine gelungene
 * Erneuerung sieht sie damit nie** — die Erneuerung ist installiert, bevor die
 * Warnung beginnt.
 *
 * Bei derselben Schwelle meldete etwa jede zwölfte gelungene Erneuerung ein
 * ablaufendes Zertifikat (`docs/142 §3` M1, gerechnet in
 * `tests/kundenmeldungen-rechnen.php`). Und das hält nur, solange der Abstand
 * grösser ist als der längste Weg bis zur Erneuerung:
 *
 * ```
 * Abstand  >  Takt  +  Streuung von srvpanel-tls  +  Spiel für die Bestellung
 * ```
 *
 * Der Takt ist ein Tag, wenn die Fälligkeit gerade nach dem Lauf der Nacht
 * eintritt; die Streuung schiebt den nächsten Lauf um bis zu ihre Länge
 * hinaus, und jede Nacht wird neu gewürfelt (`FixedRandomDelay=no`, gemessen
 * in `docs/909`). Gelesen wird beides aus der Unit und nicht aus einer Zahl in
 * diesem Test — wer den Zeitgeber auf zwei Stunden Streuung stellt, soll hier
 * erfahren, dass die Schwelle mitgehen muss.
 *
 * > **Eine Entprellung ohne ihren Takt ist eine halbe Zahl.** Der Satz steht
 * > seit B1 an `Notices::HOLD_HOURS`; hier gilt er für eine Schwelle.
 *
 * ## Was er nicht kann
 *
 * **Mehr als zehn fällige Zertifikate in einer Nacht.** {@see
 * CertificateRenewal::PER_RUN} bestellt höchstens zehn je Lauf, der Rest kommt
 * in den Nächten danach — und für ihn kann die Warnung dann einmal dastehen.
 * Gemeldet wird sie erst nach zwei Läufen hintereinander, und bis dahin ist er
 * erneuert, solange der Rückstand nicht über eine weitere Nacht reicht.
 *
 * **Und eine Bestellung, die länger dauert als das Spiel.** Eine Stunde ist
 * grosszügig gegen die fünf bis dreihundert Sekunden, mit denen die Rechnung
 * M1 fährt; gemessen ist die Dauer auf einem Server nicht.
 */
final class CertificateCadenceTest extends TestCase
{
    /** Eine Stunde Spiel für Bestellung und Ablage — siehe Kopf. */
    private const SPIEL = 3600;

    private static function unit(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/packaging/systemd/'.$name);
    }

    /**
     * Der Takt eines Zeitgebers in Sekunden — hier nur `OnCalendar=daily`.
     *
     * Eine andere Form ist ein Fehler dieses Tests und kein Takt: Sonst sähe
     * ein unlesbarer Kalender aus wie einer, der passt.
     */
    private static function takt(string $timer): int
    {
        preg_match_all('/^OnCalendar=(.+)$/m', self::unit($timer), $treffer);

        self::assertSame(['daily'], $treffer[1], $timer.' trägt nicht genau einen Kalender „daily" — dann rechnet dieser Test einen anderen Takt.');

        return 86400;
    }

    /** Die Streuung eines Zeitgebers in Sekunden, aus `RandomizedDelaySec`. */
    private static function streuung(string $timer): int
    {
        if (preg_match('/^RandomizedDelaySec=(.+)$/m', self::unit($timer), $treffer) !== 1) {
            return 0;
        }

        if (preg_match('/^(\d+)(s|sec|min|m|h)?$/D', trim($treffer[1]), $teile) !== 1) {
            self::fail(sprintf('RandomizedDelaySec=%s in %s kann dieser Test nicht lesen.', $treffer[1], $timer));
        }

        return (int) $teile[1] * match ($teile[2] ?? '') {
            'h' => 3600,
            'min', 'm' => 60,
            default => 1,
        };
    }

    public function test_a_healthy_renewal_is_installed_before_the_warning_begins(): void
    {
        $abstand = (CertificateRenewal::LEAD_DAYS - Certificates::expiringDays(true)) * 86400;
        $weg = self::takt('srvpanel-tls.timer') + self::streuung('srvpanel-tls.timer') + self::SPIEL;

        self::assertGreaterThan($weg, $abstand, sprintf(
            "Die Warnung beginnt %d s nach der Fälligkeit, die Erneuerung kann bis zu %d s danach kommen.\n".
            'Dazwischen meldet die Diagnose ein Zertifikat, das gerade erneuert wird.',
            $abstand,
            $weg,
        ));
    }

    /**
     * **Und nicht mehr Nächte als nötig.** Jede Nacht zu viel nimmt dem
     * Kunden eine, in der er von einer scheiternden Erneuerung erfährt.
     */
    public function test_the_warning_waits_no_night_longer_than_it_has_to(): void
    {
        $weg = self::takt('srvpanel-tls.timer') + self::streuung('srvpanel-tls.timer') + self::SPIEL;

        self::assertSame(
            (int) ceil(($weg + 1) / 86400),
            Certificates::RENEWAL_NIGHTS,
            'Die Schwelle liegt weiter hinter der Erneuerung, als die Zeitgeber es verlangen.',
        );
    }

    /**
     * Die Diagnose läuft jede Nacht — sonst hiessen „zwei Nächte" etwas
     * anderes als zwei Läufe.
     */
    public function test_the_diagnosis_runs_every_night(): void
    {
        self::assertSame(86400, self::takt('srvpanel-diagnose.timer'));
        self::assertLessThan(86400 / 2, self::streuung('srvpanel-diagnose.timer'),
            'Streut die Diagnose um mehr als einen halben Tag, liegen zwei Läufe manchmal in derselben Nacht.');
    }
}
