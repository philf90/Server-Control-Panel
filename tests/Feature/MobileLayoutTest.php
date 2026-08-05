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

    public function test_every_table_carries_one_of_the_patterns(): void
    {
        /*
         * Eine Tabelle ohne Muster ist auf 390px entweder abgeschnitten oder
         * sie schiebt die ganze Seite seitwärts.
         *
         * **Seit dem Rework sind es drei und nicht mehr zwei.** `.paare` ist
         * die Tabelle aus Bezeichnung und Wert — Kontingente, Freigaben,
         * Dienste. Sie passt auf 390px und muss weder rollen noch zu Kärtchen
         * zerfallen; ihr fehlte nur eine Regel, was mit dem Wert geschieht,
         * wenn eine Zustandsmarke in der dritten Spalte ihn zusammendrückt.
         * Ohne die Regel stand „3 von 10" auf drei Zeilen — gesehen in der
         * Aufnahme, nicht im Entwurf.
         *
         * Das dritte Muster ist keine Aufweichung der Regel, sondern eine
         * Lücke, die vorher jede Seite selbst gefüllt hat. docs/24 §5 nennt
         * alle drei und sagt, wann welches gilt.
         */
        $tables = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            if (! str_contains($template, '<table')) {
                continue;
            }

            preg_match_all('/<table([^>]*)>/', $template, $matches, PREG_OFFSET_CAPTURE);

            // Die Position kommt aus dem *ganzen* Treffer und nicht aus der
            // Klammer: Der Versatz der Attributklammer zeigt hinter `<table`,
            // und alles davor endet damit auf genau dieser Zeichenfolge statt
            // auf dem Behälter, nach dem hier gefragt wird.
            foreach ($matches[0] as $index => $match) {
                $attributes = $matches[1][$index][0];
                $offset = $match[1];
                $tables++;

                $stacks = str_contains($attributes, 'stacks');
                $pairs = str_contains($attributes, 'pairs');

                /*
                 * Rollt sie? Dann steht der Behälter unmittelbar davor.
                 *
                 * **Zweiter Anlauf an dieser Stelle, und beide Male aus
                 * demselben Grund:** Der Ausdruck sah sich den ganzen Text vor
                 * der Tabelle an, statt die eine Zeile davor. Erst stand hier
                 * `explode(...)[$index]` — das liefert ab der zweiten Tabelle
                 * nur das Stück *zwischen* zwei Tabellen. Dann wurde gezählt,
                 * offene `rollt` gegen geschlossene Tabellen, und das hielt
                 * genau so lange, wie jede Tabelle einer Seite gerollt hat:
                 * Eine gestackse Tabelle davor verschiebt die Bilanz, und die
                 * gerollten dahinter fielen durch — obwohl an ihnen nichts
                 * geändert wurde.
                 *
                 * Gefragt ist ohnehin etwas Einfacheres: Steht der Behälter
                 * direkt um diese Tabelle? Alles andere war eine Bilanz über
                 * eine Seite, die niemand behauptet hat.
                 */
                $before = rtrim(substr($template, 0, $offset));
                $scrolls = str_ends_with($before, '<div class="scrolls">');

                $this->assertTrue(
                    $stacks || $scrolls || $pairs,
                    sprintf(
                        'In %s steht eine Tabelle ohne Muster aus docs/24 §5. Messwerte gehören in '.
                        '<div class="scrolls">, Verzeichnisse bekommen class="stacks", und '.
                        'Bezeichnung-und-Wert bekommt class="pairs". Was gar keine Tabelle ist — '.
                        'ein Katalog von Aufgaben etwa —, wird auch keine.',
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
        // `data-column` steht danach ohne Beschriftung da — es sei denn, sie
        // enthält ein Bedienelement, das für sich selbst spricht.
        $cells = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            foreach ($this->stackedTables($template) as $table) {
                preg_match_all('#<td([^>]*)>(.*?)</td>#su', $table, $matches, PREG_SET_ORDER);

                foreach ($matches as $cell) {
                    $cells++;

                    $labelled = str_contains($cell[1], 'data-column');
                    $spans = str_contains($cell[1], 'colspan');
                    $acts = (bool) preg_match('/<(button|a|Link)\b/i', $cell[2]);

                    $this->assertTrue(
                        $labelled || $spans || $acts,
                        sprintf(
                            'In %s hat eine Zelle einer gestacksen Tabelle kein data-column und keine Aktion '.
                            'darin. Auf dem Telefon steht ihr Wert ohne Beschriftung (docs/24 §5).',
                            $this->relative($path),
                        ),
                    );
                }
            }
        }

        $this->assertGreaterThan(10, $cells, 'Es werden kaum Zellen gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Das Gerüst der schmalen Fläche ist eine Spalte und kein Raster.
     *
     * **Der Fehler, den das festhält, hing von einem Kind ab.** Das Gerüst war
     * unter 720px weiterhin ein Raster mit `grid-template-rows: auto 1fr` —
     * Kopfzeile oben, Inhalt darunter. Das ging, solange es zwei Kinder im
     * Fluss gab. Beim Wechsel in die Sicht eines Kunden kommt das Band dazu,
     * und damit rutscht die **Kopfzeile** in die `1fr`-Zeile und nimmt sich
     * allen übrigen Platz: Auf einem Telefon mit 844px Höhe war sie 591px
     * hoch, und zwischen Band und Seitentitel stand eine leere schwarze
     * Fläche. Der Inhalt landete in einer Zeile, die es im Raster gar nicht
     * gab.
     *
     * Am Schreibtisch sieht man das nie — dort gilt die Regel nicht, und ohne
     * „Anmelden als" gibt es das dritte Kind nicht.
     *
     * Eine dritte Zeile wäre die falsche Antwort gewesen: Dann zählt man
     * Kinder, und beim nächsten Band zählt jemand falsch. Auf einer Spalte
     * gibt es nichts zu zählen.
     */
    public function test_the_narrow_frame_is_a_column_and_not_a_grid(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue');
        $layout = (string) preg_replace('#/\*.*?\*/#su', '', $layout);

        if (preg_match('/@media\s*\(\s*max-width:\s*720px\s*\)\s*\{(.*)\n\}/su', $layout, $match) !== 1) {
            $this->fail('In PanelLayout.vue steht kein @media (max-width: 720px) mehr.');
        }

        if (preg_match('/(^|\})\s*\.frame\s*\{([^}]*)\}/s', $match[1], $frame) !== 1) {
            $this->fail('Unter 720px gibt es keine Regel für .frame mehr.');
        }

        $this->assertMatchesRegularExpression(
            '/display\s*:\s*flex/',
            $frame[2],
            'Das Gerüst der schmalen Fläche muss eine Spalte sein (display: flex). Ein Raster mit '.
            'festen Zeilen hängt davon ab, wie viele Kinder gerade da sind — und das Band von '.
            '„Anmelden als" ist eines davon.',
        );

        $this->assertSame(
            0,
            preg_match('/grid-template-rows/', $frame[2]),
            'Unter 720px setzt .frame wieder Rasterzeilen. Damit hängt die Höhe der Kopfzeile daran, '.
            'ob gerade jemand in der Sicht eines Kunden arbeitet.',
        );
    }

    /**
     * Was untereinander liegt, trennt keine senkrechte Linie.
     *
     * **Der Befund kam vom Telefon des Betreibers.** Unter 720px legt
     * `--kachel-min: 100%` die Kacheln untereinander — der Trenner aus
     * `.tile + .tile` blieb aber der **linke** Rand. Auf 390px stand damit ein
     * senkrechter Strich neben allen Kacheln ausser der ersten, und ihr Inhalt
     * war um 24px eingerückt: Die erste begann am Seitenrand, die vier darunter
     * nicht.
     *
     * **Auf meiner eigenen 390px-Aufnahme war es zu sehen.** Eine Aufnahme zu
     * machen genügt nicht, wenn man sie nur auf das ansieht, was man gerade
     * geändert hat. Deshalb hier eine Regel, die der Blick nicht ersetzt: Wer
     * eine Trennlinie hat und stapelt, dreht sie.
     */
    public function test_stacked_tiles_are_separated_from_above(): void
    {
        $css = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'));

        $regeln = $this->insideMediaQuery($css, 720);

        $this->assertNotSame('', $regeln, 'Es gibt keine 720px-Abfrage mehr — dann prüft dieser Test nichts.');

        if (preg_match('/(^|\})\s*\.tile\s*\+\s*\.tile\s*\{([^}]*)\}/s', $regeln, $tile) !== 1) {
            $this->fail(
                "Unter 720px gibt es keine Regel für `.tile + .tile` mehr.\n\n".
                'Ohne sie gilt der Trenner der breiten Fläche weiter — ein linker Rand neben Kacheln, '.
                'die untereinander liegen, und 24px Einrückung ab der zweiten.',
            );
        }

        $this->assertMatchesRegularExpression(
            '/border-top\s*:\s*1px/',
            $tile[2],
            'Gestapelte Kacheln brauchen ihre Trennlinie oben.',
        );

        $this->assertMatchesRegularExpression(
            '/border-left\s*:\s*0/',
            $tile[2],
            'Die senkrechte Trennlinie muss unter 720px weg — untereinander trennt sie nichts, '.
            'sondern rückt ein.',
        );

        $this->assertMatchesRegularExpression(
            '/padding-left\s*:\s*0/',
            $tile[2],
            'Mit der linken Linie geht auch der linke Abstand: Sonst beginnt die erste Kachel am '.
            'Seitenrand und jede weitere 24px daneben.',
        );
    }

    public function test_input_fields_use_the_zoom_safe_size(): void
    {
        /*
         * Ein Feld mit --text-body ist ein Feld, das Safari beim Fokus
         * hineinzoomt. Gesucht wird jede Regel, deren Selektor ein Feld nennt
         * und die eine Schriftgröße setzt.
         *
         * **Gesucht wird in app.css mit.** Vorher las dieser Test nur
         * `resources/js` — zu der Zeit brachte jede Seite ihr eigenes Feld mit,
         * und dort standen die Regeln. Genau das hört auf: Das Aussehen eines
         * Feldes gehört in app.css, wie das eines Knopfes
         * (`ButtonStyleTest::test_no_page_styles_a_field_itself`).
         *
         * Ohne diese Erweiterung hätte der Test in dem Augenblick nichts mehr
         * gefunden, in dem die Felder umziehen — und wäre an seiner eigenen
         * Untergrenze durchgefallen statt an der Sache. Ein Wächter, der beim
         * Aufräumen zubeisst, wird beim Aufräumen abgeschaltet.
         */
        $checked = 0;

        foreach ($this->stylesheets() as $name => $css) {
            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

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
                        $name,
                        $selector,
                        $token,
                        $this->scale()[$token] ?? 0,
                    ),
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'Es wird keine Feldregel mehr gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Jedes Stylesheet des Panels: app.css und die `<style>`-Blöcke.
     *
     * @return array<string, string>
     */
    private function stylesheets(): array
    {
        $sheets = [];

        foreach ($this->files('resources/css', 'css') as $path) {
            $sheets[$this->relative($path)] = $this->withoutComments((string) file_get_contents($path));
        }

        foreach ($this->files('resources/js', 'vue') as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) === 1) {
                $sheets[$this->relative($path)] = $this->withoutComments($match[1]);
            }
        }

        return $sheets;
    }

    /**
     * Kommentare weg, bevor eine Regel gelesen wird.
     *
     * **Das hat gefehlt, und es hat falschen Alarm geschlagen.** Der Ausdruck
     * `([^{}]*)\{([^{}]*)\}` nimmt als Selektor alles, was vor der Klammer
     * steht — also auch den Kommentar darüber. In app.css steht über `.schalter`
     * die Begründung, warum ein `input[type='checkbox']` dort seine eigene
     * Größe bekommt; das Wort „input" darin genügte, damit die Regel als
     * Feldregel galt und an `--text-table` durchfiel. Ein Ankreuzfeld zoomt
     * Safari nie hinein — das ist das Gegenteil einer echten Meldung.
     *
     * Jeder andere Wächter mit demselben Ausdruck macht das längst
     * (`ButtonStyleTest`, `TableStyleTest`, `ClassReachTest`); hier war es
     * vergessen worden. Ein Wächter, der bei einem gut kommentierten
     * Stylesheet Fehlalarm gibt, wird beim dritten Mal abgeschaltet.
     */
    private function withoutComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#su', '', $css);
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
     * Die Inhalte aller `.stacks`-Tabellen eines Templates.
     *
     * @return list<string>
     */
    private function stackedTables(string $template): array
    {
        preg_match_all('#<table[^>]*stacks[^>]*>(.*?)</table>#su', $template, $matches);

        return $matches[1];
    }

    /**
     * Der Inhalt aller `@media (max-width: Npx)`-Blöcke, zusammengesetzt.
     *
     * **Klammern zählen und nicht Zeichen abschneiden.** Der erste Anlauf nahm
     * 4000 Zeichen ab dem `@media` — und lief damit über das Ende der Abfrage
     * hinaus in die Regeln der breiten Fläche. Der Test fand dort `.tile +
     * .tile` mit `border-left` und meldete Rot für eine Regel, die an ihrer
     * Stelle richtig ist.
     */
    private function insideMediaQuery(string $css, int $breakpoint): string
    {
        $inhalt = '';
        $muster = sprintf('/@media\s*\(\s*max-width:\s*%dpx\s*\)\s*\{/', $breakpoint);

        preg_match_all($muster, $css, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[0] as $start) {
            $offen = 1;
            $i = (int) $start[1] + strlen((string) $start[0]);
            $von = $i;

            while ($offen > 0 && $i < strlen($css)) {
                $offen += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                $i++;
            }

            $inhalt .= substr($css, $von, $i - $von - 1);
        }

        return $inhalt;
    }
}
