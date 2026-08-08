<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Ein Wächter, auf den sich ein Kommentar beruft, existiert auch.
 *
 * **Der Anlass ist ein Versprechen, das niemand eingelöst hat.** In
 * `DatabaseController::store()` stand seit P5: *„Die Gegenprobe, dass beide
 * dasselbe sagen, steht in `DatabaseFormTest`."* Diese Datei gab es nicht. Der
 * Kommentar las sich wie eine Absicherung, war aber eine Behauptung — und wer
 * ihn liest, hört auf zu suchen. Gefunden am 8. August 2026 auf dem Umweg über
 * eine Frage des Betreibers, nicht durch ein Werkzeug.
 *
 * Es ist genau das Muster aus CLAUDE.md: *eine Zeichenkette, die auf etwas
 * verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft.*
 * Diesmal verweist sie auf einen Wächter, und das macht sie teurer als die
 * anderen: Ein toter Verweis auf eine Klasse fällt beim nächsten Aufruf auf,
 * einer auf einen Test niemals.
 *
 * **Beim ersten Lauf waren es drei.** `DatabaseFormTest` (nie geschrieben),
 * `DbTenancyTest` (im Plan §16.7 vorgesehen, nie geschrieben — die Hälfte des
 * Abnahmekriteriums, die im Panel spielt) und `SecretsStayOutOfTheStoreTest`
 * (die Regel lebt, sie steht als Methode in `SecretsStayOutOfTheQueueTest`; nur
 * der Name im Kommentar war stehengeblieben).
 *
 * **Die Ausnahme kommt aus `ChangelogTest::REMOVED`** und nicht aus einer Liste
 * hier: Dort stehen die Tests, die es absichtlich nicht mehr gibt, mit
 * Begründung. Zwei Listen für dieselbe Auskunft wären eine zu viel.
 */
final class GuardReachTest extends TestCase
{
    /** Wo Kommentare stehen, die sich auf Wächter berufen. */
    private const ROOTS = ['app', 'agent/src', 'database', 'routes', 'config', 'tests'];

    public function test_every_test_named_in_the_code_exists(): void
    {
        $existing = $this->existingTests();
        $removed = $this->removedTests();

        $this->assertGreaterThan(100, count($existing), 'Es werden kaum Tests gefunden — dann prüft dieser Test nichts.');

        $missing = [];
        $mentioned = 0;

        foreach ($this->mentions() as $name => $paths) {
            $mentioned++;

            if (in_array($name, $existing, true) || in_array($name, $removed, true)) {
                continue;
            }

            $missing[] = sprintf('%s — genannt in %s', $name, implode(', ', $paths));
        }

        $this->assertGreaterThan(50, $mentioned, 'Es werden kaum Nennungen gefunden.');

        $this->assertSame([], $missing, sprintf(
            "Diese Wächter werden im Code genannt und gibt es nicht:\n\n  %s\n\n".
            'Entweder der Test fehlt — dann ist der Kommentar ein Versprechen und keine '.
            'Absicherung —, oder er heisst inzwischen anders. Ein Test, den es absichtlich '.
            'nicht mehr gibt, gehört mit Begründung in ChangelogTest::REMOVED.',
            implode("\n  ", $missing),
        ));
    }

    /**
     * Jeder Testname, der irgendwo im Code steht, mit seinen Fundstellen.
     *
     * @return array<string, list<string>>
     */
    private function mentions(): array
    {
        $found = [];

        foreach (self::ROOTS as $root) {
            foreach ($this->phpFiles(dirname(__DIR__, 2).'/'.$root) as $path) {
                $source = (string) file_get_contents($path);

                preg_match_all('/\b([A-Z][A-Za-z0-9]*Test)\b/', $source, $matches);

                foreach (array_unique($matches[1]) as $name) {
                    $found[$name][] = basename($path);
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> */
    private function existingTests(): array
    {
        $names = [];

        foreach ($this->phpFiles(dirname(__DIR__)) as $path) {
            $names[] = basename($path, '.php');
        }

        return $names;
    }

    /**
     * Die Tests, die es absichtlich nicht mehr gibt.
     *
     * @return list<string>
     */
    private function removedTests(): array
    {
        $removed = (new ReflectionClass(ChangelogTest::class))->getConstant('REMOVED');

        return is_array($removed) ? array_map(strval(...), array_keys($removed)) : [];
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $found = [];

        if (! is_dir($root)) {
            return $found;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
