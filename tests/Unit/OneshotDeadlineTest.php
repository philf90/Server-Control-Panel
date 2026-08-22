<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ein Dienst, den ein Timer startet, hat eine Frist — und sie ist kürzer als
 * der Takt.
 *
 * ## Der Anlass, und er ist gemessen
 *
 * Am 22. August 2026 auf `cloudsrv24` abgelesen (`docs/74`, Befund 4):
 *
 *     srvpanel-dns.service    TimeoutStartUSec=10min
 *     srvpanel-cron.service   TimeoutStartUSec=infinity
 *
 * **Ein `Type=oneshot` ohne eigene Angabe läuft ohne Frist.** Hängt so ein Lauf
 * — an einem Socket, an einem fremden Server, an einem Systemaufruf —, bleibt
 * die Unit in `activating`, und systemd startet sie beim nächsten Termin
 * **nicht noch einmal**. Ein einziger hängender Lauf nimmt damit alle folgenden
 * mit, und der Timer meldet dabei weiter `enabled`.
 *
 * > **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
 * > abgeschaltet und sieht aus wie eingeschaltet.**
 *
 * Derselbe Satz wie am 19. August 2026, als `srvpanel-cron.timer` zweiundzwanzig
 * Stunden lang keinen Termin mehr hatte. Andere Ursache, dieselbe Form: Damals
 * fehlte dem Timer der Kalender, hier fehlt dem Dienst die Frist.
 *
 * ## Warum die Frist grosszügig ist und nicht knapp
 *
 * Bei `srvpanel-tls` wäre eine knappe Frist schädlicher als gar keine: Sie
 * räumte eine laufende ACME-Bestellung mitten im Vorgang ab, und ein
 * abgebrochener Versuch kostet trotzdem einen der **fünf Fehlversuche je Stunde,
 * die für alle Kunden dieses Servers gelten** (`docs/34 §11`).
 *
 * > **Ein Deckel gegen das Hängenbleiben muss nicht knapp sein — er muss
 * > endlich sein.**
 *
 * Deshalb prüft dieser Wächter **nicht**, ob die Frist zur Dauer des Laufs
 * passt. Er prüft, dass es sie gibt und dass sie unter dem Takt liegt — denn
 * eine Frist über dem Takt kostet im Hängefall mehrere Termine statt einen.
 *
 * ## Was er nicht prüft
 *
 * Ob die Frist **reicht**. Das hängt daran, wie lange ein echter Lauf braucht,
 * und für `srvpanel-tls` ist das ungemessen: In dreissig Tagen Journal steht
 * keine einzige echte Erneuerung, nur Prüfungen mit „gilt noch" in unter einer
 * Sekunde. Eine Frist, die zu kurz ist, findet dieser Wächter nicht — sie fiele
 * im Betrieb als abgebrochener Lauf auf.
 */
final class OneshotDeadlineTest extends TestCase
{
    /**
     * Jeder Timer dieses Projekts mit dem Dienst, den er startet.
     *
     * **Aufgezählt und nicht aufgeschrieben** — dieselbe Bauart wie in
     * {@see TimerRearmTest}: Eine Liste nennt die, an die man beim Schreiben
     * gedacht hat, und der nächste Timer stünde nicht darin.
     *
     * @return array<string, array{string, string}>
     */
    public static function timers(): array
    {
        $saetze = [];

        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.timer') ?: [] as $pfad) {
            $dienst = (string) preg_replace('/\.timer$/', '.service', $pfad);

            $saetze[basename($pfad)] = [$pfad, $dienst];
        }

        return $saetze;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet das Muster nichts, prüfen die Fälle darunter null Timer und sind
     * grün, ohne etwas gesehen zu haben.
     */
    public function test_there_are_timers_to_check(): void
    {
        $this->assertGreaterThanOrEqual(
            4,
            count(self::timers()),
            'Der Ausdruck über packaging/systemd findet fast nichts — er trifft nicht mehr.',
        );
    }

    /**
     * Der Dienst hinter einem Timer nennt seine Frist.
     */
    #[DataProvider('timers')]
    public function test_every_timed_service_declares_a_deadline(string $timer, string $dienst): void
    {
        $unit = (string) file_get_contents($dienst);

        $this->assertSame(
            1,
            preg_match('/^TimeoutStartSec=\d+$/m', $unit),
            implode("\n", [
                sprintf('%s nennt kein TimeoutStartSec in Sekunden.', basename($dienst)),
                'Ein Type=oneshot ohne eigene Angabe laeuft ohne Frist — gemessen am',
                '22. August 2026: TimeoutStartUSec=infinity. Haengt so ein Lauf, bleibt',
                'die Unit in activating, und systemd startet sie beim naechsten Termin',
                'nicht noch einmal. Ein einziger Haenger nimmt alle folgenden mit.',
            ]),
        );
    }

    /**
     * Und die Frist liegt unter dem Takt.
     *
     * **Sonst kostet ein Hänger mehrere Termine statt einen.** Eine Frist von
     * zwanzig Minuten bei einem Takt von fünfzehn heisst: Der Lauf um :00 hängt
     * bis :20, der um :15 fällt aus, und erst der um :30 kommt wieder dran.
     */
    #[DataProvider('timers')]
    public function test_the_deadline_is_shorter_than_the_period(string $timer, string $dienst): void
    {
        $frist = preg_match('/^TimeoutStartSec=(\d+)$/m', (string) file_get_contents($dienst), $treffer) === 1
            ? (int) $treffer[1]
            : 0;

        $this->assertGreaterThan(
            0,
            $frist,
            sprintf('%s hat keine lesbare Frist — dann prueft dieser Fall nichts.', basename($dienst)),
        );

        $takt = self::period((string) file_get_contents($timer));

        $this->assertGreaterThan(
            0,
            $takt,
            implode("\n", [
                sprintf('Der Takt von %s ist nicht zu lesen.', basename($timer)),
                'Bekannt sind `OnCalendar=*:0/N` und `OnCalendar=daily`. Eine neue',
                'Schreibweise gehoert in self::period() — stillschweigend durchgehen',
                'darf sie nicht, sonst misst dieser Fall nichts.',
            ]),
        );

        $this->assertLessThan(
            $takt,
            $frist,
            implode("\n", [
                sprintf('%s: Frist %d s, Takt %d s.', basename($dienst), $frist, $takt),
                'Eine Frist ueber dem Takt kostet im Haengefall mehrere Termine statt',
                'einen: Der Lauf haengt ueber den naechsten Termin hinweg, und systemd',
                'startet die Unit nicht, solange sie noch activating ist.',
            ]),
        );
    }

    /**
     * Der Takt eines Timers in Sekunden — oder `0`, wenn unlesbar.
     *
     * **`0` und keine Ausnahme.** Eine unbekannte Schreibweise soll rot werden
     * und nicht stillschweigend durchgehen; wer sie einführt, trägt sie hier
     * nach.
     */
    private static function period(string $unit): int
    {
        if (preg_match('/^OnCalendar=\*:0\/(\d+)$/m', $unit, $treffer) === 1) {
            return (int) $treffer[1] * 60;
        }

        if (preg_match('/^OnCalendar=daily$/m', $unit) === 1) {
            return 24 * 60 * 60;
        }

        return 0;
    }
}
