<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer eine Erfolgsmeldung setzen kann, nimmt sie im Fehlerfall zurück.
 *
 * **Warum es diesen Wächter gibt.** Im Abnahmelauf von P5c stand über der roten
 * Meldung „Der Vorgang hat 0 Zeilen getroffen" noch „Die Zeile ist geändert." in
 * Grün — von der Handlung davor (`docs/48 §3.10`). Der Kunde drückt einmal
 * Speichern und liest zwei Sätze über derselben Taste, von denen einer falsch
 * ist.
 *
 * > **Eine gescheiterte Handlung muss die Erfolgsmeldung der vorigen wegnehmen —
 * > sonst stehen zwei Sätze über derselben Taste, und einer ist falsch.**
 *
 * **Auf jeder anderen Seite dieses Panels kann das nicht passieren**, und genau
 * deshalb hat es niemand kommen sehen: Dort ist die Erfolgsmeldung ein `flash`
 * und lebt eine Antwort lang. Die Konsole aus P5c ist die erste Fläche, die
 * ändert und dabei stehen bleibt — sie wechselt die Seite nie, also räumt auch
 * nichts hinter ihr auf.
 *
 * Dieselbe Familie wie `docs/48 §3.4`: Zustand einer vorigen, erfolgreichen
 * Handlung überlebt eine gescheiterte.
 */
final class AnnounceWithdrawalTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Vorlage, die eine Erfolgsmeldung setzen kann.
     *
     * @return array<string, string>
     */
    private function announcing(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/import \{[^}]*\bannounce\b[^}]*\} from .*useAnnounce/', $source) !== 1) {
                continue;
            }

            $found[str_replace($this->root().'/', '', $file->getPathname())] = $source;
        }

        ksort($found);

        return $found;
    }

    /**
     * Der Weg zurück gibt es überhaupt.
     *
     * **Ohne ihn baut die nächste Seite ihren eigenen** — genau so ist
     * `useAnnounce` selbst entstanden, weil `docs/19 §6.3` einen Ort vorschreibt
     * und es keinen Weg dorthin gab.
     */
    public function test_there_is_a_way_to_take_it_back(): void
    {
        $source = (string) file_get_contents($this->root().'/resources/js/Composables/useAnnounce.ts');

        $this->assertStringContainsString(
            'export function dismiss(): void',
            $source,
            'Es gibt keinen Weg mehr, eine Erfolgsmeldung zurückzunehmen.',
        );

        $this->assertMatchesRegularExpression(
            '/export function dismiss\(\): void \{\s*message\.value = null\s*\}/',
            $source,
            'Der Rückweg nimmt nicht die Meldung zurück.',
        );
    }

    /**
     * Und wer einen Fehlersatz setzt, geht ihn.
     *
     * **Gemeint ist der Fehlersatz und nicht das Aufräumen davor.**
     * `failure.value = null` steht vor jeder Anfrage; erst eine Zuweisung mit
     * Inhalt ist eine Meldung an den Kunden.
     */
    public function test_reporting_a_failure_withdraws_the_success(): void
    {
        $dateien = $this->announcing();

        $this->assertNotSame(
            [],
            $dateien,
            'Keine Vorlage meldet mehr einen Erfolg. Dann ist dieser Wächter gegenstandslos und '.
            'gehört fort — er darf nicht still über nichts wachen.',
        );

        $geprueft = 0;

        foreach ($dateien as $pfad => $source) {
            foreach ($this->functions($source) as $name => $rumpf) {
                /*
                 * **Die Verneinung sitzt hinter dem `=` und nicht hinter dem
                 * Leerraum.** `\s*(?!null)` sieht richtig aus und ist es nicht:
                 * Schlägt die Vorschau fehl, gibt `\s*` ein Zeichen zurück und
                 * probiert es erneut — dann steht vor `null` ein Leerzeichen,
                 * die Vorschau greift ins Leere, und `failure.value = null`
                 * zählt als Meldung. Beim ersten Lauf hat dieser Wächter genau
                 * deshalb `loadTables` angezeigt, das nur aufräumt.
                 *
                 * > **Ein `\s*` vor einer Verneinung hebt sie auf.**
                 */
                if (preg_match('/failure\.value\s*=(?!\s*null)/', $rumpf) !== 1) {
                    continue;
                }

                $geprueft++;

                $this->assertStringContainsString(
                    'dismiss()',
                    $rumpf,
                    sprintf(
                        '`%s` in %s setzt einen Fehlersatz, ohne die Erfolgsmeldung zurückzunehmen. '.
                        'Diese Fläche wechselt die Seite nicht, also räumt auch nichts hinter ihr auf: '.
                        'Beide Sätze stehen danach übereinander.',
                        $name,
                        $pfad,
                    ),
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $geprueft,
            'Keine einzige Stelle setzt einen Fehlersatz — dann sucht dieser Wächter das Falsche, '.
            'und seine Zustimmung bedeutet nichts.',
        );
    }

    /**
     * Die Funktionen einer Vorlage, je Name ihr Rumpf.
     *
     * **Nur auf der äussersten Ebene**, und das genügt: Eine verschachtelte
     * Funktion zählt zum Rumpf der äusseren, ihr Fehlersatz fällt also dort auf.
     *
     * @return array<string, string>
     */
    private function functions(string $source): array
    {
        preg_match_all(
            '/^(?:async )?function (\w+)\([^)]*\)[^{]*\{(.*?)^\}/ms',
            $source,
            $treffer,
            PREG_SET_ORDER,
        );

        $gefunden = [];

        foreach ($treffer as $satz) {
            $gefunden[$satz[1]] = $satz[2];
        }

        return $gefunden;
    }
}
