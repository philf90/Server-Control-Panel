<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Jeder Zugriff auf den Speicher des Browsers steht in einem `try`.
 *
 * **Gemessen am 11. Oktober 2026 im Chromium** (`docs/144 §6b`): Sind die
 * Seitendaten abgeschaltet, wirft schon das Lesen von `window.localStorage` —
 * `SecurityError`, nicht `null`. In einem Profil, das nichts behält, wie es
 * ein privates Fenster benutzt, liest es sich dort ohne Ausnahme. Seit dem x
 * der Bänder läuft ein solcher Zugriff im Layout **jeder**
 * Seite; ohne die Klammer stünde jede Seite weiss da, und zwar genau bei dem,
 * der seine Seitendaten mit Absicht abgeschaltet hat.
 *
 * Die Übersicht hat es seit ihrem Takt richtig gemacht, aus einem Kommentar
 * heraus und ohne Wächter. Der zweite Ort ist dazugekommen; beim dritten soll
 * es nicht an einer Erinnerung hängen.
 *
 * > **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
 * > stimmt, nur durch Glück getrennt.**
 *
 * **Was er nicht kann:** eine Zeichenkette mit einer einzelnen geschweiften
 * Klammer von einem Block unterscheiden. Ein `${…}` in einem Template-Literal
 * zählt er auf und zu, und das gleicht sich aus; eine einzelne `{` in einem
 * Text verschöbe die Zählung, und dann schlägt seine Untergrenze nicht an,
 * sondern ein Befund — der Fehler fällt zur lauten Seite.
 */
final class BrowserStorageTest extends TestCase
{
    use WithoutMarkupComments;

    /** @return list<string> */
    private function sources(): array
    {
        $wurzel = __DIR__.'/../../resources/js';
        $dateien = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)) as $datei) {
            if ($datei instanceof \SplFileInfo && in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    /** Der Skriptteil: die ganze `.ts`, von einer `.vue` nur der `<script>`-Block. */
    private function script(string $datei): string
    {
        $quelle = $this->withoutMarkupComments((string) file_get_contents($datei));

        if (! str_ends_with($datei, '.vue')) {
            return $quelle;
        }

        return preg_match('#<script[^>]*>(.*?)</script>#s', $quelle, $treffer) === 1 ? $treffer[1] : '';
    }

    /**
     * Steht die Stelle in einem Block, den ein `try` öffnet?
     *
     * **Ein Stapel und keine Rückwärtssuche**, dieselbe Form wie in
     * `OperatorControlTest`: Vorwärts gelesen ist jeder offene Block bekannt,
     * wenn die Stelle kommt.
     */
    private function insideTry(string $script, int $stelle): bool
    {
        $stapel = [];

        for ($i = 0; $i < $stelle; $i++) {
            if ($script[$i] === '{') {
                $stapel[] = preg_match('/\btry\s*$/', substr($script, max(0, $i - 12), min(12, $i))) === 1;
            } elseif ($script[$i] === '}') {
                array_pop($stapel);
            }
        }

        return in_array(true, $stapel, true);
    }

    public function test_every_storage_access_sits_inside_a_try(): void
    {
        $befunde = [];
        $zugriffe = 0;
        $dateien = [];

        foreach ($this->sources() as $datei) {
            $script = $this->script($datei);

            preg_match_all('/\b(?:localStorage|sessionStorage)\b/', $script, $funde, PREG_OFFSET_CAPTURE);

            foreach ($funde[0] as [, $stelle]) {
                $zugriffe++;
                $dateien[$datei] = true;

                if (! $this->insideTry($script, (int) $stelle)) {
                    $befunde[] = sprintf(
                        '%s: Zeile %d des Skripts',
                        (string) preg_replace('#^.*?/resources/js/#', '', $datei),
                        substr_count(substr($script, 0, (int) $stelle), "\n") + 1,
                    );
                }
            }
        }

        /*
         * Gezählt am 11. Oktober 2026: fünf Zugriffe in zwei Dateien — zwei in
         * `Overview.vue`, drei in `useDismissedBands.ts`.
         */
        $this->assertGreaterThanOrEqual(5, $zugriffe,
            'Es werden kaum Zugriffe auf den Speicher gefunden — dann greift der Ausdruck ins Leere.');
        $this->assertGreaterThanOrEqual(2, count($dateien),
            'Die Zugriffe stehen nur noch in einer Datei — dann misst dieser Fall weniger, als er behauptet.');

        $this->assertSame([], $befunde, implode("\n", [
            'Diese Zugriffe auf den Speicher des Browsers stehen in keinem `try`:',
            '',
            '  '.implode("\n  ", $befunde),
            '',
            'Bei abgeschalteten Seitendaten wirft schon das Lesen (`SecurityError`), und ohne Klammer bricht die Seite ab.',
        ]));
    }

    /**
     * Die Zählung der Blöcke trifft, was sie treffen soll.
     *
     * Ein Prüfkörper und keine Datei des Repos: Eine Datei, die zufällig heute
     * so aussieht, sieht morgen anders aus.
     */
    public function test_the_block_count_tells_try_from_its_neighbours(): void
    {
        $script = <<<'TS'
            function a() { try { window.localStorage.getItem('a') } catch { window.localStorage.clear() } }
            function b() { const x = `${y}`; window.localStorage.getItem('b') }
            function c() { try { const z = `${y}`; window.localStorage.getItem('c') } catch {} }
            TS;

        $stellen = [];
        preg_match_all('/localStorage/', $script, $funde, PREG_OFFSET_CAPTURE);

        foreach ($funde[0] as [, $stelle]) {
            $stellen[] = $this->insideTry($script, (int) $stelle);
        }

        $this->assertSame([true, false, false, true], $stellen,
            'Im try gelesen, im catch, ausserhalb neben einem Template-Literal und im try neben einem Template-Literal.');
    }
}
