<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Was am Rand einer Datei steht, steht auch auf ihrer obersten Ebene.
 *
 * ## Der Fund, der diese Regel nötig gemacht hat
 *
 * Am 20. August (`CLAUDE.md`): In einer `.vue` war `})})` entstanden — die
 * schliessende Klammer des einen `watch` war an die des nächsten gerutscht.
 * Ein `watch` samt seinem `ref` stand damit **innerhalb** eines Rückrufs, wurde
 * nie registriert, und das Merkmal war von seinem ersten Tag an wirkungslos.
 *
 * `vue-tsc` und `npm run build` liefen durch, und jeder Wächter fand jedes
 * Wort, das er suchte.
 *
 * > **Ein Wächter, der Wörter liest, sieht keine Klammern.**
 *
 * > **Ein Fehler, der in einer Funktion sitzt, wird vom Typprüfer entschuldigt
 * > — die Funktion läuft ja später.**
 *
 * ## Was dieser Wächter deshalb tut
 *
 * Er liest keine Wörter, sondern **zählt Klammern**. Eine Zeile, die in der
 * Datei ganz links beginnt, sieht aus wie oberste Ebene; dieser Wächter
 * vergleicht diesen Eindruck mit der tatsächlichen Verschachtelung. Weichen
 * sie ab, ist eine Klammer verrutscht — und zwar genau so, dass es niemandem
 * auffällt.
 *
 * **Und die Schlusstiefe gehört dazu.** Eine Datei, die auf einer anderen Tiefe
 * endet als sie begonnen hat, ist auch dann kaputt, wenn zufällig keine
 * Erklärung am Rand steht.
 *
 * ## Was er nicht kann
 *
 * Er sieht keine regulären Ausdrücke: `/[{]/` ist für ihn eine öffnende
 * Klammer. Deshalb steht die Schlusstiefe daneben — sie fiele als Erste auf,
 * und der Fehler wäre einer *dieses* Wächters und keiner der Seite.
 */
final class TopLevelSetupTest extends TestCase
{
    /**
     * Was am linken Rand stehen darf und dann auch dort stehen muss.
     *
     * @var list<string>
     */
    private const AM_RAND = [
        'watch', 'watchEffect', 'onMounted', 'onUnmounted', 'onBeforeUnmount',
        'const', 'let', 'function', 'async function',
    ];

    /** Jede Erklärung am linken Rand steht auch auf der obersten Ebene. */
    public function test_every_declaration_at_the_margin_is_top_level(): void
    {
        $dateien = 0;
        $stellen = 0;
        $verrutscht = [];

        foreach ($this->pages() as $pfad) {
            $code = $this->setupBlock((string) file_get_contents($pfad));

            if ($code === null) {
                continue;
            }

            $dateien++;
            $kurz = substr($pfad, strlen(dirname(__DIR__, 2)) + 1);
            $tiefe = 0;

            foreach (explode("\n", $code) as $nummer => $zeile) {
                if ($this->atMargin($zeile)) {
                    $stellen++;

                    if ($tiefe !== 0) {
                        $verrutscht[] = sprintf('%s:%d (Tiefe %d)', $kurz, $nummer + 1, $tiefe);
                    }
                }

                $tiefe += $this->balance($zeile);
            }

            if ($tiefe !== 0) {
                $verrutscht[] = sprintf('%s endet auf Tiefe %d statt 0', $kurz, $tiefe);
            }
        }

        /*
         * **Zwei Untergrenzen.** Die erste sagt, dass Dateien gefunden wurden,
         * die zweite, dass in ihnen überhaupt Erklärungen am Rand stehen. Ohne
         * die zweite wäre der Wächter auch dann grün, wenn die Auslese des
         * `<script setup>`-Blocks ins Leere liefe.
         */
        $this->assertGreaterThanOrEqual(30, $dateien, sprintf(
            'Nur %d Seiten mit `<script setup>` gefunden — dann prüft dieser Wächter nichts.',
            $dateien,
        ));

        $this->assertGreaterThanOrEqual(200, $stellen, sprintf(
            'Nur %d Erklärungen am linken Rand in %d Dateien — dann liest dieser Wächter die '.
            'Blöcke nicht mehr richtig.',
            $stellen,
            $dateien,
        ));

        $this->assertSame([], $verrutscht, sprintf(
            "Hier steht etwas am linken Rand, das nicht auf der obersten Ebene steht:\n\n  %s\n\n".
            'Eine verrutschte Klammer macht daraus Code **innerhalb** eines Rückrufs: Ein `watch` '.
            'wird dann nie registriert, und das Merkmal ist von seinem ersten Tag an wirkungslos. '.
            'Der Typprüfer entschuldigt es, weil die Funktion ja später läuft.',
            implode("\n  ", $verrutscht),
        ));
    }

    /** Beginnt diese Zeile am linken Rand mit einer Erklärung? */
    private function atMargin(string $zeile): bool
    {
        foreach (self::AM_RAND as $wort) {
            if (preg_match('/^'.preg_quote($wort, '/').'\b/', $zeile) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Wie viele Klammern diese Zeile öffnet, abzüglich der geschlossenen. */
    private function balance(string $zeile): int
    {
        $auf = strlen($zeile) - strlen(str_replace(['{', '(', '['], '', $zeile));
        $zu = strlen($zeile) - strlen(str_replace(['}', ')', ']'], '', $zeile));

        return $auf - $zu;
    }

    /**
     * Der `<script setup>`-Block ohne Zeichenketten und Kommentare.
     *
     * **Ersetzt wird durch Leerzeichen gleicher Länge**, damit Zeilennummern
     * und Spalten heil bleiben — ein Wächter, der eine Zeilennummer nennt, die
     * es so nicht gibt, schickt den Leser an die falsche Stelle.
     */
    private function setupBlock(string $quelle): ?string
    {
        if (preg_match('/<script setup[^>]*>(.*?)<\/script>/s', $quelle, $treffer) !== 1) {
            return null;
        }

        $code = $treffer[1];
        $aus = '';
        $i = 0;
        $n = strlen($code);

        while ($i < $n) {
            $zeichen = $code[$i];

            if ($zeichen === '/' && $i + 1 < $n && $code[$i + 1] === '/') {
                $ende = strpos($code, "\n", $i);
                $ende = $ende === false ? $n : $ende;
                $aus .= str_repeat(' ', $ende - $i);
                $i = $ende;

                continue;
            }

            if ($zeichen === '/' && $i + 1 < $n && $code[$i + 1] === '*') {
                $ende = strpos($code, '*/', $i + 2);
                $ende = $ende === false ? $n : $ende + 2;
                $aus .= $this->blank(substr($code, $i, $ende - $i));
                $i = $ende;

                continue;
            }

            if ($zeichen === '"' || $zeichen === "'" || $zeichen === '`') {
                $ende = $i + 1;

                while ($ende < $n) {
                    if ($code[$ende] === '\\') {
                        $ende += 2;

                        continue;
                    }

                    if ($code[$ende] === $zeichen) {
                        $ende++;

                        break;
                    }

                    $ende++;
                }

                $aus .= $this->blank(substr($code, $i, min($ende, $n) - $i));
                $i = min($ende, $n);

                continue;
            }

            $aus .= $zeichen;
            $i++;
        }

        return $aus;
    }

    /** Ein Stück Quelltext als Leerraum, Zeilenumbrüche behalten. */
    private function blank(string $stueck): string
    {
        return (string) preg_replace('/[^\n]/', ' ', $stueck);
    }

    /**
     * Alle Vue-Dateien unter `resources/js`.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $treffer = [];

        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));

        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $treffer[] = $datei->getPathname();
            }
        }

        sort($treffer);

        return $treffer;
    }
}
