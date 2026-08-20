<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cron\Spoken;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Das Formular und die Regeln dahinter — sagt die Schnellwahl die Wahrheit?
 *
 * ## Die Behauptung, um die es geht
 *
 * `Cron.vue` verzichtet auf eine Übersetzung im Browser, und die Begründung
 * steht dort: Den Satz baut {@see Spoken} auf dem Server, und ihn ein zweites
 * Mal zu bauen hiesse, dieselbe Regel in zwei Sprachen zu pflegen. Für die
 * **Schnellwahl** gilt das nicht, denn dort ist die Beschriftung selbst der
 * Satz — „täglich um 03:15" steht auf dem Knopf, und derselbe Knopf stellt
 * `15 3 * * *` ein.
 *
 * Das ist eine Behauptung über zwei Dinge, die nebeneinander stehen und
 * auseinanderlaufen können:
 *
 * > **Eine Beschriftung, die eine Wirkung beschreibt, ist eine zweite Fassung —
 * > es sei denn, jemand hält sie gegen die erste.**
 *
 * Genau das tut dieser Wächter: Er liest die Vorlagen aus `Cron.vue`, schickt
 * ihre Felder durch {@see Spoken::schedule()} und vergleicht das Ergebnis mit
 * dem, was auf dem Knopf steht. Wer eine Vorlage ändert und die Beschriftung
 * vergisst — oder umgekehrt —, bekommt hier Rot.
 *
 * **Und jede Vorlage muss durch die Schranke des Agenten passen.** Eine
 * Schnellwahl, die einen Zeitplan einstellt, den {@see Schedule::parse()}
 * abweist, wäre ein Knopf, der beim Speichern eine Fehlermeldung erzeugt.
 */
final class CronScheduleFormTest extends TestCase
{
    /** Wo das Formular steht. */
    private const PAGE = 'resources/js/Pages/Subscriptions/Cron.vue';

    /**
     * Felder, die das Formular mitschickt, ohne zum Zeitplan zu gehören.
     *
     * **Die Liste ist die Ausnahme zur Regel darunter und keine Umgehung.** Wer
     * hier etwas einträgt, sagt damit: Dieses Feld beschreibt, *wie* der
     * Zeitplan gerade bedient wird, und nicht, *was* in ihm steht. Ein Feld, das
     * einen Zeitplanwert trägt, gehört nicht hierher — es wäre die zweite
     * Fassung, gegen die der Wächter steht.
     *
     * Der Grund für die Form: Ein blosses `array_diff` liesse jedes künftige
     * Feld durch, weil niemand es begründen müsste.
     *
     * @var array<string,string>
     */
    private const VIEW_FIELDS = [
        'experte' => 'Sagt, in welcher Ansicht die fünf Felder gerade bedient werden. Der Server '.
            'prüft davon unabhängig denselben Zeitplan; er richtet nur seine Meldung an eine Stelle '.
            'im Ausdruck statt an ein eingeklapptes Feld (docs/64, Befund 16).',
    ];

    /**
     * Jede Vorlage stellt ein, was ihre Beschriftung sagt.
     *
     * Die Umkehrung ist gleich mitgeprüft: Gäbe {@see Spoken} für eine Vorlage
     * `null` zurück — also „lässt sich nicht sicher übersetzen" —, stünde auf
     * dem Knopf ein Satz, für den es keinen gibt.
     */
    public function test_every_quick_choice_says_what_it_sets(): void
    {
        $vorlagen = $this->templates();

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThanOrEqual(
            4,
            count($vorlagen),
            'Es werden kaum Vorlagen gefunden — dann prüft dieser Wächter nichts.',
        );

        foreach ($vorlagen as $name => $felder) {
            $satz = Spoken::schedule($felder);

            $this->assertNotNull($satz, sprintf(
                'Die Vorlage „%s" stellt einen Zeitplan ein, für den Spoken keinen Satz hat. '.
                'Dann steht auf dem Knopf eine Behauptung, die die Liste darunter nicht wiederholen kann.',
                $name,
            ));

            $this->assertSame($name, $satz, sprintf(
                'Die Vorlage „%s" stellt „%s" ein. Beschriftung und Wirkung sind auseinandergelaufen.',
                $name,
                $satz,
            ));
        }
    }

    /** Und jede Vorlage kommt durch die Schranke, die später schreibt. */
    public function test_every_quick_choice_passes_the_agents_check(): void
    {
        foreach ($this->templates() as $name => $felder) {
            $geprueft = Schedule::parse($felder);

            $this->assertSame(
                $felder,
                $geprueft,
                sprintf('Die Vorlage „%s" kommt verändert aus Schedule::parse() zurück.', $name),
            );
        }
    }

    /**
     * Das Formular kennt genau die fünf Felder, die der Agent erwartet.
     *
     * **Ein sechstes Feld wäre nicht schlimm, ein fehlendes schon**, und ein
     * falsch geschriebenes am schlimmsten: Es käme als `null` an, der Agent
     * wiese es ab, und im Formular wäre nichts rot, weil das Feld dort einen
     * anderen Namen hat.
     */
    public function test_the_form_carries_exactly_the_five_fields(): void
    {
        $quelle = $this->source();

        foreach (Schedule::FIELDS as $feld) {
            $this->assertStringContainsString(
                "v-model=\"form.{$feld}\"",
                $quelle,
                sprintf('Das Formular hat kein Feld für „%s" — der Agent erwartet es.', $feld),
            );

            // Und eine Beschriftung dazu, sonst steht das Feld ohne Namen da.
            $this->assertStringContainsString(
                "for=\"{$feld}\"",
                $quelle,
                sprintf('Das Feld „%s" hat keine Beschriftung.', $feld),
            );
        }
    }

    /**
     * Der freie Ausdruck ist eine Sicht auf die fünf Felder und kein zweiter Wert.
     *
     * **Die Bedingung, unter der es die Expertenansicht überhaupt gibt.** Der
     * Betreiber hat sie am 19. August 2026 bestellt (`docs/64`, Wunsch 1), und
     * die erste Frage davor war, ob der Umschalter zurückschreibt. Täte er es
     * nicht, hätte die Seite zwei Wahrheiten über denselben Zeitplan — und die
     * zweite ist die, die veraltet. Derselbe Grund, aus dem die Schnellwahl die
     * Felder füllt, statt sich zu merken, dass „täglich" gewählt wurde.
     *
     * > **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**
     *
     * Geprüft wird beides, was dafür stimmen muss: Das Formular schickt kein
     * Feld über die fünf hinaus, das einen Zeitplan trägt, und der Setzer
     * schreibt **jedes** der fünf. Schriebe er eines nicht, behielte es beim
     * Umschalten seinen alten Wert — und der Ausdruck im Feld sagte etwas
     * anderes als das, was gespeichert wird.
     *
     * **Ein Feld, das keinen Zeitplan trägt, darf trotzdem mitreisen** — es
     * steht dann mit seiner Begründung in {@see self::VIEW_FIELDS}. `experte`
     * ist der erste Fall: Er sagt, *wie* die fünf Felder gerade bedient werden.
     */
    public function test_the_free_expression_is_a_view_on_the_five_fields(): void
    {
        $quelle = $this->source();

        foreach (self::VIEW_FIELDS as $feld => $grund) {
            $this->assertNotContains($feld, Schedule::FIELDS, sprintf(
                'Das Sichtfeld „%s" ist zugleich ein Feld des Zeitplans. Dann ist die Ausnahme '.
                'keine Ausnahme mehr, sondern ein zweiter Speicherort.',
                $feld,
            ));

            $this->assertNotSame('', trim($grund), sprintf(
                'Das Sichtfeld „%s" steht ohne Begründung da.',
                $feld,
            ));
        }

        $this->assertSame(
            array_merge(
                ['label', 'command'],
                Schedule::FIELDS,
                ['active'],
                array_keys(self::VIEW_FIELDS),
            ),
            $this->formKeys(),
            'Das Formular schickt andere Felder als die fünf des Zeitplans plus Beschriftung, '.
            'Befehl, Zustand und die begründeten Sichtfelder aus VIEW_FIELDS. Ein eigener Wert '.
            'für den Ausdruck waere eine zweite Fassung desselben Zeitplans.',
        );

        $this->assertSame(
            1,
            preg_match('/const freierAusdruck = computed\(\{(.+?)\n\}\)/s', $quelle, $block),
            'Den freien Ausdruck gibt es nicht mehr als berechnete Sicht — dann ist er ein Wert.',
        );

        $setzer = (string) strstr($block[1], 'set:');

        $this->assertNotSame('', $setzer, 'Der freie Ausdruck schreibt nichts zurück.');

        foreach (Schedule::FIELDS as $feld) {
            $this->assertMatchesRegularExpression(
                '/form\.'.preg_quote($feld, '/').'\s*=/',
                $setzer,
                sprintf(
                    'Der Setzer schreibt „%s" nicht. Beim Umschalten behielte das Feld seinen '.
                    'alten Wert, und der Ausdruck sagte etwas anderes als das Gespeicherte.',
                    $feld,
                ),
            );
        }
    }

    /**
     * In der Experteneingabe nennt die Meldung die Stelle im Ausdruck.
     *
     * ## Der Fund
     *
     * Griff 4 der Abnahme (`docs/63 §6b`): `* * *` eintragen und anlegen. Der
     * Server hat abgewiesen — richtig —, und die Meldung lautete „Das Feld
     * month ist erforderlich. Das Feld day of week ist erforderlich."
     *
     * Beide Felder waren in diesem Zustand **eingeklappt**. Wer der Meldung
     * folgt, sucht etwas, das er nicht sehen kann (`docs/64`, Befund 16).
     *
     * > **Eine Meldung, die ein Feld nennt, das gerade nicht zu sehen ist, ist
     * > keine Auskunft — sie ist eine Suchaufgabe.**
     *
     * ## Was sich dadurch **nicht** ändert
     *
     * Geprüft wird weiter der Zeitplan aus fünf Feldern, und zwar auf dem
     * Server. Die Experteneingabe bleibt eine Sicht; sie bekommt keinen eigenen
     * Prüfweg und keinen eigenen Speicherort. Der Unterschied liegt allein
     * darin, **wie die Antwort benannt wird**.
     *
     * > **Eine Sicht auf eine Sache ist noch keine Sicht auf ihre
     * > Fehlermeldungen.**
     */
    public function test_the_expert_input_gets_an_answer_it_can_use(): void
    {
        $quelle = $this->source();

        $this->assertMatchesRegularExpression(
            '/experte:\s*false/',
            $quelle,
            'Das Formular trägt `experte` nicht mit. Ohne diesen Wert weiss der Server nicht, '.
            'welche Ansicht offen ist — und benennt Felder, die eingeklappt sind (docs/64, '.
            'Befund 16).',
        );

        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/CronController.php',
        );

        $this->assertStringContainsString(
            "boolean('experte')",
            $controller,
            'Der Controller fragt nicht nach der Ansicht. Dann geht seine Meldung an die fünf '.
            'Felder, gleich ob sie zu sehen sind.',
        );

        $this->assertStringContainsString(
            'Im Ausdruck fehlt der %d. Teil (%s).',
            $controller,
            'Die Meldung für die Experteneingabe nennt keine Stelle im Ausdruck. Ein Feldname '.
            'hilft dort nicht: Das Feld ist eingeklappt.',
        );

        /*
         * **Die Namen der fünf Stellen gehören nicht in die Sprachdatei.**
         * Dort benennen sie ein Feld; hier benennen sie eine Stelle in einer
         * Zeichenkette, und das ist etwas anderes.
         */
        /*
         * **Und das eine sichtbare Feld wird markiert.** Der Server legt seine
         * Meldung unter `expression` ab; stünde der Name nicht in
         * `zeitplanFalsch`, bliebe der Ausdruck unmarkiert — ausgerechnet dann,
         * wenn die Meldung von ihm handelt.
         */
        $this->assertMatchesRegularExpression(
            "/const zeitplanFalsch = computed\(\s*\(\) => \[[^\]]*'expression'/",
            $quelle,
            'Das Ausdrucksfeld wird nicht rot, wenn der Server den Ausdruck bemängelt. '.
            'Die Meldung steht dann oben und zeigt auf nichts.',
        );

        /*
         * **Und die Namen kommen aus der einen Liste.** Der erste Anlauf trug
         * sie als eigene Konstante im Controller — dieselben fünf Wörter ein
         * zweites Mal, direkt neben der Sprachdatei, die sie ohnehin führt.
         */
        $this->assertStringContainsString(
            "trans('validation.attributes.'.\$feld)",
            $controller,
            'Die fünf Teile des Ausdrucks holen ihre Namen nicht aus lang/de/validation.php. '.
            'Eine eigene Liste dort wäre die zweite Fassung derselben Wörter — und ohne Namen '.
            'liest sich die Meldung als „Im Ausdruck fehlt der 4. Teil".',
        );
    }

    /**
     * Im Browser wird nicht übersetzt.
     *
     * Der Gegenprobe-Teil der Regel aus dem Klassenkopf: Fände sich hier eine
     * Wochentagstabelle oder ein „jeden Tag um", wäre {@see Spoken} zweimal
     * gebaut — und die zweite Fassung ist die, die abweicht.
     */
    public function test_the_page_does_not_translate_on_its_own(): void
    {
        $quelle = $this->source();

        foreach (['montags', 'jeden Tag um', 'jede Stunde zur Minute'] as $satzteil) {
            $stellen = substr_count($quelle, $satzteil);

            /*
             * **Die Vorlagenliste darf sie nennen** — dort *sind* sie die
             * Beschriftung, und der Wächter oben hält sie gegen Spoken. Was
             * nicht sein darf, ist eine zweite Fundstelle: Die wäre eine
             * Übersetzung im Browser.
             */
            $this->assertLessThanOrEqual(1, $stellen, sprintf(
                '„%s" steht %dmal in %s. Mehr als einmal heisst: Die Seite übersetzt selbst, '.
                'und dann gibt es die Regel zweimal.',
                $satzteil,
                $stellen,
                self::PAGE,
            ));
        }
    }

    /**
     * Die Felder, die das Formular an den Server schickt — aus `useForm`.
     *
     * @return list<string>
     */
    private function formKeys(): array
    {
        if (preg_match('/const form = useForm\(\{(.+?)\n\}\)/s', $this->source(), $block) !== 1) {
            return [];
        }

        preg_match_all('/^\s{2}(\w+):/m', $block[1], $treffer);

        return $treffer[1];
    }

    /**
     * Die Vorlagen aus `Cron.vue` lesen.
     *
     * Gelesen wird der Quelltext und nicht eine Abschrift: Eine Liste hier wäre
     * genau die zweite Fassung, gegen die dieser Wächter steht.
     *
     * @return array<string,array<string,string>>
     */
    private function templates(): array
    {
        $quelle = $this->source();

        if (preg_match('/const vorlagen = \[(.+?)\n\]/s', $quelle, $block) !== 1) {
            return [];
        }

        preg_match_all(
            "/\{ name: '([^']+)', felder: \{([^}]+)\} \}/",
            $block[1],
            $treffer,
            PREG_SET_ORDER,
        );

        $vorlagen = [];

        foreach ($treffer as $eintrag) {
            $felder = [];

            preg_match_all("/(\w+): '([^']*)'/", $eintrag[2], $paare, PREG_SET_ORDER);

            foreach ($paare as $paar) {
                $felder[$paar[1]] = $paar[2];
            }

            $vorlagen[$eintrag[1]] = $felder;
        }

        return $vorlagen;
    }

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.self::PAGE);
    }
}
