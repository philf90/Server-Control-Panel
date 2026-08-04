<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Gibt es zu jeder gerenderten Seite auch eine Datei — und findet der Browser sie?
 *
 * **Warum es diesen Test gibt.** `Inertia::render('Operations/Index')` nennt
 * eine Datei über eine Zeichenkette. Stimmt sie nicht, merkt das niemand:
 * PHPStan sieht eine Zeichenkette, vue-tsc sieht die Zeichenkette gar nicht,
 * und `assertInertia(->component(…))` prüft, dass der Server diesen Namen
 * *meldet* — nicht, dass es dazu etwas zu zeigen gibt.
 *
 * **Und warum die zweite Prüfung.** `import.meta.glob` auf ein Verzeichnis,
 * das es nicht gibt, ist kein Fehler, sondern ein leeres Ergebnis. Das
 * Verzeichnis der Seiten hiess einmal `Seiten` und heisst jetzt `Pages`; die
 * Zeile in app.ts blieb stehen. Der Build lief durch, war um jede Seite
 * leichter, und die Anwendung war im Browser vollständig unbenutzbar — jede
 * Seite endete in „Seite … gibt es nicht". Aufgefallen ist es beim Nachsehen,
 * ob eine neue Beschriftung im Bündel angekommen ist; kein Test hat es
 * gemeldet. Dieser tut es.
 */
final class InertiaPagesTest extends TestCase
{
    private const PAGES = 'resources/js/Pages';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_the_browser_looks_where_the_pages_are(): void
    {
        $app = (string) file_get_contents($this->root().'/resources/js/app.ts');

        $found = preg_match("#import\.meta\.glob<[^>]*>\('\./([A-Za-z]+)/\*\*/\*\.vue'#", $app, $matches);

        $this->assertSame(1, $found, 'In app.ts steht kein erkennbares Muster für die Seiten.');

        $directory = 'resources/js/'.$matches[1];

        $this->assertSame(
            self::PAGES,
            $directory,
            sprintf(
                'app.ts sucht die Seiten in %s, sie liegen aber in %s. '.
                'Ein leeres Glob ist kein Build-Fehler — die Seiten fehlen dann einfach im Bündel.',
                $directory,
                self::PAGES,
            ),
        );
    }

    public function test_every_rendered_page_exists_as_a_file(): void
    {
        $missing = [];

        foreach ($this->renderedNames() as $name => $where) {
            if (! is_file($this->root().'/'.self::PAGES.'/'.$name.'.vue')) {
                $missing[] = sprintf('%s (%s)', $name, $where);
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Zu diesen Seiten gibt es keine Datei:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Alle Namen aus `Inertia::render('…')` samt Fundstelle.
     *
     * @return array<string,string>
     */
    private function renderedNames(): array
    {
        $names = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root().'/app', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all("#Inertia::render\(\s*'([^']+)'#", $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $names[$name] = $file->getBasename();
            }
        }

        // Findet der Test nichts, ist er kaputt und nicht die Anwendung.
        $this->assertNotSame([], $names, 'Kein einziges Inertia::render gefunden.');

        return $names;
    }

    /**
     * Die Erfolgsmeldung steht im Gerüst — und nur dort.
     *
     * **Warum das geprüft wird.** Bis August 2026 brachte jede Seite sie
     * selbst mit, und drei von zwanzig taten es; der Rest verschluckte sie.
     * Deshalb zog sie ins Gerüst. Eine Seite blieb dabei stehen: „Mein Konto"
     * behielt ihre eigene, und wer dort etwas speicherte, bekam die Bestätigung
     * **zweimal untereinander**. Gesehen im Browser, als eine neue Einstellung
     * dazukam — sechs Monate hätte es sonst niemand bemerkt, weil zwei richtige
     * Meldungen nicht falsch aussehen, nur doppelt.
     *
     * Der Test hängt an `flash.success` und nicht an einer Klasse: Wer die
     * Meldung unter anderem Namen wieder einbaut, liest trotzdem dieselbe
     * Quelle.
     */
    public function test_only_the_frame_shows_the_success_message(): void
    {
        $seiten = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root().'/resources/js/Pages', \FilesystemIterator::SKIP_DOTS),
        );

        $geprueft = 0;

        foreach ($seiten as $datei) {
            if (! $datei instanceof \SplFileInfo || $datei->getExtension() !== 'vue') {
                continue;
            }

            $geprueft++;

            $this->assertSame(
                0,
                preg_match('/flash[^\n]*success/', (string) file_get_contents($datei->getPathname())),
                sprintf(
                    '%s zeigt die Erfolgsmeldung selbst. Sie steht in PanelLayout.vue — '.
                    'sonst erscheint sie zweimal.',
                    $datei->getBasename(),
                ),
            );
        }

        $this->assertGreaterThan(8, $geprueft, 'Es werden kaum Seiten gelesen — dann prüft dieser Test nichts.');
    }
}
