<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein `aria-labelledby` zeigt auf eine Kennung, die es gibt — und steht nicht
 * neben einem zweiten Namen.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Das ist der Fehler, der in diesem Projekt am häufigsten wiederkehrt und den
 * CLAUDE.md ganz oben nennt: *eine Zeichenkette, die auf etwas verweist, ohne
 * dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft.* Eine Policy ohne
 * Route, ein Kommando ohne Startskript, ein Verzeichnisname nach einer
 * Umbenennung — und hier eine Kennung, die kein Element trägt.
 *
 * Er fällt niemandem auf, weil nichts passiert: Kein Fehler, keine Meldung, nur
 * ein Block, der für jemanden, der die Seite hört, keinen Namen mehr hat.
 *
 * ## Und warum daneben kein `aria-label` stehen darf
 *
 * Der Baum der Dateiliste trug bis zum 16. August 2026 ein `aria-label` und
 * sonst nichts — für das Auge gab es keine Überschrift. Unter 720px steht er
 * unmittelbar über der Krümelspur, und beide fangen mit „Abo-Wurzel" an; der
 * Betreiber hat gemeldet, dass sich das als **eine** Liste mit einem doppelten
 * Eintrag liest (`docs/55`, Befund 23).
 *
 * Die Antwort war eine sichtbare Überschrift. Sie **statt** des `aria-label`
 * und nicht dazu:
 *
 * > **Ein sichtbarer Titel und ein `aria-label` sind zwei Fassungen desselben
 * > Satzes — und die zweite ist die, die veraltet.**
 *
 * Wo beides steht, gewinnt `aria-label`, und der Vorleser sagt dann etwas
 * anderes als das, was auf dem Schirm steht.
 */
final class LabelReachTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function templates(): array
    {
        $dateien = [];
        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                dirname(__DIR__, 2).'/resources/js',
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    public function test_every_labelled_by_names_an_id_that_exists(): void
    {
        $gefunden = 0;

        foreach ($this->templates() as $pfad) {
            $markup = (string) file_get_contents($pfad);

            preg_match_all('/\baria-labelledby="([^"{}]+)"/', $markup, $treffer);

            foreach ($treffer[1] as $verweis) {
                foreach (preg_split('/\s+/', trim($verweis)) ?: [] as $kennung) {
                    if ($kennung === '') {
                        continue;
                    }

                    $gefunden++;

                    $this->assertMatchesRegularExpression(
                        '/\bid="'.preg_quote($kennung, '/').'"/',
                        $markup,
                        sprintf(
                            '`%s` nennt als Beschriftung die Kennung `%s`, und kein Element in dieser '.
                            "Vorlage trägt sie.\n\n".
                            'Für das Auge ändert das nichts — der Block hat für jemanden, der die '.
                            'Seite hört, dann einfach keinen Namen mehr. Genau die Sorte Verweis, '.
                            'die dieses Projekt sechsmal teuer bezahlt hat.',
                            basename($pfad),
                            $kennung,
                        ),
                    );
                }
            }
        }

        /*
         * **Die Untergrenze zählt mit.** Sonst wäre dieser Wächter in dem
         * Moment grün, in dem sein Ausdruck nicht mehr passt — und das ist
         * derselbe Moment, in dem er gebraucht würde.
         */
        $this->assertGreaterThan(
            0,
            $gefunden,
            'Es wird kein einziges `aria-labelledby` gefunden — dann prüft dieser Wächter nichts.',
        );
    }

    public function test_nothing_carries_two_names_at_once(): void
    {
        foreach ($this->templates() as $pfad) {
            $markup = (string) file_get_contents($pfad);

            /*
             * Gesucht wird innerhalb **eines** Tags: `[^<>]*` läuft nicht über
             * ein `>` hinweg und nimmt damit nicht das `aria-label` des
             * nächsten Elements mit.
             */
            $this->assertDoesNotMatchRegularExpression(
                '/<[a-zA-Z][^<>]*\baria-labelledby="[^"]*"[^<>]*\baria-label="/s',
                $markup,
                sprintf(
                    "`%s` trägt an einem Element `aria-labelledby` **und** `aria-label`.\n\n".
                    'Dann gibt es zwei Fassungen desselben Satzes. `aria-label` gewinnt, und der '.
                    'Vorleser sagt etwas anderes als das, was auf dem Schirm steht.',
                    basename($pfad),
                ),
            );

            $this->assertDoesNotMatchRegularExpression(
                '/<[a-zA-Z][^<>]*\baria-label="[^"]*"[^<>]*\baria-labelledby="/s',
                $markup,
                sprintf(
                    '`%s` trägt an einem Element `aria-label` **und** `aria-labelledby` — in dieser '.
                    'Reihenfolge, und das ist derselbe Fehler.',
                    basename($pfad),
                ),
            );
        }
    }
}
