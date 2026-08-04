<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Operations\AfterOperation;
use App\Support\Operations\Lifecycles;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Hängt jeder Lebenslauf am Arbeiter?
 *
 * **Die Frage, die sich bis P2 nicht stellte.** Es gab genau einen, und der
 * Arbeiter rief ihn direkt auf. Mit P3 sind es zwei, und ab da gilt: Wer einen
 * dritten schreibt und {@see Lifecycles::HANDLERS} nicht anfasst, bekommt
 * einen Vorgang, der durchläuft, einen Agenten, der seine Arbeit tut — und ein
 * Panel, in dem sich nichts ändert. Ohne Fehler, ohne Meldung, ohne Spur.
 *
 * Das ist dasselbe Muster wie bei der Policy ohne Route und beim Kommando, das
 * im Startskript fehlt: eine Verbindung, die niemand prüft. Dieser Test prüft
 * sie in beide Richtungen — jede Umsetzung steht in der Liste, und jeder
 * Eintrag der Liste ist eine Umsetzung.
 */
final class LifecycleReachTest extends TestCase
{
    public function test_every_lifecycle_is_registered(): void
    {
        $found = [];

        foreach ($this->phpFiles(dirname(__DIR__, 2).'/app') as $path) {
            $class = $this->classIn($path);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isAbstract()) {
                continue;
            }

            if ($reflection->implementsInterface(AfterOperation::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        $registered = Lifecycles::HANDLERS;
        sort($registered);

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThanOrEqual(2, count($found), 'Es werden kaum Lebensläufe gefunden — dann prüft dieser Test nichts.');

        $this->assertSame($registered, $found, sprintf(
            "Diese Lebensläufe hängen nicht am Arbeiter (oder stehen dort, ohne welche zu sein):\n  %s\n\n".
            'Ein Vorgang läuft dann durch, ohne dass sich im Bestand etwas ändert.',
            implode("\n  ", array_diff(array_merge($found, $registered), array_intersect($found, $registered))),
        ));
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Der vollständige Klassenname aus Namensraum und Klassenzeile. */
    private function classIn(string $path): ?string
    {
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        return trim($namespace[1]).'\\'.$class[1];
    }
}
