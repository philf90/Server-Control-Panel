<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Points;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Kachel bekommt fertige Stützstellen vom Server — B4, `docs/129 §8`.
 *
 * Regel 2 des Gestaltungssystems (`docs/20 §7.2`) und der Grund, warum
 * `Tile.vue` im Browser mit dreissig Zeilen Logik auskommt: **Hier wird
 * gezeichnet und gesucht, nicht gerechnet.** Position, Beschriftung, Wert und
 * Einheit entstehen in {@see Points} und kommen als
 * Zeichenkette an.
 *
 * ## Warum das ein Wächter sein muss und keine Gewohnheit
 *
 * Seit B4 füllen **drei** Seiten dieselbe Kachel aus **zwei** Quellen — die
 * Übersicht aus dem Ringpuffer, Abonnement- und Domainseite aus der
 * Tagestabelle. Rechnete eine davon im Klienten, läge ihre Kurve anders als
 * die daneben, und zwar um Beträge, die niemandem auffallen, bis jemand die
 * beiden nebeneinanderlegt.
 *
 * > **Zwei Fassungen derselben Regel laufen auseinander — und die zweite ist
 * > die, die veraltet.**
 *
 * ## Was er nicht kann
 *
 * Er liest Quelltext. Dass eine Zahl **stimmt**, sagt er nicht — dafür gibt es
 * `DailyHistoryTest` und `SeriesReadingTest`, die die Wirkung messen. Und ob
 * eine Kurve *aussieht* wie ihre Nachbarin, sagt kein Test; das entscheidet
 * eine Bilderrunde.
 */
final class SeriesSourceTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const TILE = 'resources/js/Components/Tile.vue';

    /**
     * Rechnungen, die im Klienten nichts zu suchen haben.
     *
     * Jede von ihnen macht aus einer Zahl einen Text — und genau das ist die
     * Arbeit, die der Server schon getan hat. Eine zweite Formatierung im
     * Browser wäre nicht bloss doppelt, sondern **anders**: `toLocaleString`
     * folgt der Sprache des Geräts, `number_format` der des Panels.
     *
     * > **Ein Bild, das in der Sprache des Prüfstands aufgenommen wurde, sagt
     * > über die Anzeige auf dem Gerät des Lesers nichts.** Derselbe Satz wie
     * > beim Datumsfeld (`CLAUDE.md`, 5. September 2026) — hier als Regel
     * > statt als Grenze.
     *
     * @var list<string>
     */
    private const FORBIDDEN = ['toLocaleString', 'toFixed', 'Intl.NumberFormat', 'parseFloat'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $relative): string
    {
        $pfad = $this->root().'/'.$relative;

        self::assertFileExists($pfad, sprintf(
            'Ohne %s misst dieser Wächter nichts — eine fehlende Datei sähe aus wie eine saubere.',
            $relative,
        ));

        return (string) file_get_contents($pfad);
    }

    /** @return list<string> */
    private function pages(): array
    {
        $out = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/resources/js'));

        foreach ($dir as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'vue') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Der Wert und seine Beschriftung sind **Zeichenketten**.
     *
     * Wären sie Zahlen, müsste der Klient sie formatieren — und dann stünde
     * die Frage nach Nachkommastellen, Tausenderpunkt und Einheit dort, wo
     * niemand die Reihe kennt, aus der sie kommen.
     */
    public function test_a_point_carries_text_and_not_a_number(): void
    {
        $quelle = $this->withoutMarkupComments($this->read(self::TILE));

        self::assertMatchesRegularExpression('/\binterface Point\b/', $quelle);

        foreach (['t', 'v'] as $feld) {
            self::assertMatchesRegularExpression(
                '/^\s+'.$feld.':\s*string$/m',
                $quelle,
                sprintf(
                    '`%s` einer Stützstelle ist eine Zeichenkette. Als Zahl müsste die Kachel sie '
                    .'formatieren — und die Einheit kennt sie nicht.',
                    $feld,
                ),
            );
        }
    }

    /** Und die Kachel formatiert selbst nichts. */
    public function test_the_tile_formats_nothing(): void
    {
        $quelle = $this->withoutMarkupComments($this->read(self::TILE));

        foreach (self::FORBIDDEN as $ruf) {
            self::assertStringNotContainsString($ruf, $quelle, sprintf(
                '`%s` in der Kachel heisst: Der Browser rechnet eine Zahl in Text um, die der Server '
                .'schon umgerechnet hat — und zwar nach anderen Regeln.',
                $ruf,
            ));
        }
    }

    /**
     * Die Geometrie entsteht an **einer** Stelle.
     *
     * Gemessen am 21. September 2026: In `app/` und `agent/` schreibt genau
     * eine Datei den Schlüssel `'x'` einer Stützstelle, und das ist
     * {@see Points}. Eine zweite wäre die zweite Fassung
     * der Umkehr der y-Achse — und die fiele erst auf, wenn eine Kurve auf dem
     * Kopf stünde.
     */
    public function test_only_one_place_computes_a_support_point(): void
    {
        $treffer = [];

        foreach (['app', 'agent'] as $baum) {
            $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/'.$baum));

            foreach ($dir as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $quelle = $this->withoutComments((string) file_get_contents($file->getPathname()));

                if (str_contains($quelle, "'x' =>")) {
                    $treffer[] = str_replace($this->root().'/', '', $file->getPathname());
                }
            }
        }

        self::assertSame(['app/Support/Metrics/Points.php'], $treffer,
            'Eine Stützstelle entsteht in `Points::build()` und sonst nirgends. Wer eine zweite '
            .'Stelle baut, schreibt die Umkehr der y-Achse ein zweites Mal — und beim nächsten '
            .'Feld hat eine der beiden sie.');
    }

    /**
     * Jede Kurve auf einer Seite stammt aus einer Eigenschaft des Servers.
     *
     * Gelesen wird die **Bindung** und nicht der Name der Komponente: Ein
     * `:series="berechnet"` über einem `computed` wäre genau der Fall, den
     * diese Regel ausschliesst, und der Name `Tile` stünde daneben unverändert
     * da.
     *
     * Die Zuordnung „ist das eine Eigenschaft?" kommt aus dem `defineProps`
     * der Seite selbst und nicht aus einer Liste in diesem Test — sonst
     * pflegte jemand hier eine Abschrift, die beim nächsten Umbau veraltet.
     */
    public function test_every_curve_on_a_page_comes_from_the_server(): void
    {
        $gefunden = 0;

        foreach ($this->pages() as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));
            $name = str_replace($this->root().'/', '', $pfad);

            preg_match_all('/:(?:series|second)="([^"]+)"/', $quelle, $bindungen);

            foreach ($bindungen[1] as $ausdruck) {
                $gefunden++;

                $wurzel = explode('.', trim($ausdruck))[0];

                // Ein `v-for="x in y"` führt die Wurzel auf seine Quelle zurück.
                if (preg_match('/v-for="'.preg_quote($wurzel, '/').' in ([^"]+)"/', $quelle, $schleife) === 1) {
                    $wurzel = explode('.', trim($schleife[1]))[0];
                }

                if ($wurzel === 'props') {
                    continue;
                }

                self::assertTrue(
                    $this->isProp($quelle, $wurzel),
                    sprintf(
                        '%s bindet `%s` an eine Kurve, und `%s` ist keine Eigenschaft des Servers. '
                        .'Was eine Seite selbst ausrechnet, liegt anders als dieselbe Kurve daneben.',
                        $name, $ausdruck, $wurzel,
                    ),
                );
            }
        }

        self::assertGreaterThanOrEqual(6, $gefunden,
            'Sechs Bindungen sind gemessen — drei Seiten mit je `series` und `second`. Findet dieser '
            .'Ausdruck weniger, greift er ins Leere und sein Grün bedeutet nichts.');
    }

    /** Steht dieser Name im `defineProps` der Seite? */
    private function isProp(string $quelle, string $name): bool
    {
        $anfang = strpos($quelle, 'defineProps<{');

        if ($anfang === false) {
            return false;
        }

        return preg_match('/^\s{2}'.preg_quote($name, '/').'[?]?:/m', substr($quelle, $anfang)) === 1;
    }
}
