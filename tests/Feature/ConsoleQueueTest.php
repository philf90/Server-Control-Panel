<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Operations\Task;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Keine Konsolenoperation geht durch die Warteschlange.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab. Bei
 * `console.row.write` stünde dort **der Inhalt einer Kundenzeile**, bei
 * `console.rows` der Filterwert — beides für jeden lesbar, der die
 * Vorgangsliste sehen darf, und beides überlebt die Zeile, aus der es stammt.
 *
 * Das ist dieselbe Regel wie für Passwörter (`docs/36 §4`, `docs/46 §12`), mit
 * einem weiter gefassten Anlass:
 *
 * > **Was nicht in der Warteschlange stehen darf, ist nicht nur ein Geheimnis —
 * > es ist alles, was dem Kunden gehört.**
 *
 * **Und der Fehler wäre unsichtbar.** Ein eingereihter Konsolenaufruf
 * funktioniert: Die Zeile wird geändert, die Antwort kommt, die Seite sieht
 * richtig aus. Was dazukommt, ist eine Kopie der Daten an einer Stelle, an der
 * sie niemand sucht — und sie fällt erst auf, wenn jemand ein Jahr später die
 * Vorgangsliste eines Kunden durchsieht.
 *
 * ## Was geprüft wird
 *
 * 1. Keine `*.console.*`-Operation steht in {@see Task} — dort stehen die
 *    Aufgaben, die einen Lebenslauf haben und damit über die Reihe laufen.
 * 2. `App\Support\Databases\Console` reiht nichts ein: kein `RunAgentOperation`,
 *    kein `Task::`, kein `dispatch`.
 * 3. Es gibt die zehn Operationen überhaupt — sonst prüfen 1 und 2 eine leere
 *    Menge und sind grün, ohne etwas zu sagen.
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): eine `console`-Aufgabe in
 * {@see Task} eintragen.
 */
final class ConsoleQueueTest extends TestCase
{
    public function test_no_console_operation_has_a_task(): void
    {
        foreach (Task::cases() as $task) {
            $this->assertStringNotContainsString(
                '.console.',
                $task->value,
                sprintf(
                    'Task::%s heisst %s und läuft damit über die Warteschlange. Ein eingereihter Vorgang '
                    .'legt seine Argumente in operations.payload ab — dort stünde der Inhalt einer '
                    .'Kundenzeile oder ein Filterwert (docs/46 §12).',
                    $task->name,
                    $task->value,
                ),
            );
        }
    }

    public function test_the_panel_side_console_calls_the_agent_directly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/Databases/Console.php');

        $this->assertStringContainsString(
            '$this->agent->call(',
            $source,
            'App\Support\Databases\Console ruft den Agenten nicht unmittelbar. Dann läuft der Aufruf '
            .'über die Reihe, und die Argumente stehen in operations.payload.',
        );

        foreach (['RunAgentOperation', 'Task::', 'dispatch('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('App\Support\Databases\Console benutzt %s — das ist der Weg über die Reihe.', $forbidden),
            );
        }
    }

    /**
     * Die Untergrenze.
     *
     * Sie zählt dort, wo die Regel stehen **darf** — in der Registratur des
     * Agenten. Ohne sie wäre dieser Wächter grün, sobald jemand die zehn
     * Operationen umbenennt, und niemand merkte, dass er nichts mehr prüft
     * (`CLAUDE.md`).
     */
    public function test_there_are_console_operations_to_guard(): void
    {
        $console = array_filter(
            (new Registry(new Config))->names(),
            static fn (string $name): bool => str_contains($name, '.console.'),
        );

        $this->assertGreaterThanOrEqual(
            10,
            count($console),
            'Der Ausdruck findet keine Konsolenoperationen mehr — dann prüft dieser Wächter nichts.',
        );
    }
}
