<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Was ein Kunde liest, ist an ihn gerichtet.
 *
 * **Der Anlass:** Beim Ausschreiben des Durchlaufs mit einer fremden Person
 * (`docs/145 §0`, Befund 6) standen auf Kundenseiten Sätze, die ein Kunde
 * nicht brauchen kann:
 *
 * - ein frisches Abonnement nannte die Unit `srvpanel-usage.timer` und den
 *   Mount von `/var/www/vhosts`;
 * - vor „Datenbank entfernen" kam die Sicherung „mit einer späteren
 *   Ausbaustufe" — der Sprache dieses Repos;
 * - daneben stand als einziger Satz des Panels einer mit „du";
 * - die Fehlerseite 500 schickte jeden „unter Server → Logs", eine Seite, die
 *   es für einen Kunden nicht gibt.
 *
 * Gelesen wird die Vorlage ohne ihre Kommentare: Ein Kommentar, der die Regel
 * erklärt, darf das Wort tragen, gegen das sie steht.
 */
final class CustomerWordingTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Der Vorlagenblock einer Seite, ohne Kommentare.
     *
     * Vom ersten `<template>` bis zum letzten `</template>` — so liegt der
     * Block oberster Ebene, auch wenn darin weitere stehen.
     */
    private function template(string $path): string
    {
        $source = (string) file_get_contents($path);

        if (preg_match('/<template>(.*)<\/template>/s', $source, $treffer) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/s', '', $treffer[1]);
    }

    /** @return list<string> */
    private function pages(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** Das Panel siezt — überall, auch in dem einen Satz, der zuletzt dazukam. */
    public function test_the_panel_addresses_everyone_formally(): void
    {
        $pages = $this->pages();
        $this->assertGreaterThan(40, count($pages), 'Es werden kaum Seiten gefunden — dann prüft dieser Test nichts.');

        $gefunden = [];

        foreach ($pages as $path) {
            foreach (explode("\n", $this->template($path)) as $line) {
                // Ohne „dir": Das steckt in Kennungen wie `log_dir`, und als
                // Anrede ist es auf dieser Oberfläche nie vorgekommen.
                if (preg_match('/(?<![\p{L}\w])(du|dein|deine|deinen|deinem|deiner|dich)(?![\p{L}\w])/iu', $line) === 1) {
                    $gefunden[] = str_replace($this->root().'/', '', $path).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $gefunden, "Diese Zeilen duzen:\n  ".implode("\n  ", $gefunden));
    }

    /** Keine Seite spricht von Ausbaustufen — das ist die Sprache des Repos und nicht die des Panels. */
    public function test_no_page_speaks_of_stages(): void
    {
        $gefunden = [];

        foreach ($this->pages() as $path) {
            if (str_contains($this->template($path), 'Ausbaustufe')) {
                $gefunden[] = str_replace($this->root().'/', '', $path);
            }
        }

        $this->assertSame([], $gefunden, "Diese Seiten sprechen von einer Ausbaustufe:\n  ".implode("\n  ", $gefunden));
    }

    /**
     * **Unit, Mount und `docs/41` sieht nur der Betreiber.**
     *
     * Jede Stelle auf der Abonnementseite, die sie nennt, steht in einem Block
     * unter `props.can.update` — der Fähigkeit, die nur der Betreiber hat.
     * Gelesen wird die Verschachtelung der `<template>`-Blöcke, damit ein Satz
     * auch dann zählt, wenn darin noch eine Bedingung steht.
     */
    public function test_a_customer_reads_neither_unit_nor_mount(): void
    {
        $template = $this->template($this->root().'/resources/js/Pages/Subscriptions/Show.vue');

        $stellen = 0;

        foreach (['srvpanel-usage.timer', 'docs/41-dateisystem-quota.md', 'Dateisystem-Quota auf dem Mount'] as $wort) {
            $offset = 0;

            while (($pos = strpos($template, $wort, $offset)) !== false) {
                $stellen++;
                $offset = $pos + strlen($wort);

                $this->assertTrue(
                    $this->insideOperatorBlock($template, $pos),
                    sprintf('„%s" steht auf der Abonnementseite ausserhalb von props.can.update — ein Kunde liest es.', $wort),
                );
            }
        }

        $this->assertGreaterThan(2, $stellen, 'Die Sätze für den Betreiber sind fort — dann prüft dieser Test nichts.');
    }

    /** Steht diese Stelle in einem geöffneten `<template v-if="props.can.update">`? */
    private function insideOperatorBlock(string $template, int $position): bool
    {
        preg_match_all('/<template\b[^>]*>|<\/template>/', substr($template, 0, $position), $tags);

        $stapel = [];

        foreach ($tags[0] as $tag) {
            if ($tag === '</template>') {
                array_pop($stapel);
            } else {
                $stapel[] = $tag;
            }
        }

        return in_array('<template v-if="props.can.update">', $stapel, true);
    }

    /** Die Fehlerseite sagt, wer nachsehen kann — sie gilt auch für Kunden. */
    public function test_the_error_page_says_who_can_look(): void
    {
        $seite = (string) file_get_contents($this->root().'/resources/views/errors/500.blade.php');

        $this->assertStringContainsString(
            'der Betreiber findet es',
            $seite,
            'Die Fehlerseite schickt wieder jeden auf eine Seite des Betreibers, ohne es zu sagen.',
        );
    }
}
