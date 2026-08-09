<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\PgServerInstall;
use SrvPanel\Agent\Pg\Server;

/**
 * Jeder Zustand, den {@see Server::describe()} melden kann, hat einen Handgriff.
 *
 * **Das ist wieder das Muster aus CLAUDE.md** — eine Zeichenkette, die auf
 * etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
 * prüft. `describe()` erzeugt sieben Zustandsnamen als blosse Literale;
 * {@see PgServerInstall} verzweigt über dieselben Namen, und das Panel wird
 * gleich noch einmal über dieselben Namen verzweigen. Drei Fassungen einer
 * Aufzählung, und keine kennt die andere.
 *
 * Der Fehlschlag, den dieser Wächter abfängt, ist nicht der Tippfehler — den
 * fände man beim ersten Lauf. Es ist der **achte Zustand**: Wer `describe()` um
 * einen erweitert, bekommt in `PgServerInstall` stillschweigend den
 * `default`-Zweig, und der heisst „PostgreSQL ist da, es ist nichts zu tun".
 * Ein Server, der genau dann nicht läuft, meldete dann Erfolg.
 *
 * **Was der Wächter kann und was nicht.** Er hält die Namen zusammen, nicht
 * die Entscheidungen: Dass `ambiguous` abgewiesen und `stopped` gestartet wird,
 * ist gemessen worden (`docs/38 §2.2d`, vier Zustände gegen einen echten
 * Cluster) und steht nicht hier. Er sorgt dafür, dass ein neuer Zustand
 * **auffällt**, und überlässt die Antwort dem, der ihn einführt.
 */
final class PgServerStateTest extends TestCase
{
    /**
     * Die sieben, wie sie am 9. August 2026 gemessen wurden.
     *
     * Sie stehen hier abgeschrieben und nicht aus `describe()` gelesen: Ein
     * Wächter, der seine Erwartung aus der geprüften Datei bezieht, ist mit
     * jeder Änderung einverstanden.
     *
     * @var list<string>
     */
    private const KNOWN = [
        'absent',
        'no_cluster',
        'stopped',
        'ambiguous',
        'not_handed_over',
        'unusable',
        'ready',
    ];

    /** @return list<string> */
    private function statesOf(string $class): array
    {
        $path = dirname(__DIR__, 2).'/agent/src/'.str_replace(
            ['SrvPanel\\Agent\\', '\\'],
            ['', '/'],
            $class,
        ).'.php';

        /*
         * **Zwei Schritte, weil einer nicht reicht.** Zwei der sieben Zustände
         * entstehen in einem Bedingungsausdruck (`$usable ? 'ready' :
         * 'unusable'`), und der erste Wurf dieses Ausdrucks fand sie nicht — er
         * verlangte unmittelbar hinter dem Pfeil eine Zeichenkette. Er hätte
         * fünf gefunden, wäre grün gewesen und hätte genau die zwei Zustände
         * nicht bewacht, die der Betreiber am ehesten zu sehen bekommt.
         */
        preg_match_all("/'state'\s*=>\s*([^,\n]+)/", (string) file_get_contents($path), $matches);

        $states = [];

        foreach ($matches[1] as $expression) {
            preg_match_all("/'([a-z_]+)'/", $expression, $literals);
            $states = array_merge($states, $literals[1]);
        }

        return array_values(array_unique($states));
    }

    /**
     * `describe()` erfindet keinen Zustand, den niemand kennt.
     */
    public function test_the_vocabulary_is_the_documented_one(): void
    {
        $found = $this->statesOf(Server::class);

        // Die Untergrenze zählt mit: Ein Ausdruck, der ins Leere läuft, fände
        // sonst nichts und wäre damit einverstanden. Fällt sie, ist entweder
        // der Ausdruck kaputt oder ein Zustand ist fort — und das zweite gehört
        // ebenso hierher gemeldet wie das erste.
        $this->assertGreaterThanOrEqual(
            count(self::KNOWN),
            count($found),
            'Der Ausdruck findet die Zustände nicht mehr — oder es gibt einen weniger.',
        );

        foreach ($found as $state) {
            $this->assertContains(
                $state,
                self::KNOWN,
                sprintf(
                    'Server::describe() meldet den Zustand „%s". Er gehört in diese Liste — und vorher '
                    .'in PgServerInstall, ins Panel und in die Tabelle in beiden Klassenkommentaren.',
                    $state,
                ),
            );
        }
    }

    /**
     * Und jeder von ihnen wird beim Installieren angefasst.
     *
     * **Gesucht wird im ganzen Quelltext und nicht nur im `match`**, weil drei
     * der sieben absichtlich in den `default`-Zweig fallen — sie stehen dort in
     * der Tabelle des Klassenkommentars, mit dem Grund daneben. Der Wächter
     * verlangt deshalb nicht eine bestimmte Bauform, sondern dass der Name
     * überhaupt vorkommt: dass jemand über diesen Zustand nachgedacht hat.
     */
    public function test_every_state_is_answered_by_the_installer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/PgServerInstall.php',
        );

        foreach (self::KNOWN as $state) {
            $this->assertStringContainsString(
                $state,
                $source,
                sprintf(
                    'PgServerInstall sagt zum Zustand „%s" nichts — und schweigend heisst das: '
                    .'„PostgreSQL ist da, es ist nichts zu tun."',
                    $state,
                ),
            );
        }
    }

    /**
     * `ACTIONABLE` nennt nur Zustände, in denen die Operation wirklich handelt.
     *
     * **Der Knopf im Panel liest diese Liste**, und das ist der ganze Zweck:
     * `CLAUDE.md` verlangt, dass die Oberfläche dieselbe Stelle fragt, die
     * später abweist — sonst gibt es die Regel zweimal, und die zweite ist die,
     * die veraltet. Geprüft wird beides:
     *
     * - Jeder Name darin ist ein Zustand, den es gibt.
     * - Keiner davon ist einer, den `execute()` abweist. Ein `no_cluster` in
     *   dieser Liste wäre ein Knopf, dessen einzige Wirkung eine Fehlermeldung
     *   ist.
     */
    public function test_the_actionable_states_are_states_that_act(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/PgServerInstall.php',
        );

        $this->assertNotSame([], PgServerInstall::ACTIONABLE);

        foreach (PgServerInstall::ACTIONABLE as $state) {
            $this->assertContains($state, self::KNOWN, sprintf('ACTIONABLE nennt „%s"; den Zustand gibt es nicht.', $state));

            $this->assertStringContainsString(
                sprintf("'%s' => \$this->", $state),
                $source,
                sprintf(
                    'ACTIONABLE nennt „%s", aber execute() ruft dafür nichts auf — der Knopf im Panel '
                    .'täte dann nichts.',
                    $state,
                ),
            );
        }

        foreach (['no_cluster', 'ambiguous'] as $refused) {
            $this->assertNotContains(
                $refused,
                PgServerInstall::ACTIONABLE,
                sprintf('„%s" wird abgewiesen; ein Knopf dafür löst nur eine Fehlermeldung aus.', $refused),
            );
        }
    }

    /**
     * Der Befehl für die Übergabe steht an einer Stelle.
     *
     * `docs/36 §22.3v` hat einen abgedruckten Befehl teuer bezahlt, den es so
     * nicht gab. Deshalb liegt er als Konstante im Agenten, und wer ihn zeigt,
     * bekommt ihn aus der Antwort — hier wird nachgesehen, dass die Antwort ihn
     * überhaupt mitbringt.
     */
    public function test_the_handover_command_travels_with_the_answer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/PgServerInstall.php',
        );

        $this->assertStringContainsString("'handover' => \$after['handover']", $source);
        $this->assertStringContainsString('CREATE ROLE root SUPERUSER LOGIN', Server::HANDOVER);
    }
}
