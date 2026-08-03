<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Peer;
use SrvPanel\Agent\Runner;

/**
 * Die drei Glieder der Abbruchkette, einzeln belegt.
 *
 * Ein Abbruch, der nur die Ausgabe abschaltet und das Programm weiterlaufen
 * lässt, ist keiner — er sähe in der Oberfläche genauso aus. Deshalb wird hier
 * jedes Glied für sich geprüft:
 *
 * 1. **Der Aufrufer gibt auf.** Client::call bricht beim Warten ab, statt bis
 *    zum Zeitlimit zu hängen — auch wenn nie eine Zeile Ausgabe kommt.
 * 2. **Der Agent bemerkt es.** Peer::gone unterscheidet „Verbindung
 *    geschlossen" von „gerade nichts da". Daran hängt alles Weitere.
 * 3. **Das Programm stirbt.** Runner tötet den Kindprozess, erst mit SIGTERM,
 *    dann mit SIGKILL — dieselbe Behandlung wie beim Zeitlimit.
 */
final class AgentCancelTest extends TestCase
{
    /** Ein Programm von der Positivliste, das von sich aus nicht aufhört. */
    private const ENDLESS = ['journalctl', ['-f', '--no-pager']];

    private function requireProgram(): void
    {
        if (! is_executable('/usr/bin/journalctl')) {
            $this->markTestSkipped('Der Test braucht ein Programm, das läuft, bis es beendet wird.');
        }
    }

    public function test_the_peer_check_tells_closed_apart_from_quiet(): void
    {
        // Hier steht eine Annahme über den Kernel auf dem Prüfstand, und zwar
        // die, an der der ganze Abbruch hängt.
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$a, $b] = $pair;

        // Offen und still: Das ist der Normalfall, solange ein Programm läuft.
        // Würde er als „weg" gelesen, bräche jeder Vorgang sofort ab.
        $this->assertFalse(Peer::gone($a));

        socket_close($b);

        // Geschlossen bei leerem Puffer — das ist der Abbruch.
        $this->assertTrue(Peer::gone($a));

        socket_close($a);
    }

    public function test_unread_data_hides_the_close_until_it_is_read(): void
    {
        // Der blinde Fleck, festgehalten statt verschwiegen: Liegt noch etwas
        // im Puffer, meldet MSG_PEEK diese Daten und nicht das Ende. Im
        // Protokoll kann das nicht vorkommen — der Aufrufer schickt genau eine
        // Zeile, und die hat der Agent gelesen, bevor er ein Programm startet.
        // Käme je ein zweiter Schreibweg dazu, stünde der Abbruch still, und
        // dann soll dieser Test danebenstehen.
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$a, $b] = $pair;

        socket_write($b, 'unerwartet');
        socket_close($b);

        $this->assertFalse(Peer::gone($a));

        // Nach dem Lesen ist das Ende sichtbar.
        socket_read($a, 64, PHP_BINARY_READ);
        $this->assertTrue(Peer::gone($a));

        socket_close($a);
    }

    public function test_the_runner_kills_the_program_it_started(): void
    {
        $this->requireProgram();

        $runner = new Runner(new Journal('/dev/null'));
        [$program, $arguments] = self::ENDLESS;

        $startedAt = microtime(true);
        $cancelAfter = $startedAt + 1.0;
        $aborted = false;

        try {
            $runner->run(
                $program,
                $arguments,
                // Großzügig: Greift der Abbruch nicht, schlägt der Test über
                // die Dauer unten fehl und nicht über das Zeitlimit — sonst
                // wäre nicht zu unterscheiden, welches von beidem gewirkt hat.
                timeout: 30,
                abort: static fn (): bool => microtime(true) > $cancelAfter,
            );
        } catch (AgentException $error) {
            $aborted = $error->errorCode === AgentException::CANCELLED;
        }

        $duration = microtime(true) - $startedAt;

        $this->assertTrue($aborted, 'Der Abbruch hätte als solcher gemeldet werden müssen.');

        // Deutlich unter dem Zeitlimit: Beendet hat es der Abbruch, nicht die
        // Uhr.
        $this->assertLessThan(10, $duration);
    }

    public function test_the_context_passes_the_abort_through_to_the_runner(): void
    {
        $this->requireProgram();

        // Das Glied dazwischen. Es ist eine Zeile in Context::stream, und
        // genau solche Zeilen fallen beim Umbauen still weg.
        $context = new Context(
            new Runner(new Journal('/dev/null')),
            new Journal('/dev/null'),
            static function (array $line): void {},
            static fn (): bool => true,
        );

        [$program, $arguments] = self::ENDLESS;

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('abgebrochen');

        $context->stream($program, $arguments, 30);
    }

    public function test_the_client_gives_up_while_waiting_for_a_silent_agent(): void
    {
        // Eine Gegenstelle, die annimmt und dann schweigt — der Fall, den ein
        // Abbruch treffen muss: `systemctl reload nginx` schreibt nichts, und
        // ein Aufrufer, der auf die erste Zeile wartet, wartet ewig.
        $directory = '/tmp/cs-'.bin2hex(random_bytes(4));
        mkdir($directory, 0o755, true);
        $path = $directory.'/still.sock';

        $listener = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_bind($listener, $path);
        socket_listen($listener);

        $client = new Client($path, timeout: 60);
        $startedAt = microtime(true);

        try {
            $client->call('agent.ping', shouldAbort: static fn (): bool => true);
            $this->fail('Der Aufruf hätte abbrechen müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::CANCELLED, $error->errorCode);
        } finally {
            socket_close($listener);
            @unlink($path);
            @rmdir($directory);
        }

        // Das Zeitlimit steht auf 60 s. Ohne die kurze Wartezeit in call()
        // hinge dieser Aufruf bis dahin.
        $this->assertLessThan(10, microtime(true) - $startedAt);
    }
}
