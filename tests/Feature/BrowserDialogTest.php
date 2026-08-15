<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keine Seite fragt über einen Browserdialog nach einer Eingabe.
 *
 * ## Der Fehler, der diesen Wächter erzwungen hat
 *
 * Am 15. August 2026 liess sich „Als Zip packen" auf einem iPhone **gar nicht**
 * bedienen (`docs/55`, Befund 15). Der Knopf rief `window.prompt`, und Safari
 * darf die Dialoge einer Seite abschalten, nachdem sie mehrere gezeigt hat —
 * `prompt()` gibt danach ohne jedes Zeichen `null` zurück. Der Knopf tut dann
 * nichts: keine Meldung, kein Hinweis, kein Unterschied zu einem kaputten Knopf.
 *
 * > **Ein Knopf, dessen Wirkung in einem Dialog steckt, den der Browser
 * > abschalten darf, ist ein Knopf, der nichts tut.**
 *
 * ## Und die Regel war schon entschieden
 *
 * `docs/53` Befund 8 hat den `window.prompt` des Rechte-Editors durch einen
 * Bereich auf der Seite ersetzt, mit der Begründung, dass ein Systemdialog
 * keine Farbe aus `app.css` nimmt und dieses Panel keine Modalen hat. **Drei
 * weitere `prompt` in derselben Datei haben das überlebt** — sie waren nicht
 * gemeldet worden.
 *
 * > **Eine Regel, die nur auf den gemeldeten Fall angewandt wird, lässt ihre
 * > Geschwister stehen.**
 *
 * ## Warum `confirm` hier (noch) nicht steht
 *
 * `window.confirm` stellt eine Ja-Nein-Frage und nimmt keine Eingabe entgegen;
 * fällt er aus, unterbleibt die Aktion, und das ist die sichere Richtung. Er
 * steht an über zwanzig Stellen dieses Panels, seit es sie gibt, und sein Ersatz
 * ist ein eigener Schritt (`docs/51 §12`, Schritt 12). **Benannt offen und nicht
 * als erledigt gezählt** — dieser Wächter deckt ihn ausdrücklich nicht ab.
 */
final class BrowserDialogTest extends TestCase
{
    /**
     * Wo eine Eingabe erfragt wird, ohne dass ein Dialog dafür nötig wäre.
     *
     * Leer, und das ist der Punkt: Wer hier etwas einträgt, schreibt den Grund
     * dazu — eine Ausnahmeliste ohne Begründung je Eintrag wächst, bis sie
     * alles enthält.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [];

    /** @return list<string> */
    private function vueFiles(): array
    {
        $gefunden = [];
        $wurzel = dirname(__DIR__, 2).'/resources/js';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $gefunden[] = $file->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }

    /**
     * Der Kommentar zählt nicht.
     *
     * Diese Dateien erklären in ihren Kommentaren, was **früher** dastand —
     * `window.prompt` kommt darin als Wort vor, und ohne diesen Schnitt meldete
     * der Wächter genau die Stellen, die ihn befolgen. Derselbe Fehler wie bei
     * `LinkReachTest` am 15. August, dort mit einer Adresse statt einem Aufruf.
     *
     * > **Ein Wächter, der Kommentare mitliest, beisst am zuverlässigsten dort,
     * > wo jemand seine Regel aufgeschrieben hat.**
     */
    private function withoutComments(string $quelle): string
    {
        $ohne = preg_replace('#/\*.*?\*/#s', '', $quelle) ?? $quelle;
        $ohne = preg_replace('#<!--.*?-->#s', '', $ohne) ?? $ohne;

        return preg_replace('#^\s*//.*$#m', '', $ohne) ?? $ohne;
    }

    public function test_no_page_asks_for_input_through_a_browser_dialog(): void
    {
        $treffer = [];

        foreach ($this->vueFiles() as $datei) {
            $kurz = substr($datei, strlen(dirname(__DIR__, 2)) + 1);

            if (isset(self::EXEMPT[$kurz])) {
                continue;
            }

            $quelle = $this->withoutComments((string) file_get_contents($datei));

            if (preg_match('/\bprompt\s*\(/', $quelle) === 1) {
                $treffer[] = $kurz;
            }
        }

        $this->assertSame(
            [],
            $treffer,
            sprintf(
                "Diese Seiten fragen über `window.prompt` nach einer Eingabe:\n  %s\n\n".
                "Safari darf die Dialoge einer Seite abschalten; `prompt()` gibt danach ohne jedes\n".
                'Zeichen `null` zurück, und der Knopf tut nichts. Ein Feld auf der Seite tut es immer.',
                implode("\n  ", $treffer),
            ),
        );
    }

    /**
     * Und der Ausdruck findet auch etwas.
     *
     * **Ohne diese Gegenprobe ist der Test darüber wertlos.** Er behauptet eine
     * leere Liste, und die liefert ein kaputter Ausdruck genauso zuverlässig wie
     * eine saubere Oberfläche.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist
     * > keine.**
     */
    public function test_the_search_really_reads_the_pages(): void
    {
        $dateien = $this->vueFiles();

        $this->assertGreaterThanOrEqual(
            30,
            count($dateien),
            sprintf('Es werden nur %d Vue-Dateien gefunden. Dann sucht dieser Wächter '.
                'an der falschen Stelle.', count($dateien)),
        );

        $mitDialog = 0;

        foreach ($dateien as $datei) {
            $quelle = $this->withoutComments((string) file_get_contents($datei));

            if (preg_match('/\bconfirm\s*\(/', $quelle) === 1) {
                $mitDialog++;
            }
        }

        // `confirm` ist ausdrücklich erlaubt (siehe Klassenkommentar) — er dient
        // hier als Beleg, dass derselbe Ausdruck auf denselben Dateien sehr wohl
        // etwas findet. Fiele auch er auf null, läse dieser Wächter nichts.
        $this->assertGreaterThan(
            0,
            $mitDialog,
            'Kein einziges `confirm(` in allen Seiten. Dann liest dieser Wächter die Dateien nicht, '.
            'und seine leere Trefferliste oben bedeutet nichts.',
        );
    }

    /**
     * Und der Schnitt der Kommentare wirft nicht den Code weg.
     *
     * Ein zu gieriger Ausdruck — `/\*.*\*​/` ohne `?` — nähme alles zwischen dem
     * ersten und dem letzten Blockkommentar einer Datei mit. Der Wächter wäre
     * danach grün, weil er fast nichts mehr liest.
     */
    public function test_stripping_comments_keeps_the_code(): void
    {
        /*
         * **Zwei Blockkommentare und nicht einer.** Bei einem einzigen sind
         * gierig und genügsam dasselbe — die erste Fassung dieses Falls war
         * genau so gebaut, und der absichtliche Bruch lief durch sie hindurch,
         * ohne dass sie rot wurde.
         *
         * > **Ein Gegenbeispiel, das nur einen Fall enthält, prüft keinen
         * > Quantor.**
         */
        $quelle = <<<'VUE'
            /* erster Kommentar mit prompt( darin */
            function eins(): void { router.get('/a') }
            /* zweiter Blockkommentar, ebenfalls mit prompt( */
            function zwei(): void { router.get('/b') }
            <!-- und einer in HTML-Form, wieder mit prompt( -->
            function drei(): void { router.get('/c') }
            VUE;

        $ohne = $this->withoutComments($quelle);

        $this->assertStringContainsString('function eins', $ohne);
        $this->assertStringContainsString('function zwei', $ohne);
        $this->assertStringContainsString('function drei', $ohne);
        $this->assertDoesNotMatchRegularExpression('/\bprompt\s*\(/', $ohne);
    }

    /** @see ValidationLanguageTest::test_every_exemption_carries_a_reason */
    public function test_every_exemption_carries_a_reason(): void
    {
        foreach (self::EXEMPT as $datei => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Ausnahmeliste.', $datei),
            );
        }
    }
}
