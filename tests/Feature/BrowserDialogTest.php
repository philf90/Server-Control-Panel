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
 * ## Und `confirm` steht mit hier, seit er gemessen ist
 *
 * Zuerst stand hier, `window.confirm` sei die sichere Richtung: eine Ja-Nein-
 * Frage, die bei Ausfall `false` liefert, also unterbleibt die Aktion. Das
 * stimmt und war trotzdem die falsche Schlussfolgerung — **auf demselben iPhone
 * kam auch keine einzige Rückfrage an** (`docs/55`, Befund 16). Achtzehn
 * Aktionen dieses Panels taten damit nichts: Sperren, Zurückziehen, Löschen,
 * Zurückspielen, einen Vorgang abbrechen.
 *
 * > **„Es geschieht nichts Falsches" und „es geschieht das Richtige" sind zwei
 * > Sätze, und nur der zweite beschreibt ein bedienbares Panel.**
 *
 * Der Ersatz ist {@see BrowserDialogTest} selbst nicht — er ist
 * `useConfirmation` mit `Confirmation.vue` im Layout, an derselben Stelle, an
 * der auch die grüne Meldung und die Fehlerzusammenfassung stehen.
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

    /**
     * Die Ausnahmeliste, mit ihrem Typ statt mit ihrem Inhalt.
     *
     * **Sie ist leer, und eine leere Konstante hat den Typ `array{}`.** PHPStan
     * meldet darauf „Offset string in isset() does not exist" und „Empty array
     * passed to foreach" — beides richtig für den heutigen Inhalt und falsch für
     * den Zweck: Die Liste steht da, damit jemand etwas einträgt.
     *
     * > **Ein Typ, der aus dem heutigen Inhalt geschlossen wird, verbietet den
     * > morgigen.**
     *
     * @return array<string, string>
     */
    private function exempt(): array
    {
        /** @var array<string, string> $liste */
        $liste = self::EXEMPT;

        return $liste;
    }

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

            if (isset($this->exempt()[$kurz])) {
                continue;
            }

            $quelle = $this->withoutComments((string) file_get_contents($datei));

            if (preg_match('/\b(prompt|confirm|alert)\s*\(/', $quelle) === 1) {
                $treffer[] = $kurz;
            }
        }

        $this->assertSame(
            [],
            $treffer,
            sprintf(
                "Diese Seiten benutzen einen Browserdialog:\n  %s\n\n".
                "Safari darf die Dialoge einer Seite abschalten; danach gibt `prompt()` ohne jedes\n".
                "Zeichen `null` zurück und `confirm()` `false`, und der Knopf tut nichts.\n\n".
                'Auf der Seite: ein Feld für eine Eingabe, `useConfirmation()` für eine Rückfrage.',
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

        /*
         * **Der Beleg war einmal `confirm(` selbst**, solange er noch erlaubt
         * war. Seit er es nicht mehr ist, wäre das eine Null neben einer Null —
         * und damit keine Messung, sondern zwei leere Listen, die einander
         * bestätigen.
         *
         * Gesucht wird deshalb nach etwas, das jede Seite hat und das mit dieser
         * Regel nichts zu tun hat: der öffnende Vorlagenblock. Verschwindet er,
         * liest dieser Wächter keine Vue-Datei mehr, gleich was sein
         * Dialog-Ausdruck sagt.
         */
        $mitVorlage = 0;

        foreach ($dateien as $datei) {
            if (str_contains((string) file_get_contents($datei), '<template>')) {
                $mitVorlage++;
            }
        }

        $this->assertGreaterThanOrEqual(
            30,
            $mitVorlage,
            sprintf(
                'Nur %d von %d gefundenen Dateien haben überhaupt einen `<template>`-Block. Dann liest '.
                'dieser Wächter nicht, was er zu lesen glaubt, und seine leere Trefferliste oben '.
                'bedeutet nichts.',
                $mitVorlage,
                count($dateien),
            ),
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
        foreach ($this->exempt() as $datei => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Ausnahmeliste.', $datei),
            );
        }
    }
}
