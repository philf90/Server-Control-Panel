<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Zwei Knöpfe in einer Tabellenzelle stehen in einer `.button-row`.
 *
 * **Der Anlass ist dieser Fehler zum zweiten Mal, gefunden von derselben
 * Person am selben Server.** In P5b trug auf der PHP-Seite jede Zeile genau
 * einen Knopf, und der Abstand war nie eine Frage; mit „Ergänzen" neben
 * „Entfernen" war er es (`docs/38 §24.2`). Damals entstand die Regel
 * `td.right > .button-row` in `app.css`, und ihr Kommentar sagt den Grund
 * wörtlich: *„in eine Reihe gehören sie, weil sie sonst ohne Abstand
 * aneinanderkleben"*.
 *
 * Am 6. September 2026 bekam die Ankündigungsseite einen zweiten Knopf — die
 * Vorschau —, und die Reihe fehlte wieder. Die Regel gab es, der Kommentar
 * beschrieb den Ausfall, und **nichts hat sie durchgesetzt**.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Warum die Zelle und nicht jeder Ort mit zwei Knöpfen
 *
 * Der erste Ausdruck fragte nach zwei benachbarten Knöpfen **irgendwo** und
 * meldete zehn Stellen; neun davon standen in einem `<template #actions>`,
 * das seinen Abstand selbst mitbringt. Ein Wächter, der so meldet, wird
 * abgeschaltet.
 *
 * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von dem,
 * > der ihn gebaut hat.**
 *
 * Gemessen an der engen Frage: 398 Zellen, **sechs** mit zwei oder mehr
 * Knöpfen, und genau **eine** ohne die Reihe — die neue. Die anderen fünf
 * halten die Form schon; damit ist sie als Hausform belegt und nicht als
 * Geschmack.
 *
 * ## Was er nicht kann
 *
 * Er liest `<td …>…</td>` als Text und zählt darin die Knöpfe. Zwei Knöpfe,
 * die aus einer Schleife oder einem `v-if` entstehen und im Markup nur einmal
 * stehen, sieht er als einen. Und über den Abstand **ausserhalb** von Tabellen
 * sagt er nichts — dort tragen ihn die Hüllen der Bausteine.
 */
final class ButtonRowTest extends TestCase
{
    /**
     * Wieviele Zellen mindestens zusammenkommen müssen.
     *
     * Gemessen am 6. September 2026: 398. Die Untergrenze liegt weit darunter
     * — sie fängt den Fall ab, dass der Ausdruck über `<td>` ins Leere läuft.
     * Dann meldet dieser Wächter nichts und sieht aus wie erfüllt.
     */
    private const AT_LEAST = 250;

    public function test_two_buttons_in_a_cell_sit_in_a_row(): void
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $befunde = [];
        $zellen = 0;

        foreach ($this->templates($wurzel) as $datei) {
            $quelle = (string) file_get_contents($datei);

            if (! str_contains($quelle, '<template>')) {
                continue;
            }

            $vorlage = substr($quelle, (int) strpos($quelle, '<template>'));

            preg_match_all('/<td\b.*?<\/td>/s', $vorlage, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as [$zelle, $wo]) {
                $zellen++;

                $knoepfe = preg_match_all('/<(?:button|Link|a)\b[^>]*class="[^"]*\bbutton\b/', $zelle);

                if ($knoepfe < 2 || str_contains($zelle, 'button-row')) {
                    continue;
                }

                $befunde[] = sprintf(
                    '%s: Zeile %d — %d Knöpfe in einer Zelle ohne `.button-row`. '
                    .'Sie kleben dann aneinander; app.css hält die Reihe für genau diesen Fall bereit.',
                    basename($datei),
                    substr_count(substr($vorlage, 0, $wo), "\n") + 1,
                    $knoepfe,
                );
            }
        }

        self::assertSame([], $befunde, implode("\n", $befunde));

        self::assertGreaterThanOrEqual(
            self::AT_LEAST,
            $zellen,
            sprintf(
                'Nur %d Zellen gefunden. Trifft der Ausdruck <td> nicht mehr, hat dieser Wächter nichts geprüft.',
                $zellen,
            ),
        );
    }

    /**
     * Die Regel, für die es die Reihe gibt, steht in `app.css`.
     *
     * Ohne diesen Fall wäre die Regel darüber erfüllt, sobald irgendwo das
     * Wort `button-row` steht — auch wenn die Klasse keinen Abstand mehr setzt
     * oder in einer rechtsbündigen Zelle nach links rutscht.
     */
    public function test_the_row_keeps_its_gap_and_its_alignment(): void
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        self::assertSame(1, preg_match('/(^|\n)\.button-row\s*\{([^{}]*)\}/s', $css, $reihe));
        self::assertMatchesRegularExpression('/(?:^|;|\s)gap:\s*(?!0\b|0px)\S+;/', $reihe[2],
            '`.button-row` ohne Fuge ist keine Reihe — genau das war der Befund.');

        self::assertSame(1, preg_match('/(^|\n)td\.right > \.button-row\s*\{([^{}]*)\}/s', $css, $zelle));
        self::assertStringContainsString('flex-end', $zelle[2],
            '`td.right` setzt `text-align`, und das erreicht ein Flexkind nicht — ohne diese Regel '
            .'rutschen die Knöpfe in der letzten Spalte nach links.');
    }

    /**
     * @return list<string>
     */
    private function templates(string $wurzel): array
    {
        $gefunden = [];

        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $gefunden[] = $datei->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }
}
