<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Weissraum in einer Datenzelle ist ein Wert und keine Formatierung.
 *
 * **Warum es diesen Wächter gibt.** Im Abnahmelauf von P5c stand `a\tb` als
 * `a b` da und `z1\nz2` als `z1 z2` — und ein Wert mit einem gewöhnlichen
 * Leerzeichen sähe genauso aus (`docs/48 §3.2`). Gemessen am 14. August 2026 im
 * Browser, im Kontext der echten Tabelle:
 *
 * | Wert | vorher | nachher |
 * |---|---|---|
 * | `a b` | 25×16 px | 25×16 px |
 * | `a\tb` | **25×16 px** | 76×16 px |
 * | `a  b` | **25×16 px** | 34×16 px |
 * | `z1\nz2` | 42×16 px, eine Zeile | 17×37 px, zwei Zeilen |
 *
 * Drei verschiedene gespeicherte Werte ergaben **exakt dieselben Pixel**.
 *
 * > **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt,
 * > behauptet etwas, das sie nicht weiss.**
 *
 * Nach dem Wortlaut von Kriterium 2 war das erfüllt — der Umbruch blieb
 * *innerhalb* der Zelle und hat keine Zeile erzeugt. Das ist der Unterschied
 * zwischen „der Lauf ist abgenommen" und „die Anzeige stimmt".
 *
 * **Zwei Eigenschaften und nicht eine**, und die zweite ist die, die man
 * vergisst: `pre-wrap` allein liess den Tabulator 29 px breit werden gegen 28 px
 * für zwei Leerzeichen — technisch verschieden, für ein Auge dasselbe. Der Grund
 * stand nicht in `app.css`, sondern in Tailwinds Reset, der `tab-size: 4` auf
 * `html` setzt.
 *
 * > **Eine Vorgabe für Quelltext, die alles erbt, gilt irgendwann für Daten.**
 */
final class CellWhitespaceTest extends TestCase
{
    private function css(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );
    }

    /**
     * Der zuletzt gültige Wert einer Eigenschaft für genau diesen Selektor.
     *
     * **Die letzte Regel gewinnt**, und deshalb wird nicht die erste genommen:
     * Wer die Eigenschaft weiter unten überschreibt, hätte sie sonst hier noch
     * stehen — und der Wächter läse den Wert, den die Seite nicht benutzt.
     */
    private function declaration(string $selector, string $property): ?string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css(), $rules, PREG_SET_ORDER);

        $value = null;

        foreach ($rules as $rule) {
            $selectors = array_map(trim(...), explode(',', trim($rule[1])));

            if (! in_array($selector, $selectors, true)) {
                continue;
            }

            if (preg_match('/(?:^|[;\s])'.preg_quote($property, '/').':\s*([^;]+)/', $rule[2], $match) === 1) {
                $value = trim($match[1]);
            }
        }

        return $value;
    }

    /** Eine Zelle der Zeilentabelle gibt wieder, was gespeichert ist. */
    public function test_a_row_cell_keeps_the_whitespace_it_was_given(): void
    {
        $this->assertSame(
            'pre-wrap',
            $this->declaration('.rows .cell', 'white-space'),
            'Die Zeilenzelle fasst Weissraum wieder zusammen. Dann sehen ein Tabulator, ein '.
            'Zeilenumbruch und ein gewöhnliches Leerzeichen gleich aus — drei verschiedene '.
            'gespeicherte Werte auf denselben Pixeln (docs/48 §3.2).',
        );
    }

    /**
     * Und ein Tabulator sieht aus wie einer.
     *
     * **`8` ist kein Geschmack.** Es ist der Ausgangswert von CSS und der
     * Abstand, den `psql`, `mysql` und jedes Terminal benutzen: Ein Wert sieht im
     * Panel aus wie dort. Die `4` kommt aus einem Reset für Quelltext und macht
     * aus dem Tabulator ein breiteres Leerzeichen.
     */
    public function test_a_tab_is_wide_enough_to_be_one(): void
    {
        $this->assertSame(
            '8',
            $this->declaration('.rows .cell', 'tab-size'),
            'Die Zeilenzelle erbt den Tabulatorabstand aus dem Reset (`tab-size: 4`). Gemessen '.
            'sind das 29 px für `a\tb` gegen 28 px für `a  b` — verschieden, aber nicht sichtbar.',
        );
    }

    /**
     * Und der genaue Blick zeigt dasselbe wie die Übersicht.
     *
     * **Sonst wäre die Zelleinzelsicht keine Gegenprobe, sondern eine zweite
     * Meinung.** Sie ist der einzige Ort, an dem ein gekürzter Wert vollständig
     * steht; stünde er dort anders da als in der Zeile, hätte der Kunde zwei
     * Darstellungen und keine Auskunft.
     */
    public function test_the_single_cell_view_shows_it_the_same_way(): void
    {
        $this->assertSame(
            'pre-wrap',
            $this->declaration('.cell-value', 'white-space'),
            'Die Zelleinzelsicht fasst Weissraum zusammen — der genaue Blick zeigt dann weniger '.
            'als die Übersicht, aus der er kommt.',
        );

        $this->assertSame(
            $this->declaration('.rows .cell', 'tab-size'),
            $this->declaration('.cell-value', 'tab-size'),
            'Übersicht und Einzelsicht setzen verschiedene Tabulatorabstände. Derselbe Wert sähe '.
            'an den beiden Orten verschieden aus, und keiner der beiden wäre erkennbar der '.
            'richtige.',
        );
    }

    /**
     * Und der Ausdruck liest überhaupt etwas.
     *
     * **Ohne diese Gegenprobe stünde die Zustimmung oben auf `null === null`.**
     * Ein Selektor, den es nicht gibt, liefert für jede Eigenschaft `null` — und
     * `assertSame(null, null)` ist grün.
     */
    public function test_the_reader_finds_a_known_declaration(): void
    {
        $this->assertSame(
            '48ch',
            $this->declaration('.rows .cell', 'max-width'),
            'Der Selektor `.rows .cell` wird nicht mehr gefunden — dann sagen die Prüfungen '.
            'darüber nichts.',
        );

        $this->assertNull(
            $this->declaration('.rows .cell', 'gibt-es-nicht'),
            'Der Ausdruck findet eine Eigenschaft, die es nicht gibt.',
        );
    }
}
