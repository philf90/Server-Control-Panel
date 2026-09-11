<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Der Punkt am Menüknopf — ausserhalb des Flusses, aus einer Quelle, mit Namen.
 *
 * **Warum es diesen Wächter gibt.** Am 11. September 2026 hat die Nachmessung
 * des Abzeichens an der gebauten Seite einen Befund geliefert, den keine Zahl
 * auf Seitenebene meldet: Unter 720 px ist die Leiste eine Schublade, und
 * zugeklappt steht das Abzeichen am Menüpunkt „Updates" bei **x = −63 px**.
 * Auf dem Telefon sah es niemand. Der Punkt in der Kopfleiste ist die Antwort
 * darauf.
 *
 * **Die tragende Regel ist gemessen und nicht überlegt.** `.nav-toggle` ist ein
 * Raster mit `place-items: center` und **einem** Kind. Ein zweites Kind im
 * Fluss macht daraus zwei Zellen: Das Zeichen rutscht um **6,5 px** nach oben,
 * und die Kopfleiste bleibt dabei in beiden Fällen **65 px** hoch.
 *
 * > **Ein Schaden, der innerhalb eines Knopfes sitzt, hat auf Seitenebene keine
 * > Zahl, die sich beschwert.**
 *
 * Dieselbe Familie wie die gestapelte Zelle aus dem A6-Lauf, die genau ein Kind
 * verträgt — dort zog `space-between` den Inhalt auseinander, hier zieht
 * `place-items` ihn nach oben.
 *
 * **Und er streift die Kommentare ab, bevor er sucht.** Das ist hier kein
 * Formalismus: Der Absatz, der `position: relative` an `.nav-toggle`
 * *begründet*, schreibt die Zeile wörtlich hin. Ohne den Abtaster bliebe dieser
 * Wächter grün, nachdem jemand die Deklaration entfernt hat.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für
 * > ihn wieder her.**
 *
 * **Was er nicht kann:** Er sagt nicht, ob der Punkt *sichtbar* ist oder ob
 * seine Lage gut gewählt war. Dass er die Tinte des Zeichens nicht berührt —
 * 16 × 10 px Tinte in einem 24 × 24 px grossen Kasten, 3 px Luft bei
 * `top/right: 8px` — ist gemessen und steht in `docs/907`; ein Wächter über den
 * Quelltext erreicht es nicht.
 */
final class NavDotTest extends TestCase
{
    use WithoutMarkupComments;

    private const LAYOUT = __DIR__.'/../../resources/js/Layouts/PanelLayout.vue';

    /**
     * Der Punkt ist absolut gesetzt, und der Knopf trägt seinen Bezug.
     *
     * Beides zusammen, weil eines ohne das andere nichts hält: Ein `absolute`
     * ohne `relative` am Knopf bezieht sich auf das nächste positionierte
     * Element weiter oben, und ein `relative` ohne den Punkt ist eine Zeile
     * ohne Wirkung.
     */
    public function test_the_dot_stands_outside_the_grid_flow(): void
    {
        $stil = $this->styleBlock();

        $punkt = $this->rule($stil, '.nav-dot');
        $knopf = $this->rule($stil, '.nav-toggle');

        $this->assertStringContainsString('position: absolute', $punkt, implode("\n", [
            'Die Regel `.nav-dot` setzt den Punkt nicht absolut.',
            '',
            'Gemessen am 11. September 2026: Als zweites Kind im Fluss von `.nav-toggle`',
            '— einem Raster mit `place-items: center` — schiebt er das Zeichen um 6,5 px',
            'nach oben. Die Kopfleiste bleibt dabei 65 px hoch, der Schaden hat also auf',
            'Seitenebene keine Zahl.',
        ]));

        $this->assertStringContainsString('position: relative', $knopf, implode("\n", [
            'Die Regel `.nav-toggle` trägt kein `position: relative`.',
            '',
            'Der Punkt bezöge sich damit auf das nächste positionierte Element weiter',
            'oben und landete irgendwo auf der Seite.',
        ]));
    }

    /**
     * Punkt und Abzeichen lesen dieselbe Zahl.
     *
     * Zwei Bedingungen nebeneinander wären zwei Fassungen derselben Regel, und
     * die zweite ist die, die veraltet: Ein Telefon zeigte den Punkt, während
     * die breite Ansicht daneben kein Abzeichen hätte — oder umgekehrt.
     */
    public function test_the_dot_and_the_badge_read_the_same_number(): void
    {
        $quelle = $this->withoutMarkupComments($this->source());

        $this->assertSame(
            1,
            substr_count($quelle, 'const pendingUpdates'),
            'Die Zahl der offenen Aktualisierungen hat mehr als eine Quelle in dieser Datei.',
        );

        /*
         * Der Menüeintrag wird über seinen **Namen** gesucht und nicht über
         * `badge:`. Der erste Wurf tat das — und traf das Typfeld der
         * Ankündigungen, das ebenso heisst. Dieselbe Familie wie ein Regelname
         * als Zeichenkette, der jeden gleichnamigen Feldnamen mitfindet.
         */
        $stellen = [
            'class="nav-dot"' => 'Der Punkt',
            "name: 'Updates'" => 'Der Menüeintrag „Updates"',
        ];

        foreach ($stellen as $stelle => $was) {
            $bei = strpos($quelle, $stelle);
            $this->assertNotFalse($bei, sprintf('%s steht nicht mehr in der Vorlage (`%s`).', $was, $stelle));

            $zeile = $this->lineAt($quelle, $bei);
            $this->assertStringContainsString('pendingUpdates', $zeile, sprintf(
                "%s liest nicht `pendingUpdates`:\n  %s\n\n".
                'Zwei Quellen für dieselbe Zahl laufen auseinander — und die zweite ist die, die veraltet.',
                $was,
                trim($zeile),
            ));
        }
    }

    /**
     * Ein Punkt ohne Text bekommt seinen Namen vom Knopf.
     *
     * Er trägt `aria-hidden`, hat also keinen Namen, den man vorlesen könnte.
     * Bliebe die Beschriftung des Knopfes die feste Zeichenkette „Navigation",
     * wäre die Auskunft rein sichtbar — und der Punkt gibt es gerade deshalb,
     * weil eine Auskunft jemanden erreichen soll.
     */
    public function test_the_button_says_what_the_dot_shows(): void
    {
        $quelle = $this->withoutMarkupComments($this->source());

        $this->assertStringContainsString(':aria-label="navLabel"', $quelle, implode("\n", [
            'Der Menüknopf trägt keine gebundene Beschriftung.',
            '',
            'Der Punkt ist `aria-hidden` — wer den Bildschirm nicht sieht, hört sonst',
            'weiterhin nur „Navigation" und erfährt nichts über die offenen',
            'Aktualisierungen.',
        ]));

        $this->assertMatchesRegularExpression(
            '/offen === 1\s*\r?\n?\s*\?/D',
            $quelle,
            implode("\n", [
                'Die Beschriftung entscheidet die Einzahl nicht.',
                '',
                '„1 Aktualisierungen stehen an" ist genau der Befund, für den es',
                '`CountedNounTest` gibt — nur steht er hier in einem `aria-label`, das',
                'kein Auge sieht und jeder Screenreader vorliest.',
            ]),
        );
    }

    /**
     * Die Untergrenze: Der Abtaster entfernt wirklich etwas.
     *
     * Ohne diesen Fall wäre nicht zu unterscheiden, ob die Regeln oben halten
     * oder ob `withoutMarkupComments()` die Datei unverändert zurückgibt — dann
     * läse jede Prüfung den Kommentar mit, und der zitiert genau die Zeilen,
     * nach denen gefragt wird.
     */
    public function test_the_comments_really_fall_away(): void
    {
        $roh = $this->source();
        $ohne = $this->withoutMarkupComments($roh);

        $this->assertGreaterThan(
            substr_count($ohne, 'position: relative'),
            substr_count($roh, 'position: relative'),
            implode("\n", [
                'Im Rohtext steht `position: relative` nicht öfter als im abgestreiften.',
                '',
                'Entweder zitiert der Kommentar an `.nav-toggle` die Zeile nicht mehr —',
                'dann ist diese Untergrenze zu streichen —, oder der Abtaster greift',
                'nicht. Im zweiten Fall messen die Prüfungen darüber nichts.',
            ]),
        );
    }

    /** Die Datei, roh. */
    private function source(): string
    {
        $quelle = file_get_contents(self::LAYOUT);
        $this->assertNotFalse($quelle, 'PanelLayout.vue ist nicht lesbar.');

        return $quelle;
    }

    /** Der Inhalt des `<style scoped>`-Blocks, ohne Kommentare. */
    private function styleBlock(): string
    {
        $quelle = $this->withoutMarkupComments($this->source());

        $von = strpos($quelle, '<style scoped>');
        $this->assertNotFalse($von, 'PanelLayout.vue hat keinen `<style scoped>`-Block.');

        $bis = strpos($quelle, '</style>', $von);
        $this->assertNotFalse($bis, 'Der `<style scoped>`-Block ist nicht geschlossen.');

        return substr($quelle, $von, $bis - $von);
    }

    /**
     * Der Rumpf einer Regel, über Klammern gezählt statt bis zur nächsten
     * schliessenden gelesen: Die Regeln stehen in Medienblöcken, und ein
     * `}` gehört dort ebenso oft dem Block wie der Regel.
     */
    private function rule(string $stil, string $selektor): string
    {
        $bei = strpos($stil, "\n  {$selektor} {");
        $this->assertNotFalse($bei, sprintf('Die Regel `%s` steht nicht in PanelLayout.vue.', $selektor));

        $von = strpos($stil, '{', $bei);
        $tiefe = 0;

        for ($i = $von; $i < strlen($stil); $i++) {
            $tiefe += $stil[$i] === '{' ? 1 : ($stil[$i] === '}' ? -1 : 0);

            if ($tiefe === 0) {
                return substr($stil, $von, $i - $von);
            }
        }

        $this->fail(sprintf('Die Regel `%s` ist nicht geschlossen.', $selektor));
    }

    /** Die Zeile, in der eine Fundstelle liegt. */
    private function lineAt(string $quelle, int $bei): string
    {
        $von = strrpos(substr($quelle, 0, $bei), "\n");
        $bis = strpos($quelle, "\n", $bei);

        return substr($quelle, $von === false ? 0 : $von + 1, ($bis === false ? strlen($quelle) : $bis) - ($von === false ? 0 : $von + 1));
    }
}
