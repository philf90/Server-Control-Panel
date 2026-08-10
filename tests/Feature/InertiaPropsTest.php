<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Jede Eigenschaft, die eine Seite verlangt, wird ihr auch geschickt.
 *
 * **Der Anlass ist ein Fehler, den eine Aufnahme gefunden hat und kein Test
 * finden konnte.** `Databases/Index.vue` liest seit Schritt 7
 * `props.shows_engine`, und `DatabaseController::index()` hat die Angabe nie
 * mitgeschickt. In JavaScript ist `undefined` falsch — die Spalte „System"
 * blieb also **immer** aus. Auf `cloudsrv24` stand am 10. August 2026 eine
 * PostgreSQL-Datenbank in der Liste, ohne dass irgendwo stand, dass sie eine
 * ist (`docs/39`, Punkt 4).
 *
 * **Es ist der Musterfehler dieses Projekts, an einer neuen Stelle:** *eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft.* Und diesmal liegt er genau zwischen den Werkzeugen:
 * `vue-tsc` prüft die Vorlage gegen ihre eigene Deklaration, PHPStan prüft das
 * Feld im Steuerungscode — **die Brücke dazwischen ist eine Zeichenkette in
 * einem Array**, und die sieht keiner von beiden.
 *
 * {@see InertiaPagesTest} prüft die andere Hälfte derselben Brücke: dass es zum
 * Namen der Seite eine Datei gibt. Hier geht es um ihren Inhalt.
 *
 * ## Was geprüft wird und was nicht
 *
 * Verglichen werden die **Pflichteigenschaften** aus `defineProps<{…}>()` mit
 * den Schlüsseln, die ein `Inertia::render()` für diese Seite mitgibt. Eine
 * Eigenschaft mit `?` ist keine Pflicht und fällt heraus; was
 * `HandleInertiaRequests::share()` für alle Seiten beisteuert, ebenso.
 *
 * **Verschachtelte Felder prüft er nicht.** Ob `databases.data[0].engine_label`
 * ankommt, sagt erst die laufende Anwendung — hier geht es um die oberste Ebene,
 * und dort sass der Fehler.
 */
final class InertiaPropsTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Schlüssel auf der obersten Ebene eines Literals.
     *
     * **Klammern zählen statt Zeilen lesen.** Ein Ausdruck über die ganze Datei
     * fände auch Schlüssel aus verschachtelten Feldern — und meldete dann
     * `Vorhanden`, wo nichts vorhanden ist. Gezählt wird deshalb ab der
     * öffnenden Klammer, und gesammelt wird nur, was auf Tiefe eins steht.
     *
     * @return list<string>
     */
    private function topLevelKeys(string $source, int $open, string $pattern): array
    {
        $depth = 0;
        $keys = [];
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if (str_contains('([{', $char)) {
                $depth++;

                continue;
            }

            if (str_contains(')]}', $char)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth === 1 && preg_match($pattern, substr($source, $i, 64), $found) === 1) {
                $keys[] = $found[1].($found[2] ?? '');
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Was jede Seite ohnehin bekommt.
     *
     * @return list<string>
     */
    private function shared(): array
    {
        $middleware = (string) file_get_contents(
            $this->root().'/app/Http/Middleware/HandleInertiaRequests.php'
        );

        preg_match_all("/^ {12}'([a-z_]+)' =>/m", $middleware, $found);

        // `errors` und `flash` kommen von Inertia und Laravel selbst.
        return array_values(array_merge($found[1], ['errors', 'flash', 'status']));
    }

    public function test_every_page_gets_the_props_it_declares(): void
    {
        $shared = $this->shared();

        // Die Untergrenze: Ohne sie liefe der Test grün, wenn der Ausdruck über
        // die Middleware ins Leere zeigt — und meldete dann jede geteilte
        // Eigenschaft als fehlend.
        $this->assertContains('account', $shared, 'Die geteilten Eigenschaften werden nicht mehr gefunden.');

        $missing = [];
        $checked = 0;

        foreach ($this->controllers() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/Inertia::render\(\s*'([^']+)'\s*,\s*\[/", $source, $renders, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($renders as $render) {
                $page = (string) $render[1][0];
                $vue = $this->root().'/resources/js/Pages/'.$page.'.vue';

                if (! is_file($vue)) {
                    // Dass es die Datei gibt, prüft InertiaPagesTest — zwei
                    // Fassungen derselben Regel wären eine zu viel.
                    continue;
                }

                $declared = $this->declaredProps($vue);

                if ($declared === null) {
                    continue;
                }

                $checked++;

                $supplied = $this->topLevelKeys(
                    $source,
                    (int) $render[0][1] + strlen((string) $render[0][0]) - 1,
                    "/^'([A-Za-z_0-9]+)'()\s*=>/",
                );

                foreach (array_diff($declared, $supplied, $shared) as $prop) {
                    $missing[] = sprintf(
                        '%s verlangt „%s" — %s schickt es nicht mit.',
                        $page,
                        $prop,
                        basename($file),
                    );
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'Es werden kaum noch Seiten geprüft — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $missing, sprintf(
            "Diese Seiten lesen Eigenschaften, die ihnen niemand schickt:\n\n  %s\n\n".
            'In JavaScript ist `undefined` falsch: Eine fehlende Angabe blendet aus, statt zu '.
            'scheitern. Genau so blieb die Spalte „System" in der Datenbankliste immer leer.',
            implode("\n  ", $missing),
        ));
    }

    /**
     * Die Pflichteigenschaften einer Seite.
     *
     * Kommentare fallen vorher weg: In diesem Projekt steht in jedem zweiten
     * `defineProps` ein Absatz Prosa, und `Absicht:` sähe darin aus wie eine
     * Eigenschaft.
     *
     * @return list<string>|null `null`, wenn die Seite gar keine erklärt
     */
    private function declaredProps(string $vue): ?array
    {
        $source = (string) file_get_contents($vue);
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#//[^\n]*#', '', $source);

        if (preg_match('/defineProps<\{/', $source, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $keys = $this->topLevelKeys(
            $source,
            (int) $found[0][1] + strlen((string) $found[0][0]) - 1,
            "/^\n\s*([a-zA-Z_][a-zA-Z_0-9]*)(\??)\s*:/",
        );

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ! str_ends_with($key, '?'),
        ));
    }

    /**
     * Jede Datei, die eine Seite rendern könnte.
     *
     * Gesucht wird unter `app/` und nicht nur in `Http/Controllers`: Ein
     * `Inertia::render()` steht heute nur dort, und ein Wächter, der sich darauf
     * verlässt, wäre beim ersten Umzug still.
     *
     * @return list<string>
     */
    private function controllers(): array
    {
        $files = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
