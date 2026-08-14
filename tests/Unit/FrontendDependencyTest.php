<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Welche Frontend-Abhängigkeit es gibt, und warum.
 *
 * **Bis zum 14. August 2026 gab es keine, und das war nirgends geprüft.** Es
 * war eine Selbstverständlichkeit — `docs/20 §4.6` begründet für die
 * Kennzahlen, warum keine Diagramm-Bibliothek hereinkommt, und niemand ist je
 * auf die Idee gekommen, eine andere hinzuzufügen. Genau das macht eine
 * Selbstverständlichkeit gefährlich:
 *
 * > **Eine Regel, die nie jemand gebrochen hat, sieht aus wie eine Regel und
 * > ist eine Gewohnheit.** Sobald die erste Ausnahme da ist, entscheidet die
 * > Gewohnheit über die zweite — und die zweite kommt ohne Entscheidung.
 *
 * Der Betreiber hat CodeMirror 6 für den Editor zugelassen (`docs/51 §3`,
 * Entscheidung 1). Ab hier ist die Liste eine Regel und braucht ihren Wächter.
 *
 * Geprüft werden die drei Auflagen aus `docs/51 §8.1`, jede an dem, was sie
 * tatsächlich bewirken soll — nicht an ihrem Vorhandensein.
 */
final class FrontendDependencyTest extends TestCase
{
    /**
     * Was in `package.json` stehen darf, und weshalb.
     *
     * **Mit Begründung je Eintrag, so wie `EngineReachTest` es für die
     * Datenbanksysteme tut.** Eine Liste ohne Begründung wächst, bis sie alles
     * enthält; eine mit Begründung zwingt den nächsten dazu, seine
     * hinzuschreiben — und dabei fällt auf, wenn es keine gibt.
     *
     * Präfixe, weil CodeMirror in ein Dutzend Pakete zerfällt, die zusammen
     * eine Entscheidung sind und nicht zwölf.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Das Gerüst. Es ist keine „Abhängigkeit" im Sinn dieser Regel,
        // sondern das, worin dieses Panel geschrieben ist.
        '@inertiajs/' => 'das Gerüst der Oberfläche',
        'vue' => 'das Gerüst der Oberfläche',
        '@vitejs/' => 'Bauwerkzeug',
        'vite' => 'Bauwerkzeug',
        'laravel-vite-plugin' => 'Bauwerkzeug',
        'vue-tsc' => 'Bauwerkzeug',
        'typescript' => 'Bauwerkzeug',
        'tailwindcss' => 'Bauwerkzeug — die Marken stehen in app.css',
        '@tailwindcss/' => 'Bauwerkzeug',
        'concurrently' => 'Bauwerkzeug',
        'autoprefixer' => 'Bauwerkzeug',
        'postcss' => 'Bauwerkzeug',
        '@types/' => 'nur Typen, nichts im Bündel',

        // **Die eine Ausnahme, entschieden am 14. August 2026.**
        '@codemirror/' => 'der Editor (docs/51 §3, Entscheidung 1) — nachgeladen, Farben aus app.css',
        'codemirror' => 'der Editor (docs/51 §3, Entscheidung 1)',
        '@lezer/' => 'gehört zu CodeMirror',
    ];

    /** Die einzige Datei, die CodeMirror anfassen darf. */
    private const EDITOR = 'resources/js/Components/CodeEditor.vue';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * In `package.json` steht nichts, wofür niemand eine Begründung hat.
     */
    public function test_every_dependency_is_accounted_for(): void
    {
        /** @var array{dependencies?: array<string,string>, devDependencies?: array<string,string>} $manifest */
        $manifest = json_decode((string) file_get_contents($this->root().'/package.json'), true);

        $names = array_keys(($manifest['dependencies'] ?? []) + ($manifest['devDependencies'] ?? []));
        $unexplained = [];

        foreach ($names as $name) {
            $known = false;

            foreach (array_keys(self::ALLOWED) as $allowed) {
                if ($name === $allowed || str_starts_with($name, $allowed)) {
                    $known = true;

                    break;
                }
            }

            if (! $known) {
                $unexplained[] = $name;
            }
        }

        sort($unexplained);

        $this->assertSame([], $unexplained, implode("\n", [
            'In package.json steht eine Abhaengigkeit, die hier nicht begruendet ist.',
            'Dieses Projekt kam bis zum 14. August 2026 ohne jede aus; CodeMirror ist',
            'eine Entscheidung des Betreibers und keine Gewohnheit. Wer eine hinzufuegt,',
            'traegt sie mit ihrem Grund in ALLOWED ein — und holt vorher die Entscheidung ein.',
        ]));

        // Der Nachbar der leeren Liste: Ohne Abhängigkeiten prüft das oben nichts.
        $this->assertGreaterThan(5, count($names), 'package.json ist (fast) leer — dann prueft dieser Test nichts.');
    }

    /**
     * Auflage 1: CodeMirror wird an genau einer Stelle angefasst.
     */
    public function test_only_the_editor_component_touches_codemirror(): void
    {
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
                continue;
            }

            $relative = str_replace($this->root().'/', '', $file->getPathname());

            if ($relative === self::EDITOR) {
                continue;
            }

            if (preg_match('/@codemirror\/|@lezer\/|[\'"]codemirror[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'CodeMirror wird ausserhalb von '.self::EDITOR.' angefasst.',
            'Die Abhaengigkeit ist auf eine Seite begrenzt — wer sie an einer zweiten',
            'Stelle einbindet, zieht sie ins gemeinsame Buendel.',
        ]));
    }

    /**
     * Auflage 1, die andere Hälfte: Der Import ist **dynamisch**.
     *
     * **Ein statischer Import oben in der Datei wäre der ganze Unterschied.**
     * Er zieht die Bibliothek in das gemeinsame Bündel, und dann lädt jeder
     * Betrachter jeder Seite 624 KB mit, um sie nie zu benutzen. Die Datei sähe
     * dabei genauso aus wie jetzt — nur die Zeile stünde woanders.
     */
    public function test_codemirror_is_loaded_lazily(): void
    {
        $source = (string) file_get_contents($this->root().'/'.self::EDITOR);

        // `import(...)` als **Aufruf**, nicht `await import(...)`: Die
        // Bibliotheken werden in einem `Promise.all` geladen, das `await`
        // steht dort und nicht an jedem einzelnen. Der erste Entwurf dieses
        // Wächters verlangte das `await` daneben und war deshalb rot, obwohl
        // der Code stimmte.
        $this->assertMatchesRegularExpression(
            "/(?<!\\.)import\\(['\"]@codemirror\\/view['\"]\\)/",
            $source,
            'Der Editor laedt @codemirror/view nicht mehr dynamisch.',
        );

        // Und kein statischer daneben: `import … from '@codemirror/…'` am
        // Zeilenanfang ist genau die Form, die ins Bündel zieht.
        $this->assertDoesNotMatchRegularExpression(
            "/^\s*import\s+[^(]*from\s+['\"](@codemirror\/|@lezer\/|codemirror)/m",
            $source,
            implode("\n", [
                'Im Editor steht ein statischer Import von CodeMirror.',
                'Damit landet die Bibliothek im gemeinsamen Buendel, und jeder Betrachter',
                'jeder Seite laedt sie mit.',
            ]),
        );
    }

    /**
     * Auflage 2: Keine Farbe kommt aus der Bibliothek.
     *
     * Geprüft an beidem — kein Hexwert in der Komponente (das gilt hier wie
     * überall), und die Hervorhebung vergibt `class:` statt `color:`.
     */
    public function test_the_editor_brings_no_colours_of_its_own(): void
    {
        $source = (string) file_get_contents($this->root().'/'.self::EDITOR);

        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}\b/',
            $source,
            'Im Editor steht ein Hexwert. Jede Farbe kommt aus resources/css/app.css.',
        );

        $this->assertMatchesRegularExpression(
            "/class:\s*'tok-/",
            $source,
            'Die Hervorhebung vergibt keine Klassen mehr — dann kommen die Farben aus der Bibliothek.',
        );

        // Und die Klassen, die sie vergibt, haben drüben eine Regel. Das ist
        // dieselbe Zusage, die `ClassReachTest` für Templates gibt — hier
        // greift der nicht, weil die Namen im Skript stehen.
        preg_match_all("/class:\s*'(tok-[a-z]+)'/", $source, $matches);
        $css = (string) file_get_contents($this->root().'/resources/css/app.css');

        $this->assertNotSame([], $matches[1], 'Es werden keine Marken vergeben.');

        foreach (array_unique($matches[1]) as $mark) {
            $this->assertStringContainsString(
                '.'.$mark.' {',
                $css,
                sprintf('Die Marke %s bekommt in app.css keine Farbe — sie bleibt unsichtbar.', $mark),
            );
        }
    }

    /**
     * Auflage 3: Es geht auch ohne.
     *
     * Lädt das Bündel nicht, bleibt das `textarea` stehen. Ohne diesen Rückweg
     * hinge das Speichern einer `.htaccess` an einer Bibliothek — und ein
     * Kunde, dessen Netz sie nicht durchlässt, käme an seine Dateien nicht
     * heran.
     */
    public function test_there_is_a_way_without_the_library(): void
    {
        $source = (string) file_get_contents($this->root().'/'.self::EDITOR);

        $this->assertMatchesRegularExpression(
            '/<textarea/',
            $source,
            'Der Rueckweg ohne CodeMirror ist weg — faellt das Buendel aus, faellt der Editor aus.',
        );

        $this->assertMatchesRegularExpression(
            '/\}\s*catch\s*\{/',
            $source,
            'Ein Fehlschlag beim Nachladen wird nicht aufgefangen — dann bleibt die Seite leer.',
        );
    }
}
