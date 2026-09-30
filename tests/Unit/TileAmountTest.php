<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Ein Betrag in der Beizeile einer Kachel bricht nicht in sich — `docs/139 §6b`.
 *
 * ## Der Fund
 *
 * Auf `cloudsrv24` stand am 30. September 2026 auf der Abonnementseite bei
 * 1440px „ausgehend · eingehend 3,6" und in der Zeile darunter „MB", im
 * Ruhezustand und in beiden Ablesungen (`docs/139 §7`). Die Traffic-Kachel ist
 * dort 228px breit, ihre Beizeile 179px, und die Zeile brach am letzten
 * Leerzeichen, das noch passte: dem zwischen Zahl und Einheit.
 *
 * Die Zeichenketten kommen fertig vom Server (`Points`), und die Kachel
 * formatiert nichts ({@see SeriesSourceTest}). Ein geschütztes Leerzeichen
 * hätte der Server in jede Stützstelle schreiben müssen, auch in die, die in
 * der Beschriftung für die Vorlesesoftware steht. Die Kachel fasst den Betrag
 * deshalb in ein `.amount`, und `app.css` hält ihn in einer Zeile.
 *
 * ## Was er hält
 *
 * Jede Einbettung in der Beizeile, die einen Wert trägt (`.v` einer
 * Stützstelle, `value`, `unit`), steht in einem `<span class="amount">`. Zahl
 * und Einheit der zweiten Richtung stehen im **selben**. Dazu kommt die Regel,
 * ohne die die Klasse nichts tut, und keine zweite nimmt sie zurück.
 *
 * ## Was er nicht kann
 *
 * Er liest Quelltext. Wo die Zeile bricht, entscheidet der Browser; gemessen
 * ist das im Container gegen das gebaute Stylesheet, alt gegen neu
 * (`docs/139 §6b`). Und ob ein Betrag in die schmalste Beizeile passt, hängt an
 * der Länge dessen, was `Points` schreibt. Gemessen sind 102px gegen 151px, und
 * diese Länge hält kein Wächter.
 */
final class TileAmountTest extends TestCase
{
    use WithoutMarkupComments;

    private const TILE = 'resources/js/Components/Tile.vue';

    private const STYLESHEET = 'resources/css/app.css';

    /**
     * Eine Einbettung, die einen Wert trägt: die Ablesung einer Stützstelle
     * (`.v`), Zahl und Einheit der zweiten Richtung (`value`, `unit`).
     *
     * `hovered.t`, `subline` und `second.label` sind Wörter und keine Beträge;
     * zwischen ihnen darf die Zeile brechen.
     */
    private const AMOUNT = '/\.v\b|\bvalue\b|\bunit\b/';

    /** Die Einfassung, wie die Vorlage sie schreibt. */
    private const ENCLOSURE = '#<span class="amount">(.*?)</span>#s';

    private function read(string $relative): string
    {
        $pfad = dirname(__DIR__, 2).'/'.$relative;

        self::assertFileExists($pfad, sprintf(
            'Ohne %s misst dieser Wächter nichts — eine fehlende Datei sähe aus wie eine saubere.',
            $relative,
        ));

        return (string) file_get_contents($pfad);
    }

    /**
     * Die Beizeile ohne Kommentare, vom öffnenden `<div>` bis zu dem, das ihn
     * schliesst.
     *
     * Gezählt wird die Tiefe und nicht das erste `</div>`: Ein Block, der
     * später in die Zeile kommt, schnitte sie sonst in der Mitte ab, und alles
     * danach fiele wortlos aus der Prüfung.
     */
    private function subline(): string
    {
        $quelle = $this->withoutMarkupComments($this->read(self::TILE));
        $klasse = strpos($quelle, 'class="tile-sub"');

        self::assertNotFalse($klasse, 'In Tile.vue steht keine Beizeile mehr — dann prüft dieser Wächter nichts.');

        $anfang = (int) strrpos(substr($quelle, 0, $klasse), '<div');
        $tiefe = 0;

        preg_match_all('#<div\b|</div>#', $quelle, $marken, PREG_OFFSET_CAPTURE, $anfang);

        foreach ($marken[0] as [$marke, $stelle]) {
            $tiefe += $marke === '</div>' ? -1 : 1;

            if ($tiefe === 0) {
                return substr($quelle, $anfang, $stelle + strlen('</div>') - $anfang);
            }
        }

        self::fail('Die Beizeile in Tile.vue wird nicht geschlossen.');
    }

    /**
     * Jeder Betrag in der Beizeile steht in einer Einfassung.
     */
    public function test_every_amount_in_the_subline_is_enclosed(): void
    {
        $zeile = $this->subline();

        preg_match_all(self::ENCLOSURE, $zeile, $einfassungen, PREG_OFFSET_CAPTURE);

        /** @var list<array{0: int, 1: int}> $bereiche */
        $bereiche = [];

        foreach ($einfassungen[1] as [$inhalt, $stelle]) {
            $bereiche[] = [$stelle, $stelle + strlen($inhalt)];
        }

        preg_match_all('/\{\{(.*?)\}\}/s', $zeile, $einbettungen, PREG_OFFSET_CAPTURE);

        $betraege = 0;

        foreach ($einbettungen[1] as [$ausdruck, $stelle]) {
            if (preg_match(self::AMOUNT, $ausdruck) !== 1) {
                continue;
            }

            $betraege++;
            $drin = false;

            foreach ($bereiche as [$von, $bis]) {
                $drin = $drin || ($stelle >= $von && $stelle < $bis);
            }

            self::assertTrue($drin, sprintf(
                "In der Beizeile von Tile.vue steht `{{%s}}` ohne `<span class=\"amount\">`.\n\n".
                'Ohne die Einfassung bricht die Zeile am Leerzeichen zwischen Zahl und Einheit — auf '.
                'der Abonnementseite bei 1440px „3,6" oben und „MB" darunter (docs/139 §7).',
                $ausdruck,
            ));
        }

        // Eine Ablesung und die zweite Richtung mit Zahl und Einheit: Findet
        // der Ausdruck weniger, greift er ins Leere, und sein Grün bedeutet nichts.
        self::assertGreaterThanOrEqual(3, $betraege,
            'In der Beizeile werden kaum Beträge gefunden — dann prüft dieser Wächter nichts.');
    }

    /**
     * Zahl und Einheit der zweiten Richtung stehen in **einer** Einfassung.
     *
     * Zwei Einfassungen nebeneinander hielten jede für sich zusammen und
     * liessen zwischen sich umbrechen, und zwar genau an der Stelle aus
     * `docs/139 §7`. Der Fall darüber sähe das nicht: Jede der beiden
     * Einbettungen stünde in einer Einfassung.
     */
    public function test_number_and_unit_share_one_enclosure(): void
    {
        preg_match_all(self::ENCLOSURE, $this->subline(), $einfassungen);

        $gemeinsam = array_filter(
            $einfassungen[1],
            static fn (string $inhalt): bool => preg_match('/\{\{\s*second\.value\s*\}\}/', $inhalt) === 1
                && preg_match('/\{\{\s*second\.unit\s*\}\}/', $inhalt) === 1,
        );

        self::assertCount(1, $gemeinsam,
            'Zahl und Einheit der zweiten Richtung stehen nicht zusammen in einem `<span class="amount">`. '.
            'Getrennt eingefasst bricht die Zeile zwischen den beiden Einfassungen.');
    }

    /**
     * Und ohne die Regel tut die Klasse nichts — keine zweite nimmt sie zurück.
     *
     * Gelesen werden `app.css` und die Stilblöcke der Kachel selbst: Eine
     * Regel in `<style scoped>` trägt das Attribut des Übersetzers und schlägt
     * die aus dem Stylesheet, ohne dass dort etwas anders aussieht.
     *
     * > **Eine zweite Regel für dieselbe Hülle macht die Frage „gibt es eine?"
     * > stumpf.**
     */
    public function test_the_stylesheet_keeps_an_amount_on_one_line(): void
    {
        $tile = $this->read(self::TILE);
        preg_match_all('#<style\b[^>]*>(.*?)</style>#s', $tile, $bloecke);

        $css = (string) preg_replace('#/\*.*?\*/#s', ' ', $this->read(self::STYLESHEET)."\n".implode("\n", $bloecke[1]));

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $regeln, PREG_SET_ORDER);

        $haelt = 0;

        foreach ($regeln as [, $kopf, $rumpf]) {
            if (preg_match('/\.amount\b/', $kopf) !== 1 || preg_match('/white-space\s*:\s*([^;}\s]+)/', $rumpf, $wert) !== 1) {
                continue;
            }

            self::assertSame('nowrap', $wert[1], sprintf(
                '„%s" setzt `white-space: %s` an einem Betrag. Dann bricht die Zeile wieder zwischen Zahl '.
                'und Einheit (docs/139 §7).',
                trim($kopf),
                $wert[1],
            ));

            if (in_array('.tile-sub .amount', array_map('trim', explode(',', $kopf)), true)) {
                $haelt++;
            }
        }

        self::assertSame(1, $haelt,
            'In app.css steht keine Regel `.tile-sub .amount { white-space: nowrap }`. Ohne sie ist die '.
            'Einfassung in Tile.vue ein Wunsch, und die Zeile bricht wie vorher.');
    }
}
