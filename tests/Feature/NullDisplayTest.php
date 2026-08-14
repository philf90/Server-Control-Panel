<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * `NULL` wird als `NULL` angezeigt — vor jeder typabhängigen Darstellung.
 *
 * **Der Anlass ist ein Bildschirmfoto aus P5c Schritt 5.** In der Zeilenansicht
 * einer MariaDB-Tabelle stand in jeder Zeile der Spalte `anhang`
 * „binär · 0 B". Die Spalte war leer — nicht null Byte lang, sondern `NULL` —,
 * und die Anzeige machte daraus eine Zahl:
 *
 *     <span v-if="isBinary(column)">binär · {{ formatBytes(Number(row[column] ?? 0)) }}</span>
 *     <span v-else-if="row[column] === null">NULL</span>
 *
 * Der Zweig für die binäre Spalte stand zuerst, `OCTET_LENGTH(NULL)` ist `NULL`,
 * und `Number(null ?? 0)` ist `0`. Eine leere Zelle war damit von einem
 * tatsächlich leeren Blob nicht mehr zu unterscheiden — und genau das verlangt
 * Kriterium 2 aus `docs/46 §4`.
 *
 * > **Eine 0, die für „nichts da" steht, sieht aus wie eine Antwort.**
 *
 * Es ist derselbe Fund wie bei der geschätzten Zeilenzahl in `docs/46 §9`: Dort
 * hiess die falsche Antwort „0 Zeilen", hier „0 B". Beide Male war die richtige
 * Anzeige ein Wort und keine Zahl.
 *
 * **Geprüft wird die Reihenfolge und nicht die Zeichenkette „NULL".** Dass das
 * Wort irgendwo in der Vorlage steht, sagt nichts darüber, ob es je zu sehen
 * ist; ein Zweig davor, der jeden Wert abfängt, macht es unerreichbar. Der
 * Fehler war ja nicht, dass die Anzeige fehlte — sie war da und kam nie dran.
 */
final class NullDisplayTest extends TestCase
{
    private function console(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Databases/Console.vue',
        );
    }

    /**
     * Die Zelle der Zeilenansicht, ohne Kommentare.
     *
     * Kommentare fliegen raus, weil dieser Test sich in einem Kommentar erklärt,
     * der die fehlerhafte Reihenfolge zitiert.
     *
     * > **Ein Wächter, der Kommentare liest, bestraft das Dokumentieren genau
     * > des Fehlers, vor dem er schützt.**
     */
    private function cell(): string
    {
        $source = (string) preg_replace('/<!--.*?-->/su', '', $this->console());

        $this->assertSame(
            1,
            preg_match('#<table class="rows">(.*?)</table>#su', $source, $table),
            'In Console.vue gibt es keine `<table class="rows">` mehr — dann prüft dieser Test nichts.',
        );

        $this->assertSame(
            1,
            preg_match('#<td\b[^>]*>(.*?)</td>#su', $table[1], $cell),
            'In der Zeilentabelle steht keine Zelle — dann prüft dieser Test nichts.',
        );

        return $cell[1];
    }

    public function test_a_null_is_shown_before_any_type_decides(): void
    {
        $cell = $this->cell();

        preg_match_all('/\bv-(if|else-if)="([^"]*)"/', $cell, $branches, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(
            3,
            count($branches),
            'Die Zelle hat kaum Zweige — dann rechnet dieser Test an nichts mehr nach.',
        );

        $this->assertSame(
            'if',
            $branches[0][1],
            'Der erste Zweig der Zelle ist kein `v-if` — dieser Test liest die Kette falsch herum.',
        );

        $this->assertStringContainsString(
            '=== null',
            $branches[0][2],
            sprintf(
                "Der erste Zweig der Zelle prüft nicht auf `NULL`, sondern: %s\n\n".
                "Ein Zweig davor, der auf den Typ der Spalte sieht, fängt das `NULL` mit ab — bei einer\n".
                "binären Spalte wurde daraus „binär · 0 B\", und ein leerer Wert war von einem leeren Blob\n".
                'nicht mehr zu unterscheiden (docs/46 §4, Kriterium 2).',
                $branches[0][2],
            ),
        );
    }

    /**
     * Eine Sicht bekommt keine Grösse.
     *
     * **Der dritte Fall derselben Falle in dieser Stufe.** Eine Sicht speichert
     * nichts; der Katalog meldet dafür `0`, und „0 B" liest sich wie „leer"
     * statt wie „gibt es nicht".
     *
     * > **Eine 0, die für „nichts da" steht, sieht aus wie eine Antwort.**
     *
     * Vorher: die geschätzte Zeilenzahl (`docs/46 §9`, wo `-1` zu `null` wird
     * statt zu `0`) und die Länge einer binären Spalte mit `NULL` (§20.16).
     * Dreimal dieselbe Ursache — eine Zahl, die es gibt, für eine Angabe, die es
     * nicht gibt. Beim dritten Mal bekommt sie einen Wächter.
     *
     * **Geprüft wird der Zusammenhang und nicht der Aufruf**: Dass irgendwo
     * `formatBytes` steht, ist richtig; falsch wäre, es ohne Rücksicht auf die
     * Art der Tabelle zu tun.
     */
    public function test_a_view_is_shown_without_a_size(): void
    {
        $source = (string) preg_replace('#/\*.*?\*/#su', '', $this->console());

        $this->assertSame(
            1,
            preg_match('/const openFacts = computed\(.*?\n\}\)/su', $source, $treffer),
            'Es gibt keine Angabenzeile zur offenen Tabelle mehr — dann prüft dieser Test nichts.',
        );

        $this->assertStringContainsString(
            'formatBytes',
            $treffer[0],
            'Die Angabenzeile nennt keine Grösse mehr — dann rechnet dieser Test an nichts nach.',
        );

        $this->assertMatchesRegularExpression(
            "/kind !== 'view'[^}]*formatBytes/su",
            $treffer[0],
            'Die Angabenzeile nennt die Grösse ohne Rücksicht auf die Art. Eine Sicht speichert '
            .'nichts, der Katalog meldet dafür 0, und „0 B" liest sich wie „leer" statt wie „gibt es '
            .'nicht" (docs/46 §20.28).',
        );
    }

    /**
     * Eine geschätzte Zeilenzahl sagt, dass sie geschätzt ist.
     *
     * **Gefunden, weil jemand nachgezählt hat.** Auf `cloudsrv24` stand in der
     * Beizeile `16.008 Zeilen`; `SELECT COUNT(*)` sagte **16384**. Die Zahl
     * kommt aus dem Katalog und nicht aus einer Zählung — so entschieden in
     * `docs/46 §9`, weil die Zählung selbst die teure Abfrage wäre —, und sie
     * stand ohne ein Wort dazu da. Fünf Stellen Genauigkeit für eine Angabe, die
     * keine hat.
     *
     * > **Eine Zahl ohne das Wort, das sie einschränkt, behauptet mehr als sie
     * > weiss.**
     *
     * Es ist derselbe Fehler wie „0 B" für eine Sicht, nur andersherum: Dort log
     * eine Null über etwas, das es nicht gibt, hier eine Genauigkeit über etwas,
     * das es ungefähr gibt. Kein Test konnte das sehen — die Zahl ist richtig
     * gerechnet, sie ist nur falsch angekündigt.
     *
     * **Geprüft wird die Nachbarschaft und nicht das Vorkommen**: Dass das Wort
     * irgendwo in der Datei steht, sagt nichts; es muss an der Zahl stehen.
     */
    public function test_an_estimated_row_count_says_so(): void
    {
        $source = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $this->console());

        $this->assertSame(
            1,
            preg_match('/const openFacts = computed\(.*?\n\}\)/su', $source, $treffer),
            'Es gibt keine Angabenzeile zur offenen Tabelle mehr — dann prüft dieser Test nichts.',
        );

        /*
         * **Der Name hat sich geändert, die Frage nicht.** Bis zum 14. August
         * 2026 stand hier `formatRows`; seit dem Fund „geschätzt 1 Zeilen"
         * (`docs/48 §3.3`) entscheidet {@see counted()} auch über das Wort, und
         * die Zahl kommt weiter aus derselben Formatierung. Gemerkt hat den
         * Umzug dieser Wächter selbst — er war rot, bevor die Änderung
         * eingecheckt war.
         */
        $this->assertStringContainsString(
            'counted(',
            $treffer[0],
            'Die Angabenzeile nennt keine Zeilenzahl mehr — dann rechnet dieser Test an nichts nach.',
        );

        $this->assertMatchesRegularExpression(
            '/geschätzt[^`\n]*\$\{counted\(/su',
            $treffer[0],
            'Die Zeilenzahl steht ohne das Wort „geschätzt" da. Sie kommt aus dem Katalog und nicht '
            .'aus einem `count(*)` (docs/46 §9); auf cloudsrv24 waren es 16.008 gegen 16384 '
            .'tatsächliche Zeilen, und der Kunde las die Schätzung als Zählung.',
        );
    }

    /**
     * Und die Länge einer binären Spalte wird nicht auf 0 geklemmt.
     *
     * **Die zweite Hälfte, und ohne sie wäre die erste zu umgehen.** Wer die
     * Reihenfolge richtig stellt und `?? 0` stehen lässt, hat den Fehler an
     * dieser Stelle behoben und den Grund behalten — bis jemand die Zweige
     * wieder umsortiert. Ein `?? 0` über einer Länge ist die Behauptung, `NULL`
     * sei leer, und die gehört hier nirgends hin.
     */
    public function test_a_length_is_never_defaulted_to_zero(): void
    {
        $cell = $this->cell();

        $this->assertStringContainsString(
            'formatBytes(',
            $cell,
            'Die Zelle nennt keine Länge mehr — dann prüft diese Hälfte nichts.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/formatBytes\(\s*Number\([^)]*\?\?/',
            $cell,
            'Die Länge einer binären Spalte fällt auf einen Ersatzwert zurück. `NULL` ist keine Länge, '.
            'und `0 B` sieht aus wie eine (docs/46 §4, Kriterium 2).',
        );
    }
}
