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
    /*
     * **Hier stand eine Liste `BRINGS_ITS_OWN`**, und sie ist beim Umdrehen der
     * Frage überflüssig geworden: Solange der Test *Knopfreihen* suchte, musste
     * er wissen, welche Vorgänger ihren Abstand selbst mitbringen. Seit er
     * *bündige Bausteine* sucht, kommen die anderen gar nicht mehr vor.
     *
     * Gefunden hat den Rest **PHPStan in der CI** — hier gibt es ihn nicht, und
     * kein Wächter dieses Projekts sieht eine ungenutzte Konstante.
     *
     * > **Wer eine Frage umdreht, lässt die Antwort auf die alte stehen.**
     *
     * Was die Liste wusste, steht weiter dort, wo es hingehört: bei der Regel in
     * `app.css`. `.empty` hat `padding: 22px 0` und braucht sie deshalb nicht.
     */

    /**
     * Bausteine, die bündig enden — sie bringen unten keinen Abstand mit.
     *
     * `.scrolls` hört an der Tabellenkante auf, `.pager` hat oben eine Linie und
     * unten nichts, `.cell-value` steht auf `margin: 0`. Steht eine Knopfreihe
     * unter einem von ihnen, muss der Nachbarschaftsausdruck ihn kennen.
     */
    private const FLUSH = ['scrolls', 'pager', 'cell-value'];

    /**
     * Bausteine, die oben bündig anfangen — sie bringen keinen Abstand darüber.
     *
     * **Die Gegenrichtung, und sie hat vier Fälle lang niemand gestellt.**
     * `.sections` setzt bewusst keinen Rand nach oben: Davor steht auf jeder
     * anderen Seite dieses Panels eine Meldung oder `FormErrors`, und die
     * bringen ihren `margin-bottom` selbst mit. Die Datenbankseite ist die
     * einzige, auf der eine Knopfreihe davor steht — und die bringt in keine
     * Richtung etwas mit.
     *
     * > **Eine Regel über den Nachbarn davor sagt nichts über den danach.**
     */
    private const FLUSH_TOP = ['sections'];

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
     * Ein ganzer Klassenname in einer Klassenliste.
     *
     * **`\b` ist dafür die falsche Grenze, und dieses Projekt hat die Klassen,
     * an denen es auffällt.** `\bpager\b` trifft `pager-state`, `\bcell\b`
     * trifft `cell-value` — beide gibt es in `app.css`, und beide sind etwas
     * anderes als der Baustein, nach dem gefragt wird. Aufgefallen ist es beim
     * Bruch: Umbenennen von `sections` nach `sections-x` liess den Wächter grün,
     * weil `\bsections\b` das umbenannte auch noch traf.
     *
     * > **Ein Bindestrich ist für einen regulären Ausdruck eine Wortgrenze und
     * > für eine Klassenliste keine.**
     *
     * Ausschauen statt verankern: `^` und `$` meinten hier den Anfang der
     * ganzen Datei und nicht den des Attributs — der Ausdruck steht mitten in
     * einem `class="[^"]*…[^"]*"`.
     */
    private function className(string $name): string
    {
        return '(?<![\w-])'.preg_quote($name, '/').'(?![\w-])';
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

    /**
     * Die Nachbarn, die eine Knopfreihe **nach unten** abdeckt.
     *
     * @return list<string>
     */
    private function coveredBelow(): array
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        preg_match_all('/\.button-row\s*\+\s*([^{]+)\{/', $css, $regeln);

        $klassen = [];

        foreach ($regeln[1] as $selektor) {
            // Eine Knopfreihe nach einer Knopfreihe ist ein anderer Fall — den
            // regelt `td > .button-row + .button-row`, und er gehört nicht in
            // diese Liste.
            preg_match_all('/\.([\w-]+)/', $selektor, $treffer);

            foreach ($treffer[1] as $klasse) {
                if ($klasse !== 'button-row') {
                    $klassen[] = $klasse;
                }
            }
        }

        return array_values(array_unique($klassen));
    }

    /**
     * Jeder oben bündige Baustein unter einer Knopfreihe ist erfasst.
     *
     * **Der fünfte Fall derselben Sache, und der erste in der anderen
     * Richtung.** Auf der Datenbankseite steht „Tabellen durchsehen" am Kopf und
     * darunter fangen die Bereiche an; zwischen beiden waren **0px**. Gefunden
     * hat es wieder der Betreiber auf einem Bild, und wieder hat nichts
     * überlaufen — es sah nur gedrängt aus.
     *
     * Gemessen mit dem gebauten Stylesheet bei 1440px und 390px, mit der
     * Meldung an derselben Stelle als Gegenprobe: 0 gegen 26 und 0 gegen 24.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_every_flush_top_block_under_a_button_row_is_covered(): void
    {
        $abgedeckt = $this->coveredBelow();
        $gesehen = 0;

        foreach ($this->templates() as $path) {
            $template = (string) preg_replace(
                '/<!--.*?-->/su',
                '',
                (string) file_get_contents($path),
            );

            foreach (self::FLUSH_TOP as $baustein) {
                $treffer = preg_match_all(
                    sprintf(
                        '/<div[^>]*class="button-row"[^>]*>(?:(?!<\/?div[\s>]).)*<\/div>\s*'.
                        '<\w+[^>]*class="[^"]*%s/su',
                        $this->className($baustein),
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
                        "%s setzt `.%s` direkt unter eine Knopfreihe, und app.css kennt diese\n".
                        "Nachbarschaft nicht.\n\n".
                        '`.button-row` bringt in **keine** Richtung einen Abstand mit, und `.%s` fängt '.
                        'oben bündig an. Die Bereiche kleben dann am Knopf — gemessen 0px, wo eine '.
                        'Meldung an derselben Stelle 26px macht (docs/46 §20.30).',
                        $this->relative($path),
                        $baustein,
                        $baustein,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            1,
            $gesehen,
            'Es wird keine Knopfreihe mit einem bündigen Baustein darunter gefunden — dann rechnet '.
            'diese Hälfte an nichts mehr nach.',
        );
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
                        '/<(\w+)[^>]*class="[^"]*%s[^"]*"[^>]*>(?:(?!<\/?\1[\s>]).)*<\/\1>\s*<div class="button-row">/su',
                        $this->className($baustein),
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
