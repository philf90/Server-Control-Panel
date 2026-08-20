<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Weg zur Zeitsteuerung — führt er irgendwohin?
 *
 * **Die Fehlerklasse, gegen die dieses Projekt die meisten Wächter hat:** eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft. Eine Policy ohne Route. Ein Kommando, das im
 * Startskript fehlt. Und hier: ein Menüpunkt, der auf eine Adresse zeigt, die es
 * nicht gibt — oder eine Seite, die niemand verlinkt.
 *
 * `LinkReachTest` und `NavIconTest` decken Teile davon für alle Merkmale ab.
 * Dieser Wächter prüft, was sie nicht wissen können: dass die drei Seiten
 * dieses Merkmals **untereinander** zusammenpassen, und dass der Weg hinein
 * derselbe ist wie bei den beiden Merkmalen davor.
 *
 * ## Warum der Menüpunkt hier noch einmal geprüft wird
 *
 * Er ist zweimal gefehlt — beim Dateimanager (`docs/55` Befund 8) und bei SFTP
 * (`docs/59` Befund 19), beide Male gemeldet vom Betreiber während eines
 * Abnahmelaufs, und beide Male fand ihn kein einziger der 136 Wächter.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
 * > wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * Hier wird sie zur Regel: Der Punkt heisst `/cron`, **ohne Abo-Kennung** — und
 * das ist die eigentliche Aussage. Eine Adresse mit Kennung darin liesse sich
 * aus dem Menü gar nicht bilden, und genau deshalb lag das Merkmal beide Male
 * drei Klicks tief.
 */
final class CronPageReachTest extends TestCase
{
    /** Die drei Seiten dieses Merkmals. */
    private const PAGES = [
        'Subscriptions/Cron',
        'Subscriptions/CronPick',
        'Subscriptions/CronRuns',
    ];

    /** Jede Seite, die der Controller rendert, gibt es auch. */
    public function test_every_rendered_page_exists(): void
    {
        $controller = $this->read('app/Http/Controllers/CronController.php');

        preg_match_all("/Inertia::render\('([^']+)'/", $controller, $treffer);

        $gerendert = array_unique($treffer[1]);

        $this->assertNotEmpty($gerendert, 'Der Controller rendert keine einzige Seite — dann prüft dieser Wächter nichts.');

        foreach ($gerendert as $seite) {
            $this->assertFileExists(
                dirname(__DIR__, 2)."/resources/js/Pages/{$seite}.vue",
                sprintf('CronController rendert „%s", und diese Seite gibt es nicht.', $seite),
            );
        }

        // Und die Gegenrichtung: Keine der drei liegt ungenutzt herum.
        foreach (self::PAGES as $seite) {
            $this->assertContains(
                $seite,
                $gerendert,
                sprintf('Die Seite „%s" liegt da, und niemand rendert sie.', $seite),
            );
        }
    }

    /**
     * Der Menüpunkt zeigt auf `/cron`, und die Route gibt es.
     *
     * **Ohne Abo-Kennung**, und das ist der Kern: Wäre dort
     * `/subscriptions/…/cron`, liesse sich der Punkt aus dem Menü nicht bilden —
     * dort ist kein Abonnement bekannt.
     */
    public function test_the_menu_leads_to_a_route_that_exists(): void
    {
        $layout = $this->read('resources/js/Layouts/PanelLayout.vue');
        $routes = $this->read('routes/web.php');

        $this->assertStringContainsString(
            "href: '/cron'",
            $layout,
            'Im Menü steht kein Punkt für die Cronjobs — er hat beim Dateimanager und bei SFTP je einen Abnahmelauf gekostet.',
        );

        $this->assertStringNotContainsString(
            "href: '/subscriptions/",
            $layout,
            'Ein Menüpunkt mit Abo-Kennung lässt sich im Menü nicht bilden — dort ist kein Abonnement bekannt.',
        );

        $this->assertStringContainsString(
            "Route::get('/cron'",
            $routes,
            'Der Menüpunkt zeigt auf /cron, und diese Route gibt es nicht.',
        );
    }

    /**
     * Jede Route dieses Merkmals trägt `can:` — ausser der Auswahlseite.
     *
     * **Die Auswahl trägt keines, und das ist kein Versehen.** Sie filtert
     * selbst über die Policy und zeigt nur, was der Betrachter darf; ein
     * `can:manageCron` an ihr bräuchte ein Abonnement, das sie gerade erst
     * sucht. Dieselbe Bauart wie bei `/files` und `/sftp`.
     */
    public function test_every_cron_route_is_guarded(): void
    {
        $routes = $this->read('routes/web.php');

        preg_match_all(
            "/Route::(get|post|put|delete)\('([^']*cron[^']*)'.*?->name\('([^']+)'\)/s",
            $routes,
            $treffer,
            PREG_SET_ORDER,
        );

        $this->assertGreaterThanOrEqual(
            5,
            count($treffer),
            'Es werden kaum Cron-Routen gefunden — dann prüft dieser Wächter nichts.',
        );

        foreach ($treffer as $route) {
            [$ganz, , $pfad, $name] = $route;

            if ($name === 'cron.pick') {
                $this->assertStringNotContainsString('can:', $ganz,
                    'Die Auswahlseite kann kein can: tragen — sie sucht das Abonnement gerade erst.');

                continue;
            }

            $this->assertStringContainsString('can:manageCron,subscription', $ganz, sprintf(
                'Die Route „%s" (%s) trägt kein can:manageCron.',
                $name,
                $pfad,
            ));
        }
    }

    /** Der Menüpunkt trägt ein Zeichen, und das Zeichen ist gezeichnet. */
    /**
     * Die Jobliste trägt einen Griff zum Formular.
     *
     * ## Warum das ein eigener Fall ist
     *
     * Der Bereich „Job anlegen" ist der dritte von drei Bereichen; dazwischen
     * liegt die Jobliste mit bis zu zehn Kärtchen. Bei 390 px war er damit nur
     * durch Rollen zu erreichen, und nichts sagte einem, dass dort etwas ist
     * (`docs/64`, Befund 13).
     *
     * **Das ist der dritte Fall derselben Art** — nach dem Menüpunkt des
     * Dateimanagers (`docs/55`, Befund 8) und dem des SFTP-Zugangs
     * (`docs/59`, Befund 19).
     *
     * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
     * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
     *
     * ## Und warum hier trotzdem eine Stelle steht und keine Regel
     *
     * **Weil es keine gibt, die ein Test halten könnte.** „Erreichbar" heisst:
     * Jemand, der eine Handlung sucht, findet sie dort, wo er sucht. Das hängt
     * daran, was ein Kunde erwartet, und keine Eigenschaft des Quelltextes
     * bildet es ab. Gemeldet haben alle drei Fälle Menschen und kein Wächter.
     *
     * Dieser Fall hält deshalb nur fest, **dass der Griff nicht wieder
     * verschwindet**. Die Regel selbst steht in `CLAUDE.md` als Frage, die vor
     * jedem neuen Merkmal gestellt wird.
     *
     * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
     * > nicht als Zusage.**
     */
    public function test_the_job_list_leads_to_the_form(): void
    {
        $seite = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Subscriptions/Cron.vue',
        );

        $this->assertMatchesRegularExpression(
            '/<div class="section-head">.*?<h2>Jobs<\/h2>.*?@click="zumFormular".*?<\/div>/s',
            $seite,
            'Die Kopfzeile der Jobliste trägt keinen Griff zum Formular mehr.

'.
            'Der Bereich „Job anlegen" steht unter der Liste; bei 390 px liegen bis zu zehn '.
            'Kärtchen dazwischen, und ohne Griff findet ihn niemand (docs/64, Befund 13).

'.
            'Er gehört in die Kopfzeile der Liste — dorthin, wo man nach „noch einem" sucht, '.
            'und nicht dorthin, wo man ihn schliesslich findet.',
        );

        $this->assertStringContainsString(
            'bringIntoView(formBlock.value)',
            $seite,
            'Der Griff führt zum Formular, holt es aber nicht ins Bild — bei 390 px ist das '.
            'dasselbe, als gäbe es ihn nicht.',
        );
    }

    /**
     * Die Auskunft über das volle Kontingent steht vor der Liste.
     *
     * ## Der Fund
     *
     * Sie stand im Bereich „Job anlegen", also unmittelbar über dem Formular,
     * auf das sie sich bezieht — bei 1440 px genau richtig. Bei 390 px stapeln
     * sich die drei Bereiche, und die Jobliste dazwischen ist zehn Kärtchen
     * hoch. Gemessen im Container, mit zehn Jobs:
     *
     *   im Formularbereich   3566 px von der Oberkante, Seite 3795 px
     *   vor den Bereichen      18 px
     *
     * Vier Bildschirme weit unten heisst: Wer wissen will, warum er keinen Job
     * anlegen kann, erfährt es an einer Stelle, die nur erreicht, wer ohnehin
     * schon rollt (`docs/64`, Befund 12; gemeldet vom Betreiber).
     *
     * > **Eine Auskunft, die erklärt, warum etwas nicht geht, gehört dorthin,
     * > wo man es versucht — nicht dorthin, wo es scheitert.**
     *
     * ## Was dieser Fall hält und was nicht
     *
     * Er hält den **Ort**: vor `.sections` und damit vor der Liste. Dass die
     * Meldung ihren Bezug behält, hält er nicht — das steht im Satz selbst
     * („um einen neuen anzulegen") und ist eine Frage an einen Leser.
     */
    public function test_the_full_quota_is_said_before_the_list(): void
    {
        $seite = $this->read('resources/js/Pages/Subscriptions/Cron.vue');

        $stelle = strpos($seite, 'voll && bearbeitet === null');
        $sections = strpos($seite, '<div class="sections">');

        $this->assertNotFalse($stelle, 'Die Meldung über das volle Kontingent gibt es nicht mehr.');
        $this->assertNotFalse($sections, 'Die Seite hat keinen Bereichsblock mehr.');

        $this->assertLessThan(
            $sections,
            $stelle,
            "Die Meldung über das volle Kontingent steht wieder innerhalb der Bereiche.\n\n".
            'Bei 390 px liegt sie damit hinter der ganzen Jobliste — gemessen 3566 px von der '.
            'Oberkante auf einer Seite von 3795 px (docs/64, Befund 12). Sie gehört vor '.
            '`.sections`.',
        );
    }

    /**
     * Die Schnellwahl gehört zum Zeitplan und steht nicht daneben.
     *
     * ## Der Fund
     *
     * Bei 1440 px lagen vier Gruppen als 2×2 nebeneinander. Die Schnellwahl ist
     * sechs Knöpfe hoch, der Zeitplan mit fünf Feldern und Erklärung mehr als
     * doppelt so hoch — unter der Schnellwahl blieb eine grosse leere Fläche,
     * und die vier Gruppen lasen sich als vier Kästen (`docs/64`, Befund 14).
     *
     * Gemessen im Container gegen das gebaute Stylesheet, tote Fläche in
     * Tausend Pixeln²: **134k** als vier Gruppen, **34k** zusammengelegt.
     *
     * ## Warum die Zusammenlegung die richtige Anordnung ist und nicht nur die
     * ruhigere
     *
     * Die Schnellwahl **stellt den Zeitplan ein** — sie füllt genau die fünf
     * Felder darunter, und `CronScheduleFormTest` rechnet für jede Vorlage
     * nach, dass ihre Beschriftung das auch sagt. Eine eigene Gruppe daneben
     * behauptete, sie sei etwas anderes.
     *
     * > **Zwei Gruppen, von denen die eine nur die andere füllt, sind eine.**
     */
    public function test_the_quick_choice_belongs_to_the_schedule(): void
    {
        $seite = $this->read('resources/js/Pages/Subscriptions/Cron.vue');

        $this->assertSame(1, substr_count($seite, '<fieldset'), sprintf(
            'Das Formular hat %d Gruppen statt einer. Der Zeitplan ist eine Sache, und die '.
            'Schnellwahl stellt ihn ein (docs/64, Befund 14).',
            substr_count($seite, '<fieldset'),
        ));

        $this->assertMatchesRegularExpression(
            '/<fieldset class="field wide">.*?vorlage in vorlagen.*?<\/fieldset>/s',
            $seite,
            'Die Schnellwahl steht nicht mehr innerhalb der Zeitplangruppe — oder die Gruppe '.
            'trägt `wide` nicht mehr. Ohne `wide` steht sie in 540 px, und dort passen von '.
            'fünf Feldern zwei (docs/64, Befund 14).',
        );
    }

    public function test_the_menu_icon_is_drawn(): void
    {
        $layout = $this->read('resources/js/Layouts/PanelLayout.vue');
        $icons = $this->read('resources/js/Components/NavIcon.vue');

        $this->assertStringContainsString("icon: 'cron'", $layout, 'Der Menüpunkt trägt kein Zeichen.');
        $this->assertStringContainsString('  cron: ', $icons, 'Zu „cron" ist kein Zeichen gezeichnet.');
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }
}
