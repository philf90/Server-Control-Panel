<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Feature\NavIconTest;

/**
 * Die Zeichen der Handlungen — ein geschlossener Satz, wie der der Navigation.
 *
 * ## Warum es diesen Satz überhaupt gibt
 *
 * Der Betreiber hat am 20. August gemeldet, dass die vier Knöpfe der Kopfleiste
 * des Dateimanagers auf dem Telefon gestapelt stehen und 225 px fressen — vier
 * Zeilen, bevor eine einzige Datei zu sehen ist. Gemessen (`docs/64 §12`):
 *
 *     Zeichen **neben** dem Wort      215px    spart nichts
 *     Zeichen **über** dem Wort       119px    spart 106px
 *     nur Zeichen                     107px    spart 118px
 *
 * > **Ein Zeichen, das neben seinem Wort steht, kostet die Breite des Wortes
 * > noch einmal. Erst über dem Wort kostet es nichts.**
 *
 * ## Die Regel, die dieser Wächter hält
 *
 * `NavIcon.vue` schreibt sie in seinem eigenen Kopf, und der Betreiber hat sie
 * am 7. August an der Domainauswahl gemeldet:
 *
 * > **Sie tragen keine Bedeutung allein.** Neben jedem Zeichen steht sein Wort.
 *
 * Für zwölf Pixel Unterschied ist das ein schlechter Tausch — und einer, den
 * niemand bemerkt, weil ein Knopf ohne Wort aussieht wie eine Gestaltung und
 * nicht wie ein Verlust.
 */
final class ActionIconTest extends TestCase
{
    /** Wo die Zeichnungen stehen. */
    private const COMPONENT = 'resources/js/Components/ActionIcon.vue';

    /**
     * Vorlagen, die Zeichen verlangen.
     *
     * @var list<string>
     */
    private const USERS = ['resources/js/Pages/Files/Index.vue'];

    /** Für jeden verlangten Namen gibt es eine Zeichnung. */
    public function test_every_requested_icon_is_drawn(): void
    {
        $verlangt = $this->requested();

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertGreaterThanOrEqual(4, count($verlangt), 'Es werden kaum Zeichen verlangt — dann prüft dieser Wächter nichts.');

        $this->assertSame([], array_values(array_diff($verlangt, $this->drawn())), sprintf(
            "Für diese Namen gibt es keine Zeichnung in %s:\n  %s\n\n".
            'Die Komponente zeichnet dann nichts — kein Fehler, keine Meldung, nur ein Knopf ohne '.
            'sein Zeichen.',
            self::COMPONENT,
            implode("\n  ", array_diff($verlangt, $this->drawn())),
        ));
    }

    /**
     * Und jede Zeichnung wird verlangt.
     *
     * **Die Sperrklinke.** Ein Satz von Symbolen, aus dem niemand mehr wählt,
     * wächst still weiter — und beim nächsten Knopf greift jemand hinein und
     * nimmt eines, das nichts mit ihm zu tun hat.
     */
    public function test_every_drawn_icon_is_used(): void
    {
        $waisen = array_values(array_diff($this->drawn(), $this->requested()));

        $this->assertSame([], $waisen, sprintf(
            "Diese Zeichnungen verlangt kein Knopf:\n  %s",
            implode("\n  ", $waisen),
        ));
    }

    /**
     * Ein Satz und nicht zwei.
     *
     * Dieselben vier Zusagen wie in {@see NavIconTest}, weil die
     * beiden Sätze nebeneinander stehen: Ein gefülltes Symbol zwischen
     * umrissenen liest sich als eigene Bedeutung.
     */
    public function test_the_icons_are_one_set(): void
    {
        $svg = $this->read(self::COMPONENT);

        $this->assertStringContainsString('stroke="currentColor"', $svg,
            'Die Zeichen müssen ihre Farbe von der Umgebung erben — der Hauptknopf trägt eine andere.');
        $this->assertStringContainsString('fill="none"', $svg,
            'Ein Satz aus Umrissen: Eine gefüllte Zeichnung dazwischen liest sich als eigene Bedeutung.');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg,
            'Ein Raster für alle, sonst sind die Zeichen unterschiedlich gross.');
        $this->assertStringContainsString('stroke-width="1.6"', $svg,
            'Eine Strichstärke für alle — dieselbe wie im Menü, denn beide stehen auf derselben Seite.');

        $this->assertSame(
            0,
            preg_match('/#[0-9a-fA-F]{3,8}\b/', $svg),
            'In ActionIcon.vue steht ein Farbwert. Jede Farbe kommt aus app.css (Plan §7.2).',
        );
    }

    /**
     * Neben jedem Zeichen steht ein Wort.
     *
     * **Das ist die eigentliche Regel.** Reine Zeichen wären zwölf Pixel
     * billiger und ein Regelbruch; geprüft wird deshalb nicht das Zeichen,
     * sondern der Text daneben.
     */
    public function test_every_icon_has_a_word_beside_it(): void
    {
        $ohneWort = [];
        $gesehen = 0;

        foreach (self::USERS as $pfad) {
            $quelle = $this->read($pfad);

            preg_match_all(
                '/<button[^>]*>(.*?)<\/button>/s',
                $quelle,
                $knoepfe,
                PREG_SET_ORDER,
            );

            foreach ($knoepfe as [$ganz, $inhalt]) {
                if (! str_contains($inhalt, '<ActionIcon')) {
                    continue;
                }

                $gesehen++;

                // Was nach dem Zeichen an sichtbarem Text bleibt.
                $text = trim(strip_tags(preg_replace('/<ActionIcon[^>]*\/>/', '', $inhalt) ?? ''));

                if ($text === '') {
                    $ohneWort[] = trim(preg_replace('/\s+/', ' ', $ganz) ?? '');
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $gesehen, 'Es werden kaum Knöpfe mit Zeichen gefunden — dann prüft dieser Wächter nichts.');

        $this->assertSame([], $ohneWort, sprintf(
            "Diese Knöpfe tragen ein Zeichen und kein Wort:\n  %s\n\n".
            'Ein Zeichen trägt keine Bedeutung allein (NavIcon.vue, und der Befund des Betreibers '.
            'vom 7. August an der Domainauswahl). Gemessen sind zwölf Pixel Unterschied.',
            implode("\n  ", $ohneWort),
        ));
    }

    /**
     * Das verkürzte Wort bleibt im zugänglichen Namen.
     *
     * Auf der schmalen Fläche steht auf dem Knopf „Verzeichnis"; der Rest des
     * Satzes trägt `.verb`. Wäre der auf `display: none`, hiesse der Knopf auch
     * für die Vorlesesoftware nur „Verzeichnis" — also genau das Halbe, das
     * sichtbar dasteht.
     */
    public function test_the_hidden_verb_still_has_a_name(): void
    {
        $css = $this->read('resources/css/app.css');

        $this->assertSame(
            1,
            preg_match('/\.page-head \.verb \{([^}]*)\}/', $css, $block),
            'Die Regel für `.verb` im Seitenkopf gibt es nicht mehr — dann steht dort entweder das '.
            'ganze Wort oder gar keines.',
        );

        $this->assertStringNotContainsString(
            'display: none',
            $block[1],
            'Das Verb ist mit `display: none` versteckt. Damit fällt es aus dem zugänglichen Namen, '.
            'und der Knopf heisst „Verzeichnis" statt „Verzeichnis anlegen".',
        );

        $this->assertStringContainsString(
            'clip-path',
            $block[1],
            'Das Verb wird nicht mehr aus dem Bild genommen — dann steht der ganze Satz da, und die '.
            'Reihe bricht in drei Zeilen (docs/64 §12).',
        );
    }

    /**
     * Die Namen der Zeichnungen.
     *
     * @return list<string>
     */
    private function drawn(): array
    {
        if (preg_match('/const PATHS: Record<string, string> = \{(.*?)\n\}/su', $this->read(self::COMPONENT), $block) !== 1) {
            $this->fail('In ActionIcon.vue gibt es keinen PATHS-Block mehr.');
        }

        preg_match_all("/^  (\w+): '/m", $block[1], $namen);

        return array_values(array_unique($namen[1]));
    }

    /**
     * Die Namen, die eine Vorlage verlangt.
     *
     * @return list<string>
     */
    private function requested(): array
    {
        $namen = [];

        foreach (self::USERS as $pfad) {
            preg_match_all('/<ActionIcon name="(\w+)"/', $this->read($pfad), $treffer);

            $namen = array_merge($namen, $treffer[1]);
        }

        return array_values(array_unique($namen));
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
