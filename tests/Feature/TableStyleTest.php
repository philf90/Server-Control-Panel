<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Tabellen kommen aus app.css, und ihre Zeilenhöhe aus der Dichtemarke.
 *
 * **Warum es diesen Test gibt — der Befund, den nichts gemeldet hat.**
 * §7.2 verspricht zwei Dichtestufen und nennt als erste Zeile ihrer Tabelle
 * die Zeilenhöhe: 34px auf der Adminfläche, 42px auf der Kundenfläche. Die
 * Marke dafür heisst `--row-height` und wurde von **zwei der 26 Seiten**
 * benutzt. Auf den übrigen 24 entstand die Zeilenhöhe aus `padding: 6px 8px`,
 * je Seite neu geschrieben — die Kundenfläche war dort also nicht ruhiger als
 * die Adminfläche, und niemand hat es gemerkt, weil kein Lauf danach fragt.
 *
 * Dazu kam, was daraus folgt: **zehn Seiten definieren `table`, und es gibt
 * zwei unvereinbare Fassungen.** Auf der Übersicht ein Spaltenkopf in
 * Versalien mit `--text-label` und `--row-height`; auf allen Listen
 * `th { text-align: left; color: var(--text-muted) }` mit `6px 8px` und ohne
 * Zeilenhöhe. Dieselbe Sache, zweimal gebaut, verschieden.
 *
 * Das ist genau das Muster, das `ButtonStyleTest` für Knöpfe schon einmal
 * beendet hat. Dieser Test ist dieselbe Regel für die Tabelle.
 *
 * **Was hier nicht geprüft wird:** wie eine Tabelle aussieht. Geprüft wird,
 * dass keine Seite ihre eigene Form erfindet — und dass die Zeilenhöhe aus
 * der Marke kommt, die die Dichtestufe umschaltet.
 */
final class TableStyleTest extends TestCase
{
    /**
     * Die Eigenschaften, die die Form einer Tabelle ausmachen.
     *
     * `color` steht bewusst nicht dabei: Eine Seite darf eine Zelle nach ihrem
     * Zustand einfärben (`td[data-status='suspended']`). Das ist eine Aussage
     * über den Inhalt und keine über die Form — und dass die Farbe aus einer
     * Marke kommt, prüft bereits die CI.
     */
    private const SHAPE = [
        'height', 'min-height', 'padding', 'padding-top', 'padding-bottom',
        'padding-left', 'padding-right', 'border', 'border-bottom', 'border-top',
        'border-collapse', 'border-spacing', 'font-size', 'font-weight',
        'text-transform', 'letter-spacing', 'width', 'background',
    ];

    /** Ein Selektor, der eine Tabelle meint. */
    private const TABLE_SELECTOR = '/(^|[\s,>+~])(table|thead|tbody|tfoot|tr|th|td)\b/';

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(8, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    public function test_no_component_styles_a_table_itself(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
                continue;
            }

            // Ohne das klebt ein Kommentar vor einer Regel am Selektor, und ein
            // Kommentar, der das Wort „Tabelle" erklärt, liest sich für den
            // Ausdruck unten wie ein Tabellenselektor.
            $style = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);

            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                if (preg_match(self::TABLE_SELECTOR, $selector) !== 1) {
                    continue;
                }

                $checked++;

                foreach (self::SHAPE as $property) {
                    if (preg_match('/(^|[;\s])'.preg_quote($property, '/').'\s*:/', $rule[2]) === 1) {
                        $found[] = sprintf('%s  „%s" setzt %s', $this->relative($path), $selector, $property);
                    }
                }
            }
        }

        $this->assertGreaterThan(4, $checked, 'Es werden kaum Tabellenregeln gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Regeln geben einer Tabelle ihre eigene Form:\n  %s\n\n".
            'Die Form einer Tabelle steht in resources/css/app.css und sonst nirgends — '.
            "Zeilenhöhe, Innenabstand, Rahmen, Spaltenkopf.\nWer sie je Seite schreibt, hat nach dem ".
            'dritten Modul drei Fassungen davon, und die Dichtestufe aus §7.2 wirkt auf keiner.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Die Zeilenhöhe kommt aus der Marke, die die Dichte umschaltet.
     *
     * Ohne diese Prüfung ist `--row-height` eine Marke, die gesetzt wird und
     * die niemand liest — und die Dichtestufe der Kundenfläche ist ein
     * Versprechen im Dokument statt einer Eigenschaft der Oberfläche.
     */
    public function test_the_row_height_comes_from_the_density_token(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        preg_match_all('/([^{}@]*)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $setzt = false;

        foreach ($rules as $rule) {
            if (preg_match(self::TABLE_SELECTOR, trim($rule[1])) !== 1) {
                continue;
            }

            if (preg_match('/(^|[;\s])height\s*:\s*var\(--row-height\)/', $rule[2]) === 1) {
                $setzt = true;

                break;
            }
        }

        $this->assertTrue($setzt, implode("\n", [
            'In resources/css/app.css bezieht keine Tabellenregel ihre Zeilenhöhe aus var(--row-height).',
            '',
            '§7.2 staffelt die Zeilenhöhe je Dichtestufe — 34px auf der Adminfläche, 42px auf der',
            'Kundenfläche. Ohne eine Regel, die die Marke liest, ist beides derselbe Wert, und die',
            'Kundenfläche wird nicht ruhiger. Genau so war es auf 24 von 26 Seiten.',
        ]));
    }

    /**
     * Und die Marke gibt es in beiden Dichtestufen.
     *
     * Ein `var(--row-height)` ohne Wert fällt still auf die geerbte Höhe
     * zurück — der Browser meldet nichts, und die Tabelle sieht bloss anders
     * aus als gedacht. Dieselbe Falle wie bei den Schriftmarken.
     *
     * **Dieser Test hat beim Gegenprüfen zuerst nicht zugebissen.** Die
     * Dichtestufe `customer` wurde absichtlich um `--row-height` erleichtert,
     * und er blieb grün: In app.css steht `:root[data-density='customer']` ein
     * zweites Mal, nämlich im `@media (max-width: 720px)`-Block, wo beide
     * Stufen auf 44px zusammenlaufen. Der Ausdruck fand diese Fundstelle und
     * war zufrieden.
     *
     * Damit hätte er eine Gestaltung durchgelassen, in der die Dichtestufe nur
     * auf dem Telefon existiert — also genau dort, wo sie keine Rolle spielt.
     * Die Grundwerte stehen ausserhalb der Haltepunkte; dort wird gesucht.
     */
    public function test_the_density_token_exists_in_both_steps(): void
    {
        $css = $this->withoutMediaBlocks(
            (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css')),
        );

        foreach (['admin', 'customer'] as $dichte) {
            $this->assertSame(
                1,
                preg_match(
                    '/\[data-density=\'?'.$dichte.'\'?\][^{]*\{[^{}]*--row-height\s*:\s*\d/s',
                    $css,
                ),
                sprintf(
                    'In app.css setzt die Dichtestufe „%s" ausserhalb der Haltepunkte kein --row-height. '.
                    'Eine Stufe, die es nur im @media-Block gibt, wirkt genau dort nicht, wo sie gemeint ist.',
                    $dichte,
                ),
            );
        }
    }

    /**
     * Das Stylesheet ohne seine `@media`-Blöcke.
     *
     * Über Klammern gezählt: Ein regulärer Ausdruck endet an der ersten
     * schliessenden Klammer und schneidet den Block mitten in der ersten Regel
     * ab — dieselbe Falle, die `ButtonStyleTest` schon einmal gestellt hat.
     */
    private function withoutMediaBlocks(string $css): string
    {
        while (($start = strpos($css, '@media')) !== false) {
            $open = strpos($css, '{', $start);

            if ($open === false) {
                break;
            }

            $depth = 1;
            $end = strlen($css);

            for ($i = $open + 1; $i < strlen($css); $i++) {
                $depth += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $end = $i + 1;

                    break;
                }
            }

            $css = substr($css, 0, $start).substr($css, $end);
        }

        return $css;
    }
}
