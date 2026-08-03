<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Der Changelog zeigt auf Dinge — zeigt er auf welche, die es gibt?
 *
 * Er nennt Dokumente (`docs/22`), Tests (`PackagingTest`), Klassen
 * (`App\Support\Plans\Quota`) und Komponenten (`EyeIcon.vue`). Das sind
 * Zeichenketten in einer Markdown-Datei: Niemand prüft sie, kein Werkzeug
 * meldet sie, und wenn eine Datei umbenannt wird, bleibt der Verweis stehen.
 *
 * Das ist genau das Muster, das dieses Projekt schon mehrfach eingeholt hat —
 * der Aufgabenkatalog, der auf eine verschwundene Prüfung verwies; das Glob,
 * das in ein umbenanntes Verzeichnis sah; die Unit, die ein Kommando aufrief,
 * das es nicht mehr gab. Ein Changelog, der auf `docs/24` verweist, ist kein
 * Betriebsfehler; er ist eine Einladung, an einer Stelle zu suchen, die es
 * nicht gibt.
 *
 * **Und die Gegenrichtung:** Ein Ausdruck, der nichts findet, ist kein
 * bestandener Test. Jede Prüfung sagt deshalb auch, wie viele Verweise sie
 * gesehen hat.
 */
final class ChangelogTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function changelog(): string
    {
        return (string) file_get_contents($this->root().'/CHANGELOG.md');
    }

    /**
     * Alle Dateien unter einem Verzeichnis, nach Namen abgelegt.
     *
     * @return array<string, string>
     */
    private function filesUnder(string $directory, string $extension): array
    {
        $path = $this->root().'/'.$directory;

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[$file->getFilename()] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_every_referenced_document_exists(): void
    {
        preg_match_all('/`?docs\/(\d{2})/', $this->changelog(), $matches);

        $numbers = array_unique($matches[1]);

        $this->assertGreaterThanOrEqual(4, count($numbers), 'Der Ausdruck findet keine Dokumentverweise mehr.');

        foreach ($numbers as $number) {
            $this->assertNotSame(
                [],
                glob($this->root().'/docs/'.$number.'-*.md') ?: [],
                "Der Changelog verweist auf docs/{$number}, dieses Dokument gibt es nicht.",
            );
        }
    }

    public function test_every_named_test_exists(): void
    {
        preg_match_all('/`([A-Z][A-Za-z]*Test)`/', $this->changelog(), $matches);

        $names = array_unique($matches[1]);
        $files = $this->filesUnder('tests', 'php');

        $this->assertGreaterThanOrEqual(4, count($names), 'Der Ausdruck findet keine Testverweise mehr.');

        foreach ($names as $name) {
            $this->assertArrayHasKey(
                $name.'.php',
                $files,
                "Der Changelog nennt {$name}; eine Datei dieses Namens gibt es unter tests/ nicht.",
            );
        }
    }

    public function test_every_named_class_exists(): void
    {
        // `App\Support\Plans\Quota` und Verwandte. Ein angehängtes `::methode`
        // wird abgeschnitten — geprüft wird die Datei, nicht die Signatur.
        preg_match_all('/`(App(?:\\\\[A-Z][A-Za-z0-9]*)+)/', $this->changelog(), $matches);

        $classes = array_unique($matches[1]);

        $this->assertGreaterThanOrEqual(3, count($classes), 'Der Ausdruck findet keine Klassenverweise mehr.');

        foreach ($classes as $class) {
            $relative = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';

            $this->assertFileExists(
                $this->root().'/'.$relative,
                "Der Changelog nennt {$class}; die Datei dazu gibt es nicht.",
            );
        }
    }

    public function test_every_named_component_exists(): void
    {
        preg_match_all('/`([A-Z][A-Za-z]*\.vue)`/', $this->changelog(), $matches);

        $names = array_unique($matches[1]);
        $files = $this->filesUnder('resources/js', 'vue');

        $this->assertGreaterThanOrEqual(2, count($names), 'Der Ausdruck findet keine Komponentenverweise mehr.');

        foreach ($names as $name) {
            $this->assertArrayHasKey(
                $name,
                $files,
                "Der Changelog nennt {$name}; diese Komponente gibt es nicht.",
            );
        }
    }

    public function test_every_named_unit_and_script_exists(): void
    {
        // `srvpanel-metrics.service`, `packaging/testbed.sh` — die Dateien, die
        // ein Betreiber nach dem Lesen tatsächlich aufruft.
        preg_match_all('/`(packaging\/[A-Za-z0-9._\/-]+)`/', $this->changelog(), $scripts);
        preg_match_all('/`(srvpanel-[a-z]+\.service)`/', $this->changelog(), $units);

        $paths = array_unique($scripts[1]);
        $unitNames = array_unique($units[1]);

        $this->assertGreaterThanOrEqual(1, count($paths) + count($unitNames), 'Der Ausdruck findet keine Dateiverweise mehr.');

        foreach ($paths as $path) {
            $this->assertFileExists($this->root().'/'.$path, "Der Changelog nennt {$path}; die Datei gibt es nicht.");
        }

        foreach ($unitNames as $unit) {
            $this->assertFileExists(
                $this->root().'/packaging/systemd/'.$unit,
                "Der Changelog nennt die Unit {$unit}; die Datei gibt es nicht.",
            );
        }
    }
}
