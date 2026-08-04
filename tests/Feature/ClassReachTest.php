<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Jede Klasse im Template zeigt auf eine Regel, die es gibt.
 *
 * **Warum es diesen Test gibt.** Er ist die Verallgemeinerung einer Prüfung,
 * die es schon gibt und die zu eng gefasst war. `ButtonStyleTest` prüft seit
 * P3, dass jede *Knopfklasse* in app.css existiert — der Anlass war
 * `class="knopf betont"` auf drei Seiten, eine Klasse, die niemand kennt: Der
 * Knopf sah aus wie ein gewöhnlicher, die Hervorhebung fehlte, und kein Lauf
 * sagte etwas.
 *
 * Für jede andere Klasse fragte danach niemand. Gefunden hat dieser Test
 * beim ersten Lauf drei tote Klassen, zwei davon vorher unbemerkt:
 *
 *   Tile.vue     `class="series empty"`  — das CSS kennt `.series.leer`
 *   Tile.vue     `class="value num"`     — `.num` gibt es nirgends
 *   PanelLayout  `class="name"`          — im Konto-Block nicht definiert
 *
 * Der zweite ist der teuerste und war am schwersten zu sehen: `.num` sollte
 * der Kachelzahl Tabellenziffern geben. app.css setzt sie über `.zahl` — die
 * Klasse heisst anders. Die eine grosse Zahl, für die die Kachel da ist,
 * stand also in Proportionalziffern, und zwei Kacheln nebeneinander hatten
 * ihre Ziffern nicht auf derselben Linie.
 *
 * Das ist wieder dasselbe Muster wie überall in diesem Projekt: eine
 * Zeichenkette, die auf etwas verweist, ohne dass jemand den Bezug prüft.
 */
final class ClassReachTest extends TestCase
{
    /**
     * Ein Literal rechts von einem Vergleich ist keine Klasse.
     *
     * In `:class="['knopf', { aktiv: props.kind === 'access' }]"` ist `aktiv`
     * die Klasse und `'access'` der Wert, mit dem verglichen wird. Ohne diese
     * Ausnahme meldete der Test `access`, `error` und `active` als unbekannte
     * Klassen — drei Fehlalarme, und ein Wächter, der Fehlalarme gibt, wird
     * abgeschaltet.
     */
    private const COMPARISON = '/[=!]==?\s*(?:\'[^\']*\'|"[^"]*")/';

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

    private function withoutComments(string $source): string
    {
        return (string) preg_replace('#/\*.*?\*/#su', '', $source);
    }

    /** Der `<template>`-Block ohne HTML-Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    private function style(string $source): string
    {
        if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    /**
     * Die Klassen, die ein Stylesheet definiert.
     *
     * @return list<string>
     */
    private function defined(string $css): array
    {
        preg_match_all('/\.([\p{L}][\w-]*)/u', $this->withoutComments($css), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Die Klassen, die ein Template benutzt — beide Schreibweisen.
     *
     * @return list<string>
     */
    private function used(string $template): array
    {
        $found = [];

        // `class="a b c"`, aber nicht `:class="…"`: Der Blick zurück verhindert,
        // dass die gebundene Form hier ein zweites Mal landet und ihr ganzer
        // Ausdruck als Klassenliste gelesen wird.
        preg_match_all('/(?<![:\w-])class="([^"]*)"/', $template, $statisch);

        foreach ($statisch[1] as $liste) {
            foreach (preg_split('/\s+/', trim($liste)) ?: [] as $klasse) {
                if ($klasse !== '') {
                    $found[] = $klasse;
                }
            }
        }

        // `:class="['knopf', { aktiv: … }]"` — Zeichenkettenliterale und
        // Objektschlüssel.
        preg_match_all('/:class="([^"]*)"/', $template, $gebunden);

        foreach ($gebunden[1] as $ausdruck) {
            $ausdruck = (string) preg_replace(self::COMPARISON, '', $ausdruck);

            preg_match_all('/\'([\p{L}][\w-]*)\'/u', $ausdruck, $literale);
            preg_match_all('/([\p{L}][\w-]*)\s*:/u', $ausdruck, $schluessel);

            $found = array_merge($found, $literale[1], $schluessel[1]);
        }

        return array_values(array_unique($found));
    }

    public function test_every_class_in_a_template_points_at_a_rule(): void
    {
        $stylesheet = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $global = $this->defined($stylesheet);

        $unknown = [];
        $checked = 0;

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);
            $template = $this->template($source);

            if ($template === '') {
                continue;
            }

            /*
             * Die eigene Datei zählt mit — und zwar vollständig.
             *
             * Ein `scoped`-Block gilt für die Elemente dieser Komponente *und*
             * für das Wurzelelement einer Komponente, die sie einsetzt. Beides
             * steht hier im selben Template, also reicht die Vereinigung aus
             * app.css und dem eigenen Block.
             */
            $known = array_merge($global, $this->defined($this->style($source)));

            foreach ($this->used($template) as $klasse) {
                $checked++;

                if (! in_array($klasse, $known, true)) {
                    $unknown[] = sprintf('%s: %s', $this->relative($path), $klasse);
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'Es werden kaum Klassen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $unknown, sprintf(
            "Diese Klassen stehen in einem Template und in keiner Regel:\n  %s\n\n".
            'Eine Klasse, die auf nichts zeigt, sieht aus wie Gestaltung und ist keine. '.
            'Entweder fehlt die Regel in resources/css/app.css oder im <style>-Block der Datei — '.
            'oder die Klasse ist ein Rest und gehört entfernt.',
            implode("\n  ", $unknown),
        ));
    }
}
