<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Runner;
use Tests\Support\WithoutPhpComments;

/**
 * Der Agent wechselt seine Kennung nicht.
 *
 * **Der Anlass ist eine Empfehlung dieses Projekts, die eine Messung nicht
 * überlebt hat.** `docs/38 §6` sah zuerst vor, dass `Runner` ein Feld „läuft
 * als" bekommt — PostgreSQL bildet Unix-Kennungen auf Rollen ab, und root ist
 * keine. Der Weg dorthin wäre `runuser` auf der Positivliste gewesen oder
 * `pcntl_fork` mit `posix_setuid` im `Runner` selbst. Gemessen wurde beides:
 *
 * - Der Fork **läuft**, und die Umleitung der Dateinummern ist trotzdem nicht
 *   verlässlich — die Ausgabe von `psql` landete in der Datei für stderr, bei
 *   Rückgabewert 0. *Was Erfolg meldet und die Daten woanders ablegt* ist die
 *   Sorte Fehler, gegen die dieses Projekt seine Wächter baut.
 * - Der geforkte Prozess **erbt den Socket des Agenten**.
 *
 * Gebraucht wird von beidem nichts: Debians `pg_hba.conf` enthält
 * `local all all peer`, und gibt es eine PostgreSQL-Rolle namens `root`, kommt
 * der Agent als Superuser durch, ohne dass eine Datei angefasst wird
 * (`docs/38 §6`).
 *
 * Dieser Wächter hält fest, dass es dabei bleibt — **in beide Richtungen**: kein
 * Programm auf der Positivliste, das die Kennung wechselt, und kein zweiter
 * Aufruf von `psql` neben `Pg\Session`.
 */
final class AgentIdentityTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Programme, die als root beliebige andere unter beliebiger Kennung starten.
     *
     * **Die Liste ist absichtlich länger als der eine Name, um den es ging.**
     * Ein Wächter, der nur `runuser` verbietet, wird mit `setpriv` umgangen —
     * und zwar von jemandem, der die Regel gar nicht brechen wollte, sondern
     * nur ein anderes Werkzeug kannte.
     *
     * @var list<string>
     */
    private const SWITCHES_IDENTITY = ['runuser', 'su', 'sudo', 'setpriv', 'chroot', 'env', 'nsenter'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Kein Programm auf der Positivliste wechselt die Kennung.
     */
    public function test_no_program_on_the_allowlist_switches_identity(): void
    {
        $programs = array_keys(Runner::programs());

        foreach (self::SWITCHES_IDENTITY as $forbidden) {
            $this->assertNotContains($forbidden, $programs, sprintf(
                '%s steht auf der Positivliste. Ein Programm, das als root beliebige andere unter '
                .'beliebiger Kennung startet, ist die weiteste Zeile dieser Liste — weiter als certbot, '
                .'der mit einer schwächeren Begründung gehen musste (docs/38 §6.3).',
                $forbidden,
            ));
        }

        // Die Untergrenze zählt, wo die Regel stehen darf: Wächst die Liste,
        // soll das hier kein Rot geben; schrumpft sie auf nichts, schon.
        $this->assertGreaterThan(10, count($programs), 'Die Positivliste ist leer — dann prüft dieser Test nichts.');
    }

    /**
     * Die drei PostgreSQL-Programme stehen darauf, und zwar ohne Fassungszahl.
     *
     * `psql`, `pg_dump` und `pg_restore` sind Wrapper von
     * `postgresql-client-common` und wählen die Serverfassung selbst — anders
     * als `php-fpm8.2`, das je Version eine eigene Zeile braucht. Eine Zahl im
     * Namen wäre hier eine Zusage über den Bestand des Servers, die dieses
     * Paket nicht einlösen kann.
     */
    public function test_the_postgresql_programs_are_listed_without_a_version(): void
    {
        foreach (['psql', 'pg_dump', 'pg_restore'] as $program) {
            $this->assertTrue(Runner::knows($program), $program.' fehlt auf der Positivliste.');
            $this->assertDoesNotMatchRegularExpression(
                '/\d/',
                Runner::programs()[$program],
                $program.' steht mit einer Fassungszahl auf der Liste.',
            );
        }
    }

    /**
     * `psql` wird nur aus `Pg\Session` gerufen.
     *
     * Dieselbe Zusage, die `Db\Session` für `mysql` gibt — und dieselbe
     * Begründung: Wer daran vorbei ein zweites `run('psql', …)` schreibt, macht
     * aus einer Trennung eine Behauptung. Der Unterschied zu P5 ist, dass es
     * dort nie geprüft wurde; die Zusage stand nur im Klassenkopf.
     */
    public function test_psql_is_called_from_one_place_only(): void
    {
        $callers = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/agent/src', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            // Der Runner selbst nennt jeden Namen — er ist die Liste.
            if (str_ends_with($file->getPathname(), '/Runner.php')) {
                continue;
            }

            if (preg_match("/->run\(\s*'psql'/", $source) === 1) {
                $callers[] = str_replace($this->root().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(['agent/src/Pg/Session.php'], $callers, 'psql wird an mehr als einer Stelle gerufen.');
    }

    /**
     * Und der Agent meldet sich als die Rolle an, die seine Kennung ist.
     *
     * **Das ist die ganze Mechanik von `peer`**, und sie steht als Zeichenkette
     * in zwei Dateien: `Session::ROLE` ist der Name, unter dem `psql` sich
     * anmeldet, und `Server::HANDOVER` ist der Befehl, den das Panel dem
     * Betreiber anzeigt. Laufen die beiden auseinander, legt der Betreiber eine
     * Rolle an, die niemand benutzt — und das Panel sagt ihm weiter, PostgreSQL
     * sei nicht übergeben. Ein abgedruckter Befehl, der ins Leere geht, hat
     * `docs/36 §22.3v` schon einmal gekostet.
     */
    public function test_the_handover_command_creates_the_role_the_agent_uses(): void
    {
        $this->assertStringContainsString(
            'CREATE ROLE '.Session::ROLE.' ',
            Server::HANDOVER,
            'Der angezeigte Befehl legt eine andere Rolle an, als der Agent benutzt.',
        );
    }
}
