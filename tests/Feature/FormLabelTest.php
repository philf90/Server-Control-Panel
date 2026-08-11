<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Bedienelement trägt eine Beschriftung, die man sieht.
 *
 * **Der Befund, aus dem diese Regel entstanden ist.** Auf der Domainliste stand
 * neben „Domain anlegen" ein Auswahlfeld, in *welches* Abonnement die neue
 * Domain kommt. Es trug ein `aria-label` und sonst nichts — für einen sehenden
 * Betrachter also ein Feld mit einem Domainnamen darin, neben einem Knopf. Der
 * Betreiber hat es am 7. August 2026 gemeldet: „geht unter und wird nicht
 * wirklich wahrgenommen."
 *
 * **Der Schaden ist nicht kosmetisch.** Wer die Auswahl übersieht, legt die
 * Domain im falschen Abonnement an — mit eigenem Verzeichnisbaum, eigenem
 * Systembenutzer und eigenem Server-Block. Gemerkt wird das erst danach, und
 * zurück geht es nur über Entfernen und neu Anlegen.
 *
 * **`aria-label` ist kein Ersatz, sondern eine Ergänzung.** Es beschriftet für
 * die Vorlesehilfe und für nichts sonst; der Fehler, den es verhindert, ist ein
 * anderer als der, um den es hier geht.
 *
 * Geprüft wird die Klammer, nicht der Text: Steht das Feld in einem `<label>`,
 * gibt es eine Beschriftung, und ob sie taugt, sieht man im Bild. Ein Feld
 * ausserhalb hat gar keine.
 */
final class FormLabelTest extends TestCase
{
    /**
     * Die Elemente, die ohne Beschriftung nicht zu erraten sind.
     *
     * `input` steht bewusst **nicht** dabei: Ein Suchfeld trägt seinen Zweck im
     * `placeholder`, und ein Kästchen hat seinen Text daneben statt darüber.
     * Ein `<select>` zeigt dagegen immer einen gültigen Wert an — es sieht nie
     * leer aus und lädt deshalb dazu ein, überlesen zu werden.
     *
     * @var list<string>
     */
    private const ELEMENTS = ['select'];

    public function test_every_select_sits_in_a_label(): void
    {
        $gelesen = 0;
        $ohne = [];

        foreach ($this->vueFiles() as $name => $source) {
            $markup = $this->withoutComments($source);

            foreach (self::ELEMENTS as $element) {
                foreach ($this->unlabelled($markup, $element) as $line) {
                    $ohne[] = sprintf('%s:%d', $name, $line);
                }

                $gelesen += substr_count($markup, '<'.$element);
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf* — sonst meldet
        // dieser Wächter Grün, sobald der Ausdruck ins Leere läuft.
        $this->assertGreaterThanOrEqual(5, $gelesen, 'Es werden kaum Auswahlfelder gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $ohne, sprintf(
            "Diese Auswahlfelder stehen ausserhalb eines <label> und sind damit unbeschriftet:\n  %s\n\n".
            'Ein `aria-label` beschriftet für die Vorlesehilfe und für nichts sonst. Wer ein Feld übersieht, '.
            'trifft seine Vorgabe — und die ist bei einer Auswahl nie leer.',
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Ein Kommentar ist kein Markup.
     *
     * **Der Anlass:** Auf der neuen Seite „Allgemein" stand im Kommentar über
     * dem Feld, warum es *in* seiner Beschriftung steht — mit einem `<select>`
     * im Fliesstext. Der Wächter las ihn mit und meldete ein unbeschriftetes
     * Feld an einer Stelle, an der gar keines steht.
     *
     * **Die falsche Meldung ist dabei die harmlose Richtung.** Ein `<label>` in
     * einem Kommentar hebt die Verschachtelung, ohne je zugehen zu müssen — und
     * dann verschwindet ein *echtes* nacktes Auswahlfeld dahinter. Genau das
     * prüft {@see self::test_a_label_in_a_comment_does_not_hide_a_bare_select()}.
     *
     * Ausgebleicht statt entfernt: Zeilenumbrüche und Länge bleiben stehen, die
     * gemeldete Zeilennummer zeigt also weiter auf die Stelle in der Datei.
     */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace_callback(
            '/<!--.*?-->/s',
            static fn (array $treffer): string => (string) preg_replace('/[^\n]/', ' ', $treffer[0]),
            $source,
        );
    }

    /**
     * Ein `<label>` im Kommentar deckt kein nacktes Feld zu.
     *
     * Die Gegenprobe zum Abzug der Kommentare, und sie steht hier statt in
     * `waechter-brechen.sh`: Sie braucht keine Datei im Baum, nur die Regel.
     */
    public function test_a_label_in_a_comment_does_not_hide_a_bare_select(): void
    {
        $quelle = "<template>\n  <!-- Ein <label> gehört um jedes <select>. -->\n  <select id=\"nackt\" />\n</template>\n";

        $this->assertSame(
            [3],
            $this->unlabelled($this->withoutComments($quelle), 'select'),
            'Ein `<label>` im Kommentar hebt die Verschachtelung und versteckt das Feld darunter.',
        );
    }

    /**
     * Die Zeilen, in denen ein Element ohne offenes `<label>` steht.
     *
     * **Gezählt und nicht gesucht.** Ein regulärer Ausdruck über „`<label>` …
     * `<select>`" findet auch ein Paar, zwischen dem ein `</label>` steht;
     * dieselbe Falle wie bei den `@media`-Blöcken in `TableStyleTest`. Gezählt
     * wird deshalb die Verschachtelung.
     *
     * @return list<int>
     */
    private function unlabelled(string $source, string $element): array
    {
        preg_match_all('/<(\/?)(label|'.$element.')\b/', $source, $treffer, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $tiefe = 0;
        $lines = [];

        foreach ($treffer as $token) {
            $schliessend = $token[1][0] === '/';
            $tag = $token[2][0];

            if ($tag === 'label') {
                $tiefe += $schliessend ? -1 : 1;

                continue;
            }

            if (! $schliessend && $tiefe < 1) {
                $lines[] = substr_count(substr($source, 0, (int) $token[0][1]), "\n") + 1;
            }
        }

        return $lines;
    }

    /**
     * Jede Seite und jede Komponente.
     *
     * @return array<string, string>
     */
    private function vueFiles(): array
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files['resources/js'.substr($file->getPathname(), strlen($root))] =
                    (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
