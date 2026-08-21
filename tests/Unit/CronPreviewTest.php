<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Umrechnung während des Tippens — Wunsch 4 des Betreibers.
 *
 * ## Was bestellt war
 *
 * > Bitte eine Live-Umrechnung einbauen und anzeigen, die sofort anzeigt, in
 * > welchem Rhythmus der anzulegende Job läuft. Keine zusätzliche Eingabe, nur
 * > eine Anzeige. Die reine Cron-Schreibweise als Darstellung kann für
 * > unerfahrene Nutzer mehr Hindernis als Hilfsmittel sein.
 *
 * ## Die Bedingung, unter der es das gibt
 *
 * `Cron.vue` übersetzt **mit Absicht** nicht selbst, und
 * `CronScheduleFormTest::test_the_page_does_not_translate_on_its_own` hält das.
 * Den Satz baut `Spoken` auf dem Server, die Fälligkeiten `Occurrence`; ihn im
 * Browser nachzubauen hiesse, dieselbe Regel in zwei Sprachen zu pflegen.
 *
 * > **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**
 *
 * Deshalb **fragt** die Seite, statt zu rechnen — und dieser Wächter hält die
 * vier Eigenschaften fest, ohne die aus dieser Anfrage etwas anderes wird:
 * Sie ändert nichts, sie ist keine Eingabe, sie fragt nicht bei jedem
 * Tastendruck, und eine überholte Antwort gewinnt nicht.
 */
final class CronPreviewTest extends TestCase
{
    private const PAGE = 'resources/js/Pages/Subscriptions/Cron.vue';

    private const CONTROLLER = 'app/Http/Controllers/CronController.php';

    /**
     * Die Vorschau rechnet und ändert nichts.
     *
     * **Sie ist kein Freibrief, nur weil sie nichts ändert.** Sie trägt
     * dieselbe Fähigkeit wie die Seite, auf der getippt wird — sonst wäre sie
     * ein zweiter Weg an der Policy vorbei. Dass sie an einem fremden Zeitplan
     * heute nichts preisgäbe, ist eine Eigenschaft von heute und keine Zusage.
     *
     * **`POST`, obwohl sie nichts ändert.** Das ist die Bauform aller
     * JSON-Griffe dieses Panels: Ein Wert des Kunden landet nie in einer
     * Adresse — dort stünde er im Zugriffsprotokoll des Webservers, in der
     * Verlaufsliste des Browsers und in jedem `Referer`. Der erste Wurf war ein
     * `GET`, und `PanelRequestTest` hat ihn überführt.
     */
    public function test_the_preview_route_computes_and_changes_nothing(): void
    {
        $routen = $this->read('routes/web.php');

        $this->assertMatchesRegularExpression(
            "/Route::post\('\/subscriptions\/\{subscription\}\/cron\/preview',".
            "\s*\[CronController::class, 'preview'\]\)\s*\n\s*->middleware\('can:manageCron,subscription'\)/",
            $routen,
            'Die Vorschau ist keine POST-Route mehr oder trägt nicht mehr `can:manageCron`. Als '.
            '`GET` stünde der Zeitplan des Kunden in der Adresse; ohne die Klammer wäre sie ein '.
            'zweiter Weg an der Policy vorbei.',
        );

        $rumpf = $this->methodBody('preview');

        $this->assertNotSame('', $rumpf, 'Die Methode `preview` ist nicht mehr zu finden — dann prüft dieser Wächter nichts.');

        foreach (['audit->', '->save(', 'Operation', '$this->cron->'] as $spur) {
            $this->assertStringNotContainsString($spur, $rumpf, sprintf(
                '`preview` enthält „%s'.
                "\".\n\nSie soll rechnen und sonst nichts: kein Agent, kein Vorgang, keine Zeile ".
                'im Protokoll. Ein Eintrag je Tastendruck wäre eine Datenhaltung über die Bedienung.',
                $spur,
            ));
        }
    }

    /**
     * Die Zeitpunkte gehen durch die Anzeigezone.
     *
     * Der Zeitplan gilt in Serverzeit, die Anzeige in der Zone des Lesers —
     * genau der Unterschied, den der Kasten oben auf der Seite erklärt. Wer ihn
     * hier vergisst, zeigt zwei Wahrheiten auf derselben Seite, und die eine
     * ist nicht beschriftet.
     */
    public function test_the_times_go_through_the_display_zone(): void
    {
        $this->assertStringContainsString(
            'Clock::display(',
            $this->methodBody('preview'),
            'Die Vorschau rechnet ihre Zeitpunkte nicht in die Anzeigezone. Dann stehen auf einer '.
            'Seite zwei Zeiten, von denen nur eine beschriftet ist (docs/40).',
        );
    }

    /**
     * Die Anzeige ist keine Eingabe.
     *
     * Was der Betreiber ausdrücklich **nicht** bestellt hat: „Keine zusätzliche
     * Eingabe, nur eine Anzeige." Ein Feld an dieser Stelle wäre ein zweiter
     * Speicherort für denselben Zeitplan — und der zweite ist der, der
     * veraltet.
     */
    public function test_the_preview_is_a_display_and_not_an_input(): void
    {
        foreach ($this->previewBlocks() as $block) {
            foreach (['v-model', '<input', '<textarea', '<select'] as $griff) {
                $this->assertStringNotContainsString($griff, $block, sprintf(
                    'Die Vorschau enthält „%s" und ist damit ein Griff. Bestellt war eine Anzeige.',
                    $griff,
                ));
            }
        }
    }

    /**
     * Der Satz kommt vom Server und nicht aus der Seite.
     *
     * Geprüft wird die Herkunft und nicht der Wortlaut: Was auf der Seite steht,
     * muss aus `vorschau` kommen. Eine Übersetzung im Browser fiele
     * `CronScheduleFormTest` erst dann auf, wenn sie zufällig eines der drei
     * Wörter benutzt, die er kennt.
     */
    public function test_the_sentence_comes_from_the_server(): void
    {
        $bloecke = $this->previewBlocks();

        $this->assertGreaterThanOrEqual(1, count($bloecke), sprintf(
            'In %s ist die Vorschau nicht mehr zu finden — dann prüft dieser Wächter nichts.',
            self::PAGE,
        ));

        $this->assertStringContainsString(
            'vorschau.spoken',
            implode("\n", $bloecke),
            'Die Vorschau zeigt den Satz nicht mehr aus `vorschau`. Baut die Seite ihn selbst, '.
            'gibt es die Übersetzungsregel zweimal — und die zweite weicht ab.',
        );
    }

    /**
     * Es wird entprellt, und die Zahl dazu ist ablesbar.
     *
     * **Warum der Zähler mitgeprüft wird.** `docs/48` hat einmal zwanzig
     * Konsolenöffnungen gebraucht, bis eine Entprellung überhaupt als solche
     * belegt war — weil daneben keine Zahl stand, die etwas anderes als Null
     * hätte sein können.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_preview_is_debounced_and_countable(): void
    {
        $quelle = $this->read(self::PAGE);

        $this->assertMatchesRegularExpression(
            '/const VORSCHAU_PAUSE = ([1-9]\d*)/',
            $quelle,
            'Die Pause vor einer Anfrage steht nicht mehr als Zahl in der Seite — oder sie ist 0. '.
            'Dann fragt jeder Tastendruck.',
        );

        $this->assertMatchesRegularExpression(
            '/clearTimeout\(vorschauTimer\)\s*\n\s*vorschauTimer = setTimeout\(/',
            $quelle,
            'Vor dem neuen Zeitgeber wird der alte nicht mehr abgeräumt. Dann sammeln sich die '.
            'Anfragen, statt sich abzulösen — und die Entprellung ist keine.',
        );

        $this->assertStringContainsString(
            'vorschauAnfragen.value++',
            $quelle,
            'Die Anfragen werden nicht mehr gezählt. Dann lässt sich die Entprellung nur '.
            'behaupten und nicht messen.',
        );
    }

    /**
     * Eine überholte Antwort gewinnt nicht.
     *
     * Zwei Anfragen können sich überholen. Ohne diese Zählung stünde das
     * Ergebnis zu einem Zeitplan da, den niemand mehr eingetippt hat — und zwar
     * still, weil beide Antworten für sich richtig sind.
     *
     * > **Zwei Antworten, die beide stimmen, ergeben zusammen eine falsche
     * > Anzeige, wenn die Reihenfolge fehlt.**
     */
    public function test_a_late_answer_cannot_overwrite_a_newer_one(): void
    {
        $quelle = $this->read(self::PAGE);

        $this->assertStringContainsString(
            'const lauf = ++vorschauLauf',
            $quelle,
            'Die Anfragen tragen keine Nummer mehr. Dann kann eine frühere Antwort eine spätere '.
            'überschreiben.',
        );

        $this->assertSame(
            2,
            substr_count($quelle, 'lauf === vorschauLauf'),
            'Die Nummer wird nicht mehr an beiden Ausgängen geprüft — im Erfolgs- und im '.
            'Fehlerfall. Fehlt sie an einem, gewinnt dort die überholte Antwort.',
        );
    }

    /**
     * Die Vorschau nimmt den einen HTTP-Weg dieses Panels.
     *
     * **Der erste Wurf baute sich den Aufruf selbst** — genau der Fall, vor dem
     * `usePanelRequest.ts` in seinem eigenen Kopf warnt. `PanelRequestTest` hat
     * ihn überführt, und zwar erst beim Durchlauf aller Wächter.
     *
     * > **Ein Mechanismus, den zwei Stellen selbst bauen, hat zwei Fassungen —
     * > und die zweite ist die, die eine der drei Kopfzeilen vergisst.**
     */
    public function test_the_preview_uses_the_one_http_path(): void
    {
        $quelle = $this->read(self::PAGE);

        $this->assertStringContainsString(
            "from '../../Composables/usePanelRequest'",
            $quelle,
            'Die Seite holt die Vorschau nicht mehr über `usePanelRequest`. Dann baut sie sich '.
            'den Aufruf selbst — mit ihrer eigenen Fassung der drei Kopfzeilen.',
        );

        $this->assertStringNotContainsString(
            'fetch(',
            $quelle,
            'Die Seite ruft `fetch` selbst auf. Es soll genau eine Stelle im Panel geben, die das '.
            'tut.',
        );
    }

    /**
     * Die beiden Absätze der Vorschau aus dem Template.
     *
     * @return list<string>
     */
    private function previewBlocks(): array
    {
        preg_match_all(
            '/<p v-if="vorschau\.[^"]+"[^>]*>(.*?)<\/p>/s',
            $this->read(self::PAGE),
            $treffer,
        );

        return $treffer[0];
    }

    /** Der Rumpf einer Methode der Steuerung, von `{` bis zur passenden `}`. */
    private function methodBody(string $methode): string
    {
        $quelle = $this->read(self::CONTROLLER);
        $muster = '/function\s+'.preg_quote($methode, '/').'\s*\(/';

        if (preg_match($muster, $quelle, $t, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $auf = strpos($quelle, '{', (int) $t[0][1]);

        if ($auf === false) {
            return '';
        }

        $tiefe = 0;

        for ($i = $auf, $n = strlen($quelle); $i < $n; $i++) {
            if ($quelle[$i] === '{') {
                $tiefe++;
            } elseif ($quelle[$i] === '}') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($quelle, $auf, $i - $auf + 1);
                }
            }
        }

        return '';
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
