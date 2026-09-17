<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DatabaseEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\BackupCreate;

/**
 * Die Naht für das Datenbanksystem einer Sicherung — Panel → Agent → Panel.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * Am 17. September 2026, beim **allerersten** Griff auf „Jetzt sichern" im
 * Abnahmelauf von P8: *„Unbekanntes Datenbanksystem in der Dumpliste."*
 *
 * {@see BackupCreate::ENGINES} stand als Literal in der Prüfung und lautete
 * `['mariadb', 'postgresql']`. Das Panel schickt
 * `App\Enums\DatabaseEngine::Postgres->value`, und das ist `postgres`. Jede
 * Sicherung eines Abonnements mit einer PostgreSQL-Datenbank scheiterte an
 * dieser Zeile — und der nächtliche Lauf legte dafür jede Nacht einen
 * fehlgeschlagenen Vorgang an.
 *
 * Die Liste war in **beide** Richtungen falsch: `postgresql` konnte vom Panel
 * nicht kommen, und zurücklesen könnte es den Wert auch nicht —
 * `RestoreLifecycle` nimmt `DatabaseEngine::tryFrom()`.
 *
 * ## Warum kein Wächter ihn gesehen hat
 *
 * `DatabaseEngineTest` besteht darauf, dass den Wert im **Panel** niemand
 * tippt. Der Agent liegt ausserhalb seiner Reichweite und darf das Enum nicht
 * importieren (erste Grenze, `docs/20 §4.1`). Jede Seite für sich war in
 * Ordnung; zwischen ihnen stand nichts.
 *
 * > **Fehler an Nähten zwischen zwei Dateien** — dieselbe Stelle wie die sechs
 * > Befunde von A10.
 *
 * Bemerkenswert ist, wo die Begründung stand: Der Kopf von
 * {@see DatabaseEngine} schreibt ausdrücklich hin, *warum* der Wert `postgres`
 * heisst und nicht `postgresql`. Sie hat den Fehler nicht verhindert, weil sie
 * auf der anderen Seite der Naht steht.
 *
 * > **Eine Begründung schützt die Datei, in der sie steht.**
 *
 * ## Was er misst, und was nicht
 *
 * Gemessen wird die **Wirkung** und nicht der Quelltext: Jeder Fall des Enums
 * geht durch dieselbe Prüfung, an der der echte Aufruf gescheitert ist. Ein
 * Wächter, der `ENGINES` nur gegen das Enum hielte, bliebe grün, sobald jemand
 * in {@see BackupCreate::dumps()} wieder ein Literal schreibt.
 *
 * Die Gegenprobe ist der alte Wert selbst: Käme `postgresql` durch, wäre die
 * Prüfung keine Tür mehr, und die drei Fälle darüber belegten nichts.
 *
 * Was er **nicht** kann: Er sagt nichts darüber, ob der Wert nach dem Packen
 * noch im Manifest steht — das misst Punkt 4 des Abnahmelaufs auf einem echten
 * Server. Und er sagt nichts über die Operationen `backup.restore` und
 * `backup.verify`: Die prüfen das System gar nicht, sie lesen es aus dem
 * Manifest zurück.
 */
final class BackupEngineSeamTest extends TestCase
{
    /**
     * Jeder Fall des Enums kommt durch die Tür — gemessen an ihr selbst.
     */
    public function test_every_engine_of_the_panel_passes_the_door(): void
    {
        $faelle = DatabaseEngine::cases();

        // Die Untergrenze: Mit einem einzigen Fall wäre die Schleife eine
        // Behauptung über MariaDB und über sonst nichts — und genau der
        // zweite Fall ist der, der gescheitert ist.
        $this->assertGreaterThanOrEqual(2, count($faelle), 'Weniger als zwei Systeme — dann misst die Schleife den Fall nicht, der diesen Wächter ausgelöst hat.');

        foreach ($faelle as $fall) {
            $gelesen = $this->durchDieTuer($fall->value);

            $this->assertSame([[
                'storage' => 'dump-1',
                'engine' => $fall->value,
                'database' => 'kunde_db',
            ]], $gelesen, sprintf('Der Agent weist das System %s ab, das das Panel schickt.', $fall->value));
        }
    }

    /**
     * Die alte Schreibweise wird abgewiesen — sonst ist die Prüfung keine Tür.
     */
    public function test_the_old_spelling_is_refused(): void
    {
        $this->expectException(AgentException::class);

        $this->durchDieTuer('postgresql');
    }

    /**
     * Und ein leeres System auch — der Fall, den ein fehlendes Feld erzeugt.
     */
    public function test_a_missing_engine_is_refused(): void
    {
        $this->expectException(AgentException::class);

        $this->durchDieTuer('');
    }

    /**
     * Die Gegenrichtung: kein Wert der Liste ohne Fall im Enum.
     *
     * **So entsteht ein toter Eintrag wirklich** — bei einer Umbenennung trägt
     * man den neuen Wert nach, die Richtung oben ist danach wieder grün, und
     * der alte bleibt liegen. Er wiese dann ein System durch, das das Panel
     * nicht mehr kennt, und `DatabaseEngine::tryFrom()` gäbe beim Zurücklesen
     * `null` — eine Sicherung, die entsteht und sich nicht zuordnen lässt.
     */
    public function test_every_value_of_the_list_is_a_case_of_the_enum(): void
    {
        $this->assertNotSame([], BackupCreate::ENGINES, 'Eine leere Positivliste ist kein Mechanismus, sondern eine Verzierung.');

        foreach (BackupCreate::ENGINES as $wert) {
            $this->assertNotNull(DatabaseEngine::tryFrom($wert), sprintf('Der Agent liesse %s durch, und das Panel kennt es nicht.', $wert));
        }
    }

    /**
     * Ein Eintrag der Dumpliste durch die Prüfung, an der der echte Aufruf
     * gescheitert ist.
     *
     * Über Reflexion und nicht über `run()`: Der volle Lauf legte ein Archiv
     * an und bräuchte einen Kundenbaum. Gemessen werden soll die Tür und nicht
     * das Zimmer dahinter.
     *
     * @return list<array{storage: string, engine: string, database: string}>
     */
    private function durchDieTuer(string $engine): array
    {
        $tuer = new ReflectionMethod(BackupCreate::class, 'dumps');
        $tuer->setAccessible(true);

        /** @var list<array{storage: string, engine: string, database: string}> $gelesen */
        $gelesen = $tuer->invoke(new BackupCreate, [[
            'storage' => 'dump-1',
            'engine' => $engine,
            'database' => 'kunde_db',
        ]]);

        return $gelesen;
    }
}
