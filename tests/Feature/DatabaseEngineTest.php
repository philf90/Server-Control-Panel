<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DatabaseEngine;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Welches Datenbanksystem hinter einer Zeile steht, tippt niemand.
 *
 * **Dieselbe Regel wie {@see DumpKindTest}, und dieselbe Vorgeschichte — nur
 * dass sie diesmal vorher aufgeschrieben wurde.** Bei `kind` war der Wert bis
 * zum 9. August 2026 eine nackte Spalte, deren zwei Zeichenketten an vier
 * Stellen verstreut standen, eine davon im Vue-Template, also über eine Grenze,
 * die kein Typ prüft. `engine` wäre der nächste Kandidat gewesen: Er steht in
 * drei Tabellen, entscheidet in jeder Verzweigung zwischen `db.*` und `pg.*`
 * und geht als Marke in die Oberfläche.
 *
 * **Die Ausnahmeliste ist hier länger als bei `kind`, und das hat einen
 * Grund.** `mariadb` heisst zweierlei: das Datenbanksystem eines Kunden — darum
 * geht es hier — und der **Verbindungstreiber des Panels selbst**, den Laravel
 * so nennt. Der zweite hat mit dieser Aufzählung nichts zu tun, und ihn
 * mitzufangen wäre ein Fehlalarm. *Ein Wächter, der Fehlalarm gibt, wird
 * abgeschaltet* — der Satz steht in `ClassReachTest` und hat sich in P5 sofort
 * bestätigt.
 */
final class DatabaseEngineTest extends TestCase
{
    /**
     * Wo die Werte stehen dürfen — mit dem Grund je Eintrag.
     *
     * Der Grund steht **im Wert** und nicht in einem Kommentar daneben:
     * dieselbe Form wie `RemovalPathTest::WITHOUT_REMOVAL`. Eine Liste ohne
     * Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'app/Enums/DatabaseEngine.php' => 'Die Aufzählung selbst.',
        'database/migrations/2026_08_09_100000_postgresql_joins_the_databases.php' => 'Eine Migration läuft '
            .'einmal, und was sie schreibt, muss zu dem passen, was an dem Tag galt — sie darf nicht von '
            .'einer Aufzählung abhängen, die sich morgen ändert. Dieselbe Entscheidung wie bei `kind`.',
        'app/Console/Commands/Setup.php' => 'Hier heisst `mariadb` der Verbindungstreiber des Panels selbst '
            .'(DB_CONNECTION), nicht das Datenbanksystem eines Kunden. Zwei Bedeutungen, ein Wort.',
        'app/Support/Operations/OperationRecorder.php' => 'Dasselbe: die Verzweigung über den Treiber der '
            .'Panel-Datenbank, um die Ausgabe eines Vorgangs anzuhängen.',
        'app/Http/Controllers/DatabaseSettingsController.php' => 'Die dritte Bedeutung: was `db.server.info` '
            .'aus `@@version` gelesen hat. Hier kommt der Wert des Agenten an und wird GENAU EINMAL in '
            .'einen Text übersetzt — bis zum 9. August 2026 tat das Settings/Database.vue, also über die '
            .'Grenze zwischen PHP und Browser, die kein Typ prüft. Dieser Wächter hat es beim ersten Lauf '
            .'gefunden; die Ausnahme deckt seitdem die Übersetzung und nicht mehr den Vergleich.',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Der Quelltext, in dem gesucht wird.
     *
     * `app/`, `database/` und `resources/js/` — die drei Bereiche, in denen der
     * Wert eine Bedeutung hat. **`agent/` steht nicht dabei**, und das ist keine
     * Lücke: Der Agent kennt keine Geschmacksrichtung. Er hat `db.*` und `pg.*`
     * als getrennte Operationen (`docs/38 §8`), und `Db\Server::flavour()`
     * beantwortet eine ganz andere Frage — nämlich welches System dort *läuft*,
     * gelesen aus `@@version`.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $found = [];

        foreach (['app', 'database', 'resources/js'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root().'/'.$directory, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'vue', 'ts'], true)) {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        return $found;
    }

    public function test_no_engine_is_written_as_a_literal(): void
    {
        $values = array_map(static fn (DatabaseEngine $engine): string => $engine->value, DatabaseEngine::cases());

        $this->assertNotSame([], $values, 'Die Aufzählung ist leer — dann prüft dieser Test nichts.');

        $found = [];
        $read = 0;

        foreach ($this->sources() as $file) {
            $short = str_replace($this->root().'/', '', $file);

            if (isset(self::ALLOWED[$short])) {
                continue;
            }

            $read++;

            foreach (file($file) ?: [] as $number => $line) {
                foreach ($values as $value) {
                    // In Anführungszeichen und für sich allein: `postgresql` im
                    // Fliesstext eines Kommentars trifft nicht, und ein längeres
                    // Wort mit demselben Anfang auch nicht.
                    if (preg_match('/([\'"])'.preg_quote($value, '/').'\1/', $line) === 1) {
                        $found[] = sprintf('%s:%d  %s', $short, $number + 1, trim($line));
                    }
                }
            }
        }

        // Die Untergrenze zählt die gelesenen Dateien und nicht die Fundstellen.
        $this->assertGreaterThan(50, $read, 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Das Datenbanksystem steht hier als Zeichenkette:\n  %s\n\n"
            ."Es gehört in App\\Enums\\DatabaseEngine. Wer es tippt, kann sich vertippen — und ein\n"
            .'Vergleich, der nie zutrifft, meldet sich nicht.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und die Ausnahmen zeigen auf Dateien, die es gibt.
     *
     * Dieselbe Gegenrichtung wie in `RouteGuard` und
     * `AgentOperationReachTest::test_every_declared_exception_is_still_an_operation`:
     * Eine Ausnahme für eine Datei, die es nicht mehr gibt, fällt sonst nie auf
     * — und deckt irgendwann etwas, an das niemand mehr gedacht hat.
     */
    public function test_every_exception_still_points_at_a_file(): void
    {
        foreach (array_keys(self::ALLOWED) as $path) {
            $this->assertFileExists(
                $this->root().'/'.$path,
                sprintf('ALLOWED nennt %s; diese Datei gibt es nicht mehr.', $path),
            );
        }
    }

    /**
     * Beide tragen eine Marke — und zwei verschiedene Präfixe.
     *
     * **Die zweite Behauptung ist die, auf die es ankommt.** `operationPrefix()`
     * entscheidet, ob eine Datenbank über `db.*` oder `pg.*` angefasst wird.
     * Gäben beide dasselbe zurück, liefe eine PostgreSQL-Datenbank in die
     * MariaDB-Operationen — und die scheiterten mit einer Meldung über SQL, in
     * der von der Ursache nichts steht.
     */
    public function test_each_engine_has_its_own_label_and_operation_prefix(): void
    {
        $labels = array_map(static fn (DatabaseEngine $e): string => $e->label(), DatabaseEngine::cases());
        $prefixes = array_map(static fn (DatabaseEngine $e): string => $e->operationPrefix(), DatabaseEngine::cases());

        $this->assertSame($labels, array_unique($labels), 'Zwei Systeme heissen in der Oberfläche gleich.');
        $this->assertSame($prefixes, array_unique($prefixes), 'Zwei Systeme benutzen dieselben Operationen.');

        $this->assertSame('db', DatabaseEngine::MariaDb->operationPrefix());
        $this->assertSame('pg', DatabaseEngine::Postgres->operationPrefix());
    }
}
