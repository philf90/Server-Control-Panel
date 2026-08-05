<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jeder Menüpunkt trägt ein Zeichen, und jedes Zeichen ist gezeichnet.
 *
 * **Warum das ein Test ist.** Es ist dasselbe Muster wie überall in diesem
 * Projekt: `<NavIcon name="domains" />` ist eine Zeichenkette, die auf etwas
 * verweist. Steht sie falsch da — `domain` statt `domains`, oder ein neuer
 * Menüpunkt ohne Zeichnung —, dann zeichnet die Komponente **nichts**. Kein
 * Fehler, keine Meldung, nur ein Eintrag, der als einziger keinen Punkt vor
 * sich hat. Genau die Sorte Fehler, die man auf einem Bildschirmfoto übersieht,
 * weil dort ohnehin viel steht.
 *
 * Dieselbe Familie wie `IconTest`, nur eine Ebene tiefer: Der prüft, dass die
 * Adressen im Kopf der Seite auf Dateien zeigen, die es gibt. Dieser prüft die
 * Kette vom Menüeintrag bis zum Pfad im SVG.
 *
 * Geprüft wird in beide Richtungen — ein Satz von Zeichnungen, den niemand
 * benutzt, wächst genauso still wie einer, in dem etwas fehlt.
 */
final class NavIconTest extends TestCase
{
    private function layout(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue');
    }

    private function component(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/NavIcon.vue');
    }

    /** Die Namen, für die es eine Zeichnung gibt. @return list<string> */
    private function drawn(): array
    {
        // Nur der `PATHS`-Block: Der Rest der Datei enthält Prosa, in der
        // dieselben Wörter vorkommen.
        if (preg_match('/const PATHS: Record<string, string> = \{(.*?)\n\}/su', $this->component(), $match) !== 1) {
            $this->fail('In NavIcon.vue gibt es keinen PATHS-Block mehr.');
        }

        preg_match_all("/^\s*([a-z][a-zA-Z0-9]*):\s*'([^']+)'/m", $match[1], $namen, PREG_SET_ORDER);

        $drawn = [];

        foreach ($namen as $eintrag) {
            // Ein Name mit leerem Pfad wäre ein Eintrag, der aussieht wie eine
            // Zeichnung und keine ist.
            $this->assertMatchesRegularExpression(
                '/^[Mm]\s*-?[\d.]/',
                trim($eintrag[2]),
                sprintf('Die Zeichnung „%s" beginnt nicht mit einem Startpunkt (M) — sie zeichnet nichts.', $eintrag[1]),
            );

            $drawn[] = $eintrag[1];
        }

        return $drawn;
    }

    /** Die Namen, die das Menü verlangt. @return list<string> */
    private function requested(): array
    {
        preg_match_all("/icon:\s*'([a-z][a-zA-Z0-9]*)'/", $this->layout(), $namen);

        return array_values(array_unique($namen[1]));
    }

    public function test_every_menu_entry_carries_an_icon(): void
    {
        /*
         * Gezählt und nicht bloss „kommt vor": Ein Menüblock, in dem drei von
         * vier Einträgen ein Zeichen tragen, bestünde sonst.
         */
        $eintraege = preg_match_all("/\{\s*name:\s*'[^']+',\s*href:\s*'[^']+'/", $this->layout());
        $zeichen = preg_match_all("/icon:\s*'/", $this->layout());

        $this->assertGreaterThan(8, $eintraege, 'Es werden kaum Menüpunkte gefunden — dann prüft dieser Test nichts.');

        $this->assertSame(
            $eintraege,
            $zeichen,
            sprintf(
                "%d Menüpunkte, aber nur %d Zeichen.\n\n".
                "Ein Eintrag ohne `icon:` steht in der Spalte als einziger ohne Zeichen da — das sieht\n".
                'nach einem Fehler aus und ist einer.',
                $eintraege,
                $zeichen,
            ),
        );
    }

    public function test_every_requested_icon_is_drawn(): void
    {
        $missing = array_values(array_diff($this->requested(), $this->drawn()));

        $this->assertGreaterThan(8, count($this->requested()), 'Es werden kaum Zeichen verlangt — dann prüft dieser Test nichts.');

        $this->assertSame([], $missing, sprintf(
            "Für diese Namen gibt es keine Zeichnung in NavIcon.vue:\n  %s\n\n".
            'Die Komponente zeichnet dann nichts — kein Fehler, keine Meldung, nur ein Menüpunkt '.
            'ohne Zeichen.',
            implode("\n  ", $missing),
        ));
    }

    public function test_every_drawn_icon_is_used(): void
    {
        $orphans = array_values(array_diff($this->drawn(), $this->requested()));

        $this->assertSame([], $orphans, sprintf(
            "Diese Zeichnungen verlangt kein Menüpunkt:\n  %s\n\n".
            "Ein Satz von Symbolen, aus dem niemand mehr wählt, wächst still weiter — und beim\n".
            'nächsten Menüpunkt greift jemand hinein und nimmt eines, das nichts mit ihm zu tun hat.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * Ein Satz und nicht zwei.
     *
     * Gemischte Zeichnungen — hier ein gefülltes Symbol, dort ein umrissenes —
     * sehen in einer Spalte untereinander nach zwei Sätzen aus, und der Blick
     * liest daraus eine Bedeutung, die es nicht gibt.
     */
    public function test_the_icons_are_one_set(): void
    {
        $svg = $this->component();

        $this->assertStringContainsString('stroke="currentColor"', $svg,
            'Die Zeichen müssen ihre Farbe von der Umgebung erben — der aktive Menüpunkt trägt den Akzent.');
        $this->assertStringContainsString('fill="none"', $svg,
            'Ein Satz aus Umrissen: Eine gefüllte Zeichnung dazwischen liest sich als eigene Bedeutung.');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg,
            'Ein Raster für alle, sonst sind die Zeichen unterschiedlich groß.');

        $this->assertSame(
            0,
            preg_match('/#[0-9a-fA-F]{3,8}\b/', $svg),
            'In NavIcon.vue steht ein Farbwert. Jede Farbe kommt aus app.css (Plan §7.2).',
        );
    }
}
