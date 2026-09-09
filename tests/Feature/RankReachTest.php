<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadsAnnouncementRanks;

/**
 * Jeder Rang einer Ankündigung hat seine drei Regeln, und jede Regel hat ihren
 * Rang.
 *
 * **Warum es diesen Wächter gibt und nicht `ClassReachTest` genügt.** Der
 * Rückgabewert von `AnnouncementCategory::badge()` fährt in drei Bausteine —
 * `Bands.vue` setzt ihn auf `.band`, `Announcements/Index.vue` auf `.badge`,
 * `Announcements/Show.vue` auf `.notice`. Alle drei schreiben ihn als
 * **Ausdruck** (`:class="hinweis.badge"`) und nicht als Objektschlüssel, und
 * `ClassReachTest` führt dafür keine Ausnahmeliste: Er kann den Rang aus dem
 * Ausdruck nicht ableiten.
 *
 * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
 * > gemessen — er hat an dieser Stelle gar nicht gemessen.**
 *
 * Der Plan zu diesem Merkmal (`docs/900 §7`) hat genau das zuerst falsch
 * behauptet: `ClassReachTest` greife „von selbst, vorausgesetzt, die Klasse
 * steht als Objektschlüssel". Die Voraussetzung ist an allen drei Stellen
 * nicht erfüllt. Dieser Wächter ist deshalb keine Redundanz, sondern die
 * einzige Deckung.
 *
 * **Beide Richtungen, und die zweite ist die, an der ein toter Eintrag
 * entsteht.** Ein fehlender Rang ist eine Marke ohne Farbe, sichtbar erst im
 * Browser und nur für den, der genau diesen Zustand vor sich hat. Eine Regel
 * ohne Rang entsteht bei einer Umbenennung: Man trägt den neuen Namen nach,
 * die erste Richtung ist wieder grün, und der alte bleibt liegen.
 *
 * **Gelesen wird als Text und nicht über den Autolader**, damit der Wächter
 * ohne Framework läuft — dieselbe Entscheidung wie bei `OperationOriginTest`
 * über `routes/web.php`.
 */
final class RankReachTest extends TestCase
{
    use ReadsAnnouncementRanks;

    /** Die drei Bausteine, in die der Wert aus `badge()` fährt. */
    private const BAUSTEINE = ['band', 'badge', 'notice'];

    public function test_every_rank_has_a_rule_in_every_component(): void
    {
        $raenge = $this->announcementRanks();
        $css = $this->css();

        $this->assertGreaterThan(
            2,
            count($raenge),
            'Aus AnnouncementCategory::badge() kommen kaum Ränge — dann prüft dieser Test nichts.',
        );

        foreach ($raenge as $rang) {
            foreach (self::BAUSTEINE as $baustein) {
                $this->assertMatchesRule(
                    $css,
                    $baustein,
                    $rang,
                    sprintf(
                        'AnnouncementCategory::badge() gibt den Rang „%s" zurück, und app.css hat keine Regel `.%s.%s`.'."\n".
                        'Der Wert fährt in drei Bausteine; ohne die Regel ist es dort eine Marke ohne Farbe — '.
                        'sichtbar erst im Browser und nur für den, der genau diesen Zustand vor sich hat.',
                        $rang,
                        $baustein,
                        $rang,
                    ),
                );
            }
        }
    }

    /**
     * Die Gegenrichtung — und sie gilt **nur für `.band`**, weil das der
     * einzige der drei Bausteine ist, dessen Klassen sich vollständig
     * aufzählen lassen: Sie kommen aus `Bands.vue` (dem Rückgabewert von
     * `badge()`) und aus dem einen wörtlichen `class="band warn"` des
     * Sichtwechsels in `PanelLayout.vue`. Sonst niemand.
     *
     * **`.badge` und `.notice` sind nicht so zu prüfen, und der erste Wurf
     * dieses Wächters hat das übersehen.** Er meldete `.badge.ok` und
     * `.notice.ok` als tote Einträge, weil `AnnouncementCategory` „ok" nicht
     * mehr zurückgibt — dabei bedienen `CronRunStatus`, `DnsHealth`,
     * `DnsRecordState` und `FindingState` dieselben Regeln, und
     * `PanelLayout.vue` schreibt `class="notice ok"` wörtlich hin.
     *
     * > **Eine Regel, die mehrere Erzeuger hat, ist nicht tot, weil einer von
     * > ihnen sie nicht mehr braucht.**
     *
     * Gefunden hat der Wächter dabei trotzdem etwas Echtes: `.band.ok` hatte
     * nach dem Umbau wirklich keinen Erzeuger mehr und ist fort.
     */
    public function test_every_band_rule_belongs_to_a_rank(): void
    {
        $erzeugt = array_unique(array_merge($this->announcementRanks(), $this->woertlicheBandKlassen()));
        $css = $this->css();

        preg_match_all('/^\.band\.([a-z-]+)\s*\{/m', $css, $treffer);

        $this->assertGreaterThan(
            1,
            count($treffer[1]),
            'Es werden kaum `.band`-Regeln gefunden — dann prüft dieser Test nichts.',
        );

        foreach ($treffer[1] as $rang) {
            $this->assertContains(
                $rang,
                $erzeugt,
                sprintf(
                    'app.css hat eine Regel `.band.%s`, und niemand erzeugt diese Klasse.'."\n".
                    'Erzeuger sind AnnouncementCategory::badge() über Bands.vue und die wörtlichen '.
                    '`class="band …"` in den Vorlagen. So entsteht ein toter Eintrag wirklich: Bei einer '.
                    'Umbenennung trägt man den neuen Namen nach, die andere Richtung ist wieder grün, '.
                    'und der alte bleibt liegen.',
                    $rang,
                ),
            );
        }
    }

    /**
     * Die Ränge, die eine Vorlage wörtlich neben `band` schreibt — heute genau
     * einer, der Sichtwechsel in `PanelLayout.vue`.
     *
     * @return list<string>
     */
    private function woertlicheBandKlassen(): array
    {
        $gefunden = [];

        foreach ($this->vueDateien() as $pfad) {
            $quelle = (string) file_get_contents($pfad);

            preg_match_all('/class="band ([a-z-]+)"/', $quelle, $treffer);

            foreach ($treffer[1] as $rang) {
                $gefunden[] = $rang;
            }
        }

        return $gefunden;
    }

    /** @return list<string> */
    private function vueDateien(): array
    {
        $dateien = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 2).'/resources/js',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    private function css(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/app.css',
        );
    }

    /**
     * Gesucht wird der Selektor am **Anfang einer Zeile** und mit seiner
     * öffnenden Klammer — nicht als Zeichenkette irgendwo in der Datei. Sonst
     * stellte ein Kommentar, der die entfernte Regel zitiert, sie für diesen
     * Wächter wieder her, und genau das hält dieses Repo in jedem Kommentar
     * fest.
     */
    private function assertMatchesRule(string $css, string $baustein, string $rang, string $meldung): void
    {
        $this->assertSame(
            1,
            preg_match('/^\.'.preg_quote($baustein, '/').'\.'.preg_quote($rang, '/').'\s*\{/m', $css),
            $meldung,
        );
    }
}
