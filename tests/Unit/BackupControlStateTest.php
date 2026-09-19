<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BackupStatus;
use PHPUnit\Framework\TestCase;

/**
 * Das Bedienelement, das eine Sicherung entfernt, kennt den Zustand der Zeile.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Befund A des Nachlaufs zu `0.7.4-rc.16`** (`docs/123 §9`), gemessen am
 * 18. September 2026 auf `cloudsrv24`. Die Zeile stand auf `wird entfernt`, und
 * daneben liess sich das Entfernen ein zweites Mal auslösen: `Herunterladen`
 * und `Zurückspielen` hängen an `usable` und waren fort, der gefährlichste der
 * drei trug kein `v-if`.
 *
 * **Und `BackupPick.vue` hat es die ganze Zeit richtig gemacht.** Es war damit
 * keine Entwurfsfrage — eine der beiden Listen zeigte, wie es gemeint war.
 *
 * > **Zwei Fassungen derselben Regel — und die zweite ist die, die veraltet.**
 *
 * Die Begründung von {@see BackupStatus::Removing} setzt es
 * ausserdem voraus: *„sonst wäre ‚wird entfernt' eine Sackgasse ohne Knopf und
 * ohne zweiten Versuch."* Stünde der Knopf ohnehin immer da, trüge dieser Satz
 * nicht.
 *
 * ## Was er hält, und woran die Klammer hängt
 *
 * **Eine Seite, die vom Server ein `running` bekommt, versteckt ihr änderndes
 * Bedienelement dahinter.** Gesucht werden die Dateien, die `running: boolean`
 * deklarieren — heute die beiden Sicherungslisten, morgen jede weitere, die den
 * Wert bekommt. Keine Liste von Dateinamen, die beim nächsten Umbau veraltet.
 *
 * **Der erste Wurf hing am Namen des Handlers** und meldete vier Stellen mehr:
 * `entfernen(` heisst in den Ankündigungen, bei den PHP-Fassungen, im Cron und
 * beim SFTP-Zugang genauso — Merkmale ohne jeden Zustand dieser Art.
 *
 * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von dem,
 * > der ihn gebaut hat.**
 *
 * ## Was er ausdrücklich nicht verlangt
 *
 * **Dass beide Listen gleich aussehen.** `BackupPick.vue` zeigt im `v-else` die
 * Beschriftung des Zustands, `Backups.vue` nicht — dort gibt es eine Spalte
 * *Zustand*, und derselbe Satz stünde sonst zweimal in einer Zeile. Eine Regel,
 * zwei richtige Anzeigen.
 */
final class BackupControlStateTest extends TestCase
{
    /**
     * Die Untergrenze.
     *
     * **Zwei Listen zeigen Sicherungen.** Findet der Ausdruck weniger, greift
     * er ins Leere — etwa weil `entfernen(` umbenannt wurde —, und ein grüner
     * Lauf sagte nichts.
     */
    private const MINDESTENS = 2;

    /** Die Marke, an der eine Zeile mit Zustand zu erkennen ist. */
    private const MARKE = 'running: boolean';

    public function test_a_removal_control_stands_behind_the_running_state(): void
    {
        $gefunden = 0;
        $ungeschuetzt = [];

        foreach ($this->dateien() as $pfad) {
            $text = (string) file_get_contents($pfad);
            $kurz = str_replace(dirname(__DIR__, 2).'/', '', $pfad);

            if (! str_contains($text, self::MARKE)) {
                continue;
            }

            $gefunden++;

            if (! preg_match_all('/@click="entfernen\(/', $text, $_, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($_[0] as [$__, $stelle]) {

                // Vom `@click` zurück bis zum öffnenden `<button` oder `<a`.
                $anfang = max(
                    (int) strrpos(substr($text, 0, $stelle), '<button'),
                    (int) strrpos(substr($text, 0, $stelle), '<a ')
                );

                $tag = substr($text, $anfang, $stelle - $anfang);

                if (! str_contains($tag, 'v-if') || ! str_contains($tag, 'running')) {
                    $ungeschuetzt[] = $kurz;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINDESTENS,
            $gefunden,
            'Der Ausdruck hat kaum Zeilen mit Zustand gefunden — er misst nichts mehr.'
        );

        $this->assertSame(
            [],
            $ungeschuetzt,
            "Hier lässt sich das Entfernen auslösen, während die Zeile schon entfernt wird.\n"
            ."Erwartet ist ein v-if auf running, wie in BackupPick.vue:\n  "
            .implode("\n  ", $ungeschuetzt)
        );
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $gefunden = [];

        /** @var \SplFileInfo $datei */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel)) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $gefunden[] = $datei->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }
}
