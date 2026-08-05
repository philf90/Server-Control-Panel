<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer paginiert, muss auch blättern lassen.
 *
 * **Der Befund, der diesen Test ausgelöst hat.** Vier Controller riefen
 * `paginate()` auf, und **keine einzige Seite zeigte einen Weg zur zweiten.**
 * Vom Protokoll waren 76 Einträge da und 50 zu sehen; von den Vorgängen — der
 * Liste, die man ansieht, wenn etwas nicht stimmt, und die am schnellsten
 * wächst — ebenso. Kein Fehler, keine Meldung, nur eine Liste, die nach
 * fünfzig Zeilen aufhört.
 *
 * Drei der vier schickten die Seitenzahlen nicht einmal mit; beim vierten
 * kamen sie an und die Seite warf sie weg. Zwei weitere Verzeichnisse —
 * Abonnements und Pläne — paginierten gar nicht und wuchsen ohne Grenze.
 *
 * Das ist wieder der Fehler, gegen den dieses Projekt seine Wächter baut: eine
 * Zusage auf der einen Seite (`paginate`), der auf der anderen nichts
 * entspricht. Er war ein Jahr alt und aufgefallen ist er beim Ansehen einer
 * Aufnahme.
 *
 * Geprüft werden drei Dinge, und die dritte ist die, die gefehlt hat:
 *
 *   1. Jede Paginierung geht durch `Page::from()` — sonst schickt jeder
 *      Controller ein anderes Feld.
 *   2. Jede Paginierung behält ihre Abfrage (`withQueryString`) — sonst
 *      verliert „Weiter" die eingestellten Filter.
 *   3. **Jede Seite, die eine paginierte Nutzlast bekommt, zeigt den Pager.**
 */
final class PaginationTest extends TestCase
{
    /** @return list<string> */
    private function controllers(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Http/Controllers', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(4, count($files), 'Es werden kaum Controller gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    private function withoutComments(string $php): string
    {
        return (string) preg_replace(['#/\*.*?\*/#su', '#//[^\n]*#'], '', $php);
    }

    public function test_every_pagination_goes_through_the_page_helper(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->controllers() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            $paginates = preg_match_all('/->paginate\(/', $source);

            if ($paginates === 0) {
                continue;
            }

            $checked += $paginates;

            /*
             * Gezählt und nicht nur „kommt vor": Ein Controller mit zwei
             * paginierten Listen und einem `Page::from()` hätte sonst
             * bestanden — und die zweite Liste schickte weiter ihre eigene
             * Nutzlast.
             */
            $wrapped = preg_match_all('/Page::from\(/', $source);

            if ($wrapped < $paginates) {
                $found[] = sprintf(
                    '%s: %d× paginate(), aber nur %d× Page::from()',
                    $this->relative($path),
                    $paginates,
                    $wrapped,
                );
            }
        }

        $this->assertGreaterThan(3, $checked, 'Es wird kaum paginiert — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Controller bauen ihre Seitennutzlast selbst:\n  %s\n\n".
            'Sie entsteht in App\Support\Web\Page und sonst nirgends. Vorher schickte das Protokoll '.
            "vier Felder und Kunden, Domains und Vorgänge zwei — und die Oberfläche konnte bei\n".
            'dreien gar nicht blättern, weil die Seitenzahlen nie ankamen.',
            implode("\n  ", $found),
        ));
    }

    public function test_every_pagination_keeps_its_query_string(): void
    {
        $found = [];

        foreach ($this->controllers() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            $paginates = preg_match_all('/->paginate\(/', $source);

            if ($paginates === 0) {
                continue;
            }

            if (preg_match_all('/->withQueryString\(\)/', $source) < $paginates) {
                $found[] = $this->relative($path);
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Controller paginieren ohne withQueryString():\n  %s\n\n".
            'Die Filter des Protokolls stehen als Abfrage in der Adresse. Ohne diesen Aufruf trägt '.
            "der Verweis auf Seite 2 sie nicht mit:\nman filtert auf „fehlgeschlagen\", blättert ".
            'weiter und steht wieder in der ungefilterten Liste.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und jede Seite, die eine paginierte Nutzlast bekommt, zeigt den Pager.
     *
     * **Das ist die Prüfung, die gefehlt hat.** Die beiden darüber hätte auch
     * ein aufmerksamer Blick in den Controller ersetzt. Diese hier springt über
     * die Sprachgrenze: Sie liest, welche Inertia-Seite ein `Page::from()` in
     * ihrer Nutzlast hat, und sieht in der zugehörigen `.vue` nach. Genau an
     * dieser Naht ist der Fehler entstanden — der Controller war richtig, die
     * Seite ignorierte ihn, und beide Seiten für sich sahen in Ordnung aus.
     */
    public function test_every_paginated_page_renders_the_pager(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->controllers() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            // Ein `Inertia::render('X/Y', [ … ])` samt seiner Nutzlast. Der
            // Ausdruck endet an der nächsten `render`-Anweisung oder am Ende
            // der Datei — genug, um zu sehen, was in dieser Nutzlast steht.
            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'(.*?)(?=Inertia::render\(|\z)/su",
                $source,
                $renders,
                PREG_SET_ORDER,
            );

            foreach ($renders as $render) {
                if (! str_contains($render[2], 'Page::from(')) {
                    continue;
                }

                $checked++;
                $page = dirname(__DIR__, 2).'/resources/js/Pages/'.$render[1].'.vue';

                if (! is_file($page)) {
                    $found[] = sprintf('%s: es gibt keine Seite %s.vue', $this->relative($path), $render[1]);

                    continue;
                }

                if (! str_contains((string) file_get_contents($page), '<Pager')) {
                    $found[] = sprintf(
                        '%s bekommt eine paginierte Liste aus %s, zeigt aber keinen <Pager>',
                        'resources/js/Pages/'.$render[1].'.vue',
                        $this->relative($path),
                    );
                }
            }
        }

        $this->assertGreaterThan(3, $checked, 'Es wird kaum paginiert — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Hier hört eine Liste auf, ohne dass man weiterkommt:\n  %s\n\n".
            "Wer paginiert, muss auch blättern lassen. Ohne den Pager sind alle Zeilen ab der\n".
            'zweiten Seite unerreichbar — ohne Fehler, ohne Meldung, nur eine Liste, die aufhört.',
            implode("\n  ", $found),
        ));
    }
}
