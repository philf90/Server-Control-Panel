<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\CronState;
use Tests\Support\WithoutPhpComments;

/**
 * Die Naht der Zeitplanseite — A6, `docs/111 §5`.
 *
 * ## Zwei Regeln, und beide sind an einer Naht zwischen zwei Dateien
 *
 * **Die Seite trägt die Fähigkeit ihrer Route.** `/schedules` steht unter
 * `can:operate-server` (`docs/111 §2`, Frage 1); ein Menüpunkt mit einer
 * anderen Fähigkeit zeigte dem Administrator einen Eintrag, der ihm einen 403
 * gibt — genau der Zustand, gegen den es `AbilityReachTest` gibt, nur eine
 * Ebene früher.
 *
 * **Und die Dateien des Panels kommen von dort, wo sie geschrieben werden.**
 * `srvpanel-` steht in {@see CronFile}, und `LocalHost::cronFiles()` holt es
 * schon von dort. Ein drittes `srvpanel-` im Leser wäre dieselbe Regel an drei
 * Orten.
 *
 * > **Ein Fehler an einer Naht zwischen zwei Dateien: Jede Seite für sich ist
 * > in Ordnung, und kein Wächter steht dazwischen.**
 *
 * Der Plan (`docs/111 §3.1`) nannte dafür `Diagnose\Host::cronFiles()`. Das ist
 * die Stelle im **Panel** — der Agent kann sie nicht fragen, und sie selbst
 * fragt {@see CronFile}. Genommen wird deshalb die Quelle und nicht ihr
 * Verbraucher.
 */
final class CronPayloadTest extends TestCase
{
    use WithoutPhpComments;

    private const ABILITY = 'operate-server';

    /**
     * Route und Menüpunkt tragen dieselbe Fähigkeit.
     */
    public function test_the_menu_entry_carries_the_ability_of_its_route(): void
    {
        $routen = $this->quelle('routes/web.php');

        $this->assertMatchesRegularExpression(
            "/'\/schedules'.*?->middleware\('can:".self::ABILITY."'\)/s",
            $routen,
            'Die Route /schedules steht nicht unter '.self::ABILITY.'.',
        );

        $this->assertStringContainsString(
            "href: '/schedules', icon: 'schedules', ability: '".self::ABILITY."'",
            file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue') ?: '',
            'Der Menüpunkt trägt eine andere Fähigkeit als seine Route.',
        );
    }

    /**
     * Und die Route schreibt nichts.
     *
     * A6 ist eine Leseansicht (`docs/111 §8`). Ein `POST` auf `/schedules`
     * entstünde beim nächsten Merkmal, das „nur schnell" etwas ändern will —
     * und die Begründung dafür, dass es keinen Editor gibt, stünde dann in
     * einem Dokument neben einer Route, die einen hat.
     */
    public function test_the_route_only_reads(): void
    {
        $treffer = [];

        preg_match_all("/Route::(\w+)\('\/schedules/", $this->quelle('routes/web.php'), $treffer);

        $this->assertSame(['get'], array_values(array_unique($treffer[1])), 'Auf /schedules steht mehr als ein GET.');
    }

    /**
     * Die eigenen Dateien werden an der einen Stelle erkannt.
     */
    public function test_the_own_files_come_from_where_they_are_written(): void
    {
        $zustand = CronState::read([
            CronFile::DIR.'/'.CronFile::PREFIX.'p1139' => "15 3 * * *\tp1139\t/usr/lib/srvpanel/cron-run 7\n",
            CronFile::DIR.'/fremd' => "15 3 * * *\troot\t/usr/bin/true\n",
        ], [], false);

        $eigen = array_column($zustand['tables'], 'owned', 'path');

        $this->assertTrue($eigen[CronFile::DIR.'/'.CronFile::PREFIX.'p1139']);
        $this->assertFalse($eigen[CronFile::DIR.'/fremd']);
    }

    /**
     * Und der Leser trägt das Präfix nicht ein zweites Mal.
     *
     * **Das ist die Richtung, an der eine zweite Fassung wirklich entsteht.**
     * Der Fall oben bliebe grün, wenn im Leser `'srvpanel-'` wörtlich stünde —
     * bis jemand das Präfix in {@see CronFile} ändert und nur eine der beiden
     * Stellen mitnimmt.
     */
    public function test_the_reader_carries_no_second_prefix(): void
    {
        foreach (['agent/src/CronState.php', 'app/Support/Diagnose/LocalHost.php'] as $pfad) {
            $this->assertStringNotContainsString(
                "'".CronFile::PREFIX."'",
                $this->quelle($pfad),
                sprintf('%s trägt das Präfix wörtlich statt es von CronFile zu holen.', $pfad),
            );

            $this->assertStringContainsString(
                'CronFile::PREFIX',
                $this->quelle($pfad),
                sprintf('%s holt das Präfix nicht von CronFile.', $pfad),
            );
        }
    }

    /**
     * Der Inhalt der eigenen Dateien steht nicht auf dieser Seite.
     *
     * `docs/111 §2` Frage 2: Sie haben ihre Seite. Zweimal dieselbe Sache
     * anzuzeigen ist keine doppelte Auskunft — und die zweite Anzeige ist die,
     * die veraltet.
     */
    public function test_an_own_file_is_shown_as_one_line_with_a_link(): void
    {
        $seite = $this->seite();

        $this->assertStringContainsString("kind: 'owned'", $seite, 'Die Seite kennt die eigenen Dateien nicht als eigenen Fall.');
        $this->assertStringContainsString('href="/cron"', $seite, 'Von einer eigenen Datei führt kein Weg auf die Cronseite.');
    }

    /**
     * Der Bereich „Übergangen" steht nur da, wenn es etwas zu zeigen gibt.
     *
     * Leer wäre er eine Beruhigung, die niemand bestellt hat — und ein Bereich,
     * der immer dasteht, wird von dem überlesen, für den es ihn gibt.
     */
    public function test_the_ignored_section_is_conditional(): void
    {
        $this->assertMatchesRegularExpression(
            '/<Section\s+v-if="uebergangen\.length > 0"/',
            $this->seite(),
            'Der Bereich „Übergangen" steht auch dann da, wenn nichts übergangen wurde.',
        );
    }

    /**
     * Das Kommando wird nicht gekürzt und bricht statt zu rollen.
     *
     * `docs/111 §2` Frage 3, und die Zahl dahinter steht in `docs/46 §20.13`:
     * Eine Textzelle ohne Umbruch hat den Inhalt einer Tabelle **5710 px** breit
     * gemacht statt 1907 — bei 390 px zehn Bildschirme Rollen durch eine
     * einzige Zelle, und die Überlaufmessung sieht davon nichts.
     */
    public function test_the_command_wraps_and_is_not_cut(): void
    {
        $seite = $this->seite();

        $this->assertStringContainsString('class="cell-command"', $seite, 'Das Kommando steht in keiner brechenden Zelle.');

        $this->assertStringNotContainsString(
            'slice(',
            $seite,
            'Auf der Seite wird gekürzt — ein gekürztes Kommando ist die Auskunft, die man gerade nicht brauchen kann.',
        );

        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css') ?: '';

        $this->assertMatchesRegularExpression(
            '/\.cell-command\s*\{[^}]*overflow-wrap:\s*anywhere/s',
            $css,
            'Die Zelle des Kommandos bricht nicht.',
        );

        /*
         * **Und sie hat eine Obergrenze — sonst nützt das Brechen nichts.**
         * Gemessen am 8. September 2026 bei 1440 px gegen den echten Bestand:
         * ohne Grenze ist die Zelle 1396 px breit und die Tabelle läuft 736 px
         * über ihren Bereich, während der Überlauf am Dokument 0 bleibt.
         *
         * > **`overflow-wrap` sagt, wo gebrochen werden darf, und nicht
         * > wann.**
         */
        $this->assertMatchesRegularExpression(
            '/\.cell-command\s*\{[^}]*max-width:/s',
            $css,
            'Die Zelle des Kommandos hat keine Obergrenze — dann wächst die Tabelle statt zu brechen.',
        );

        /*
         * **Die Grenze steht auf einem Element in der Zelle und nicht auf ihr.**
         * `max-width` gilt für eine Tabellenzelle laut CSS 2.1 nicht; dass
         * dieses Chromium sie dort beachtet, ist gemessen und trotzdem keine
         * Zusage.
         */
        $this->assertStringContainsString(
            '<div class="cell-command">',
            $seite,
            'Die Grenze hängt an der Zelle statt an einem Element darin.',
        );
    }

    /**
     * Ohne diese Zahlen wären die Behauptungen oben auch dann grün, wenn keine
     * der gelesenen Dateien Inhalt hätte.
     */
    public function test_the_sources_have_something_in_them(): void
    {
        $this->assertGreaterThan(1000, strlen($this->seite()), 'Die Seite ist fast leer — dann prüft dieser Wächter nichts.');
        $this->assertStringContainsString("'/schedules'", $this->quelle('routes/web.php'));
    }

    private function seite(): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Schedules/Index.vue');

        $this->assertIsString($inhalt, 'Die Seite Schedules/Index.vue gibt es nicht.');

        return $inhalt;
    }

    private function quelle(string $pfad): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        $this->assertIsString($inhalt, sprintf('%s ist nicht lesbar.', $pfad));

        // Ohne die Kommentare: Jede Behebung in diesem Repo hält ihren
        // Vorzustand dort fest, und ein zitiertes `'srvpanel-'` stellte die
        // entfernte Zeile für diesen Wächter wieder her.
        return $this->withoutComments($inhalt);
    }
}
