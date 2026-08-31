<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\WithoutMarkupComments;

/**
 * Die Dienste-Seite — und die eine Regel, um die es dort geht.
 *
 * **Ein Timer ohne nächsten Termin meldet `active`.** Gemessen gegen systemd 255
 * (`docs/89 §3`): Der gesunde und der kaputte Timer sind an `ActiveState` nicht
 * zu unterscheiden. Wer die Farbe daran hängt, malt beide grün — und das
 * Abnahmekriterium von A2 ist genau, dass man den Schaden sieht.
 *
 * Gehalten wird das am Quelltext und nicht an einer Aufnahme: Ein Bild sagt,
 * wie es an einem Tag aussah; dieser Wächter sagt, dass die Entscheidung noch
 * dort steht, wo sie hingehört.
 */
final class ServicesViewTest extends TestCase
{
    use WithoutMarkupComments;

    private const SEITE = 'resources/js/Pages/Services/Index.vue';

    /**
     * Wo die Regeln über den Zustand seit dem 31. August 2026 wohnen.
     *
     * **Sie standen in der Seite, und dieser Wächter zeigte dorthin.** Mit
     * Befund 5 sind sie in die geteilte Stelle gezogen, weil die Übersicht eine
     * zweite, ärmere Fassung hatte — und drei Fälle hier wurden rot, obwohl
     * nichts kaputt war.
     *
     * > **Ein Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen
     * > abgeschaltet.**
     *
     * Die Antwort ist nicht, ihn abzuschwächen, sondern ihn dorthin zu zeigen,
     * wo die Regel jetzt steht. Was die **Seite** entscheidet — zwei Bereiche,
     * der schweigende Agent, das Datum vom Server, die Meldung über `rang` —
     * bleibt an der Seite.
     */
    private const ZUSTAND = 'resources/js/Composables/useUnitState.ts';

    private const CONTROLLER = 'app/Http/Controllers/ServicesController.php';

    private static function quelle(string $pfad): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        self::assertIsString($inhalt, $pfad.' ist nicht lesbar.');

        return $inhalt;
    }

    /**
     * Der Rumpf einer Funktion der Seite — von ihrer Zeile bis zur nächsten.
     *
     * Grob genug für einen Wächter über eine Reihenfolge und bewusst kein
     * Parser: Was er nicht findet, meldet er als Fehlschlag und nicht als
     * leeren Rumpf, in dem jede Suche nichts ergibt.
     */
    private static function rumpfVon(string $quelle, string $name): string
    {
        $anfang = strpos($quelle, 'function '.$name.'(');

        self::assertIsInt($anfang, 'Die Seite hat keine Funktion '.$name.'.');

        $ende = strpos($quelle, "\n}", $anfang);

        self::assertIsInt($ende, 'Der Rumpf von '.$name.' hört nirgends auf.');

        return substr($quelle, $anfang, $ende - $anfang);
    }

    /**
     * Die Farbe folgt dem Termin und nicht dem Zustand von systemd.
     *
     * Geprüft wird die **Reihenfolge**: Der Termin muss vor `active_state`
     * gefragt werden. Stünde er danach, träfe der `active`-Zweig zuerst, und der
     * kaputte Timer wäre grün — der Ausdruck wäre trotzdem da, und ein Wächter,
     * der nur nach dem Wort sucht, bliebe still.
     *
     * **Gemessen wird im Rumpf von `rang` und nicht in der ganzen Datei — und
     * das ist die Berichtigung vom 31. August 2026.** Bis zum Umzug in die
     * geteilte Stelle stand die Bedingung wörtlich in `rang`; seitdem steht sie
     * im Helfer `ohneTermin`, und der wird **oben** definiert. Über die ganze
     * Datei gemessen war `has_next === false` damit immer zuerst da — auch dann,
     * wenn die Reihenfolge in `rang` verkehrt herum stand.
     *
     * > **Ein Wächter über eine Reihenfolge wird stumpf, sobald einer der beiden
     * > Ausdrücke in einen Helfer zieht, der weiter oben steht.**
     *
     * Der Bruchlauf hat das gemeldet und nicht dieser Test: Sein Eingriff fand
     * seinen Text nicht mehr.
     */
    public function test_the_colour_of_a_timer_follows_its_next_date(): void
    {
        $rumpf = self::rumpfVon($this->withoutMarkupComments(self::quelle(self::ZUSTAND)), 'rang');

        $termin = strpos($rumpf, 'ohneTermin(unit)');
        $zustand = strpos($rumpf, "active_state === 'active'");

        $this->assertIsInt($termin, 'Der Rang fragt den nächsten Termin gar nicht.');
        $this->assertIsInt($zustand, 'Der Rang fragt den Zustand von systemd gar nicht.');
        $this->assertLessThan(
            $zustand,
            $termin,
            'Der Zustand wird vor dem Termin gefragt — dann ist ein Timer ohne Termin grün.',
        );

        // Und die Bedingung selbst steht genau einmal: `rang` und `zustand`
        // greifen beide auf denselben Helfer zu. Zwei Fassungen liefen
        // auseinander, und die zweite ist die, die veraltet.
        $this->assertSame(
            1,
            substr_count(
                $this->withoutMarkupComments(self::quelle(self::ZUSTAND)),
                "unit.kind === 'timer' && unit.has_next === false",
            ),
            'Die Bedingung steht nicht genau einmal.',
        );
    }

    /**
     * Der Schaden steht als Satz da und nicht als Zahl.
     *
     * `docs/81 §A2`: „ohne dass man die Zahl deuten muss".
     */
    public function test_a_timer_without_a_date_is_named_in_words(): void
    {
        // **Zwei Quellen, weil es zwei Aussagen sind.** Der Satz in der Zelle
        // gehört seit dem 31. August in die geteilte Stelle; die Meldung über
        // der Tabelle gehört der Seite, denn nur sie zählt.
        $this->assertStringContainsString(
            'kein nächster Termin',
            $this->withoutMarkupComments(self::quelle(self::ZUSTAND)),
        );

        $this->assertStringContainsString(
            'meldet',
            $this->withoutMarkupComments(self::quelle(self::SEITE)),
            'Die Meldung oben sagt nicht, was daran falsch ist.',
        );
    }

    /**
     * „Kein Termin" und „Termin unbekannt" sind zwei Auskünfte.
     *
     * Das erste ist ein Schaden, das zweite eine Lücke im Messmittel — auf einem
     * System, dessen `systemctl` kein JSON kann, fehlt das Datum, und der Timer
     * ist trotzdem gesund. Dieselbe Zelle für beides machte aus jeder Lücke
     * einen Befund.
     */
    public function test_a_missing_date_is_not_the_same_as_no_date(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertStringContainsString("'unbekannt'", $quelle);
        $this->assertStringContainsString("'—'", $quelle);
    }

    /**
     * Der Termin wird auf dem Server zu Text.
     *
     * `toLocaleString` im Browser nähme die Zone des Betrachters; die
     * Anzeigezone dieses Panels steht in den Einstellungen (`docs/40`), und
     * `Clock` ist die einzige Stelle, die daraus eine Anzeige macht.
     */
    public function test_the_date_is_formatted_on_the_server(): void
    {
        $this->assertStringContainsString('Clock::display', self::quelle(self::CONTROLLER));
        $this->assertStringNotContainsString(
            'toLocaleString',
            $this->withoutMarkupComments(self::quelle(self::SEITE)),
            'Die Seite rechnet die Zeit selbst — dann entscheidet die Zone des Betrachters.',
        );
    }

    /**
     * Dienste und Timer stehen getrennt.
     *
     * Ein Timer hat keine PID, keinen Neustartzähler und keinen Startzeitpunkt.
     * In einer gemeinsamen Tabelle stünden bei ihm drei Spalten leer und eine
     * bei allen anderen.
     */
    /**
     * Ein Dienst, den ein Timer startet, darf stillstehen.
     *
     * **Der Befund vom 31. August 2026 auf `cloudsrv24`.** Vier der eigenen
     * zwölf Dienste sind `Type=oneshot` und stehen zwischen ihren Läufen auf
     * `inactive`; die erste Fassung dieser Seite malte sie rot und meldete
     * darüber „4 Dienste laufen nicht" — auf einem Server, an dem nichts fehlte.
     *
     * Das ist derselbe Fehler wie beim Timer, nur spiegelverkehrt: Dort sieht
     * der kaputte gesund aus, hier der gesunde kaputt.
     *
     * Geprüft wird die **Bedingung** und nicht das Wort: Die Nachsicht hängt an
     * `inactive` und nicht an „nicht aktiv". Stünde dort `!== 'active'`, deckte
     * sie einen gescheiterten Lauf mit zu — und `failed` ist der Zustand, in dem
     * ein oneshot-Dienst nach einem Fehlschlag steht (gemessen, `docs/91 §2`).
     */
    public function test_a_service_a_timer_starts_may_stand_still(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::ZUSTAND));

        // **Die Bedingung steht einmal, und beide Funktionen rufen sie.**
        //
        // Der erste Wurf dieses Wächters suchte sie *irgendwo* in der Datei und
        // blieb grün, als der Bruchlauf sie aus `rang` entfernte und in
        // `zustand` stehenliess. Der zweite verlangte sie wörtlich in **beiden**
        // Rümpfen — und wurde rot, als der Umzug in die geteilte Stelle sie zu
        // Recht in einen Helfer zog.
        //
        // > **Ein Wächter, der eine Zeichenkette in jedem Rumpf verlangt,
        // > verbietet genau die Zusammenfassung, die er erzwingen wollte.**
        //
        // Gehalten wird deshalb die Regel und nicht ihre Schreibweise: Die
        // Bedingung steht **einmal**, und beide Funktionen greifen darauf zu.
        $this->assertSame(
            1,
            substr_count($quelle, "unit.scheduled === true && unit.active_state === 'inactive'"),
            'Die Bedingung steht nicht genau einmal — zwei Fassungen laufen auseinander.',
        );

        foreach (['rang', 'zustand'] as $funktion) {
            $this->assertMatchesRegularExpression(
                '/\bwartet\(unit\)/',
                self::rumpfVon($quelle, $funktion),
                'Ohne die Nachsicht in '.$funktion.' ist ein wartender oneshot-Dienst ein Schaden.',
            );
        }

        // **Gemessen wird im Rumpf von `zustand` und nicht in der ganzen
        // Datei.** Der erste Wurf dieses Wächters verglich zwei Fundstellen aus
        // *zwei* Funktionen — die Nachsicht aus `rang`, den Fehlschlag aus
        // `zustand` — und meldete eine Reihenfolge, die es so nicht gibt.
        //
        // > Zwei Fundstellen aus zwei Funktionen haben keine Reihenfolge
        // > zueinander.
        $rumpf = self::rumpfVon($quelle, 'zustand');

        $nachsicht = strpos($rumpf, 'wartet(unit)');
        $gescheitert = strpos($rumpf, "unit.active_state === 'failed'");

        $this->assertIsInt($nachsicht, 'Die Nachsicht steht nicht in der Zustandsspalte.');
        $this->assertIsInt($gescheitert, 'Der Fehlschlag steht nicht in der Zustandsspalte.');
        $this->assertLessThan(
            $nachsicht,
            $gescheitert,
            'Die Nachsicht steht vor dem Fehlschlag — dann liest sich ein gescheiterter Lauf als „wartet".',
        );
    }

    /**
     * Die Meldung zählt, was die Farbe sagt.
     *
     * Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist die,
     * die veraltet: Die erste Fassung dieser Seite färbte über `rang` und zählte
     * über `active_state`. Nach der Behebung waren vier Zeilen grün — und
     * darüber stand weiter „4 Dienste laufen nicht".
     */
    public function test_the_notice_counts_what_the_colour_says(): void
    {
        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertMatchesRegularExpression(
            '/const gestoppt = computed\(.{0,120}rang\(/s',
            $quelle,
            'Die Meldung zählt an der Farbe vorbei.',
        );
    }

    public function test_services_and_timers_are_two_sections(): void
    {
        $controller = self::quelle(self::CONTROLLER);

        $this->assertStringContainsString("'service'", $controller);
        $this->assertStringContainsString("'timer'", $controller);

        $quelle = $this->withoutMarkupComments(self::quelle(self::SEITE));

        $this->assertSame(
            2,
            substr_count($quelle, '<Section'),
            'Es sind nicht mehr genau zwei Bereiche — dann prüft die Trennung hier nichts.',
        );
    }

    /**
     * Eine leere Liste heisst nicht „nichts installiert".
     *
     * Ohne `live` wäre ein schweigender Agent von einem Server ohne Dienste
     * nicht zu unterscheiden — derselbe Unterschied, den `null` und `0` im
     * Leser machen.
     */
    public function test_a_silent_agent_is_told_apart_from_an_empty_server(): void
    {
        $this->assertStringContainsString("'live' =>", self::quelle(self::CONTROLLER));
        $this->assertStringContainsString('v-if="!live"', $this->withoutMarkupComments(self::quelle(self::SEITE)));
    }

    /**
     * Kein Rohwert von systemd steht in der Oberfläche.
     *
     * **Das ist die zweite Hälfte von Befund 5.** Die Übersicht druckte
     * `service.active_state`, also wörtlich `active` — englisch, wo
     * `docs/19 §4a` Deutsch bindet, und ärmer als die Dienste-Seite daneben,
     * die für denselben Zustand `läuft` sagte. Zwei Seiten, ein Server, zwei
     * Auskünfte.
     *
     * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte
     * > Auskunft, sondern eine widersprüchliche.**
     *
     * **`WordChoiceTest` konnte es nicht sehen**, und das ist der Grund für
     * diesen Wächter: Das englische Wort steht nirgends im Quelltext. Es
     * entsteht zur Laufzeit aus einem Feld, das der Agent liefert — gesucht
     * wird deshalb das Feld in einer Ausgabe und nicht das Wort.
     *
     * Die Untergrenze steht daneben: Beide Seiten, die Units zeigen, müssen
     * `zustand(` rufen. Ohne sie hielte die Regel auch dann, wenn gar keine
     * Seite mehr eine Unit anzeigt.
     */
    public function test_no_page_prints_a_raw_unit_state(): void
    {
        $roh = ['active_state', 'sub_state', 'load_state'];

        // Rekursiv und nicht über `glob`: PHPs `**` ist kein `**`, es steht für
        // genau eine Ebene. Ein Muster mit zwei Sternen läse die Ebenen, die es
        // heute gibt, und die nächste nicht — wortlos.
        $seiten = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS)
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $seiten[] = $datei->getPathname();
            }
        }

        $this->assertGreaterThan(
            50,
            count($seiten),
            'Es wurden kaum Vorlagen gelesen — dann prüft dieser Test fast nichts.',
        );

        foreach ($seiten as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));

            preg_match_all('/\{\{(.+?)\}\}/s', $quelle, $treffer);

            foreach ($treffer[1] as $ausgabe) {
                foreach ($roh as $feld) {
                    $this->assertStringNotContainsString(
                        $feld,
                        $ausgabe,
                        sprintf(
                            '`%s` druckt `%s` roh. Das ist ein englisches Wort von systemd; '
                            .'der Zustand kommt aus `zustand()`.',
                            basename($pfad),
                            $feld,
                        ),
                    );
                }
            }
        }

        foreach ([self::SEITE, 'resources/js/Pages/Overview.vue'] as $seite) {
            $this->assertStringContainsString(
                'zustand(',
                $this->withoutMarkupComments(self::quelle($seite)),
                $seite.' zeigt Units und fragt den Zustand nicht — dann steht die Regel ohne Fall da.',
            );
        }
    }
}
