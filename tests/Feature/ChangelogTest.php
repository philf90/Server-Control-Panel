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
 *
 * **Die Brüche zu {@see self::test_every_named_class_exists()} stehen ebenfalls
 * nicht im Skript**, aus demselben Grund wie die zu {@see self::REMOVED}: Sie
 * ändern `CHANGELOG.md`, und `wiederherstellen()` fasst die Datei nicht an. Von
 * Hand also, und beide Richtungen, weil der Fall seit Lauf 463 zwei hat:
 *
 *     # 1 — ein Namensraum, den es nicht gibt
 *     #     `App\Support\Databases\Engines\` → `App\Support\Databases\Motoren\`
 *     #     erwartet: rot mit „nennt den Namensraum … das Verzeichnis"
 *     # 2 — eine Klasse, den es nicht gibt (der abschliessende \ fällt weg)
 *     #     `App\Support\Databases\Engines\` → `App\Support\Databases\Engines`
 *     #     erwartet: rot mit „nennt … die Datei dazu gibt es nicht"
 *     git checkout -- CHANGELOG.md
 *
 * Am 9. August 2026 beide gefahren, beide rot, danach wieder grün.
 */
final class ChangelogTest extends TestCase
{
    /**
     * Tests, die es gab und nicht mehr gibt — mit dem Eintrag, der sie nennt.
     *
     * **Der Anlass ist ein Eintrag, den es geben muss.** Der Changelog ist der
     * Ort, an dem steht, *was vorher falsch war*; ein Beitrag, der etwas
     * zurücknimmt, muss das Zurückgenommene benennen können. Bis hierher
     * verlangte die Prüfung unten, dass jeder genannte Test **existiert** — und
     * damit war die eine Sorte Eintrag unmöglich, die am meisten erklärt.
     *
     * Ohne diese Liste blieben zwei Auswege, und beide sind schlechter: den Test
     * im Fliesstext ohne Rückstriche zu nennen (dann greift der Ausdruck nicht,
     * und der Wächter ist umgangen statt erweitert), oder ihn zu umschreiben
     * (dann findet ihn niemand mehr in der Historie).
     *
     * **Wer hier einträgt, schreibt dazu, wann und warum.** Der Eintrag ist das,
     * was jemand liest, der dem Namen im Changelog folgt und ihn unter `tests/`
     * nicht findet.
     *
     * **Die zwei Brüche dazu stehen nicht in `tests/waechter-brechen.sh`.** Sie
     * müssten diese Datei ändern, und `wiederherstellen()` fasst `tests/` nicht
     * an — das Verzeichnis nachzutragen ginge nicht, weil das Skript selbst
     * darin liegt. Von Hand also, und so:
     *
     *     # 1 — der Eintrag fehlt, der Changelog nennt den Test trotzdem
     *     #     erwartet: test_every_named_test_exists wird rot
     *     # 2 — ein zweiter Eintrag für einen Test, den es gibt
     *     #     erwartet: test_the_list_of_removed_tests_does_not_outlive_them wird rot
     *     git checkout -- tests/Feature/ChangelogTest.php
     *
     * Am 8. August 2026 beide gefahren, beide rot, danach wieder grün.
     *
     * @var array<string, string>
     */
    private const REMOVED = [
        'ButtonRowSpacingTest' => 'Am 13. August 2026 zu BlockSpacingTest geworden (P5c Schritt 6). '
            .'Er fragte nach Knopfreihen — die Frage der ersten vier Fälle derselben Fuge und '
            .'trotzdem die falsche: Beim fünften stand unter der Blätterleiste eine Meldung, und er '
            .'sah nichts. Der Nachfolger fragt, ob der eine Baustein bündig endet und der andere '
            .'bündig anfängt, und prüft alle Paare daraus. Der alte Name bleibt in CHANGELOG.md und '
            .'docs/46 stehen, weil dort die Geschichte der vier Fälle erzählt wird.',

        'SecretsStayOutOfTheStoreTest' => 'Beim Bauen in SecretsStayOutOfTheQueueTest aufgegangen '
            .'(P5): Die Regel lebt dort als test_the_database_tables_have_no_place_for_a_secret(). '
            .'Der Name blieb in einem Kommentar der Migration und im Plan stehen und zeigte ins '
            .'Leere — gefunden am 8. August 2026 von GuardReachTest, der genau dafür entstand.',
    ];

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
            if (array_key_exists($name, self::REMOVED)) {
                continue;
            }

            $this->assertArrayHasKey(
                $name.'.php',
                $files,
                sprintf(
                    'Der Changelog nennt %s; eine Datei dieses Namens gibt es unter tests/ nicht. '
                    .'Wurde der Test entfernt, gehört er mit Datum und Grund in '
                    .'ChangelogTest::REMOVED — ihn im Fliesstext ohne Rückstriche zu nennen wäre '
                    .'kein Ausweg, sondern eine Umgehung dieses Wächters.',
                    $name,
                ),
            );
        }
    }

    /**
     * Und die Gegenrichtung: Die Liste läuft nicht mit.
     *
     * **Dieselbe Falle, die dieses Projekt dreimal erwischt hat**, nur in einer
     * Ausnahmeliste statt in einem Zähler: Ein Eintrag, der einen Test nennt,
     * den es wieder gibt, weicht die Prüfung für ihn dauerhaft auf — und
     * gemerkt hätte es niemand, denn `REMOVED` steht auf der grünen Seite.
     *
     * **Und sie hat zugebissen.** `UploadLimitTest` stand hier, weil er mit dem
     * Hochladen zurückgenommen wurde; als Schritt 11 ihn zurückbrachte, meldete
     * diese Zeile den Eintrag — genau wie angekündigt, in der CI und nicht
     * Monate später (`docs/36 §22.3u`).
     */
    public function test_the_list_of_removed_tests_does_not_outlive_them(): void
    {
        $files = $this->filesUnder('tests', 'php');

        foreach (array_keys(self::REMOVED) as $name) {
            $this->assertArrayNotHasKey(
                $name.'.php',
                $files,
                sprintf(
                    '%s steht in ChangelogTest::REMOVED und gibt es wieder. Der Eintrag gehört '
                    .'entfernt, sonst ist der Test dauerhaft von der Prüfung ausgenommen.',
                    $name,
                ),
            );
        }
    }

    public function test_every_named_class_exists(): void
    {
        /*
         * `App\Support\Plans\Quota` und Verwandte. Ein angehängtes `::methode`
         * wird abgeschnitten — geprüft wird die Datei, nicht die Signatur.
         *
         * **Der abschliessende Backslash wird mitgefangen, und das ist der
         * ganze Unterschied zwischen einer Klasse und einem Namensraum.**
         * `App\Support\Databases\Engines\` ist die übliche Schreibweise für
         * „alles unter diesem Namen" und hat Lauf 463 rot gemacht: Der
         * Ausdruck sah eine Klasse, suchte `Engines.php` und fand ein
         * Verzeichnis. Den Text stattdessen umzuschreiben hiesse, eine Regel
         * einzuführen, die nirgends steht — man darf im Changelog keinen
         * Namensraum nennen — und die der nächste Beitrag wieder bricht.
         */
        preg_match_all('/`(App(?:\\\\[A-Z][A-Za-z0-9]*)+)(\\\\)?/', $this->changelog(), $matches, PREG_SET_ORDER);

        $named = [];

        foreach ($matches as $match) {
            // Der Schlüssel trägt beides. Derselbe Name kann als Klasse und als
            // Namensraum vorkommen — dann sind es zwei Prüfungen und nicht die,
            // die zufällig zuletzt kam.
            $named[$match[1].($match[2] ?? '')] = [$match[1], ($match[2] ?? '') === '\\'];
        }

        $this->assertGreaterThanOrEqual(3, count($named), 'Der Ausdruck findet keine Klassenverweise mehr.');

        foreach ($named as [$name, $isNamespace]) {
            $relative = 'app/'.str_replace('\\', '/', substr($name, 4));

            if ($isNamespace) {
                $this->assertDirectoryExists(
                    $this->root().'/'.$relative,
                    "Der Changelog nennt den Namensraum {$name}; das Verzeichnis dazu gibt es nicht.",
                );

                continue;
            }

            $this->assertFileExists(
                $this->root().'/'.$relative.'.php',
                "Der Changelog nennt {$name}; die Datei dazu gibt es nicht.",
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
