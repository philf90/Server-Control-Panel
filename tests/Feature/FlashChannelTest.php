<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Schreiber und ein Leser machen keinen Kanal.
 *
 * **Der Fund** (`docs/59`, Befund 13, Phase D von Punkt 8). Der Betreiber
 * drückte „Entfernen", der Vorgang scheiterte richtig ab — und die Seite sagte
 * **nichts**. `SftpController` schickte `->with('error', …)`,
 * `HandleInertiaRequests` gab den Schlüssel nicht weiter, und damit war die
 * Meldung fort, bevor sie jemand sehen konnte.
 *
 * Betroffen waren **sieben** Aufrufe aus vier Controllern, darunter „Zertifikat
 * abgewiesen: …" und „Der Versand ist gescheitert: …". Das Bitterste daran:
 * `Settings/Mail.vue` **las** `flash.error` und renderte ihn. Schreiber und
 * Leser waren also beide da, seit P4.
 *
 * > **Ein Kanal, den niemand trägt, ist kein Kanal — er ist ein Ort, an dem
 * > Meldungen verschwinden.**
 *
 * Das ist wörtlich das Muster aus `CLAUDE.md`: eine Zeichenkette, die auf etwas
 * verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft. Eine
 * Policy ohne Route, ein Kommando, das im Startskript fehlt — und jetzt ein
 * `flash`-Schlüssel ohne Träger.
 *
 * Geprüft werden **beide** Richtungen, wie bei `ClassNameTest`: Was ein
 * Controller schreibt, wird getragen; was getragen wird, liest jemand.
 */
final class FlashChannelTest extends TestCase
{
    private const MIDDLEWARE = 'app/Http/Middleware/HandleInertiaRequests.php';

    /**
     * Schlüssel, die ein Controller schreibt und die Mittelschicht nicht trägt.
     *
     * **Seit dem 23. September ist sie leer, und das ist ein Ergebnis.** Sie
     * führte seit `docs/59` Befund 13 zwei Einträge: `status` (elf Stellen in
     * drei Controllern) und `operation` (eine). Beide hat B8 geschlossen —
     * `status` heisst jetzt `success` und wird gerendert, und die Kennung des
     * Vorgangs sagt der Streifen oben.
     *
     * > **Ein Rest, den ein Merkmal beiläufig schliesst, war der Grund, ihn
     * > nicht früher zu schliessen — nicht der, ihn zu vergessen.**
     *
     * @return array<string, string>
     */
    private function knownLost(): array
    {
        /*
         * **Eine Methode und keine Konstante.** Eine leere Konstante ist für
         * PHPStan `array{}`, und jeder `array_key_exists()` darüber gilt ihm
         * als immer falsch — zu Recht. Ein `ignore` daneben hiesse, das
         * Werkzeug für eine Zeile abzuschalten, die morgen wieder etwas
         * enthält.
         *
         * > **Ein Mechanismus, der leer richtig ist, darf nicht daran
         * > zerbrechen, dass er leer ist.**
         */
        return [];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Schlüssel, die die Mittelschicht weitergibt.
     *
     * @return list<string>
     */
    private function carried(): array
    {
        $source = (string) file_get_contents($this->root().'/'.self::MIDDLEWARE);

        /*
         * **Zwischen dem Schlüssel und der Klammer darf etwas stehen.**
         *
         * Seit B4 ist jeder Eintrag von `share()` ein Verschluss
         * (`SharedClosureTest`), und aus `'flash' => [` wurde
         * `'flash' => fn (): array => [`. Der alte Ausdruck fand dann nichts
         * und gab eine leere Liste zurück — der Fall, gegen den die
         * Untergrenze in `test_every_written_flash_key_is_carried` steht. Sie
         * hat ihn gemeldet, und zwar sofort.
         *
         * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes
         * > als Null steht.**
         *
         * `[^[]*` statt der ausgeschriebenen Form: Wie der Verschluss genau
         * geschrieben ist, geht diesen Wächter nichts an — er sucht die
         * Ablage und nicht ihre Verpackung.
         */
        if (preg_match("/'flash' =>[^[]*\[(.*?)\n            \],/s", $source, $match) !== 1) {
            return [];
        }

        preg_match_all("/'([a-zA-Z_]+)' =>/", $match[1], $keys);

        return array_values(array_unique($keys[1]));
    }

    /**
     * Die Schlüssel, die ein Controller auf eine Weiterleitung schreibt.
     *
     * **Nur auf einer Weiterleitung**, und das ist der Grund für die Suche nach
     * `to_route`/`redirect`/`->route`: `->with('relation', …)` gibt es auch an
     * einer Abfrage, und dort ist es Eager Loading und kein `flash`.
     *
     * @return array<string,list<string>>
     */
    private function written(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app/Http', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents($file->getPathname()));

            foreach (explode(';', $source) as $statement) {
                if (preg_match('/(to_route\(|redirect\(|->route\()/', $statement) !== 1) {
                    continue;
                }

                preg_match_all("/->with\('([a-zA-Z_]+)'\s*,/", $statement, $keys);

                foreach ($keys[1] as $key) {
                    $found[$key][] = str_replace($this->root().'/', '', $file->getPathname());
                }
            }
        }

        return $found;
    }

    /** Was ein Controller schreibt, gibt die Mittelschicht weiter. */
    public function test_every_written_flash_key_is_carried(): void
    {
        $carried = $this->carried();
        $written = $this->written();

        $this->assertNotSame([], $carried, 'In der Mittelschicht wird keine flash-Ablage gefunden.');
        $this->assertGreaterThan(2, count($written), 'Es werden kaum flash-Schlüssel gefunden — dann prüft dieser Wächter nichts.');

        $lost = [];

        foreach ($written as $key => $files) {
            if (array_key_exists($key, $this->knownLost())) {
                continue;
            }

            if (! in_array($key, $carried, true)) {
                $lost[] = sprintf('%s — geschrieben in %s', $key, implode(', ', array_unique($files)));
            }
        }

        $this->assertSame([], $lost, sprintf(
            "Diese flash-Schlüssel schreibt ein Controller, und die Mittelschicht trägt sie nicht:\n  %s\n\n".
            'Sie erreichen die Seite nie. Entweder gehört der Schlüssel in die flash-Ablage von '.
            self::MIDDLEWARE.' — oder der Controller meldet ins Leere.',
            implode("\n  ", $lost),
        ));
    }

    /**
     * Eine Ausnahme, die niemand mehr braucht, ist ein Rest.
     *
     * **Der Grund für diese Prüfung** ist die Falle, in die dieses Vorgehen
     * dreimal gelaufen ist (`CLAUDE.md`): Ein Wächter, der beim Aufräumen
     * zubeisst, wird beim Aufräumen abgeschaltet — hier andersherum. Wer
     * `status` eines Tages auf `success` umstellt, soll den Eintrag streichen
     * müssen und nicht dürfen.
     */
    public function test_no_exception_outlives_its_reason(): void
    {
        $written = $this->written();

        /*
         * **Eine Behauptung und keine Schleife.** Eine `foreach` über eine
         * leere Liste führt keine Zusicherung aus; PHPUnit nennt den Fall dann
         * riskant, und vier solche Fälle stehen in `CLAUDE.md` schon als
         * „nebenbei aufgefallen und nicht angefasst". Der Vergleich hier läuft
         * auch über die leere Liste und sagt dasselbe.
         *
         * > **Ein Fall, der bei leerer Liste nichts behauptet, ist von einem,
         * > der nichts prüft, nicht zu unterscheiden.**
         */
        $tot = array_values(array_diff(array_keys($this->knownLost()), array_keys($written)));

        $this->assertSame([], $tot, sprintf(
            "Diese Ausnahmen nennen einen Schlüssel, den niemand mehr schreibt:\n  %s\n\n".
            'Dann ist die Ausnahme ein Rest und gehört gestrichen.',
            implode("\n  ", $tot),
        ));
    }

    /**
     * Und was getragen wird, liest jemand.
     *
     * Die andere Richtung, aus demselben Grund wie bei `ClassNameTest`: Ein
     * Schlüssel, den die Mittelschicht mitschleppt und niemand ausliest, ist
     * ein Rest, der wie ein Kanal aussieht.
     */
    public function test_every_carried_flash_key_has_a_reader(): void
    {
        $frontend = '';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'ts'], true)) {
                $frontend .= (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotSame('', $frontend, 'Es wird keine Oberfläche gelesen — dann prüft dieser Wächter nichts.');

        $unread = [];

        foreach ($this->carried() as $key) {
            if (! str_contains($frontend, '?.'.$key) && ! str_contains($frontend, 'flash.'.$key)) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread, sprintf(
            "Diese flash-Schlüssel trägt die Mittelschicht, und niemand liest sie:\n  %s",
            implode("\n  ", $unread),
        ));
    }
}
