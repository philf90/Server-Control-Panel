<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Databases;
use App\Enums\DatabaseEngine;
use App\Support\Databases\DatabasePrune;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReadsMethodSource;
use Tests\Support\WithoutPhpComments;

/**
 * `srvpanel db` sieht beide Systeme — oder es sagt über keines etwas.
 *
 * **Der Anlass steht in `docs/39 §12a`.** Am Ende des Abnahmelaufs von P5b
 * meldete das Kommando `Nichts liegengeblieben.` — und konnte diese Frage für
 * PostgreSQL gar nicht stellen. Der Statusteil rief nur `db.server.info`, das
 * Feld `stale_roles` aus `pg.server.info` las niemand, und `--prune` räumte mit
 * drei MariaDB-Operationen. Die Zahlen stimmten, weil sie Zeilen ohne Rücksicht
 * auf `engine` zählen; die Reichweite nicht.
 *
 * > **Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen können,
 * > über die es Entwarnung gibt.**
 *
 * Deshalb musste Punkt 7f seine Reste von Hand mit `SELECT … FROM pg_roles`
 * zählen — neben einem Kommando, das genau dafür da ist.
 *
 * ## Warum das ein eigener Wächter ist und kein Fall in `EngineScopeTest`
 *
 * Jener prüft, dass kein Lebenslauf die Zeilen des anderen Systems anfasst —
 * eine Grenze. Dieser prüft das Gegenteil: dass ein Werkzeug, das über den
 * ganzen Server spricht, auch den ganzen Server fragt. Beide Regeln brechen
 * still, und sie brechen in entgegengesetzte Richtungen.
 */
final class DbCommandReachTest extends TestCase
{
    use ReadsMethodSource;
    use WithoutPhpComments;

    /**
     * Der Rumpf einer Methode — ohne seine Kommentare.
     *
     * **Die Erklärung darüber nennt jeden Namen, den der Code nennen soll.**
     * Ohne diese Zeile fände dieser Wächter `pg.server.info` im Kommentar und
     * bliebe grün, während der Aufruf fehlt — genau die Falle, in die
     * `PgOwnerTest` beim Gegenbruch gelaufen ist.
     */
    private function code(string $class, string $method): string
    {
        return $this->withoutComments('<?php '.((string) $this->methodSource($class, $method)));
    }

    /**
     * Der Statusteil fragt beide Server.
     *
     * Nicht „irgendwo in der Datei": `--postgresql=on` fragt `pg.server.info`
     * seit Schritt 4, und genau deshalb sah die Datei immer so aus, als wäre
     * die Frage gestellt. Gelesen wird der Weg, den `srvpanel db` ohne Schalter
     * nimmt.
     */
    public function test_the_status_asks_both_servers(): void
    {
        $status = $this->code(Databases::class, 'showServer');

        $this->assertStringContainsString('db.server.info', $status);

        /*
         * **Der Aufruf und nicht nur die Methode.** Hier stand ein Test, der
         * beide Rümpfe aneinanderhängte und darin nach `pg.server.info` suchte
         * — er blieb grün, als der Aufruf aus `showServer()` entfernt wurde,
         * weil `reportPostgres()` weiter dastand. Gefunden hat es der
         * Gegenbruch, zum dritten Mal in dieser Woche.
         *
         * **Eine Methode, die niemand ruft, beantwortet keine Frage.**
         */
        $this->assertStringContainsString('reportPostgres(', $status,
            '`srvpanel db` ruft den PostgreSQL-Teil nicht mehr. Was es nicht fragt, kann es nicht '
            .'melden — und es meldet trotzdem „Nichts liegengeblieben."');

        $this->assertStringContainsString(
            'pg.server.info',
            $this->code(Databases::class, 'reportPostgres'),
        );
    }

    /**
     * Und es liest beide Antworten.
     *
     * **Das ist die stillere Schwester von `AgentOperationReachTest`.** Dort
     * wird eine Operation gebaut und nicht gerufen; hier wird sie gerufen und
     * eine ihrer Antworten nicht gelesen. `stale_roles` stand seit Schritt 6 im
     * Agenten, wurde bei jedem Aufruf berechnet und fiel auf den Boden.
     *
     * **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**
     */
    public function test_both_stale_lists_are_read(): void
    {
        $sites = [
            'stale_users' => 'showServer',
            'stale_roles' => 'reportPostgres',
        ];

        foreach ($sites as $key => $method) {
            $this->assertStringContainsString($key, $this->code(Databases::class, $method), sprintf(
                'Die Antwort `%s` wird in %s() nicht gelesen. Der Agent rechnet sie bei jedem '
                .'Aufruf aus, und niemand erfährt davon.',
                $key,
                $method,
            ));
        }

        // Und die Methode, die `stale_roles` liest, wird auch gerufen — sonst
        // ist die Zeile darüber wieder nur eine, die irgendwo im Code steht.
        $this->assertStringContainsString(
            'reportPostgres(',
            $this->code(Databases::class, 'showServer'),
        );
    }

    /**
     * Der Rückbau nennt für **jedes** System eine Operation.
     *
     * **Gelesen wird der Aufzählungstyp und nicht eine Liste hier.** Eine Liste
     * wäre die zweite Fassung von {@see DatabaseEngine}, und die zweite ist die,
     * die veraltet: Käme ein drittes System dazu, bliebe dieser Wächter grün und
     * der Rückbau schickte dessen Zeilen an den falschen Agenten.
     */
    public function test_prune_names_an_operation_for_every_engine(): void
    {
        $source = $this->code(Databases::class, 'prune');

        $expected = [
            DatabaseEngine::MariaDb->name => ['db.user.remove', 'db.database.remove'],
            DatabaseEngine::Postgres->name => ['pg.role.remove', 'pg.database.remove'],
        ];

        foreach (DatabaseEngine::cases() as $engine) {
            $this->assertArrayHasKey($engine->name, $expected, sprintf(
                'Für %s steht in diesem Test keine Operation. Ein neues System, das niemand '
                .'nachgezogen hat — genau dafür wird hier der Aufzählungstyp gelesen.',
                $engine->name,
            ));

            foreach ($expected[$engine->name] as $operation) {
                $this->assertStringContainsString($operation, $source, sprintf(
                    '`srvpanel db --prune` kennt %s nicht. Eine liegengebliebene Zeile aus %s '
                    .'ginge damit an den falschen Agenten und nähme den ganzen Lauf mit.',
                    $operation,
                    $engine->label(),
                ));
            }
        }
    }

    /**
     * Und der Plan trägt das System, aus dem die Verzweigung liest.
     *
     * **Ohne diese Zeile wäre die Verzweigung darüber eine Behauptung.** Sie
     * fragt `$user['engine']`; steht der Schlüssel nicht im Plan, gibt es keinen
     * Fehlschlag beim Lesen der Datei, sondern einen zur Laufzeit — an dem Tag,
     * an dem tatsächlich etwas liegengeblieben ist.
     */
    public function test_the_plan_carries_the_engine(): void
    {
        $source = $this->code(DatabasePrune::class, 'plan');

        $this->assertSame(
            2,
            substr_count($source, "'engine' =>"),
            'Der Rückbauplan nennt das System nicht für Datenbanken **und** Zugänge. '
            .'Die Verzweigung in `srvpanel db --prune` liest genau diesen Schlüssel.',
        );
    }
}
