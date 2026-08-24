<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wo ein Knopf neben einem Feld steht, gibt nicht das Feld seine Höhe vor.
 *
 * ## Der Anlass
 *
 * Gemeldet vom Betreiber am 23. August 2026 im Nachlauf zu `v0.7.0-rc.7`: „Der
 * Knopf ‚Domain anlegen' ist zu gross im Verhältnis zum Auswahlfeld bei 390 px.
 * Gleiches beim Knopf ‚Datenbank anlegen'."
 *
 * `.page-head .button-row` richtet mit `stretch` aus — richtig für eine Reihe
 * aus lauter Knöpfen, die dann gleich hoch sind. Steht ein **beschriftetes**
 * Feld dabei, ist die Reihe so hoch wie Beschriftung plus Feld, und der Knopf
 * wächst auf etwas, das mit ihm nichts zu tun hat: gemessen 133×72 gegen ein
 * Feld von 206×44.
 *
 * > **Eine Höhe, die ein Nachbar vorgibt, ist keine Aussage über den Knopf.**
 *
 * ## Warum es diesen Wächter gibt und nicht nur die Regel
 *
 * **Die Ausrichtung hat es schon einmal gegeben.** Sie stand als
 * `align-items: center` in der Regel, die bei 390 px den Seitenkopf der
 * Übersicht in eine Zeile bringt — und diese Regel traf zuerst *alle* Reihen
 * mit einem Feld. Am selben Tag ist sie verengt worden, weil sie den
 * Abonnementnamen beschnitt (`kunde-mustermann-`), und dabei ist die
 * Ausrichtung mit verschwunden.
 *
 * > **Wer eine Regel verengt, verengt alles, was in ihr steht — auch das, was
 * > mit dem Grund der Verengung nichts zu tun hatte.**
 *
 * Die Verengung war richtig und die Ausrichtung auch; sie gehören nur nicht in
 * dieselbe Regel. Genau diese Verwechslung prüft dieser Wächter: Die
 * Ausrichtung muss am **breiten** `:has(.field)` hängen, nicht an einer Fassung,
 * die zusätzlich etwas über den Inhalt des Feldes verlangt.
 *
 * **Kein Bild und keine Messung hätte das gefunden.** Der Überlauf war in allen
 * vier Lagen `0`, und die Kopfhöhe ändert sich nicht — 134 px vorher wie
 * nachher. Es ist ein Verhältnis und keine Zahl, die überläuft.
 */
final class ButtonFieldAlignTest extends TestCase
{
    /** Der Selektor, an dem die Ausrichtung hängen muss. */
    private const BROAD = '.page-head .button-row:has(.field)';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents($this->root().'/resources/css/app.css');
    }

    /**
     * Die Erklärungen der Regel mit genau diesem Selektor.
     *
     * **Genau diesem und nicht „einem, der ihn enthält".** `:has(.field)` ist
     * ein Anfangsstück von `:has(.field > select:only-child)`; ein Vergleich
     * über `str_contains` fände die verengte Fassung und wäre grün für den
     * Zustand, den dieser Wächter verhindern soll.
     *
     * > **Ein Vergleich, der ein Anfangsstück akzeptiert, akzeptiert die
     * > Verengung.**
     *
     * @return array<string, string> Eigenschaft auf Wert
     */
    private function declarationsFor(string $css, string $selector): array
    {
        $ohneKommentar = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // Gescannt und nicht gesucht: Ein Ausdruck über „Selektor … `}`" endet
        // an der ersten schliessenden Klammer, und die erste Regel eines
        // `@media`-Blocks steht hinter keinem Trennzeichen.
        $kopf = '';
        $laenge = strlen($ohneKommentar);

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $ohneKommentar[$i];

            if ($zeichen === '{') {
                if (trim($kopf) === $selector) {
                    $ende = strpos($ohneKommentar, '}', $i);
                    $rumpf = $ende === false ? '' : substr($ohneKommentar, $i + 1, $ende - $i - 1);
                    $werte = [];

                    foreach (explode(';', $rumpf) as $zeile) {
                        if (! str_contains($zeile, ':')) {
                            continue;
                        }

                        [$name, $wert] = explode(':', $zeile, 2);
                        $werte[trim($name)] = trim($wert);
                    }

                    return $werte;
                }

                $kopf = '';

                continue;
            }

            if ($zeichen === '}' || $zeichen === ';') {
                $kopf = '';

                continue;
            }

            $kopf .= $zeichen;
        }

        return [];
    }

    /**
     * Die Vorlagen, in denen ein Feld und ein Knopf in derselben Reihe stehen.
     *
     * **Die Untergrenze, ohne die dieser Wächter ein Loch hat.** Gäbe es diese
     * Paarung nirgends mehr, prüfte er eine Regel ohne Gegenstand und wäre
     * grün, ohne etwas gesehen zu haben.
     *
     * @return list<string>
     */
    private function pairings(): array
    {
        $wurzel = $this->root().'/resources/js';
        $gefunden = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'vue') {
                continue;
            }

            $quelle = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($datei->getPathname()));

            foreach (['#actions', 'class="button-row"'] as $anker) {
                $von = strpos($quelle, $anker);

                if ($von === false) {
                    continue;
                }

                $ausschnitt = substr($quelle, $von, 2000);

                if (str_contains($ausschnitt, 'class="field') && str_contains($ausschnitt, 'class="button')) {
                    $gefunden[] = basename($datei->getPathname());

                    break;
                }
            }
        }

        sort($gefunden);

        return array_values(array_unique($gefunden));
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_rows_that_pair_a_field_with_a_button(): void
    {
        $this->assertGreaterThanOrEqual(3, count($this->pairings()), implode("\n", [
            'Es gibt kaum noch Reihen, in denen ein Feld neben einem Knopf steht —',
            'dann prueft die Regel darunter nichts. Gemessen waren es drei:',
            'Uebersicht (Selbstlauf), Domainliste und Datenbankliste.',
        ]));
    }

    /**
     * **Und die Gegenprobe zum Ausschnitt.**
     *
     * Sie steht hier statt in `waechter-brechen.sh`, weil sie keine Datei im
     * Baum braucht — nur die Regel. Der Prüfkörper hat beide Fassungen: die
     * breite, die zählt, und die verengte, die genau nicht zählen darf.
     */
    public function test_a_narrowed_selector_is_not_the_broad_one(): void
    {
        $probe = implode("\n", [
            '@media (max-width: 720px) {',
            '  .page-head .button-row:has(.field > select:only-child) { align-items: flex-end; }',
            '}',
        ]);

        $this->assertSame(
            [],
            $this->declarationsFor($probe, self::BROAD),
            'Eine verengte Fassung wird als die breite gelesen — dann fällt genau der Fehler durch, für den dieser Wächter da ist.',
        );

        $eng = $this->declarationsFor($probe, '.page-head .button-row:has(.field > select:only-child)');

        $this->assertSame(
            ['align-items' => 'flex-end'],
            $eng,
            'Der Ausschnitt liest die Erklärungen einer Regel nicht.',
        );
    }

    /** Und die Ausrichtung hängt am breiten Selektor. */
    public function test_the_alignment_hangs_on_the_broad_selector(): void
    {
        $werte = $this->declarationsFor($this->stylesheet(), self::BROAD);

        $this->assertArrayHasKey('align-items', $werte, implode("\n", [
            'Es gibt keine Regel `'.self::BROAD.'`, die `align-items` setzt.',
            '',
            'Ohne sie richtet `.page-head .button-row` mit `stretch` aus, und der',
            'Knopf waechst auf die Hoehe von Beschriftung PLUS Feld — gemessen',
            '133x72 gegen ein Feld von 206x44.',
            '',
            'Sie darf NICHT in der verengten Regel stehen: Die gilt nur fuer ein',
            'Feld ohne Beschriftung, und die beiden Listenseiten haben eine.',
        ]));

        $this->assertNotSame('stretch', $werte['align-items'], implode("\n", [
            '`'.self::BROAD.'` setzt `align-items: stretch` und damit genau das,',
            'was die geerbte Regel ohnehin tut — der Knopf waechst weiter mit.',
        ]));
    }
}
