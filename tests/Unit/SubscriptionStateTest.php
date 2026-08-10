<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SubscriptionResume;
use SrvPanel\Agent\Ops\SubscriptionSuspend;

/**
 * Sperren und Entsperren müssen sich vollständig aufheben.
 *
 * **Warum das die eigentliche Frage ist.** Eine Sperre, die etwas anders
 * zurücknimmt, als sie gesetzt hat, hinterlässt ein Abonnement, das nach dem
 * Entsperren „läuft" und trotzdem nicht ausliefert — oder schlimmer: eines,
 * das gesperrt aussieht und noch erreichbar ist. Beides fällt niemandem auf,
 * weil beides im Panel gleich aussieht.
 *
 * Geprüft wird deshalb Wert gegen Wert, nicht Ablauf gegen Ablauf.
 */
final class SubscriptionStateTest extends TestCase
{
    private function value(object $op, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($op::class, $method))->invoke($op, ...$args);
    }

    public function test_suspending_takes_the_traversal_bit_away_and_resuming_gives_it_back(): void
    {
        // 0755 ist der Wert aus §4.5. `www-data` kommt über das x-Bit für
        // „andere" in das Verzeichnis; fällt es weg, kommt kein
        // Webserver-Prozess mehr hinein — unabhängig davon, wie viele Domains
        // darunter hängen und wann sie dazukamen.
        $this->assertSame(0750, $this->value(new SubscriptionSuspend, 'rootMode'));
        $this->assertSame(0755, $this->value(new SubscriptionResume, 'rootMode'));
    }

    public function test_the_owner_bits_never_change(): void
    {
        // Nur das x-Bit für „andere" unterscheidet die beiden Zustände. Wer
        // hier auch an den Rechten des Eigentümers dreht, sperrt nicht, sondern
        // macht das Abonnement kaputt.
        $suspended = (int) $this->value(new SubscriptionSuspend, 'rootMode');
        $resumed = (int) $this->value(new SubscriptionResume, 'rootMode');

        $this->assertSame($resumed & 0770, $suspended & 0770, 'Eigentümer- und Gruppenrechte bleiben gleich.');
        $this->assertSame(0, $suspended & 0001, 'Gesperrt: kein Betreten für andere.');
        $this->assertSame(1, $resumed & 0001, 'Frei: Betreten für andere.');
    }

    public function test_suspending_locks_and_expires_the_account(): void
    {
        $args = $this->value(new SubscriptionSuspend, 'accountArgs', 'p1001');

        // Beides, nicht eines. Ein gesperrtes Passwort hindert niemanden, der
        // sich mit einem Schlüssel anmeldet; das Ablaufdatum ist die Schranke,
        // die SSH und SFTP prüfen.
        $this->assertContains('--lock', $args);
        $this->assertContains('--expiredate', $args);
        $this->assertContains('1', $args);
        $this->assertSame('p1001', end($args));
    }

    /**
     * Die Freigabe nimmt das Ablaufdatum zurück — **ohne Bedingung**.
     *
     * Hier stand zusätzlich `assertContains('--unlock', $args)`, und dieser
     * Test ist mit `v0.5.1-rc.4` rot geworden: `--unlock` hängt seitdem daran,
     * ob es überhaupt ein Passwort zu entsperren gibt. Der Systembenutzer eines
     * Abonnements hat keines, und `usermod` schrieb bei **jeder** Freigabe eine
     * Warnung (`AccountUnlockTest`, gemeldet aus Vorgang 492).
     *
     * **Die Zusicherung ist damit nicht weggefallen, sondern umgezogen.** Was
     * hier bleibt, ist die Schranke, die SSH und SFTP wirklich prüfen — sie
     * fällt bei jeder Freigabe, auf jedem Server. Ob dazu ein `--unlock`
     * gehört, beantwortet `SubscriptionResume::unlocks()` aus `/etc/shadow`,
     * und **dort** ist es geprüft, in beide Richtungen. Auf dem CI-Läufer gibt
     * es kein `p1001`, also käme hier nie eines heraus.
     */
    public function test_resuming_clears_the_expiry(): void
    {
        $args = $this->value(new SubscriptionResume, 'accountArgs', 'p1001');

        $this->assertContains('--expiredate', $args);

        // Leer und nicht „0": `--expiredate 0` wäre der 1. Januar 1970 und
        // damit weiterhin abgelaufen. Ein entsperrtes Abonnement, bei dem sich
        // niemand anmelden kann, sähe im Panel aus wie ein Fehler des Kunden.
        $position = array_search('--expiredate', $args, true);
        $this->assertSame('', $args[$position + 1]);
        $this->assertNotContains('0', $args);
    }

    public function test_only_suspending_stops_processes(): void
    {
        // Beim Sperren nötig: Ein laufender FPM-Prozess hat sein Verzeichnis
        // offen, und der Kernel prüft das Zugriffsbit beim Öffnen, nicht bei
        // jedem Lesen. Ohne das Beenden liefe ein gesperrtes Abonnement bis
        // zum nächsten Pool-Recycling weiter.
        $this->assertTrue($this->value(new SubscriptionSuspend, 'stopsProcesses'));
        $this->assertFalse($this->value(new SubscriptionResume, 'stopsProcesses'));
    }

    public function test_both_declare_themselves_as_changing_the_system(): void
    {
        $this->assertSame('subscription.suspend', SubscriptionSuspend::name());
        $this->assertSame('subscription.resume', SubscriptionResume::name());
        $this->assertTrue(SubscriptionSuspend::mutating());
        $this->assertTrue(SubscriptionResume::mutating());
    }

    public function test_the_two_differ_in_exactly_three_places(): void
    {
        // Die Gegenprobe zum Zuschnitt: Sperren und Entsperren teilen sich die
        // Mechanik und unterscheiden sich in genau drei Werten. Käme ein
        // vierter dazu, ohne dass jemand hier nachzieht, wäre die Umkehrung
        // wieder eine Behauptung.
        //
        // **`unlocks()` steht ausdrücklich dabei und nicht als Ausnahme.** Es
        // ist kein vierter Unterschied, sondern die Hilfsmethode zu einem der
        // drei — sie liest `/etc/shadow` und beantwortet, ob `--unlock` in
        // `accountArgs` gehört (`AccountUnlockTest`). Sie hier zu nennen statt
        // den Vergleich aufzuweichen, ist der Unterschied zwischen „diese eine
        // kennen wir" und „vier sind auch in Ordnung": Eine fünfte beisst
        // weiter.
        $suspend = new \ReflectionClass(SubscriptionSuspend::class);
        $resume = new \ReflectionClass(SubscriptionResume::class);

        $own = static fn (\ReflectionClass $class): array => array_values(array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $class->getMethods(),
                static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class->getName()
                    && $m->getName() !== 'name',
            ),
        ));

        $expected = ['rootMode', 'accountArgs', 'stopsProcesses'];
        sort($expected);

        $suspendMethods = $own($suspend);
        $resumeMethods = $own($resume);
        sort($suspendMethods);
        sort($resumeMethods);

        $expectedForResume = [...$expected, 'unlocks'];
        sort($expectedForResume);

        $this->assertSame($expected, $suspendMethods);
        $this->assertSame($expectedForResume, $resumeMethods);
    }
}
