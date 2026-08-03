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

    public function test_resuming_unlocks_and_clears_the_expiry(): void
    {
        $args = $this->value(new SubscriptionResume, 'accountArgs', 'p1001');

        $this->assertContains('--unlock', $args);
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

        $this->assertSame($expected, $suspendMethods);
        $this->assertSame($expected, $resumeMethods);
    }
}
