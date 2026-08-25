<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein `<style>`- oder `<script>`-Block einer `.vue` steht auf oberster Ebene.
 *
 * ## Der Fund
 *
 * Der Betreiber hat am 25. August 2026 gemeldet, die Marke „diese Sitzung"
 * klebe ohne Abstand an der Adresse (`docs/83`, Punkt 13). Im Quelltext stand
 * der Abstand: `.title-row { display: flex; gap: 8px }`.
 *
 * Der Block, in dem er stand, war **in den `<template>`-Block gerutscht** —
 * zwischen den benannten Bereich `#breadcrumb` und den ersten Inhalt. Ein
 * `<style>` dort ist kein Block der Komponente, sondern Markup, und Vues
 * Übersetzer wirft es weg: Die Regeln stehen weder im gebauten Stylesheet noch
 * in der Renderfunktion. Nachgezählt am Bündel — kein `data-v`-Kennzeichen
 * trug `.agent` und `.title-row`, während die beiden anderen Komponenten mit
 * einer gleichnamigen Regel ihres hatten.
 *
 * Auf der Seite waren damit **beide** Regeln dieser Komponente fort. Die Marke
 * stand auf 0 px neben der Adresse — nicht auf 4, wie ein von Hand
 * geschriebener Aufsatz meldet: Vue zieht den Zeilenumbruch zwischen zwei
 * Elementen ein, ein Aufsatz behält ihn als Wortabstand.
 *
 * > **Ein Block, der an der falschen Stelle steht, ist kein falsch stehender
 * > Block — er ist keiner.**
 *
 * ## Warum kein bestehender Wächter ihn sah
 *
 * `ClassReachTest` fragt, ob jede Klasse einer Vorlage eine Regel hat, und
 * suchte sie mit `#<style[^>]*>(.*)</style>#su` **in der ganzen Datei**. Für
 * diese Frage sieht ein weggeworfener Block genauso aus wie ein wirksamer.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht statt eines Blocks, ist grün,
 * > sobald die Zeichenkette irgendwo steht.**
 *
 * Derselbe Satz wie bei Punkt 12 aus `docs/62`, dort über die Erreichbarkeit
 * einer Meldung. `ClassReachTest` schneidet den Vorlagenblock seitdem heraus,
 * bevor er nach `<style>` sucht — dieser Wächter hier hält die Regel selbst,
 * damit der Fehler auch dann auffällt, wenn niemand nach einer Klasse fragt.
 *
 * ## Warum die Spalte und nicht nur die Verschachtelung
 *
 * Ein eingerückter Block wäre auch dann keiner, wenn er hinter `</template>`
 * stünde — Vue trennt die Blöcke am Element und nicht an der Spalte, aber jede
 * `.vue` dieses Repos schreibt sie an den linken Rand. Eine Einrückung ist
 * deshalb das Zeichen dafür, dass jemand den Block in etwas hineingeschrieben
 * hat, und wird hier gemeldet, bevor sie wirkt.
 */
final class SfcBlockTest extends TestCase
{
    /**
     * Kein `<style>` und kein `<script>` steht innerhalb des Vorlagenblocks.
     */
    public function test_no_block_hides_inside_the_template(): void
    {
        $funde = [];
        $geprueft = 0;

        foreach ($this->components() as $pfad => $quelle) {
            $spanne = $this->templateSpan($quelle);

            if ($spanne === null) {
                continue;
            }

            $geprueft++;

            [$von, $bis] = $spanne;

            foreach (['style', 'script'] as $marke) {
                foreach ($this->markers($quelle, $marke) as $stelle) {
                    if ($stelle > $von && $stelle < $bis) {
                        $funde[] = sprintf(
                            '%s: <%s> in Zeile %d steht innerhalb von <template>',
                            $pfad,
                            $marke,
                            substr_count(substr($quelle, 0, $stelle), "\n") + 1,
                        );
                    }
                }
            }
        }

        /*
         * **Die Untergrenze steht daneben**, weil ein leeres `$funde` sonst
         * auch dann grün wäre, wenn der Ausdruck für `<template>` ins Leere
         * liefe und gar keine Datei geprüft würde.
         *
         * > Eine Null ist nur dann eine Messung, wenn daneben etwas anderes
         * > als Null steht.
         */
        $this->assertGreaterThan(50, $geprueft, 'Es wurde fast nichts geprüft — der Ausdruck für <template> greift nicht mehr.');

        $this->assertSame([], $funde, sprintf(
            "Diese Blöcke stehen im Vorlagenblock und werden vom Übersetzer weggeworfen:\n  %s\n\n".
            'Ein <style scoped> gehört an das Ende der Datei, hinter </template>.',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Jeder `<style>`-Block beginnt am linken Rand.
     */
    public function test_every_style_block_starts_at_the_margin(): void
    {
        $funde = [];
        $mitStil = 0;

        foreach ($this->components() as $pfad => $quelle) {
            $stellen = $this->markers($quelle, 'style');

            if ($stellen === []) {
                continue;
            }

            $mitStil++;

            foreach ($stellen as $stelle) {
                $zeilenanfang = strrpos(substr($quelle, 0, $stelle), "\n");
                $spalte = $stelle - ($zeilenanfang === false ? -1 : $zeilenanfang) - 1;

                if ($spalte !== 0) {
                    $funde[] = sprintf(
                        '%s: <style> in Zeile %d steht um %d Zeichen eingerückt',
                        $pfad,
                        substr_count(substr($quelle, 0, $stelle), "\n") + 1,
                        $spalte,
                    );
                }
            }
        }

        // Dieselbe Untergrenze wie oben, und aus demselben Grund.
        $this->assertGreaterThan(15, $mitStil, 'Es wurde fast nichts geprüft — der Ausdruck für <style> greift nicht mehr.');

        $this->assertSame([], $funde, sprintf(
            "Diese <style>-Blöcke stehen eingerückt und sind damit keine Blöcke der Komponente:\n  %s",
            implode("\n  ", $funde),
        ));
    }

    /**
     * Die Anfangsstellen aller `<marke …>` einer Quelle.
     *
     * @return list<int>
     */
    private function markers(string $quelle, string $marke): array
    {
        preg_match_all('#<'.$marke.'(?:\s[^>]*)?>#s', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $t): int => (int) $t[1], $treffer[0]);
    }

    /**
     * Anfang und Ende des obersten `<template>`-Blocks.
     *
     * `<template #name>` und `<template v-if>` sind benannte Bereiche innerhalb
     * der Vorlage und keine Blöcke — der oberste trägt keine Attribute und
     * steht am linken Rand.
     *
     * @return array{int, int}|null
     */
    private function templateSpan(string $quelle): ?array
    {
        if (preg_match('/^<template>$/m', $quelle, $t, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        if (preg_match('#^</template>$#m', $quelle, $e, PREG_OFFSET_CAPTURE) !== 1) {
            // Mehrere Zeilen `</template>` am linken Rand gibt es nicht; gäbe
            // es sie, wäre die Datei ohnehin ein Fall für den Menschen.
            $letzte = strrpos($quelle, "\n</template>");

            return $letzte === false ? null : [(int) $t[0][1], $letzte];
        }

        return [(int) $t[0][1], (int) $e[0][1]];
    }

    /**
     * Alle Komponenten, Pfad zu Quelltext.
     *
     * @return array<string, string>
     */
    private function components(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $pfad = substr($datei->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
                $dateien[$pfad] = (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
