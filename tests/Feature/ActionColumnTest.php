<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Eine Tabelle mit einer Aktionsspalte bekommt die ganze Zeile.
 *
 * **Der Anlass ist eine Seite, auf der man schieben musste, um einen Knopf zu
 * treffen.** Auf der Datenbankseite standen „Zugänge" und „Sicherungen" im
 * Grundriss, und beide tragen eine Spalte mit drei Knöpfen. Die Breite einer
 * solchen Tabelle ist die Summe ihrer Beschriftungen — gemessen am gebauten
 * Stylesheet 755px und 923px —, und `.scrolls > table` hält sie auf
 * `max-content`. Ein Bereich im Grundriss bekommt bei 1440px 548px. Der Rest
 * lag ausserhalb (`docs/36 §22.3s`).
 *
 * **Bemerkenswert war die Richtung des Fehlers:** Er wurde auf einem *breiteren*
 * Bildschirm schlimmer. Bei 1440px wich „Sicherungen" in eine eigene Zeile aus
 * und stand richtig; bei 1600px passten alle drei Bereiche nebeneinander, und
 * damit rollten beide Tabellen — 350px und 518px. Wer bei 1440px prüft, sieht
 * die Hälfte.
 *
 * **Warum die Regel an der Aktionsspalte hängt und nicht an der Spaltenzahl.**
 * Vier Spalten sind kein Maß: „Konten" beim Kunden hat vier und passt bequem,
 * weil in jeder Zelle ein Wort steht. Was die Breite erzwingt, sind Knöpfe —
 * ihre Beschriftung ist unverkürzbar, sie brechen nicht um, und sie sind der
 * einzige Inhalt einer Tabelle, den man *treffen* muss. Zum Lesen genügt
 * Schieben, zum Drücken nicht.
 *
 * Als dieser Test entstand, gab es im ganzen Panel genau zwei solche Tabellen,
 * und beide standen falsch. Die Regel ist damit keine Verallgemeinerung aus
 * einem Fall, sondern die Beschreibung des einzigen Falls, den es gibt.
 */
final class ActionColumnTest extends TestCase
{
    /** @return list<string> */
    private function pages(): array
    {
        $found = [];

        $verzeichnis = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js/Pages'),
        );

        foreach ($verzeichnis as $datei) {
            if ($datei instanceof \SplFileInfo && $datei->getExtension() === 'vue') {
                $found[] = $datei->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Jeder Bereich einer Seite: sein Eröffnungsstück und sein Inhalt.
     *
     * @return list<array{file: string, tag: string, body: string}>
     */
    private function sections(): array
    {
        $sections = [];

        foreach ($this->pages() as $path) {
            $source = (string) file_get_contents($path);

            // Der Inhalt eines Bereichs ist alles bis zu seinem Schluss. Bereiche
            // stehen im Panel nie ineinander; ginge das, wäre dieser Schnitt zu
            // grob — und der Test würde einen äusseren Bereich für den inneren
            // verantwortlich machen.
            $teile = preg_split('/(<Section\b[^>]*>)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

            if ($teile === false) {
                continue;
            }

            for ($i = 1; $i < count($teile); $i += 2) {
                $body = explode('</Section>', $teile[$i + 1] ?? '', 2)[0];

                $sections[] = [
                    'file' => str_replace(dirname(__DIR__, 2).'/', '', $path),
                    'tag' => $teile[$i],
                    'body' => $body,
                ];
            }
        }

        return $sections;
    }

    public function test_a_table_with_an_action_column_takes_the_whole_row(): void
    {
        $sections = $this->sections();

        // **Die Untergrenze zählt Bereiche und nicht Aktionstabellen.** Ein
        // Zähler auf den Fundstellen selbst stünde auf null, sobald jemand die
        // letzte Aktionstabelle umbaut — und dieser Test meldete Rot für genau
        // die Ordnung, die er durchsetzen soll (CLAUDE.md). Geprüft wird
        // deshalb, dass der Zerleger überhaupt etwas findet.
        $this->assertGreaterThan(
            30,
            count($sections),
            'Es werden kaum Bereiche gelesen — dann prüft dieser Test nichts.',
        );

        $found = [];

        foreach ($sections as $section) {
            if (preg_match('/<table\b.*?<\/table>/s', $section['body'], $tabelle) !== 1) {
                continue;
            }

            // Eine Aktionsspalte ist eine Zelle, in der eine Knopfreihe steht.
            if (preg_match('/<td\b[^>]*>\s*(?:<!--.*?-->\s*)?<div class="button-row"/s', $tabelle[0]) !== 1) {
                continue;
            }

            if (preg_match('/\bfull\b/', $section['tag']) === 1) {
                continue;
            }

            $title = preg_match('/title="([^"]*)"/', $section['tag'], $t) === 1 ? $t[1] : '?';

            $found[] = sprintf('%s  „%s"', $section['file'], $title);
        }

        $this->assertSame([], $found, sprintf(
            "Eine Tabelle mit einer Aktionsspalte steht in einem Bereich ohne `full`:\n  %s\n\n".
            'Ihre Breite ist die Summe der Knopfbeschriftungen, und `.scrolls > table` hält '.
            "sie auf `max-content` — im Grundriss liegt der letzte Knopf ausserhalb des\n".
            'Bereichs, und man muss waagerecht schieben, um ihn zu treffen.',
            implode("\n  ", $found),
        ));
    }
}
