<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dns\Budget;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Resolver;
use SrvPanel\Agent\Ops\DnsCheck;

/**
 * Die Grenze des regelmässigen Abgleichs — beide Zahlen und die Reserve.
 *
 * **Was dieser Wächter festhält, ist eine Rechnung mit drei Beteiligten**, und
 * jeder von ihnen steht in einer anderen Datei: die Frist im Quelltext
 * ({@see Budget::SECONDS}), die Frist der Unit (`TimeoutStartSec` in
 * `packaging/systemd/srvpanel-dns.service`) und der Takt des Timers
 * (`OnCalendar` in `packaging/systemd/srvpanel-dns.timer`). Sie wissen
 * nichts voneinander, und wer eine ändert, sieht die anderen beiden nicht.
 *
 * > **Zwei Fristen über denselben Lauf, die nichts voneinander wissen,
 * > entscheidet die kleinere — und die steht woanders.**
 *
 * Das ist derselbe Fehler, den dieses Projekt immer wieder macht: eine
 * Zeichenkette oder eine Zahl, die auf etwas verweist, ohne dass ein Typ, ein
 * Test oder ein Werkzeug den Bezug prüft.
 */
final class DnsBudgetTest extends TestCase
{
    /**
     * Die Anzahl deckelt den Lauf.
     */
    public function test_the_count_bound_stops_the_run(): void
    {
        $budget = new Budget(domains: 3, seconds: 1000);

        $this->assertTrue($budget->room(2, 0.0, 1), 'Die dritte Domain muss noch drankommen.');
        $this->assertFalse($budget->room(3, 0.0, 1), 'Die vierte Domain laeuft ueber die Anzahl hinaus.');
    }

    /**
     * Die erste Domain kommt immer dran.
     *
     * **Der Fall, den das verhindert, ist eine Sperre und sieht aus wie eine
     * Grenze.** Eine Domain mit zwölf Aliassen hat eine Reserve von 240
     * Sekunden — genau die Frist. Ohne diese Ausnahme käme sie in keinem
     * einzigen Lauf an die Reihe, für immer, und im Bericht stünde nur „wartet
     * noch".
     */
    public function test_the_first_domain_always_gets_its_turn(): void
    {
        $budget = new Budget(domains: 25, seconds: 240);

        $this->assertTrue(
            $budget->room(0, 9999.0, 100),
            implode("\n", [
                'Die erste Domain kommt nicht dran, obwohl noch keine gemessen wurde.',
                'Damit haengt das ganze Merkmal an einer Domain, deren Reserve zu gross',
                'ist — und niemand sieht, warum nichts passiert.',
            ]),
        );
    }

    /**
     * Ab der zweiten zählt die Reserve.
     */
    public function test_a_domain_that_would_not_fit_is_not_started(): void
    {
        $budget = new Budget(domains: 25, seconds: 240);

        $this->assertFalse(
            $budget->room(1, 230.0, 1),
            implode("\n", [
                'Eine Domain wird mit 10 Sekunden Restfrist angefangen, obwohl sie',
                'schlimmstenfalls 20 braucht. Eine Frist, die erst nach dem',
                'Ueberschreiten prueft, ist keine Frist, sondern eine Nachricht.',
            ]),
        );
    }

    /**
     * Und was genau hineinpasst, wird angefangen.
     *
     * **Die Grenze selbst ist ein Fall.** Ein `<` statt `<=` verschenkt die
     * letzte Domain jedes Laufs, und das fällt nie auf: Der Bericht meldet
     * „eine wartet noch", was er ohnehin oft tut.
     */
    public function test_what_fits_exactly_is_started(): void
    {
        $budget = new Budget(domains: 25, seconds: 240);

        $this->assertTrue(
            $budget->room(1, 240.0 - (float) Budget::reserve(1), 1),
            'Eine Domain, deren Reserve genau noch hineinpasst, wird nicht angefangen.',
        );
    }

    /**
     * Die Reserve wächst mit der Zahl der Namen.
     *
     * **Das ist der Grund, aus dem die Anzahl allein nicht reicht.** Zwei
     * Domains, dieselbe Restfrist, verschiedene Antworten.
     */
    public function test_the_reserve_grows_with_the_names(): void
    {
        $budget = new Budget(domains: 25, seconds: 240);

        $this->assertTrue($budget->room(1, 200.0, 1), 'Eine Domain mit einem Namen passt in 40 Sekunden.');
        $this->assertFalse($budget->room(1, 200.0, 3), 'Eine Domain mit drei Namen passt dort nicht.');

        $this->assertSame(
            3 * Budget::reserve(1),
            Budget::reserve(3),
            'Die Reserve waechst nicht mit den Namen — dann misst sie den falschen Vorgang.',
        );
    }

    /**
     * Eine Domain ohne Namen kostet trotzdem etwas.
     *
     * Der Fall entsteht nicht aus `serverNames()`, das immer mindestens den
     * eigenen Namen führt. Er entsteht aus einem künftigen Aufrufer — und eine
     * Reserve von null hiesse „passt immer".
     */
    public function test_a_domain_without_names_still_costs_something(): void
    {
        $this->assertSame(
            Budget::reserve(1),
            Budget::reserve(0),
            'Eine Reserve von null passt in jede Restfrist — dann gibt es keine.',
        );
    }

    /**
     * Die Zahlen der Reserve stehen dort, wo sie gelten.
     *
     * **Keine zweite Fassung.** Würde die Reserve `4 * 5` rechnen, stünde die
     * Zahl der Nameserver an zwei Stellen — und die zweite ist die, die
     * veraltet. Hier fiele es niemandem auf: Eine zu kleine Reserve heisst
     * bloss, dass der Lauf gelegentlich überzieht.
     */
    public function test_the_reserve_is_taken_from_where_it_holds(): void
    {
        $this->assertSame(
            DnsCheck::MAX_SERVERS * Resolver::TIMEOUT_SECONDS,
            Budget::reserve(1),
            'Die Reserve je Name ist nicht Server mal Zeitlimit.',
        );

        $quelle = (string) file_get_contents($this->root().'/app/Support/Dns/Budget.php');
        $rechnung = (string) preg_replace('/^\s*\*.*$/m', '', $quelle);

        $this->assertStringContainsString(
            'DnsCheck::MAX_SERVERS',
            $rechnung,
            'Budget rechnet mit einer eigenen Zahl statt mit der des Agenten.',
        );

        $this->assertStringContainsString(
            'Resolver::TIMEOUT_SECONDS',
            $rechnung,
            'Budget rechnet mit einem eigenen Zeitlimit statt mit dem des Auflösers.',
        );
    }

    /**
     * Die Unit lässt dem Lauf mehr Zeit, als er sich selbst nimmt.
     *
     * **Sonst entscheidet die kleinere Frist, und sie steht in einer Datei, in
     * die beim Ändern des Budgets niemand sieht.** Der Lauf würde mitten in
     * einer Messung abgeräumt, der Timer meldete `failed`, und die Ursache
     * stünde in einer `.service`-Datei.
     */
    public function test_the_unit_allows_more_time_than_the_budget(): void
    {
        $unit = (string) file_get_contents($this->root().'/packaging/systemd/srvpanel-dns.service');

        $this->assertSame(
            1,
            preg_match('/^TimeoutStartSec=(\d+)$/m', $unit, $treffer),
            implode("\n", [
                'srvpanel-dns.service nennt kein TimeoutStartSec in Sekunden.',
                'Dann gilt eine Vorgabe, die niemand hier gemessen hat — und ein Lauf,',
                'der am Systemaufloeser haengt, haelt die Unit fest. Ein Timer startet',
                'seinen Dienst nicht ein zweites Mal, solange der erste noch laeuft.',
            ]),
        );

        $this->assertGreaterThan(
            Budget::SECONDS,
            (int) $treffer[1],
            implode("\n", [
                'TimeoutStartSec liegt nicht ueber App\Support\Dns\Budget::SECONDS.',
                'Von zwei Fristen ueber denselben Lauf entscheidet die kleinere.',
            ]),
        );
    }

    /**
     * Und der Takt ist länger als ein Lauf dauern darf.
     *
     * **Ein Timer, dessen Dienst beim nächsten Termin noch läuft, feuert
     * einfach nicht** — ohne Fehler und ohne Meldung. Der Abstand muss deshalb
     * über der Frist liegen und nicht knapp daneben.
     */
    public function test_the_timer_fires_less_often_than_a_run_may_last(): void
    {
        $unit = (string) file_get_contents($this->root().'/packaging/systemd/srvpanel-dns.timer');

        // **Der Ausdruck muss treffen, sonst ist der Fall kein Fall.** Ein
        // Wächter, der bei einer unbekannten Schreibweise stillschweigend
        // durchgeht, misst nichts und ist grün.
        $this->assertSame(
            1,
            preg_match('/^OnCalendar=\*:0\/(\d+)$/m', $unit, $treffer),
            'srvpanel-dns.timer nennt keinen Takt der Form *:0/N — dann prueft dieser Fall nichts.',
        );

        $this->assertGreaterThan(
            Budget::SECONDS,
            (int) $treffer[1] * 60,
            implode("\n", [
                'Der Takt des Timers ist kuerzer als die Frist eines Laufs.',
                'Der naechste Termin faellt dann in einen noch laufenden Dienst, und',
                'systemd startet ihn nicht — lautlos.',
            ]),
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
