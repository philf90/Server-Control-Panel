<?php

declare(strict_types=1);

namespace Tests\Unit;

use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tests\Support\TemplateDom;
use Tests\Support\WithoutMarkupComments;

/**
 * Jedes Band hat ein x — gewünscht und entschieden vom Betreiber am
 * 11. Oktober 2026.
 *
 * **Zwei Fassungen, und beide stehen hier.** Ankündigungen und Vorgänge bleiben
 * fort, bis sie sich ändern, gemerkt im Browser je Konto
 * (`useDismissedBands.ts`). Die fremde Sicht und die Wartung kommen beim
 * nächsten Seitenwechsel wieder: Sie melden einen Zustand, und das Band der
 * fremden Sicht trägt den einzigen Rückweg zur Verwaltung.
 *
 * **Gemessen ist das Verhalten im Chromium** (`docs/144 §6b`): merken über
 * Seitenwechsel und volles Neuladen, wiederkommen nach einer Änderung von Rang
 * oder Text, kein Takt für einen weggeklickten Vorgang, keine Hülle, wenn alles
 * weggeklickt ist. Was dieser Wächter hält, sind die Stellen im Quelltext, an
 * denen das hängt — die Laufzeit misst er nicht.
 *
 * Die Bänder kommen aus den Vorlagen und nicht aus einer Liste: Gesucht wird
 * jedes Element mit der Klasse `band`.
 */
final class BandDismissTest extends TestCase
{
    use WithoutMarkupComments;

    private const LAYOUT = __DIR__.'/../../resources/js/Layouts/PanelLayout.vue';

    private const STORE = __DIR__.'/../../resources/js/Composables/useDismissedBands.ts';

    private const BANDS = __DIR__.'/../../resources/js/Components/Bands.vue';

    /** @return list<string> */
    private function templates(): array
    {
        $dateien = glob(__DIR__.'/../../resources/js/{Layouts,Components,Pages,Pages/*,Pages/*/*}/*.vue', GLOB_BRACE) ?: [];
        sort($dateien);

        return $dateien;
    }

    private function relative(string $datei): string
    {
        return (string) preg_replace('#^.*?/resources/js/#', '', $datei);
    }

    /**
     * Jedes Band steht in einer Hülle `.band-item`, und daneben steht genau
     * ein x.
     *
     * **Nebeneinander und nicht ineinander.** Zwei der vier Bänder sind selbst
     * ein Verweis, und ein Knopf in einem `<a>` ist kein gültiges HTML; ein
     * Klick darauf ginge an den Verweis weiter.
     */
    public function test_every_band_sits_beside_its_x(): void
    {
        $befunde = [];
        $baender = 0;

        foreach ($this->templates() as $datei) {
            $xpath = new DOMXPath(TemplateDom::fromSource((string) file_get_contents($datei)));

            foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' band ')]") ?: [] as $band) {
                if (! $band instanceof DOMElement) {
                    continue;
                }

                $baender++;
                $huelle = $band->parentNode;
                $klassen = $huelle instanceof DOMElement ? ' '.$huelle->getAttribute('class').' ' : '';

                if (! str_contains($klassen, ' band-item ')) {
                    $befunde[] = sprintf('%s: ein Band steht nicht in einer Hülle `.band-item`.', $this->relative($datei));

                    continue;
                }

                $kinder = [];

                foreach ($huelle->childNodes as $kind) {
                    if ($kind instanceof DOMElement) {
                        $kinder[] = $kind === $band ? 'band' : $kind->nodeName;
                    }
                }

                if ($kinder !== ['band', TemplateDom::component('BandDismiss')]) {
                    $befunde[] = sprintf(
                        '%s: in der Hülle eines Bandes steht [%s] statt [band, x].',
                        $this->relative($datei),
                        implode(', ', $kinder),
                    );
                }
            }
        }

        /*
         * **Die Untergrenze zählt mit.** Gezählt am 11. Oktober 2026: vier
         * Bänder in drei Vorlagen — fremde Sicht und Wartung im Layout, die
         * Ankündigung in `Bands.vue`, der Vorgang in `OperationBand.vue`.
         */
        $this->assertGreaterThanOrEqual(4, $baender,
            'Es werden kaum Bänder gefunden — dann greift der Ausdruck ins Leere, und jeder Befund hier wäre ein Freispruch.');

        $this->assertSame([], $befunde, implode("\n", [
            'Diese Bänder haben kein x neben sich:',
            '',
            '  '.implode("\n  ", $befunde),
            '',
            'Jedes Band lässt sich wegklicken (11. Oktober 2026). Das x steht neben dem Band in der Hülle',
            '`.band-item`; `app.css` legt es über die rechte obere Ecke.',
        ]));
    }

    /** Kein x steht in einem Band oder in einem Verweis. */
    public function test_the_x_is_never_inside_a_band_or_a_link(): void
    {
        $befunde = [];
        $xe = 0;
        $x = TemplateDom::component('BandDismiss');
        $link = TemplateDom::component('Link');

        foreach ($this->templates() as $datei) {
            $xpath = new DOMXPath(TemplateDom::fromSource((string) file_get_contents($datei)));

            foreach ($xpath->query('//'.$x) ?: [] as $fund) {
                $xe++;

                $drin = $xpath->query(
                    "ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' band ')] | ancestor::a | ancestor::{$link} | ancestor::button",
                    $fund,
                );

                if ($drin !== false && $drin->length > 0) {
                    $befunde[] = sprintf('%s: ein x steht in einem Band, einem Verweis oder einem Knopf.', $this->relative($datei));
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $xe,
            'Es werden kaum x gefunden — dann prüft dieser Fall nichts.');

        $this->assertSame([], $befunde, implode("\n", [
            ...$befunde,
            '',
            'Ein Knopf in einem `<a>` ist kein gültiges HTML, und ein Klick darauf ginge an den Verweis weiter.',
        ]));
    }

    /**
     * Die fremde Sicht und die Wartung kommen beim Seitenwechsel wieder — und
     * der Speicher merkt sich keine von beiden.
     *
     * **Zwei Seiten derselben Entscheidung.** Im Layout hängen beide Hüllen an
     * `hiddenUntilNavigation`, dem Zustand der Komponente, den Inertia bei
     * jedem Seitenwechsel neu anlegt. Und der Speicher im Browser kennt nur
     * Ankündigungen und Vorgänge; nähme er eine dritte Art auf, wäre der
     * Rückweg aus der fremden Sicht bis zum Abmelden fort.
     */
    public function test_the_state_bands_come_back_and_the_store_never_keeps_them(): void
    {
        $vorlage = $this->withoutMarkupComments((string) file_get_contents(self::LAYOUT));

        foreach (['impersonation', 'maintenance'] as $art) {
            $this->assertSame(
                1,
                preg_match(
                    '/<div\s+v-if="[^"|]*&& !hiddenUntilNavigation\.has\(\''.$art.'\'\)"\s+class="band-item">/',
                    $vorlage,
                ),
                sprintf('Die Hülle des Bandes „%s" fragt `hiddenUntilNavigation` nicht mehr — weggeklickt bliebe es stehen.', $art),
            );

            $this->assertSame(
                1,
                substr_count($vorlage, '<BandDismiss @dismiss="hiddenUntilNavigation.add(\''.$art.'\')" />'),
                sprintf('Das x des Bandes „%s" schreibt nicht in `hiddenUntilNavigation` — dann kommt es beim Seitenwechsel nicht wieder.', $art),
            );
        }

        $speicher = $this->withoutMarkupComments((string) file_get_contents(self::STORE));

        $this->assertSame(1, preg_match('/const FORM = \/\^\((.*)\)\$\/$/m', $speicher, $form),
            'Die Form der gemerkten Schlüssel ist im Speicher nicht mehr zu finden — dann prüft dieser Fall nichts.');

        preg_match_all('/(?:^|\|)([a-z]+):/', $form[1], $arten);

        $this->assertSame(
            ['announcement', 'operation'],
            $arten[1],
            'Der Speicher nimmt andere Arten auf als Ankündigungen und Vorgänge. Ein Zustand, den das Layout bis zum '
            .'Seitenwechsel verbirgt, gehört nicht in den Browser — sonst ist der Rückweg aus der fremden Sicht fort.',
        );

        foreach (['impersonation', 'maintenance'] as $art) {
            $this->assertStringNotContainsString($art, $speicher,
                sprintf('Der Speicher nennt „%s" — er merkt sich nur Ankündigungen und Vorgänge.', $art));
        }
    }

    /**
     * Der Schlüssel einer Ankündigung trägt alles, was ihr Band zeigt.
     *
     * **„Bis es sich ändert" heisst: bis sich ändert, was man gesehen hat.**
     * Zeigt das Band einmal ein Feld mehr und der Abdruck nimmt es nicht auf,
     * bliebe eine Änderung an genau diesem Feld bei jedem verborgen, der die
     * alte Fassung weggeklickt hat.
     */
    public function test_the_key_of_an_announcement_covers_what_its_band_shows(): void
    {
        preg_match_all('/\bhinweis\.(\w+)/', TemplateDom::template((string) file_get_contents(self::BANDS)), $gezeigt);
        $gezeigt = array_values(array_unique($gezeigt[1]));

        $speicher = $this->withoutMarkupComments((string) file_get_contents(self::STORE));

        $this->assertSame(1, preg_match('/function announcementKey\([^)]*\): string \{(.*?)\n\}/s', $speicher, $rumpf),
            '`announcementKey()` ist im Speicher nicht mehr zu finden — dann prüft dieser Fall nichts.');

        preg_match_all('/\bhinweis\.(\w+)/', $rumpf[1], $abdruck);

        $this->assertGreaterThanOrEqual(3, count($gezeigt),
            'Das Band zeigt kaum ein Feld — dann greift der Ausdruck über `Bands.vue` ins Leere.');

        $fehlt = array_values(array_diff($gezeigt, $abdruck[1]));

        $this->assertSame([], $fehlt, sprintf(
            "Das Band zeigt %s, und der Schlüssel trägt es nicht.\n\nÄndert der Betreiber genau dieses Feld, bleibt "
            .'das Band bei jedem fort, der die alte Fassung weggeklickt hat.',
            implode(', ', $fehlt),
        ));
    }

    /**
     * Die Hülle und ihre Streifen fragen die sichtbare Liste.
     *
     * **Sonst steht eine leere Hülle da**, wenn alles weggeklickt ist, und ein
     * weggeklickter Vorgang hielte den Takt am Leben, für ein Band, das niemand
     * sieht. Jede Liste, die ein Streifen in einer Hülle mit Bedingung zeigt,
     * kommt aus dem Speicher (`visible`), und die Bedingung der Hülle fragt
     * dieselbe Liste.
     */
    public function test_the_hull_asks_the_visible_list_it_shows(): void
    {
        $befunde = [];
        $listen = 0;

        foreach ($this->templates() as $datei) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($datei));

            if (preg_match_all('/<div\s+v-if="([^"]*)"\s+class="bands"\s*>/', $quelle, $huellen, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($huellen[1] as $i => [$bedingung]) {
                $anfang = (int) $huellen[0][$i][1];
                $ende = strpos($quelle, "\n    </div>", $anfang);
                $ende = $ende === false ? strlen($quelle) : $ende;

                preg_match_all('/<(?:Bands|OperationBand)\s+:items="(\w+)"/', substr($quelle, $anfang, $ende - $anfang), $gebunden);

                foreach ($gebunden[1] as $liste) {
                    $listen++;

                    if (! str_contains($bedingung, $liste.'.length')) {
                        $befunde[] = sprintf('%s: die Hülle fragt `%s` nicht.', $this->relative($datei), $liste);
                    }

                    if (preg_match('/\bvisible:\s*'.$liste.'\b/', $quelle) !== 1) {
                        $befunde[] = sprintf('%s: `%s` kommt nicht aus dem Speicher der weggeklickten Bänder.', $this->relative($datei), $liste);
                    }
                }
            }
        }

        /*
         * Gezählt am 11. Oktober 2026: vier Listen in drei Hüllen — Ankündigungen
         * und Vorgänge im Layout, die Störungen auf beiden Seiten der Anmeldung.
         */
        $this->assertGreaterThanOrEqual(4, $listen,
            'Es werden kaum Listen in einer Hülle gefunden — dann prüft dieser Fall nichts.');

        $this->assertSame([], $befunde, implode("\n", [
            ...$befunde,
            '',
            'Eine Hülle, die die ganze Liste fragt, steht leer da, wenn alles weggeklickt ist; ein Streifen, der sie',
            'zeigt, holt jedes weggeklickte Band zurück.',
        ]));
    }
}
