<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Dokumente zeigen aufeinander — zeigen sie auf welche, die es gibt?
 *
 * **Der Anlass ist ein Verweis, der ins Leere zeigte, und ein Wächter, der ihn
 * hätte finden müssen.** `docs/20 §15` verwies auf `37-postgresql.md`; die Datei
 * heisst `37-uebergabe-an-p5b.md`. Gefunden am 9. August 2026 beim Planen von
 * P5b, nicht von einem Test — obwohl es
 * {@see ChangelogTest::test_every_referenced_document_exists} seit P4 gibt.
 *
 * Der sieht nämlich in den `CHANGELOG` und nirgendwo sonst, und er prüft die
 * **Nummer** über ein Glob (`docs/37` → `37-*.md`) statt den Dateinamen. Beide
 * Einschränkungen zusammen ergeben genau die Lücke, durch die der Verweis
 * gefallen ist: Er stand in einem Dokument statt im Changelog, und er nannte
 * einen Dateinamen statt einer Nummer.
 *
 * Das ist die zweite Lehre über Wächter aus `docs/37 §6` eine Ebene weiter.
 * Dort steht: *Sie dürfen `tests/` nicht auslassen* — weil die zweite Fassung
 * einer Regel dort am häufigsten steht. Hier: **sie dürfen `docs/` nicht
 * auslassen**, weil ein Dokument dieselben Zeichenketten enthält wie Code, nur
 * dass sie niemand übersetzt.
 *
 * **Der Beleg, dass die Regel nötig war, kam sofort:** Derselbe Durchgang über
 * alle Dokumente fand einen zweiten Fall — `docs/19` verwies auf
 * `14-bestaetigungen.md`, ein Dokument des Vorgängers, das mit dem
 * Repo-Übergang entfernt wurde (`docs/20 §13`). Ein Verweis, der einen
 * Lizenzwechsel und einen Neuanfang überlebt hat.
 *
 * **Was dieser Wächter ausdrücklich nicht tut:** den `CHANGELOG` auf
 * Dokumentnummern prüfen. Das tut `ChangelogTest`, und zwei Fassungen derselben
 * Regel sind der Fehler, gegen den dieses Projekt die meisten Wächter hat — die
 * zweite ist die, die veraltet. Hier wird geprüft, was dort nicht geprüft wird:
 * die Verweise **in** den Dokumenten, und der Dateiname statt der Nummer.
 */
final class DocLinkTest extends TestCase
{
    /*
     * **Es gibt hier keine Ausnahmeliste, und das ist Absicht.**
     *
     * Der erste Wurf hatte eine — leer, „für den Fall, dass ein Dokument einmal
     * auf etwas zeigen muss, das es nicht gibt". PHPStan hat sie beim ersten
     * Lauf als toten Zweig gemeldet (`isset.offset` auf `array{}`), und er hatte
     * in der Sache recht: Das ist derselbe Nullfall, den `docs/36 §14` an
     * `Feature::permission()` beschreibt — *ein Nullfall in der falschen
     * Richtung ist teurer als keiner*, weil er wie eine Erlaubnis aussieht, die
     * schon jemand gebraucht hat.
     *
     * Wer den ersten echten Fall hat, legt die Liste zusammen mit ihm an — dann
     * steht neben der Regel ein Grund statt einer Einladung, und die Form dafür
     * steht in {@see RemovalPathTest::WITHOUT_REMOVAL}: der Grund im Wert und
     * nicht in einem Kommentar daneben.
     */

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Markdown-Datei, deren Verweise geprüft werden.
     *
     * `docs/` samt `docs/entwuerfe/`, dazu `CLAUDE.md` — das ist das Dokument,
     * das jeder zuerst liest, und seine Verweise verrotten genauso.
     * `CHANGELOG.md` fehlt mit Absicht; siehe Klassenkopf.
     *
     * @return list<string>
     */
    private function markdownFiles(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/docs', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        $found[] = $this->root().'/CLAUDE.md';

        return $found;
    }

    /**
     * Die Ziele der relativen Verweise einer Datei.
     *
     * Herausgefiltert wird, was gar nicht auf eine Datei dieses Repositorys
     * zeigt: `http://`, `https://`, `mailto:` und der reine Sprungmarken-Verweis
     * `#abschnitt`. Ein Anker hinter dem Dateinamen (`27-zertifikat.md#tls`)
     * wird abgeschnitten — geprüft wird die Datei, nicht die Überschrift darin.
     *
     * @return list<string>
     */
    private function linksIn(string $file): array
    {
        preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', (string) file_get_contents($file), $matches);

        $targets = [];

        foreach ($matches[1] as $target) {
            if (preg_match('~^(https?:|mailto:|#)~', $target) === 1) {
                continue;
            }

            $targets[] = strtok($target, '#');
        }

        return $targets;
    }

    /**
     * Jeder relative Verweis zeigt auf eine Datei, die es gibt.
     */
    public function test_every_link_points_at_a_file_that_exists(): void
    {
        $seen = 0;

        foreach ($this->markdownFiles() as $file) {
            $relative = str_replace($this->root().'/', '', $file);

            foreach ($this->linksIn($file) as $target) {
                $seen++;

                /*
                 * **Aufgelöst gegen das Verzeichnis der Datei und nicht gegen
                 * die Wurzel.** Die Dokumente verweisen untereinander mit
                 * blossen Dateinamen (`[36](36-datenbanken.md)`), und genau so
                 * liest ein Browser sie auch. Wer gegen die Wurzel auflöste,
                 * bekäme für jeden dieser Verweise Rot und für `entwuerfe/`
                 * Grün, wo Rot hingehört.
                 */
                $path = dirname($file).'/'.$target;

                $this->assertFileExists(
                    $path,
                    sprintf('%s verweist auf %s — das gibt es nicht.', $relative, $target),
                );
            }
        }

        /*
         * **Die Untergrenze zählt, wo die Regel stehen darf, nicht wo sie
         * stehen soll.** Zwanzig ist weit unter dem Bestand (34 am 9. August
         * 2026) und weit über dem, was ein kaputter Ausdruck liefert. Ohne
         * diese Zeile meldet ein Muster, das nichts mehr findet, „alles in
         * Ordnung" — und der Wächter wäre still, statt rot zu sein.
         */
        $this->assertGreaterThan(20, $seen, 'Der Ausdruck findet keine Verweise mehr.');
    }

    /**
     * Jedes Dokument, das mit seiner Nummer genannt wird, gibt es.
     *
     * `docs/36` steht in den Dokumenten hundertfach ohne Verweisklammern, und
     * das ist die häufigere Schreibweise. Sie hat dieselbe Schwäche wie die
     * andere, nur trifft sie hier nicht den Dateinamen, sondern die Nummer —
     * ein Dokument, das nie geschrieben oder wieder entfernt wurde, fällt
     * niemandem auf.
     *
     * ## Zwei Stellen oder drei, und warum das nicht egal ist
     *
     * Bis zum 3. September 2026 stand hier `\d{2}`, und das war keine Grenze,
     * sondern eine Gewohnheit: Es gab schlicht noch kein dreistelliges
     * Dokument. Gemessen an dem Tag, an dem das erste entstand:
     *
     *     docs/100-wegwerf-messung.md nennt docs/10, dieses Dokument gibt es nicht.
     *
     * Die Datei lag da. Gelesen wurden ihre ersten zwei Ziffern, gesucht wurde
     * ein Dokument, das es seit dem Repo-Übergang nicht gibt — der Wächter
     * meldete also einen Verweis als tot, der auf das Dokument zeigte, in dem
     * er stand.
     *
     * > **Ein Ausdruck, der die gewohnte Stellenzahl kennt, prüft die
     * > Gewohnheit und nicht die Regel.**
     *
     * `{2,3}` ist gierig, ein `docs/99 §5` liest sich also weiter als `99`.
     *
     * **Und das `(?!\d)` dahinter ist nicht Zierrat, sondern das, was ein
     * falsches Rot verhindert.** Ohne es liest sich eine längere Zahl als ihre
     * ersten drei Ziffern — aus einem Text über `9999` wurde ein Verweis auf
     * ein Dokument `999`, das es nicht gibt. Mit ihm ist eine vierstellige
     * Zahl **gar kein** Verweis, und das ist die richtige Auskunft: Ein
     * Dokument mit vier Stellen gibt es nicht.
     *
     * > **Ein Ausdruck, der zu viel liest, meldet einen Verweis, den niemand
     * > geschrieben hat.**
     *
     * Wer ein vierstelliges Dokument anlegt, erweitert hier — bis dahin wäre
     * es ungeprüft, und diese Grenze steht hier, statt sich als Zusage zu
     * lesen.
     *
     * ## Was er nicht kann
     *
     * **Er unterscheidet einen Verweis nicht von einem Zitat.** Ein Dokument,
     * das die Meldung dieses Wächters festhält, enthält damit den toten
     * Verweis, von dem sie handelt — und wird dafür gemeldet. Genau das ist
     * dem Protokoll des A10-Nachlaufs am 3. September 2026 zweimal passiert,
     * in demselben Absatz.
     *
     * > **Ein Text, der eine Meldung über einen toten Verweis zitiert, enthält
     * > den toten Verweis.**
     *
     * Codeblöcke zu überspringen wäre keine Lösung, sondern ein Loch: Dort
     * stehen Verweise, die genauso verrotten. Wer eine solche Meldung
     * festhalten will, schreibt die Nummer ohne ihr `docs/`.
     */
    public function test_every_document_mentioned_by_number_exists(): void
    {
        $numbers = [];

        foreach ($this->markdownFiles() as $file) {
            preg_match_all('~docs/(\d{2,3})(?!\d)~', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $number) {
                // Nach Nummer abgelegt und nicht angehängt: Die Meldung soll die
                // Nummer nennen, und jede nur einmal.
                $numbers[$number] = str_replace($this->root().'/', '', $file);
            }
        }

        foreach ($numbers as $number => $mentionedIn) {
            $this->assertNotSame(
                [],
                glob($this->root().'/docs/'.$number.'-*.md') ?: [],
                sprintf('%s nennt docs/%s, dieses Dokument gibt es nicht.', $mentionedIn, $number),
            );
        }

        $this->assertGreaterThan(10, count($numbers), 'Der Ausdruck findet keine Dokumentnummern mehr.');
    }
}
