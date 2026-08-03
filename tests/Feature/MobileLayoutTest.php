<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Vorgaben aus docs/24 — mechanisch geprüft.
 *
 * **Warum das ein Test ist.** Eine schmale Fläche fällt beim Entwickeln nicht
 * auf: Man baut am Schreibtisch, dort ist alles breit genug, und die Seite
 * sieht richtig aus. Der Bruch zeigt sich erst auf einem Telefon, und dann bei
 * demjenigen, der das Panel gerade benutzen wollte. Genau dieselbe Lage wie
 * bei den Schriftgrößen: keine Regel, kein Werkzeug, das sie prüft — und ein
 * Jahr später zehn Werte für fünf Rollen.
 *
 * Geprüft wird nicht, wie eine Seite aussieht. Geprüft wird, dass sie sich an
 * die vier Vorgaben hält, deren Verletzung man am Schreibtisch nicht sieht.
 */
final class MobileLayoutTest extends TestCase
{
    /** Die Haltepunkte aus docs/24 §1. */
    private const BREAKPOINTS = ['720px', '480px'];

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
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

    /** Der `<template>`-Block einer Vue-Datei, ohne HTML-Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    public function test_only_the_two_breakpoints_are_used(): void
    {
        $sources = array_merge($this->files('resources/js', 'vue'), $this->files('resources/css', 'css'));

        $this->assertGreaterThan(10, count($sources), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $found = 0;

        foreach ($sources as $path) {
            preg_match_all('/@media[^{]*\(\s*(?:max|min)-width:\s*([0-9.]+px)\s*\)/', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $width) {
                $found++;

                $this->assertContains(
                    $width,
                    self::BREAKPOINTS,
                    sprintf(
                        '%s benutzt den Haltepunkt %s. docs/24 kennt nur %s — ein dritter Wert lässt Seiten '.
                        'bei 640px umbrechen und bei 700px nicht.',
                        $this->relative($path),
                        $width,
                        implode(' und ', self::BREAKPOINTS),
                    ),
                );
            }
        }

        $this->assertGreaterThan(3, $found, 'Der Ausdruck findet keine Haltepunkte mehr.');
    }

    public function test_no_page_measures_in_vh(): void
    {
        // `vh` zählt die Adressleiste des Telefons mit, die beim Rollen
        // verschwindet — die Seite steht dann im Ausgangszustand zu hoch.
        foreach ($this->files('resources/js', 'vue') as $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                0,
                preg_match('/:\s*[0-9.]+vh\b/', $source),
                $this->relative($path).' misst in vh. Auf einem Telefon ist dvh gemeint (docs/24 §6).',
            );
        }
    }

    public function test_every_table_carries_one_of_the_two_patterns(): void
    {
        // Eine Tabelle ohne Muster ist auf 390px entweder abgeschnitten oder
        // sie schiebt die ganze Seite seitwärts.
        $tables = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            if (! str_contains($template, '<table')) {
                continue;
            }

            preg_match_all('/<table([^>]*)>/', $template, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                [$attributes, $offset] = $match;
                $tables++;

                $stacks = str_contains($attributes, 'stapelt');

                // Rollt sie? Dann steht der Behälter davor. Gezählt wird über
                // den ganzen Text bis zu dieser Tabelle — offene `rollt`
                // gegen bereits geschlossene Tabellen. Hier stand ein
                // `explode(...)[$index]`, und das liefert ab der zweiten
                // Tabelle nur das Stück *zwischen* zwei Tabellen statt alles
                // davor: Die erste Tabelle galt als in Ordnung, die zweite
                // und dritte nicht.
                $before = substr($template, 0, $offset);
                $scrolls = substr_count($before, 'class="rollt"') > substr_count($before, '</table>');

                $this->assertTrue(
                    $stacks || $scrolls,
                    sprintf(
                        'In %s steht eine Tabelle ohne Muster aus docs/24 §5. Messwerte gehören in '.
                        '<div class="rollt">, Verzeichnisse bekommen class="stapelt".',
                        $this->relative($path),
                    ),
                );
            }
        }

        $this->assertGreaterThan(4, $tables, 'Es werden kaum Tabellen gefunden — dann prüft dieser Test nichts.');
    }

    public function test_every_cell_of_a_stacked_table_is_labelled(): void
    {
        // Im Stapel verschwindet der Spaltenkopf. Eine Zelle ohne
        // `data-spalte` steht danach ohne Beschriftung da — es sei denn, sie
        // enthält ein Bedienelement, das für sich selbst spricht.
        $cells = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            foreach ($this->stackedTables($template) as $table) {
                preg_match_all('#<td([^>]*)>(.*?)</td>#su', $table, $matches, PREG_SET_ORDER);

                foreach ($matches as $cell) {
                    $cells++;

                    $labelled = str_contains($cell[1], 'data-spalte');
                    $spans = str_contains($cell[1], 'colspan');
                    $acts = (bool) preg_match('/<(button|a|Link)\b/i', $cell[2]);

                    $this->assertTrue(
                        $labelled || $spans || $acts,
                        sprintf(
                            'In %s hat eine Zelle einer gestapelten Tabelle kein data-spalte und keine Aktion '.
                            'darin. Auf dem Telefon steht ihr Wert ohne Beschriftung (docs/24 §5).',
                            $this->relative($path),
                        ),
                    );
                }
            }
        }

        $this->assertGreaterThan(10, $cells, 'Es werden kaum Zellen gefunden — dann prüft dieser Test nichts.');
    }

    public function test_input_fields_use_the_zoom_safe_size(): void
    {
        // Ein Feld mit --text-body ist ein Feld, das Safari beim Fokus
        // hineinzoomt. Gesucht wird jede Regel, deren Selektor ein Feld nennt
        // und die eine Schriftgröße setzt.
        $checked = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
                continue;
            }

            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $match[1], $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                if (! preg_match('/(^|[\s,>])(input|select|textarea)\b/', $selector)) {
                    continue;
                }

                if (! preg_match('/font-size:\s*var\(([^)]+)\)/', $rule[2], $size)) {
                    continue;
                }

                $checked++;
                $token = trim($size[1]);

                $this->assertTrue(
                    $this->zoomSafe($token),
                    sprintf(
                        '%s setzt an „%s" die Größe %s (%dpx). Eingabefelder brauchen --text-input oder eine '.
                        'Marke ab 16px, sonst zoomt Safari beim Fokus in die Seite (docs/24 §3).',
                        $this->relative($path),
                        $selector,
                        $token,
                        $this->scale()[$token] ?? 0,
                    ),
                );
            }
        }

        $this->assertGreaterThan(3, $checked, 'Es werden kaum Feldregeln gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Zoomt Safari bei dieser Marke nicht hinein?
     *
     * `--text-input` immer: Sie ist genau dafür da und steht auf der schmalen
     * Fläche auf 16px. Sonst entscheidet der Wert — alles ab 16px ist
     * unbedenklich, und das Codefeld mit `--text-metric` (22px) ist es damit
     * auch. Die Grenze wird nicht behauptet, sondern aus app.css gelesen:
     * Ändert jemand eine Marke, ändert sich diese Prüfung mit.
     */
    private function zoomSafe(string $token): bool
    {
        return $token === '--text-input' || ($this->scale()[$token] ?? 0) >= 16;
    }

    /**
     * Die Schriftskala aus app.css.
     *
     * @return array<string, float>
     */
    private function scale(): array
    {
        static $scale = null;

        if ($scale !== null) {
            return $scale;
        }

        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        preg_match_all('/(--text-[a-z]+):\s*([0-9.]+)px/', $css, $matches, PREG_SET_ORDER);

        $scale = [];

        foreach ($matches as $match) {
            // Der erste Treffer gewinnt: Die Grundskala steht oben,
            // Abweichungen für die schmale Fläche stehen darunter und sind
            // grösser. Für diese Prüfung zählt der ungünstigere Fall.
            $scale[$match[1]] ??= (float) $match[2];
        }

        return $scale;
    }

    /**
     * Die Inhalte aller `.stapelt`-Tabellen eines Templates.
     *
     * @return list<string>
     */
    private function stackedTables(string $template): array
    {
        preg_match_all('#<table[^>]*stapelt[^>]*>(.*?)</table>#su', $template, $matches);

        return $matches[1];
    }
}
