<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutMarkupComments;

/**
 * Ein Anteil steht mit deutschem Komma da — und kommt dafür aus `percent.ts`.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Beobachtet im Abnahmelauf von „Platte voll" (`docs/137 §7`, 27. September
 * 2026): Die Übersicht zeigte „25.9 %" neben „722,3 MiB" und „2.4 %" neben
 * „245,9 MiB". Die Grösse kommt fertig formatiert vom Server, der Anteil als
 * Zahl, und `{{ }}` schreibt eine Zahl in der Schreibweise von JavaScript hin.
 * Beim Beheben fand sich dieselbe rohe Ausgabe in der Liste der Abonnements
 * (`${row.percent} %`) — zweimal derselbe Fehler, und keine der beiden Stellen
 * wusste von der anderen.
 *
 * Der Server hat das Problem nicht: Jede Zahl, die er ausgibt, geht durch
 * `number_format(…, ',', '.')`. Nur die Oberfläche gibt Zahlen roh aus.
 *
 * ## Was er hält
 *
 * **Jede Einbettung, auf die in der Anzeige ein „%" oder „Prozent" folgt, ruft
 * `formatPercent`** — oder nennt einen Namen, dessen Erklärung in derselben
 * Datei es ruft (`shown` in `Bar.vue`). Gelesen werden drei Formen:
 * `{{ … }} %`, `` `${…} %` `` und `… + ' %'`.
 *
 * **Die Regel kennt keine Ausnahme, auch nicht für ganze Zahlen.** Der
 * Fortschritt eines Vorgangs ist eine `unsignedTinyInteger`-Spalte und kann
 * kein Komma tragen; er geht trotzdem durch `formatPercent`, weil eine Liste
 * der Ausdrücke, die „sicher ganzzahlig" sind, die Stelle wäre, die veraltet.
 *
 * > **Eine Ausnahme, die eine Zahl rettet, bezahlt sie mit der Regel.**
 *
 * **Ohne Leerzeichen ist es eine Länge und keine Anzeige.** `` `${x}%` `` ist
 * die Breite einer Füllung in CSS, und dort gehört der Punkt hin; ein Komma
 * machte die Angabe ungültig. Die Anzeige schreibt das Leerzeichen, wie jeder
 * Satz dieses Panels (`25,9 %`).
 *
 * ## Was er nicht hält
 *
 * Eine Anzeige ohne das Leerzeichen — `` `${x}%` `` als Text sähe für ihn aus
 * wie eine Länge. Und ob die Zahl davor überhaupt ein Anteil ist: Gefragt wird
 * nach dem Zeichen dahinter, nicht nach der Bedeutung.
 */
final class PercentFormatTest extends TestCase
{
    use WithoutMarkupComments;

    public function test_every_shown_share_comes_from_the_one_place(): void
    {
        $fehler = [];
        $gesehen = [];

        foreach ($this->quellen() as $pfad => $quelle) {
            $ohne = $this->withoutMarkupComments($quelle);

            foreach ($this->anteile($ohne) as [$ausdruck, $stelle]) {
                $gesehen[$pfad] = ($gesehen[$pfad] ?? 0) + 1;

                if (! $this->ausDerEinenStelle($ausdruck, $ohne)) {
                    $fehler[] = sprintf(
                        '%s:%d  %s',
                        $pfad,
                        substr_count(substr($ohne, 0, $stelle), "\n") + 1,
                        trim((string) preg_replace('/\s+/', ' ', $ausdruck)),
                    );
                }
            }
        }

        // Die Untergrenzen nennen die beiden Stellen, an denen der Befund
        // stand — ein Leser, der sie nicht mehr findet, misst dort nichts.
        $this->assertGreaterThanOrEqual(7, array_sum($gesehen), 'Die Anteile der Oberfläche sind nicht gefunden worden.');
        $this->assertGreaterThanOrEqual(2, $gesehen['resources/js/Components/Bar.vue'] ?? 0, 'Zahl und Beschriftung des Balkens sind nicht gelesen worden.');
        $this->assertGreaterThanOrEqual(1, $gesehen['resources/js/Pages/Subscriptions/Index.vue'] ?? 0, 'Der Anteil in der Liste der Abonnements ist nicht gelesen worden.');

        $this->assertSame([], $fehler, "Ein Anteil, der nicht aus formatPercent kommt, steht mit Punkt statt Komma da:\n".implode("\n", $fehler));
    }

    /**
     * Der Leser trifft die beiden Zeilen, an denen der Befund stand — wörtlich.
     */
    public function test_the_reader_sees_the_case_that_started_it(): void
    {
        $balken = '<span class="bar-value">{{ percent }} %</span>';
        $liste = 'return row.percent === null ? `${wert} MB` : `${wert} MB · ${row.percent} %`';
        $beschriftung = ':aria-label="`${percent} Prozent belegt`"';

        $this->assertSame(['percent'], $this->ausdruecke($balken));
        $this->assertSame(['row.percent'], $this->ausdruecke($liste));
        $this->assertSame(['percent'], $this->ausdruecke($beschriftung));

        $this->assertFalse($this->ausDerEinenStelle('percent', $balken));
        $this->assertFalse($this->ausDerEinenStelle('row.percent', $liste));
    }

    /**
     * Was er nicht meldet, und warum nicht.
     *
     * Eine Länge für CSS ist keine Anzeige; ein Name, der aus `formatPercent`
     * kommt, ist die eine Stelle; und zwei Einbettungen in einer Zeile sind
     * zwei Ausdrücke und nicht einer, der über die Klammern hinwegliest.
     */
    public function test_a_length_a_derived_name_and_a_neighbour_are_told_apart(): void
    {
        $this->assertSame([], $this->ausdruecke('const filled = computed(() => `${Math.max(0, Math.min(100, props.percent))}%`)'));
        $this->assertSame([], $this->ausdruecke('<i :style="{ width: `${progress}%` }" />'));

        $quelle = "const shown = computed(() => formatPercent(props.percent))\n<span>{{ shown }} %</span>";
        $this->assertSame(['shown'], $this->ausdruecke($quelle));
        $this->assertTrue($this->ausDerEinenStelle('shown', $quelle));
        $this->assertFalse($this->ausDerEinenStelle('shown', "const shown = computed(() => String(props.percent))\n"));

        $this->assertSame(['b'], $this->ausdruecke('{{ a }} · {{ b }} %'));
        $this->assertSame(['formatPercent(x)'], $this->ausdruecke('`${formatPercent(x)} %`'));
    }

    /**
     * Die eine Stelle schreibt ein deutsches Komma.
     *
     * Ohne diesen Fall hielte der erste Wächter, dass alle Anteile durch
     * `formatPercent` gehen — und nichts, was dort herauskommt.
     */
    public function test_the_one_place_writes_a_german_comma(): void
    {
        $quelle = $this->withoutMarkupComments((string) file_get_contents(dirname(__DIR__, 2).'/resources/js/percent.ts'));

        $this->assertMatchesRegularExpression('/export function formatPercent\(/', $quelle);
        $this->assertMatchesRegularExpression("/toLocaleString\\('de-DE'/", $quelle);
    }

    /**
     * Die Ausdrücke vor einem Prozentzeichen in der Anzeige.
     *
     * @return list<string>
     */
    private function ausdruecke(string $quelle): array
    {
        return array_map(static fn (array $a): string => trim($a[0]), $this->anteile($quelle));
    }

    /**
     * Jede Einbettung, auf die ein „%" oder „Prozent" folgt, mit ihrer Stelle.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function anteile(string $quelle): array
    {
        $anteile = [];

        // `{{ … }} %` — der Ausdruck darf nicht über ein `}}` hinweglesen,
        // sonst wird aus `{{ a }} · {{ b }} %` ein einziger.
        preg_match_all('/\{\{((?:(?!\}\}).)*?)\}\}\s*(?:%|Prozent\b)/s', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[1] as [$ausdruck, $stelle]) {
            $anteile[] = [$ausdruck, $stelle];
        }

        // `${…} %` — mit Leerzeichen; ohne ist es eine Länge für CSS.
        preg_match_all('/\$\{((?:[^{}]|\{[^{}]*\})*)\}\s+(?:%|Prozent\b)/', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[1] as [$ausdruck, $stelle]) {
            $anteile[] = [$ausdruck, $stelle];
        }

        // `… + ' %'` — der Ausdruck ist der Rest der Zeile davor.
        preg_match_all('/([^\n]*)\+\s*[\'"`]\s*(?:%|Prozent\b)/', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[1] as [$ausdruck, $stelle]) {
            $anteile[] = [$ausdruck, $stelle];
        }

        usort($anteile, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $anteile;
    }

    /**
     * Ruft der Ausdruck `formatPercent` — oder nennt er einen Namen, dessen
     * Erklärung es ruft?
     */
    private function ausDerEinenStelle(string $ausdruck, string $quelle): bool
    {
        if (str_contains($ausdruck, 'formatPercent(')) {
            return true;
        }

        if (preg_match('/^\s*([A-Za-z_$][\w$]*)(?:\.value)?\s*$/', $ausdruck, $name) !== 1) {
            return false;
        }

        if (preg_match('/\b(?:const|let|var)\s+'.preg_quote($name[1], '/').'\s*=\s*([^\n]*)/', $quelle, $erklaerung) !== 1) {
            return false;
        }

        return str_contains($erklaerung[1], 'formatPercent(');
    }

    /**
     * Jede `.vue` und `.ts` unter `resources/js`.
     *
     * @return array<string, string> Pfad => Inhalt
     */
    private function quellen(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                $dateien['resources/js'.substr($datei->getPathname(), strlen($wurzel))] =
                    (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
