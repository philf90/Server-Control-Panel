<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Jeder Leser einer Dokumentnummer liest sie ganz — und alle lesen dieselbe.
 *
 * ## Der Fehler, den es dafür gebraucht hat
 *
 * Am 3. September 2026 entstand mit dem Protokoll des A10-Nachlaufs das erste
 * **dreistellige** Dokument. `DocLinkTest` las Nummern mit einem Ausdruck über
 * genau zwei Ziffern und meldete die Datei als Verweis auf ein Dokument, das
 * es seit dem Repo-Übergang nicht gibt — die Datei lag da.
 *
 * > **Ein Ausdruck, der die gewohnte Stellenzahl kennt, prüft die Gewohnheit
 * > und nicht die Regel.**
 *
 * **Und dann kam heraus, dass es zwei Leser sind.** `ChangelogTest` trägt
 * seinen eigenen Ausdruck über dieselbe Frage, mit derselben Annahme. Erweitert
 * war der erste; der zweite fiel eine Minute später an genau derselben Nummer
 * durch.
 *
 * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
 * > die, die veraltet.**
 *
 * ## Was dieser Wächter tut, und warum er keine dritte Fassung ist
 *
 * Er schreibt **keinen** Ausdruck vor. Er sucht die Leser im Quelltext der
 * Wächter und misst jeden an denselben drei Fällen. Ein dritter Leser, den
 * morgen jemand schreibt, kommt damit von selbst in die Messung — und das ist
 * der Unterschied zu einer Liste, die man pflegen müsste.
 *
 * > **Ein Wächter über eine Familie von Ausdrücken darf die Familie nicht
 * > aufzählen, sonst prüft er das Erinnerungsvermögen.**
 *
 * ## Was er nicht kann
 *
 * Er findet nur Leser, die ihren Ausdruck als einfaches Zeichenkettenliteral
 * schreiben. Einer, der ihn aus Teilen zusammensetzt, entgeht ihm — das ist die
 * Grenze, und sie steht hier als Frage und nicht als Zusage.
 *
 * Framework-frei.
 */
final class DocumentNumberReaderTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Fälle, an denen jeder Leser gemessen wird.
     *
     * `9999` ist der teuerste davon: Ohne die Ziffernabgrenzung liest ein
     * gieriger Ausdruck daraus `999` und meldet einen Verweis auf ein Dokument,
     * das niemand geschrieben hat. Erwartet ist deshalb **kein** Treffer — eine
     * vierstellige Zahl ist keine Dokumentnummer.
     *
     * @return array<string, array{0: string, 1: null|string}>
     */
    public static function faelle(): array
    {
        return [
            'zweistellig' => ['docs/99', '99'],
            'dreistellig' => ['docs/100', '100'],
            'vierstellig ist keine Nummer' => ['docs/9999', null],
        ];
    }

    #[DataProvider('faelle')]
    public function test_every_reader_reads_the_number_whole(string $eingabe, ?string $erwartet): void
    {
        $leser = $this->leser();

        // **Die Untergrenze zählt die Leser.** Findet der Ausdruck unten keinen
        // mehr — weil einer umzieht oder anders geschrieben wird —, liefe diese
        // Schleife leer und meldete Grün für eine Regel, die sie nie gesehen
        // hat.
        $this->assertGreaterThanOrEqual(2, count($leser), implode("\n", [
            'Es sind weniger als zwei Leser einer Dokumentnummer gefunden worden.',
            'Bekannt sind DocLinkTest und ChangelogTest — findet der Ausdruck sie nicht,',
            'misst dieser Wächter nichts.',
        ]));

        foreach ($leser as $datei => $muster) {
            $gelesen = preg_match($muster, $eingabe, $treffer) === 1 ? $treffer[1] : null;

            $this->assertSame($erwartet, $gelesen, sprintf(
                "%s liest aus %s die Nummer %s, erwartet ist %s.\n%s",
                $datei,
                $eingabe,
                $gelesen ?? '(gar nichts)',
                $erwartet ?? '(gar nichts)',
                'Alle Leser einer Dokumentnummer müssen dieselbe lesen — sonst meldet der eine '
                .'einen toten Verweis, den der andere für in Ordnung hält.',
            ));
        }
    }

    /**
     * Die Ausdrücke, mit denen ein Wächter eine Dokumentnummer liest.
     *
     * Gesucht wird über den Quelltext von `tests/` nach einfachen
     * Zeichenkettenliteralen, die `docs` **und** eine Ziffernklammer enthalten.
     * Kommentare sind vorher abgestreift: In diesem Repo hält jede Behebung
     * ihren Vorzustand im Kommentar fest, und ein zitierter alter Ausdruck wäre
     * hier ein Leser, den es nicht gibt.
     *
     * @return array<string, string> Datei → Ausdruck
     */
    private function leser(): array
    {
        $gefunden = [];

        foreach ($this->dateien() as $pfad) {
            $quelle = $this->withoutComments((string) file_get_contents($pfad));

            if (preg_match_all("~'([^'\n]*docs[^'\n]*)'~", $quelle, $treffer) === false) {
                continue;
            }

            foreach ($treffer[1] as $literal) {
                if (! str_contains($literal, '(\d')) {
                    continue;
                }

                $gefunden[str_replace(dirname(__DIR__, 2).'/', '', $pfad)] = $literal;
            }
        }

        return $gefunden;
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $gefunden = [];

        foreach (['Feature', 'Unit'] as $ordner) {
            foreach (glob(dirname(__DIR__).'/'.$ordner.'/*.php') ?: [] as $pfad) {
                // Diese Datei selbst nennt die Fälle und ist kein Leser.
                if ($pfad === __FILE__) {
                    continue;
                }

                $gefunden[] = $pfad;
            }
        }

        return $gefunden;
    }
}
