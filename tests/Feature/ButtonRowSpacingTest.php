<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Knopfreihe klebt nicht an dem, was über ihr steht.
 *
 * **Der vierte Anlauf an derselben Sache.** `.button-row` bringt keinen Rand
 * nach oben mit. In einem `.form` fällt das nicht auf — die Reihe ist dort ein
 * Flexkind und erbt den `gap`. Überall sonst klebt sie, und die Antwort war
 * dreimal eine eigene Klasse auf der jeweiligen Seite (`.spaced` im Profil, ein
 * Umbau in der Zertifikatsseite), bis der Nachbarschaftsausdruck in `app.css`
 * kam. Der kannte dann Formularinhalt — und beim vierten Mal war es eine
 * Tabelle.
 *
 * > **Eine Regel, die eine Liste von Nachbarn führt, ist eine Liste, die
 * > wächst — der Grund steht nicht in ihr, sondern daneben.**
 *
 * **Deshalb prüft dieser Wächter die Vorlagen und nicht das Stylesheet.** Er
 * sucht jede Knopfreihe, die unmittelbar auf ein Geschwister folgt, und
 * verlangt, dass dieses Geschwister von der Regel erfasst ist. Wer einen neuen
 * Baustein baut, der bündig endet, und eine Knopfreihe darunter setzt, bekommt
 * hier Rot — und muss die Liste erweitern, statt den Abstand auf seiner Seite
 * nachzubauen.
 *
 * Gefunden hat den vierten Fall der Betreiber auf einem Bildschirmfoto:
 * „Schliessen" klebte unter der Spaltentabelle. Nichts lief über, nichts war
 * abgeschnitten — es sah nur gedrängt aus, und der Fehler stand seit Schritt 4
 * da und hatte zwei Durchgänge überlebt.
 */
final class ButtonRowSpacingTest extends TestCase
{
    /**
     * Bausteine, die ihren Abstand selbst mitbringen.
     *
     * `.empty` hat `padding: 22px 0`, `.notice` und `.section-note` haben einen
     * eigenen Rand. Wer hier steht, braucht die Regel nicht — und bekäme mit ihr
     * zu viel.
     */
    private const BRINGS_ITS_OWN = ['empty', 'notice', 'section-note', 'hint', 'lead', 'prose'];

    /**
     * Bausteine, die bündig enden — sie bringen unten keinen Abstand mit.
     *
     * `.scrolls` hört an der Tabellenkante auf, `.pager` hat oben eine Linie und
     * unten nichts, `.cell-value` steht auf `margin: 0`. Steht eine Knopfreihe
     * unter einem von ihnen, muss der Nachbarschaftsausdruck ihn kennen.
     */
    private const FLUSH = ['scrolls', 'pager', 'cell-value'];

    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    /**
     * Die Nachbarn, die der Ausdruck in `app.css` erfasst.
     *
     * @return list<string>
     */
    private function covered(): array
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        $this->assertSame(
            1,
            preg_match('/:is\(([^)]*)\)\s*\+\s*\.button-row\s*\{/', $css, $regel),
            'In app.css gibt es keinen Nachbarschaftsausdruck für `.button-row` mehr — dann prüft '.
            'dieser Wächter nichts, und jede Knopfreihe klebt wieder.',
        );

        preg_match_all('/\.([\w-]+)/', $regel[1], $klassen);

        return $klassen[1];
    }

    public function test_the_rule_still_lists_what_it_used_to(): void
    {
        $abgedeckt = $this->covered();

        $this->assertGreaterThanOrEqual(
            3,
            count($abgedeckt),
            'Der Ausdruck führt kaum noch Nachbarn — das ist keine Verkleinerung, sondern ein Verlust.',
        );

        foreach (['field', 'scrolls'] as $muss) {
            $this->assertContains(
                $muss,
                $abgedeckt,
                sprintf(
                    'Der Ausdruck erfasst `.%s` nicht mehr. Eine Knopfreihe darunter klebt dann an dem, '.
                    'was über ihr steht — genau der Fehler, den dieser Wächter festhält.',
                    $muss,
                ),
            );
        }
    }

    /**
     * Jeder bündig endende Baustein mit einer Knopfreihe darunter ist erfasst.
     *
     * **Die Frage hat dreimal falsch herum gestanden.** Der erste Anlauf suchte
     * *Knopfreihen* und fragte, ob ihr Vorgänger erfasst sei. Das meldete
     * dreimal etwas Richtiges als Fehler: eine Reihe in `.form` (Flexbehälter
     * mit `gap`), eine in `.sheet` (eigene Regel `.sheet .button-row`) und eine
     * in `.tasks li` (Flexzeile mit `gap`). Alle drei regeln den Abstand
     * anderswo — die Nachbarschaftsregel ist für den Fall da, in dem *niemand*
     * ihn regelt.
     *
     * > **Ein Wächter, der von der falschen Seite fragt, findet drei richtige
     * > Antworten und nennt sie Fehler.**
     *
     * Deshalb fragt er jetzt von der anderen Seite: Welche Bausteine enden
     * bündig, und steht unter einem von ihnen eine Knopfreihe, ohne dass die
     * Regel ihn kennt?
     *
     * **Was das nicht abdeckt**, und das steht hier, damit es niemand für mehr
     * hält: Ein *neuer* bündiger Baustein, den {@see self::FLUSH} nicht kennt,
     * fällt durch. Die Liste ist die Regel, nicht ihr Ersatz.
     */
    public function test_every_flush_block_with_a_button_row_is_covered(): void
    {
        $abgedeckt = $this->covered();
        $gesehen = 0;

        foreach ($this->templates() as $path) {
            $template = (string) preg_replace(
                '/<!--.*?-->/su',
                '',
                (string) file_get_contents($path),
            );

            foreach (self::FLUSH as $baustein) {
                // Der Baustein, sein schliessendes Element, und unmittelbar
                // danach eine Knopfreihe. Mehr will dieser Ausdruck nicht
                // verstehen — und was er nicht versteht, meldet er auch nicht.
                $treffer = preg_match_all(
                    sprintf(
                        '/<(\w+)[^>]*class="[^"]*\b%s\b[^"]*"[^>]*>(?:(?!<\/?\1[\s>]).)*<\/\1>\s*<div class="button-row">/su',
                        preg_quote($baustein, '/'),
                    ),
                    $template,
                );

                if ($treffer === 0) {
                    continue;
                }

                $gesehen += $treffer;

                $this->assertContains(
                    $baustein,
                    $abgedeckt,
                    sprintf(
                        "%s setzt eine Knopfreihe direkt unter `.%s`, und der Nachbarschaftsausdruck\n".
                        "von `.button-row` in app.css kennt diesen Baustein nicht.\n\n".
                        '`.%s` endet bündig — es bringt unten keinen Abstand mit. Die Knopfreihe klebt '.
                        'dann an ihm. Der Baustein gehört in den Ausdruck; ein Abstand auf der Seite '.
                        'wäre derselbe Fehler wie ein Hexwert in einer Komponente.',
                        $this->relative($path),
                        $baustein,
                        $baustein,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $gesehen,
            'Es wird kaum ein bündiger Baustein mit einer Knopfreihe darunter gefunden — dann rechnet '.
            'dieser Wächter an nichts mehr nach.',
        );
    }
}
