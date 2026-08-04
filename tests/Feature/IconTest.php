<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jede Adresse im Kopf der Seite zeigt auf eine Datei, die es gibt.
 *
 * **Warum es diesen Test gibt.** `public/favicon.ico` lag bis August 2026 mit
 * **null Byte** da — der Platzhalter, den Laravel mitbringt. Aufgefallen ist
 * das niemandem, weil ein leeres Zeichen im Reiter genauso aussieht wie gar
 * keines: Der Browser zeichnet in beiden Fällen das leere Blatt. Ein Fehler,
 * der sich als Vorgabe tarnt, wird nicht gemeldet.
 *
 * Es ist dasselbe Muster, das dieses Projekt schon dreimal getroffen hat: ein
 * Verweis auf etwas, das niemand nachschlägt. Eine Policy ohne Route, ein
 * Kommando ohne Eintrag im Startskript, ein Zertifikat mit dem falschen Namen.
 * Deshalb prüft dieser Test nicht, wie das Zeichen aussieht — das kann er
 * nicht —, sondern das, was mechanisch prüfbar ist: dass die Kette von der
 * Zeile im Kopf bis zur Datei auf der Platte nirgends reisst.
 */
final class IconTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function referencedFiles(): array
    {
        $blade = (string) file_get_contents($this->root().'/resources/views/app.blade.php');

        preg_match_all('/<link\b[^>]*\bhref="(\/[^"]+)"/', $blade, $matches);

        $paths = $matches[1];

        $this->assertGreaterThanOrEqual(
            4,
            count($paths),
            'Im Kopf der Seite stehen kaum noch Verweise — dann prüft dieser Test nichts.',
        );

        return $paths;
    }

    public function test_every_head_link_points_at_a_file_that_exists(): void
    {
        foreach ($this->referencedFiles() as $path) {
            $file = $this->root().'/public'.$path;

            $this->assertFileExists($file, sprintf(
                'app.blade.php verweist auf %s — die Datei gibt es in public/ nicht.',
                $path,
            ));

            /*
             * Und sie darf nicht leer sein. Genau das war der Fehler: Die
             * Datei war da, der Verweis hätte gestimmt, und im Reiter stand
             * trotzdem nichts.
             */
            $this->assertGreaterThan(0, (int) filesize($file), sprintf(
                '%s ist leer. Eine Datei mit null Byte sieht im Browser aus wie eine fehlende.',
                $path,
            ));
        }
    }

    public function test_the_icon_file_is_really_an_icon(): void
    {
        $ico = (string) file_get_contents($this->root().'/public/favicon.ico');

        // Kopf einer .ico: zwei Nullbytes, Typ 1, dann die Zahl der Bilder.
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));

        $this->assertIsArray($header);
        $this->assertSame(0, $header['reserved'], 'Das ist keine .ico-Datei.');
        $this->assertSame(1, $header['type'], 'Typ 1 ist ein Icon; 2 wäre ein Mauszeiger.');
        $this->assertGreaterThanOrEqual(
            2,
            $header['count'],
            'Eine .ico mit nur einer Grösse wird irgendwo hochskaliert und sieht dort matschig aus.',
        );
    }

    /**
     * Die SVG trägt keine Farbe aus der Oberfläche, sondern ihre eigene.
     *
     * Das Zeichen im Reiter des Browsers steht nicht in unserem Theme — es
     * steht in der Leiste eines fremden Programms, und die ist mal hell und
     * mal dunkel. Deshalb bringt diese Fassung ihren eigenen Grund mit, statt
     * durchsichtig zu sein und in einer dunklen Leiste zu verschwinden.
     */
    public function test_the_svg_brings_its_own_background(): void
    {
        $svg = (string) file_get_contents($this->root().'/public/favicon.svg');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertMatchesRegularExpression(
            '/<rect[^>]*\bwidth="64"[^>]*\bheight="64"/',
            $svg,
            'Der SVG fehlt die deckende Fläche — in einer dunklen Browserleiste bliebe davon wenig.',
        );
    }

    /**
     * Das Manifest verweist nur auf Dateien, die es gibt.
     *
     * Es ist die zweite Liste mit Adressen neben dem Kopf der Seite, und die
     * zweite Liste ist die, die niemand pflegt.
     */
    public function test_the_manifest_lists_only_files_that_exist(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root().'/public/site.webmanifest'),
            true,
        );

        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest['icons'] ?? [], 'Ein Manifest ohne Zeichen ist keines.');

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists($this->root().'/public'.$icon['src'], sprintf(
                'site.webmanifest verweist auf %s — die Datei gibt es nicht.',
                $icon['src'],
            ));
        }
    }

    /**
     * Das Zeichen in der Oberfläche kommt aus einer Quelle.
     *
     * Geliefert wurde es dreifach — hell, dunkel, einfarbig. Drei Dateien in
     * der Oberfläche hiessen, an jeder Stelle die richtige auszuwählen, und
     * irgendwann die falsche. In den Seiten steht deshalb die Komponente, und
     * die Farbe kommt aus Marken, nicht aus Hexwerten (§7.2).
     */
    public function test_the_mark_in_the_interface_carries_no_colour_of_its_own(): void
    {
        $component = (string) file_get_contents(
            $this->root().'/resources/js/Components/MarkIcon.vue',
        );

        $this->assertSame(
            0,
            preg_match('/#[0-9a-fA-F]{3,8}\b/', $component),
            'In MarkIcon.vue steht ein Hexwert. Farben stehen in app.css (§7.2).',
        );

        $this->assertStringContainsString('currentColor', $component);
        $this->assertStringContainsString('var(--mark-accent)', $component);

        // Und die Marke gibt es in beiden Themes — sonst fiele sie in einem
        // davon auf den Vorgabewert des Browsers zurück, also auf Schwarz.
        $css = (string) file_get_contents($this->root().'/resources/css/app.css');

        $this->assertSame(
            2,
            preg_match_all('/--mark-accent\s*:/', $css),
            '--mark-accent muss in beiden Themes stehen, hell und dunkel.',
        );
    }
}
