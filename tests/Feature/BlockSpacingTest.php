<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Zwei Bausteine, die beide nichts mitbringen, kleben aneinander.
 *
 * ## Der fünfte Anlauf, und diesmal ist die Frage richtig gestellt
 *
 * Hier hiess der Wächter `ButtonRowSpacingTest`, und er fragte nach
 * **Knopfreihen**. Das war die Frage der ersten vier Fälle und trotzdem die
 * falsche: Beim fünften stand unter der Blätterleiste eine **Meldung** —
 * dieselbe Fuge, ein anderer Nachfolger, und der Wächter sah nichts.
 *
 * > **Eine Liste von Nachbarn, die wächst, ist keine Regel — sie ist eine
 * > Aufzählung der Fälle, die schon jemand gesehen hat.**
 *
 * Die Frage lautet jetzt: **Endet der eine bündig, und fängt der andere bündig
 * an?** Zwei Listen, alle Paare daraus, und für jedes Paar, das in einer Vorlage
 * wirklich vorkommt, muss `app.css` eine Nachbarschaftsregel haben.
 *
 * ## Und der Ausdruck hat einen seiner eigenen Bausteine nie gefunden
 *
 * Der alte suchte den Vorgänger mit `<(\w+)…>(?:(?!<\/?\1[\s>]).)*<\/\1>` — also
 * „bis zum nächsten Tag desselben Namens". Für `.scrolls` geht das; **für
 * `.pager` nicht**, denn darin stehen drei `<div>`. `pager` stand seit Schritt 5
 * in der Liste und ist **nie getroffen worden**; die Untergrenze zählte die
 * anderen mit und blieb grün.
 *
 * > **Ein Eintrag in einer Liste, den der Ausdruck nie erreicht, sieht aus wie
 * > eine Abdeckung und ist eine Lücke.**
 *
 * Gesucht wird deshalb über die **Verschachtelungstiefe**: vom öffnenden Tag des
 * Vorgängers vorwärts zählen, bis er zu ist, und dann das nächste öffnende Tag
 * ansehen. Das versteht auch einen Baustein mit Kindern.
 */
final class BlockSpacingTest extends TestCase
{
    /**
     * Bausteine, die unten bündig enden — sie bringen keinen Abstand mit.
     *
     * `.scrolls` hört an der Tabellenkante auf, `.pager` hat oben eine Linie und
     * unten nichts, `.cell-value` steht auf `margin: 0`, und `.button-row`
     * bringt in **keine** Richtung etwas mit.
     *
     * `.empty` steht bewusst nicht dabei — es hat `padding: 22px 0` und damit
     * seine eigene Luft.
     */
    private const ENDS_FLUSH = ['scrolls', 'pager', 'cell-value', 'button-row'];

    /**
     * Bausteine, die oben bündig anfangen.
     *
     * `.button-row` und `.sections` setzen gar keinen Rand; `.notice` hat
     * `margin-bottom` und oben nichts.
     */
    private const STARTS_FLUSH = ['button-row', 'notice', 'sections'];

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
     * **`\b` ist dafür die falsche Grenze.** `\bpager\b` trifft `pager-state`,
     * `\bcell\b` trifft `cell-value` — beide Klassen gibt es in `app.css`, und
     * beide sind etwas anderes als der Baustein, nach dem gefragt wird.
     * Aufgefallen beim Bruch: Umbenennen von `sections` nach `sections-x` liess
     * den Wächter grün.
     *
     * > **Ein Bindestrich ist für einen regulären Ausdruck eine Wortgrenze und
     * > für eine Klassenliste keine.**
     */
    private function hasClass(string $attributes, string $name): bool
    {
        if (preg_match('/class="([^"]*)"/', $attributes, $treffer) !== 1) {
            return false;
        }

        return in_array($name, preg_split('/\s+/', trim($treffer[1])) ?: [], true);
    }

    /**
     * Der `<template>`-Block einer Vorlage, gerendert gedacht.
     *
     * **Zwei Dinge passieren hier, und beide sind Fehler des ersten Wurfs.**
     *
     * 1. **Nur der `<template>`-Block.** Über die ganze Datei gelesen, sieht ein
     *    TypeScript-Generic wie ein Tag aus: `ref<HTMLElement | null>` liefert
     *    ein `<HTMLElement …>`, das nie zugeht. Die Tiefenzählung lief damit ins
     *    Dateiende, und die Suche fand fast nichts — sie meldete das nicht,
     *    sondern gab weniger Paare zurück.
     *
     * 2. **`<template>` selbst fällt weg.** Ein `<template v-else>` rendert
     *    nichts; seine Kinder stehen an seiner Stelle. Im Quelltext sind die
     *    Blätterleiste und die Meldung darunter deshalb **keine** Geschwister —
     *    im Browser sind sie es, und dort klebten sie. Genau dieser Fall ist der
     *    Anlass dieses Wächters.
     *
     * > **Ein Wächter, der Markup liest, muss lesen, was gerendert wird — nicht,
     * > was dasteht.**
     */
    private function rendered(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $treffer) !== 1) {
            return '';
        }

        return (string) preg_replace(
            ['/<!--.*?-->/su', '#</?template[^>]*>#s'],
            '',
            $treffer[1],
        );
    }

    /**
     * Alle Paare aus „Baustein" und „was unmittelbar danach kommt".
     *
     * **Über die Verschachtelungstiefe und nicht über den Tagnamen.** Der alte
     * Ausdruck las bis zum nächsten Tag desselben Namens und übersah damit jeden
     * Baustein mit Kindern — siehe Klassenkopf.
     *
     * Elemente ohne Ende (`<input>`) und selbstschliessende (`<Foo />`) zählen
     * nicht in die Tiefe; ohne das käme der Zähler nie zurück auf null, und die
     * Suche endete stumm am Dateiende.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(string $template): array
    {
        $void = ['input', 'br', 'hr', 'img', 'meta', 'link', 'source', 'area', 'col'];

        preg_match_all('/<(\/?)([a-zA-Z][\w.-]*)([^>]*)>/s', $template, $tags, PREG_SET_ORDER);

        $paare = [];

        foreach ($tags as $start => $tag) {
            if ($tag[1] === '/' || in_array(strtolower($tag[2]), $void, true) || str_ends_with(rtrim($tag[3]), '/')) {
                continue;
            }

            $tiefe = 1;

            for ($i = $start + 1; $i < count($tags) && $tiefe > 0; $i++) {
                $folgend = $tags[$i];
                $name = strtolower($folgend[2]);

                if ($folgend[1] === '/') {
                    $tiefe--;

                    continue;
                }

                if (! in_array($name, $void, true) && ! str_ends_with(rtrim($folgend[3]), '/')) {
                    $tiefe++;
                }
            }

            // `$i` steht hinter dem schliessenden Tag; das nächste öffnende
            // Element ist das Geschwister. Ein `</div>` dort heisst: Der
            // Vorgänger war das letzte Kind, und dann gibt es kein Paar.
            $nachbar = $tags[$i] ?? null;

            if ($nachbar === null || $nachbar[1] === '/') {
                continue;
            }

            foreach (self::ENDS_FLUSH as $unten) {
                foreach (self::STARTS_FLUSH as $oben) {
                    if ($this->hasClass($tag[3], $unten) && $this->hasClass($nachbar[3], $oben)) {
                        $paare[] = [$unten, $oben];
                    }
                }
            }
        }

        return $paare;
    }

    /**
     * Die Nachbarschaften, die `app.css` kennt.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function covered(): array
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        preg_match_all('/([^{}]*?)\+([^{}+]*?)\{/s', $css, $regeln, PREG_SET_ORDER);

        $paare = [];

        foreach ($regeln as $regel) {
            preg_match_all('/\.([\w-]+)/', $regel[1], $links);
            preg_match_all('/\.([\w-]+)/', $regel[2], $rechts);

            foreach ($links[1] as $a) {
                foreach ($rechts[1] as $b) {
                    $paare[] = [$a, $b];
                }
            }
        }

        return $paare;
    }

    public function test_the_rule_still_lists_what_it_used_to(): void
    {
        $abgedeckt = $this->covered();

        foreach ([['scrolls', 'button-row'], ['field', 'button-row'], ['button-row', 'sections']] as $muss) {
            $this->assertContains(
                $muss,
                $abgedeckt,
                sprintf(
                    'app.css kennt die Nachbarschaft `.%s + .%s` nicht mehr. Das ist eine der Fugen, '.
                    'die dieser Wächter festhält — ohne sie klebt der eine Baustein am anderen.',
                    $muss[0],
                    $muss[1],
                ),
            );
        }
    }

    /**
     * Jede Fuge zwischen zwei bündigen Bausteinen ist erfasst.
     *
     * **Fünf Fälle in fünf Schritten, jeder vom Betreiber auf einem Bild
     * gefunden** — keiner von einer Messung, denn nichts läuft dabei über und
     * nichts ist abgeschnitten. Es sieht nur gedrängt aus.
     *
     * Der fünfte war eine Meldung unter der Blätterleiste, und er hat die Frage
     * dieses Wächters umgestellt: nicht mehr „wo steht eine Knopfreihe?",
     * sondern „welche zwei bündigen Bausteine stehen aneinander?"
     */
    public function test_every_seam_between_two_flush_blocks_is_covered(): void
    {
        $abgedeckt = $this->covered();
        $gesehen = [];

        foreach ($this->templates() as $path) {
            $template = $this->rendered((string) file_get_contents($path));

            foreach ($this->pairs($template) as $paar) {
                $gesehen[implode(' + ', $paar)] = true;

                $this->assertContains(
                    $paar,
                    $abgedeckt,
                    sprintf(
                        "%s setzt `.%s` unmittelbar unter `.%s`, und app.css kennt diese Nachbarschaft\n".
                        "nicht.\n\n".
                        '`.%s` endet bündig und `.%s` fängt bündig an — die beiden kleben dann '.
                        'aneinander. Der Baustein gehört in den Nachbarschaftsausdruck; ein Abstand '.
                        'auf der Seite wäre derselbe Fehler wie ein Hexwert in einer Komponente.',
                        $this->relative($path),
                        $paar[1],
                        $paar[0],
                        $paar[0],
                        $paar[1],
                    ),
                );
            }
        }

        /*
         * **Die Untergrenze zählt verschiedene Paare und nicht Vorkommen.**
         * Hier stand vorher eine Zahl über die Treffer, und die war erfüllt,
         * solange *ein* Baustein oft genug vorkam: `pager` war seit Schritt 5
         * in der Liste und wurde nie gefunden, ohne dass es auffiel.
         *
         * > **Ein Eintrag in einer Liste, den der Ausdruck nie erreicht, sieht
         * > aus wie eine Abdeckung und ist eine Lücke.**
         */
        $this->assertGreaterThanOrEqual(
            3,
            count($gesehen),
            sprintf(
                "Es werden nur %d verschiedene Fugen gefunden: %s\n\n".
                'Dann rechnet dieser Wächter an fast nichts mehr nach — entweder ist die Suche kaputt '.
                'oder die Listen sind es.',
                count($gesehen),
                implode(', ', array_keys($gesehen)) ?: '(keine)',
            ),
        );
    }

    /**
     * Und jeder Baustein beider Listen kommt irgendwo wirklich vor.
     *
     * **Das ist der Wächter über den Wächter.** Ein Name in {@see self::ENDS_FLUSH}
     * oder {@see self::STARTS_FLUSH}, den keine Vorlage trägt, ist entweder ein
     * Tippfehler oder ein Baustein, den es nicht mehr gibt — und in beiden Fällen
     * eine Zeile, die nach Abdeckung aussieht und keine ist. Genau das war
     * `pager` fünf Schritte lang.
     */
    public function test_every_listed_block_really_exists(): void
    {
        $vorlagen = '';

        foreach ($this->templates() as $path) {
            $vorlagen .= (string) file_get_contents($path);
        }

        preg_match_all('/class="([^"]*)"/', $vorlagen, $treffer);

        $klassen = [];

        foreach ($treffer[1] as $liste) {
            foreach (preg_split('/\s+/', trim($liste)) ?: [] as $klasse) {
                $klassen[$klasse] = true;
            }
        }

        foreach ([...self::ENDS_FLUSH, ...self::STARTS_FLUSH] as $baustein) {
            $this->assertArrayHasKey(
                $baustein,
                $klassen,
                sprintf(
                    '`.%s` steht in einer der beiden Listen dieses Wächters, aber in keiner Vorlage. '.
                    'Ein Baustein, den es nicht gibt, deckt nichts ab.',
                    $baustein,
                ),
            );
        }
    }
}
