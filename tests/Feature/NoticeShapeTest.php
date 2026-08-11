<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Meldung ist eine Flexbox — ihr Text gehört in **ein** Kind.
 *
 * **Der Anlass ist ein Fehler, der ausgeliefert war.** Auf der Abonnementseite
 * standen in `<p class="notice warn">` vier direkte Kinder: ein `strong` und
 * drei Kennungen. `.notice` ist `display: flex` ohne `flex-wrap`, und damit ist
 * jedes davon ein Flex-Item, das **neben** den anderen steht statt mit ihnen
 * umzubrechen. Bei 390px schob die Meldung die Seite um **65px** aus dem Bild.
 *
 * Gemessen am 10. August 2026 im vorinstallierten Chromium — mit dem gebauten
 * Stylesheet aus `public/build` und dem Markup in einer eigenen Datei, weil
 * ohne `vendor/` kein `artisan serve` läuft (CLAUDE.md, „Diese Umgebung").
 * Einzeln lief keine der drei Kennungen über; erst zusammen. Ausgeliefert war
 * das mit `v0.5.1-rc.7`.
 *
 * **Es ist derselbe Fehler wie der aus P4**, der 83px gekostet hat und
 * ebenfalls ausgeliefert wurde: eine Kennung, die im Fliesstext nicht bricht.
 * Und wieder gefunden von einer Messung, nicht von einem Test — der Bereich
 * war vollständig grün.
 *
 * > **Eine Regel, die nur eine Seite befolgt, ist keine Regel, sondern ein
 * > Zufall.** `Overview.vue` wickelte seinen Text am selben Tag richtig ein.
 *
 * ## Warum die Form und nicht die Breite geprüft wird
 *
 * Weil hier kein Browser läuft. Ob etwas überläuft, beantwortet nur ein
 * Rendering — was sich ohne eines prüfen lässt, ist die Form, die den Überlauf
 * erzeugt. Dieselbe Wahl wie bei `SiteTemplateTest`: Der Schutz ist eine
 * Eigenschaft der erzeugten Zeichenkette.
 *
 * **Ein Kind ist erlaubt** und der Normalfall: Es gibt keine zwei Items, die
 * nebeneinander stehen könnten. Gemessen sind alle drei Kennungen dieser
 * Meldung einzeln bei 390px — jede für sich bricht.
 */
final class NoticeShapeTest extends TestCase
{
    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_a_notice_with_more_than_one_child_wraps_its_text(): void
    {
        $seen = 0;
        $broken = [];

        foreach ($this->templates() as $file) {
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $file);
            $source = (string) file_get_contents($file);

            preg_match_all(
                '~<(p|div)\s+[^>]*class="[^"]*\bnotice\b[^"]*"[^>]*>(.*?)</\1>~s',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $seen++;

                $inner = trim((string) preg_replace('~<!--.*?-->~s', '', $match[2]));

                // Gewickelt: genau ein `span` trägt alles. Das ist die Form aus
                // `Overview.vue`, und sie ist der einzige Weg, auf dem mehrere
                // Textstücke in einer Flexbox miteinander umbrechen.
                if (str_starts_with($inner, '<span>') && str_ends_with($inner, '</span>')) {
                    continue;
                }

                $children = preg_match_all('~<(?!/)[a-zA-Z][\w-]*~', $inner);

                if ($children >= 2) {
                    $broken[] = sprintf(
                        '%s: eine Meldung mit %d direkten Kindern — „%s…"',
                        $relative,
                        $children,
                        mb_substr(preg_replace('~\s+~', ' ', $inner) ?? '', 0, 60),
                    );
                }
            }
        }

        $this->assertSame([], $broken, sprintf(
            "Diese Meldungen setzen mehrere Kinder nebeneinander in eine Flexbox:\n\n%s\n\n"
            .'Bei 390px stehen sie in einer Reihe, statt umzubrechen — die Seite läuft waagerecht '
            .'über. Der Weg ist der aus `Overview.vue`: der ganze Text in ein einziges `<span>`.',
            implode("\n", $broken),
        ));

        /*
         * **Die Untergrenze zählt, wo die Regel stehen darf.** Dreissig ist weit
         * unter dem Bestand (44 am 10. August 2026) und weit über dem, was ein
         * kaputter Ausdruck liefert — und genau davor steht diese Zeile: Ein
         * Muster, das nichts mehr findet, meldete „alles in Ordnung".
         */
        $this->assertGreaterThan(30, $seen, 'Der Ausdruck findet keine Meldungen mehr.');
    }

    /**
     * Und eine Meldung darf brechen, wo kein Leerzeichen steht.
     *
     * **Der Anlass ist die Meldung, die Kriterium 5 belegt.** Sie trägt den
     * Pfad des Dumps — hundert Zeichen ohne ein einziges Leerzeichen —, und bei
     * 390px schob sie die Vorgangsseite um **110px** aus dem Bild. Gemessen am
     * 11. August 2026, unmittelbar nachdem diese Meldung überhaupt erst ankam:
     * Erst hatte sie keinen Weg ins Panel, dann keinen Platz darin.
     *
     * `anywhere` und nicht `break-word`, aus demselben Grund wie bei `.ident`:
     * Nur `anywhere` verkleinert auch die min-content-Breite, und die hält ein
     * Flex-Kind sonst auf seiner Inhaltsbreite fest.
     *
     * > **Was in einer Meldung steht, kommt von aussen.** Vom Agenten, vom
     * > Betriebssystem, von einem fremden Anbieter — und keine dieser Quellen
     * > kennt die Breite eines Telefons.
     */
    public function test_a_notice_may_break_where_there_is_no_space(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        $start = strpos($css, '.notice {');

        $this->assertNotFalse($start, 'Die Regel .notice gibt es nicht mehr.');

        $block = substr($css, $start, (int) (strpos($css, '}', $start) - $start));

        $this->assertStringContainsString('overflow-wrap: anywhere', $block, sprintf(
            "Die Regel .notice erlaubt keinen Umbruch mehr.\n\n"
            .'Eine Meldung trägt, was der Agent oder das System sagt — Pfade, Kennungen, '
            .'Kommandozeilen. Ohne Umbrucherlaubnis steht so eine Zeichenkette in einer Flexbox '
            ."und schiebt die Seite waagerecht aus dem Bild; bei 390px waren es 110px.\n\n"
            .'Gefunden hat sich der aktuelle Block so: %s',
            mb_substr(preg_replace('~\s+~', ' ', $block) ?? '', 0, 80),
        ));
    }
}
