<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Language\Counted;
use App\Support\Metrics\Store;
use App\Support\Plans\Quotas;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die Menge und ihr Wort — und dass beide Seiten dasselbe tun.
 *
 * ## Warum es das zweimal gibt
 *
 * Die Oberfläche entscheidet das seit P5c in `useCounted.ts`. Was fehlte, war
 * ein Gegenstück für die Meldungen, die **in PHP** entstehen — und der
 * Abnahmelauf von A1 hat dreizehn Stellen gezählt, an denen es fehlte
 * (`docs/86`, Befund 11).
 *
 * > **Eine Regel ohne Werkzeug wird an jeder Stelle neu entschieden — und
 * > irgendwann an einer nicht.**
 *
 * ## Und der Wächter hält beide aneinander
 *
 * Zwei Fassungen derselben Entscheidung laufen auseinander, wenn nichts sie
 * hält: Schriebe die eine „1.234" und die andere „1234", stünde dieselbe Zahl
 * auf zwei Seiten desselben Panels verschieden da.
 */
final class CountedTest extends TestCase
{
    use WithoutPhpComments;

    public function test_one_gets_the_singular_and_everything_else_the_plural(): void
    {
        $this->assertSame('1 Datei', Counted::of(1, 'Datei', 'Dateien'));
        $this->assertSame('0 Dateien', Counted::of(0, 'Datei', 'Dateien'));
        $this->assertSame('2 Dateien', Counted::of(2, 'Datei', 'Dateien'));
    }

    /**
     * Ein Wort, dessen Einzahl gleich lautet, bleibt gleich.
     *
     * Beide Wörter werden übergeben und keines abgeleitet — im Deutschen gibt
     * es dafür keine Regel. Wer sie rechnen wollte, bekäme „1 Treffers".
     */
    public function test_a_word_that_does_not_change_does_not_change(): void
    {
        $this->assertSame('1 Treffer', Counted::of(1, 'Treffer', 'Treffer'));
        $this->assertSame('7 Treffer', Counted::of(7, 'Treffer', 'Treffer'));
    }

    /** Negative Zahlen bekommen die Mehrzahl — die harmlosere Antwort. */
    public function test_a_negative_count_gets_the_plural(): void
    {
        $this->assertSame('-1 Dateien', Counted::of(-1, 'Datei', 'Dateien'));
    }

    /**
     * Die Zahl steht so da, wie dieses Panel Zahlen schreibt.
     *
     * Punkt als Tausendertrennung — wie `toLocaleString('de-DE')` drüben und
     * wie {@see Quotas} und {@see Store}
     * es hier tun.
     */
    public function test_the_number_is_written_the_way_this_panel_writes_numbers(): void
    {
        $this->assertSame('1.234', Counted::number(1234));
        $this->assertSame('1.234.567 Einträge', Counted::of(1234567, 'Eintrag', 'Einträge'));
        $this->assertSame('999', Counted::number(999));
    }

    /**
     * Und die beiden Fassungen entscheiden dasselbe.
     *
     * **Die Naht, die sonst niemand hält.** Zwei Fassungen derselben Regel
     * laufen auseinander; hier wird die Bedingung selbst verglichen und nicht
     * das Ergebnis, weil das Ergebnis in zwei Sprachen entsteht.
     */
    public function test_both_versions_decide_on_the_same_condition(): void
    {
        $ts = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Composables/useCounted.ts',
        );

        $this->assertMatchesRegularExpression('/value === 1 \? one : many/', $ts,
            'Die Fassung der Oberfläche entscheidet anders — dann steht dieselbe Menge auf zwei '
            .'Seiten desselben Panels verschieden da.');

        $php = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/Language/Counted.php'),
        );

        $this->assertMatchesRegularExpression('/\$value === 1 \? \$one : \$many/', $php);

        $this->assertStringContainsString("'de-DE'", $ts);
        $this->assertStringContainsString("',', '.'", $php,
            'Die PHP-Fassung trennt Tausender anders als `toLocaleString(\'de-DE\')`.');
    }

    /**
     * Und jemand ruft ihn.
     *
     * > **Ein Wächter über eine Methode sagt nichts darüber, dass jemand sie
     * > ruft.**
     */
    public function test_the_helper_is_used(): void
    {
        $treffer = [];

        foreach ((array) glob(dirname(__DIR__, 2).'/app/Http/Controllers/*.php') as $pfad) {
            /*
             * **Mit Wortgrenze und nicht als Teilzeichenkette.** Der Bruch, der
             * `Counted::of(` zu `xCounted::of(` machte, blieb grün — ein
             * `str_contains` findet den Namen auch im längeren Wort. Dieselbe
             * Familie wie der Ausdruck, der jeden Vergleich für eine Zuweisung
             * hält (`CLAUDE.md`).
             *
             * > **Ein Wächter, der eine Zeichenkette sucht, findet sie auch
             * > dort, wo sie nur ein Teil ist.**
             */
            if (preg_match('/\bCounted::of\(/', (string) file_get_contents((string) $pfad)) === 1) {
                $treffer[] = basename((string) $pfad);
            }
        }

        $this->assertNotSame([], $treffer,
            'Niemand ruft den Helfer — dann steht die Regel als Klasse da und an jeder '
            .'Meldung wird sie wieder neu entschieden.');
    }
}
