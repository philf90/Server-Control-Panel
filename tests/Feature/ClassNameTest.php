<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Klassennamen sind englisch — und jede Regel hat einen Nutzer.
 *
 * **Warum es diesen Test gibt.** CLAUDE.md sagt seit dem ersten Tag:
 * „Kommentare, Dokumentation und alle Texte der Oberfläche: deutsch.
 * Bezeichner: englisch." Eine CSS-Klasse ist ein Bezeichner. Trotzdem hiessen
 * nach dem Rework 110 von ihnen `knopf`, `marke`, `bereich`, `kennung`,
 * `stapelt` — und niemand hat es gemeldet, weil es dafür kein Werkzeug gab.
 * Dieselbe Geschichte wie bei den Knöpfen, den Schriftgrößen und den
 * Tabellen: eine Regel im Dokument, keine im Lauf.
 *
 * `docs/19` §4 hielt sogar das Gegenteil fest — Bezeichner blieben „von
 * alledem unberührt". Der Satz stammt aus dem Vorgängerprojekt und meinte
 * eine Schnittstelle, die man nicht für einen Wortgeschmack umbenennt; auf
 * die Klassen der eigenen Gestaltung angewandt hat er neun Monate lang eine
 * zweite Sprache in der Oberfläche gerechtfertigt.
 *
 * **Warum eine Wortliste und keine Wörterbuchprüfung.** „Ist dieses Wort
 * englisch?" lässt sich mechanisch nicht beantworten — `marke` ist im
 * Englischen kein Wort, `bar` in beiden Sprachen eines. Eine Liste beantwortet
 * die Frage, die wirklich zählt: Steht dieser Name im Vokabular des Panels?
 * Wer eine Klasse hinzufügt, trägt ihr Wort hier ein, und die Zeile steht im
 * Diff — genau dort, wo ein deutsches Wort auffällt.
 */
final class ClassNameTest extends TestCase
{
    /**
     * Das Vokabular der Klassennamen, in Teilen zwischen den Bindestrichen.
     *
     * Sortiert und ohne Dubletten. Was hier fehlt, kommt nicht in einen
     * Klassennamen — und was hier steht, ist englisch oder eine Abkürzung,
     * die in CSS und HTML zu Hause ist (`nav`, `sr`, `sub`, `op`).
     */
    private const VOCABULARY = [
        'account', 'active', 'after', 'area', 'badge', 'band', 'bar', 'blank',
        'block', 'breadcrumb', 'button', 'check', 'choice', 'choices', 'codes',
        'content', 'critical', 'cursor', 'danger', 'dependent', 'description',
        'done', 'empty', 'end', 'error', 'eye', 'facts', 'field', 'filter',
        'foot', 'footer', 'form', 'frame', 'full', 'grid', 'group', 'head',
        'hint', 'icon', 'ident', 'item', 'label', 'line', 'link', 'list',
        'log', 'long', 'mark', 'menu', 'met', 'meter', 'multiline', 'name',
        'narrow', 'nav', 'neutral', 'note', 'notice', 'ok', 'on', 'op',
        'open', 'output', 'over', 'page', 'pager', 'pair', 'paired', 'pairs', 'password',
        'postscript', 'primary', 'progress', 'quiet', 'rail', 'release',
        'reveal', 'right', 'row', 'rules', 'running', 'scrim', 'scrolls',
        'second', 'section', 'sections', 'sheet', 'signin', 'signout', 'small',
        'source', 'spaced', 'sr', 'stacks', 'state', 'strength', 'sub', 'subline',
        'tasks', 'text', 'tight', 'tile', 'tiles', 'time', 'title', 'toggle',
        'top', 'topbar', 'trend', 'unit', 'usage', 'value', 'version', 'warn',
        'wide', 'with',
    ];

    /**
     * Dateiendungen, die in `@source`-Angaben und Kommentaren wie ein
     * Klassenname aussehen: `*.blade.php` liest sich als `.blade` und `.php`.
     */
    private const NOT_A_CLASS = ['blade', 'php', 'vue', 'css', 'md', 'html', 'de', 'js', 'ts'];

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

    /**
     * Jedes Stylesheet des Panels: app.css und die `<style>`-Blöcke.
     *
     * @return array<string, string>
     */
    private function stylesheets(): array
    {
        $sheets = [
            'resources/css/app.css' => $this->withoutComments(
                (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
            ),
        ];

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) === 1) {
                $sheets[$this->relative($path)] = $this->withoutComments($match[1]);
            }
        }

        return $sheets;
    }

    public function test_every_class_name_comes_from_the_vocabulary(): void
    {
        $unknown = [];
        $checked = 0;

        foreach ($this->stylesheets() as $name => $css) {
            foreach (array_unique($this->classesIn($css)) as $class) {
                $checked++;

                foreach (explode('-', $class) as $word) {
                    if (in_array($word, self::NOT_A_CLASS, true)) {
                        continue 2;
                    }

                    if (! in_array($word, self::VOCABULARY, true)) {
                        $unknown[] = sprintf('%s  .%s  (unbekannt: „%s")', $name, $class, $word);
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $checked, 'Es werden kaum Klassen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], array_values(array_unique($unknown)), sprintf(
            "Diese Klassennamen stehen nicht im Vokabular:\n  %s\n\n".
            "Klassennamen sind englisch (CLAUDE.md). Wer eine Klasse hinzufügt, trägt ihr Wort in\n".
            'VOCABULARY in diesem Test ein — die Zeile steht im Diff, und dort fällt ein deutsches '.
            'Wort auf.',
            implode("\n  ", array_unique($unknown)),
        ));
    }

    /**
     * Und keine Regel ohne Nutzer.
     *
     * **Die Gegenrichtung zu `ClassReachTest`.** Der prüft, dass jede Klasse
     * eines Templates auf eine Regel zeigt. Diese hier prüft, dass jede Regel
     * von einem Template erreicht wird — und hat beim ersten Lauf drei tote
     * gefunden: `.pair-list` (eine Beschreibungsliste, die seit „Kontor"
     * niemand mehr baut) sowie `.output .time` und `.output .fehl`, zwei
     * Regeln für Markup, das die Vorgangsausgabe längst nicht mehr erzeugt —
     * sie ist reiner Text in einem `<pre>`.
     *
     * Tote Regeln sind nicht bloss Ballast: `.fehl` war ausserdem die letzte
     * deutsche Klasse, die beim Umbenennen fast durchgerutscht wäre, weil sie
     * in keinem Template steht und deshalb niemandem auffällt.
     */
    public function test_every_rule_in_app_css_is_reached_by_a_template(): void
    {
        $defined = array_unique($this->classesIn(
            $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css')),
        ));

        $used = [
            // Am Wurzelelement gesetzt, aus PanelLayout.vue über `classList`.
            'menu-open',
        ];

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
                continue;
            }

            $template = (string) preg_replace('/<!--.*?-->/su', '', $match[1]);

            // class="a b c"
            preg_match_all('/\bclass="([^"]*)"/', $template, $statisch);
            foreach ($statisch[1] as $liste) {
                $used = array_merge($used, preg_split('/\s+/', trim($liste)) ?: []);
            }

            // :class="{ a: …, b }" — die Schlüssel
            preg_match_all('/:class="\{([^}]*)\}"/su', $template, $objekte);
            foreach ($objekte[1] as $inhalt) {
                preg_match_all('/(?<![\w.\'"-])([\w-]+)\s*[:,}]/', $inhalt, $keys);
                $used = array_merge($used, $keys[1]);
                preg_match_all('/(?<![\w.\'":-])([\w-]+)\s*(?=,|$)/', trim($inhalt), $kurz);
                $used = array_merge($used, $kurz[1]);
            }

            // :class="bedingung ? 'a' : 'b'"
            preg_match_all('/:class="[^"]*\?\s*\'([^\']*)\'\s*:\s*\'([^\']*)\'/', $template, $ternaer);
            $used = array_merge($used, $ternaer[1], $ternaer[2]);
        }

        $used = array_unique(array_filter($used));

        $orphans = [];

        foreach ($defined as $class) {
            if (in_array($class, self::NOT_A_CLASS, true)) {
                continue;
            }

            if (! in_array($class, $used, true)) {
                $orphans[] = '.'.$class;
            }
        }

        $this->assertGreaterThan(30, count($defined), 'app.css definiert kaum Klassen — dann prüft dieser Test nichts.');

        $this->assertSame([], $orphans, sprintf(
            "Diese Regeln in app.css erreicht kein Template:\n  %s\n\n".
            "Eine Klasse ohne Nutzer ist kein Baustein, sondern ein Rest — und beim nächsten Umbau\n".
            'hält sich jemand daran fest. Entweder fehlt die Verwendung, oder die Regel gehört weg.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * Die Klassen eines Stylesheets.
     *
     * @return list<string>
     */
    private function classesIn(string $css): array
    {
        preg_match_all('/(?<!\d)\.([a-z][\w-]*)/', $css, $matches);

        return $matches[1];
    }
}
