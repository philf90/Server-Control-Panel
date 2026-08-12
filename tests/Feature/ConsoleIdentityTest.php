<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jede Konsolenoperation läuft unter einem befristeten Zugang.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Weil der Fehler, den er sucht, das richtige Ergebnis liefert.** Läuft eine
 * Abfrage der Konsole unter der Kennung des Agenten statt unter der befristeten
 * Rolle, sieht die Antwort **genau gleich aus** — dieselben Zeilen, dieselben
 * Spalten. Was fehlt, ist die zweite Wand: Die Mandantentrennung ruhte dann
 * allein auf `Names::belongsTo()`, also auf einer Prüfung dieses Projekts, und
 * nicht auf den Rechten der Datenbank (`docs/46 §5`).
 *
 * > **Eine Prüfung, die im Fehlerfall dasselbe sagt wie im Erfolgsfall, belegt
 * > nichts.** ([37 §6](../../docs/37-uebergabe-an-p5b.md), Punkt 3.)
 *
 * Der Agent verbindet als Superuser (`Pg\Session::ROLE` ist `root`). Ein
 * `$this->session->query(...)` in einer Konsolenoperation wäre deshalb kein
 * kleiner Stilbruch, sondern der Verlust der Zusage — und niemand sähe es.
 *
 * ## Was geprüft wird
 *
 * 1. Jede `*.console.*`-Operation ruft `Console::within()` — den einzigen Ort,
 *    an dem die befristete Rolle entsteht.
 * 2. Keine Konsolenoperation ruft eine Sitzung unmittelbar an.
 * 3. `Console::within()` gibt die Kennzeichnung `KIND_CONSOLE` weiter, damit ein
 *    Rest auf dem Server sagt, wobei er entstanden ist (`docs/46 §6`).
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): In einer Konsolenoperation
 * `within()` durch einen unmittelbaren Aufruf ersetzen.
 */
final class ConsoleIdentityTest extends TestCase
{
    /**
     * Wie eine Sitzung unmittelbar gerufen würde.
     *
     * Gesucht wird der **Aufruf** und nicht die Klasse: Eine Operation darf
     * `Console` halten, und `Console` hält die Sitzung. Verboten ist der Weg
     * daran vorbei.
     */
    private const DIRECT = '/->\s*(session|pg|db)\s*->\s*(query|execute|queryAs|jsonAs|executeAs|restore)\s*\(/';

    public function test_every_console_operation_goes_through_the_ephemeral_frame(): void
    {
        $found = 0;

        foreach ($this->consoleOperations() as $path => $source) {
            $found++;

            $this->assertStringContainsString(
                '->within(',
                $source,
                sprintf(
                    '%s ist eine Konsolenoperation und ruft Console::within() nicht. '
                    .'Damit liefe ihre Abfrage als root, und das Ergebnis sähe gleich aus.',
                    basename($path),
                ),
            );
        }

        // Die Untergrenze zählt dort, wo die Regel stehen *darf* — sonst meldet
        // dieser Wächter Rot, sobald jemand die Operationen umbenennt oder
        // verschiebt, und wird dann abgeschaltet (`CLAUDE.md`).
        $this->assertGreaterThanOrEqual(
            5,
            $found,
            'Der Ausdruck findet keine Konsolenoperationen mehr — dann prüft dieser Wächter nichts.',
        );
    }

    public function test_no_console_operation_talks_to_a_session_itself(): void
    {
        foreach ($this->consoleOperations() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                self::DIRECT,
                $source,
                sprintf(
                    '%s ruft eine Sitzung unmittelbar. Dann läuft die Abfrage unter der Kennung des '
                    .'Agenten — als Superuser — und nicht unter der befristeten Rolle des Abonnements.',
                    basename($path),
                ),
            );
        }
    }

    public function test_the_frame_marks_its_leftovers_as_console(): void
    {
        $console = (string) file_get_contents($this->root().'/agent/src/Pg/Console.php');

        $this->assertStringContainsString(
            'Names::KIND_CONSOLE',
            $console,
            'Console::within() gibt die Kennzeichnung nicht weiter. Ein Rest auf dem Server sagt dann '
            .'nicht, ob er aus einem Zurückspielen oder aus der Konsole stammt.',
        );
    }

    /**
     * Die Quelltexte der Konsolenoperationen.
     *
     * @return array<string, string>
     */
    private function consoleOperations(): array
    {
        $sources = [];

        foreach ((array) glob($this->root().'/agent/src/Ops/*Console*.php') as $path) {
            $sources[(string) $path] = (string) file_get_contents((string) $path);
        }

        return $sources;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
