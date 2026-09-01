<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Outcome;
use Tests\Support\WithoutHashComments;
use Tests\Support\WithoutPhpComments;

/**
 * Das Urteil eines abgesetzten Laufs, gelesen ab seinem eigenen Versatz.
 *
 * **Die Prüfkörper sind selbst gebaut und nicht aus dem Prüfling geholt.** Ein
 * Log, das dieser Leser erzeugt hat, prüfte ihn gegen sich selbst; die Zeilen
 * hier stehen so, wie `apt-run` sie im Abnahmelauf wirklich geschrieben hat
 * (`docs/86`, Punkte 5, 5d, 10).
 */
final class OutcomeTest extends TestCase
{
    use WithoutHashComments;
    use WithoutPhpComments;

    /**
     * Die fünf Ausgänge, wie `apt-run` sie schreibt.
     *
     * **Der Prüfkörper `wirkungslos` stand hier bis zum 31. August 2026 mit
     * einer `0` — und dieser Wächter behauptete daneben, das sei ein
     * Fehlschlag.** Genau diese Zeile hat auf `cloudsrv24` einen roten Vorgang
     * erzeugt, obwohl nichts anstand (`docs/91 §17`).
     *
     * > **Ein Prüfkörper, der den Fehler enthält, hält ihn fest statt ihn zu
     * > melden — wenn die Behauptung daneben ihn für richtig erklärt.**
     *
     * Er trägt jetzt eine Zahl grösser null, denn das ist der Fall, den er
     * meint: Es stand etwas an, und es ist nichts angekommen. Der Fall mit der
     * Null steht daneben als `nichts_offen` und ist **kein** Fehlschlag.
     */
    private const ECHT = [
        'gescheitert' => 'apt-run: apt-get endete mit 100. offene Aktualisierungen: vorher 5, jetzt 5.',
        'wirkungslos' => 'apt-run: Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 7.',
        'nichts_offen' => 'apt-run: Es stand nichts an — offene Aktualisierungen: 0.',
        'panel' => 'apt-run: Fassung 0.7.2~rc.3 wurde zu 0.7.2~rc.4.',
        'pakete' => 'apt-run: 5 von 5 Aktualisierungen eingespielt, 0 bleiben offen.',
    ];

    private function datei(): string
    {
        return sys_get_temp_dir().'/srvpanel-outcome-'.getmypid().'.log';
    }

    private function schreibe(string $inhalt): string
    {
        $pfad = $this->datei();
        file_put_contents($pfad, $inhalt);

        return $pfad;
    }

    protected function tearDown(): void
    {
        @unlink($this->datei());
    }

    public function test_the_verdict_is_read_from_the_own_offset(): void
    {
        $vorher = "0 aktualisiert, 0 neu installiert.\n".self::ECHT['pakete']."\n";
        $pfad = $this->schreibe($vorher);
        $versatz = strlen($vorher);

        file_put_contents($pfad, "Reading package lists...\n".self::ECHT['wirkungslos']."\n", FILE_APPEND);

        $this->assertSame(
            substr(self::ECHT['wirkungslos'], strlen(Outcome::PREFIX)),
            Outcome::verdict(Outcome::lines($pfad, $versatz)),
        );
    }

    /**
     * Und die Gegenprobe: Ohne den Versatz gewinnt der falsche Lauf.
     *
     * **Ohne sie belegte der Test darüber nichts.** Er wäre auch dann grün,
     * wenn der Leser schlicht die letzte Zeile der Datei nähme — die ist hier
     * zufällig dieselbe. Erst ein Versatz **hinter** dem zweiten Lauf zeigt,
     * dass die Grenze wirkt.
     */
    public function test_without_the_offset_an_earlier_run_would_be_read(): void
    {
        $erster = "Lauf eins\n".self::ECHT['pakete']."\n";
        $pfad = $this->schreibe($erster);

        $this->assertSame(
            '5 von 5 Aktualisierungen eingespielt, 0 bleiben offen.',
            Outcome::verdict(Outcome::lines($pfad, 0)),
            'Der Prüfkörper trägt gar kein Urteil — dann misst der Test daneben nichts.',
        );

        $this->assertNull(
            Outcome::verdict(Outcome::lines($pfad, strlen($erster))),
            'Ein Versatz hinter dem letzten Lauf muss leer zurückkommen — sonst liest dieser '
            .'Leser das Urteil eines fremden Laufs als das eigene.',
        );
    }

    /**
     * Eine Datei, die kürzer geworden ist, hat neu angefangen.
     *
     * `PanelUpdate` leert sein Log zu Beginn jedes Laufs. Ein Versatz aus dem
     * vorigen Lauf ist dann grösser als die ganze Datei — und ohne diesen Fall
     * käme „noch kein Urteil" zurück, während eines dasteht.
     */
    public function test_a_truncated_log_is_read_from_the_start(): void
    {
        $pfad = $this->schreibe(self::ECHT['panel']."\n");

        $this->assertSame(
            'Fassung 0.7.2~rc.3 wurde zu 0.7.2~rc.4.',
            Outcome::verdict(Outcome::lines($pfad, 9_000)),
        );
    }

    public function test_a_run_without_a_verdict_yet_answers_null(): void
    {
        $pfad = $this->schreibe("Reading package lists...\nBuilding dependency tree...\n");

        $this->assertNull(Outcome::verdict(Outcome::lines($pfad, 0)));
    }

    public function test_a_missing_log_is_not_a_verdict(): void
    {
        $this->assertSame([], Outcome::lines('/nicht/vorhanden/upgrade.log', 0));
        $this->assertNull(Outcome::verdict([]));
    }

    /**
     * Welche der fünf Formen ein Fehlschlag ist.
     *
     * **Beide Richtungen in einem Fall.** Ein `failed()`, das immer `true`
     * sagt, bestünde die eine Hälfte genauso.
     *
     * **Und `nichts_offen` ist die Zeile, um die es seit dem 31. August geht.**
     * „Nichts zu tun" und „nicht geschafft" sind zwei Ausgänge, und nur einer
     * ist ein Fehlschlag — die Spiegelung von M5, dem Befund, mit dem P7b
     * angefangen hat.
     */
    public function test_two_of_the_five_verdicts_are_failures(): void
    {
        $ab = static fn (string $zeile): string => substr($zeile, strlen(Outcome::PREFIX));

        $this->assertTrue(Outcome::failed($ab(self::ECHT['gescheitert'])));
        $this->assertTrue(Outcome::failed($ab(self::ECHT['wirkungslos'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['nichts_offen'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['panel'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['pakete'])));
    }

    /**
     * `apt-run` unterscheidet die beiden Fälle, und zwar an der Zahl.
     *
     * Gehalten wird am Skript und nicht am Leser: Der Leser braucht dafür keine
     * Zeile — was nicht in `BAD` steht, ist bei ihm ein Erfolg mit einer
     * Meldung. Der Fehler steckte allein darin, dass das Skript beide Fälle
     * gleich benannte und mit `3` endete.
     */
    public function test_the_script_tells_nothing_pending_from_nothing_changed(): void
    {
        $skript = $this->withoutHashComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run')
        );

        $this->assertStringContainsString(
            'Es stand nichts an',
            $skript,
            'Ein Lauf ohne Anlass meldet wieder „Der Lauf hat nichts verändert" — und damit einen Fehlschlag.',
        );

        /*
         * **Die Nachsicht gilt in allen drei Modi, und bis zum 1. September
         * 2026 stand hier das Gegenteil.**
         *
         * Dieser Wächter verlangte ausdrücklich `[ "$mass" = offen ]` — mit der
         * Begründung, bei `--fassung` sei `nachher` eine Versionsnummer und nie
         * null. Das stimmt und war die falsche Frage: Gefragt gehört nicht, ob
         * sich der Zählerstand ablesen lässt, sondern ob überhaupt etwas
         * anstand. Befund 2 aus `docs/94 §4`.
         *
         * > **Ein Wächter kann eine Beschränkung festhalten, die selbst der
         * > Fehler ist — und dann ist er das Letzte, was sich ändert.**
         */
        $this->assertDoesNotMatchRegularExpression(
            '/if \[ "\$anstand" -eq 0 \][^\n]*\$mass/',
            $skript,
            'Die Nachsicht ist wieder auf einen Modus beschränkt — „es stand nichts an" gilt in allen dreien.',
        );

        $this->assertMatchesRegularExpression(
            '/if \[ "\$anstand" -eq 0 \] && \[ "\$vorher" = "\$nachher" \]; then\n\s*echo[^\n]*Es stand nichts an[^\n]*\n\s*exit 0\n/',
            $skript,
            'Der Zweig „es stand nichts an" fragt nicht mehr `$anstand`, oder er endet nicht mit 0 — dann '
            .'steht die Unit auf `failed`.',
        );
    }

    /**
     * Und `anstand` kommt aus einer Simulation mit denselben Argumenten.
     *
     * **Die Gegenrichtung.** Ohne sie wäre die Regel darüber auch dadurch zu
     * erfüllen, dass `anstand` immer `0` ist — dann meldete jeder Lauf „es
     * stand nichts an", auch der gescheiterte. Das ist M5, nur in Grün.
     *
     * `"$@"` und keine eigene Argumentliste: Eine Simulation, die etwas anderes
     * fragt als das, was gleich ausgeführt wird, beantwortet eine andere Frage.
     */
    public function test_what_is_pending_comes_from_a_simulation_of_the_same_run(): void
    {
        $skript = $this->withoutHashComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run')
        );

        $this->assertMatchesRegularExpression(
            '/ansteht\(\) \{\n\s*apt-get -s "\$@"[^\n]*grep -c \S*\^Inst /',
            $skript,
            '`ansteht` fragt apt nicht mehr in der Simulation mit denselben Argumenten.',
        );

        $this->assertMatchesRegularExpression(
            '/^anstand=\$\(ansteht "\$@"\)$/m',
            $skript,
            '`anstand` wird nicht mehr vor dem Lauf gemessen — danach gemessen wäre es eine andere Zahl.',
        );

        // Vor dem Lauf und nicht danach: Die Reihenfolge ist die Regel, nicht
        // das Vorkommen.
        $vor = strpos($skript, 'anstand=$(ansteht');
        $lauf = strpos($skript, 'apt-get -q -y -o Dpkg::Use-Pty=0');

        $this->assertIsInt($vor);
        $this->assertIsInt($lauf);
        $this->assertLessThan(
            $lauf,
            $vor,
            '`anstand` wird erst nach dem Lauf gemessen — dann sagt es nichts darüber, was vorher anstand.',
        );
    }

    /**
     * Der Präfix steht in `apt-run`, und hier steht derselbe.
     *
     * **Die teuerste Naht dieses Bauteils.** Liefen die beiden auseinander,
     * fände der Leser nie ein Urteil und meldete „läuft noch", bis die Frist
     * abläuft — ein Fehler, der wie Geduld aussieht.
     */
    public function test_the_prefix_is_the_one_apt_run_writes(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        $this->assertSame(
            1,
            preg_match('/^NAME=(\S+)$/m', $skript, $treffer),
            'In `apt-run` steht kein `NAME=` mehr — dann weiss dieser Test nicht, wogegen er hält.',
        );

        $this->assertSame(
            $treffer[1].': ',
            Outcome::PREFIX,
            'Der Präfix in `Outcome` und der Name in `apt-run` sind auseinandergelaufen. '
            .'Der Leser fände dann nie ein Urteil und meldete „läuft noch", bis die Frist abläuft.',
        );
    }

    /**
     * Und jede Form aus `BAD` steht wirklich in `apt-run`.
     *
     * Die Gegenrichtung zum Test darüber: Ein Eintrag, der dort nicht mehr
     * geschrieben wird, ist ein Fehlschlag, den niemand mehr melden kann.
     */
    public function test_every_bad_verdict_is_a_line_apt_run_writes(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        foreach (Outcome::BAD as $anfang) {
            $this->assertStringContainsString(
                $anfang,
                $skript,
                sprintf('„%s" steht in BAD, aber `apt-run` schreibt es nicht mehr.', $anfang),
            );
        }
    }

    /**
     * Auf jede Zeile mit dem Präfix folgt ein `exit`.
     *
     * ## Der Befund, gegen den es diesen Fall gibt
     *
     * **Gemessen auf `cloudsrv24` am 1. September 2026** (`docs/94 §8`).
     * `apt-run` schrieb im Fassungsmodus zwei **Fortschrittszeilen** mit
     * demselben Präfix wie sein Urteil — `apt-run: Paketlisten werden
     * aufgefrischt.` als allererste Zeile des Laufs.
     *
     * {@see Outcome::verdict()} nimmt die **letzte** Zeile mit dem Präfix. Am
     * Ende eines Laufs ist das richtig; während des Laufs ist die letzte auch
     * die erste.
     *
     * > **Ein Leser, der „die letzte Zeile" nimmt, liest während des Laufs die
     * > erste.**
     *
     * `srvpanel update` meldete damit nach zwei Sekunden „Paketlisten werden
     * aufgefrischt." als Urteil, grün, mit Rückgabewert 0 — die Warteschleife
     * war in genau dem Modus wirkungslos, für den sie gebaut wurde.
     *
     * ## Warum diese Form und nicht eine Positivliste
     *
     * Eine Liste der fünf Urteilssätze in PHP wäre eine zweite Fassung dessen,
     * was `apt-run` schreibt — und die zweite veraltet. Gehalten wird
     * stattdessen die **Eigenschaft**, die ein Urteil von einer Meldung
     * unterscheidet: Es beendet den Lauf.
     *
     * Kommt eine sechste Urteilsform dazu, ist sie von selbst gedeckt. Kommt
     * eine dritte Fortschrittszeile dazu, meldet dieser Fall sie.
     */
    public function test_every_prefixed_line_ends_the_run(): void
    {
        $zeilen = explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run'));

        $mit = [];

        foreach ($zeilen as $nummer => $zeile) {
            if (! str_contains($zeile, 'echo "$NAME:')) {
                continue;
            }

            $mit[] = $nummer;

            $naechste = trim($zeilen[$nummer + 1] ?? '');

            $this->assertStringStartsWith(
                'exit',
                $naechste,
                sprintf(
                    "Zeile %d trägt den Präfix und beendet den Lauf nicht:\n  %s\n  → %s\n\n"
                    .'Der Leser nimmt die letzte Zeile mit dem Präfix — während des Laufs ist das '
                    .'diese hier, und der Vorgang meldet sie als Urteil.',
                    $nummer + 1,
                    trim($zeile),
                    $naechste === '' ? '(nichts)' : $naechste,
                ),
            );
        }

        $this->assertGreaterThan(
            4,
            count($mit),
            'Es werden kaum Zeilen mit dem Präfix gefunden — dann prüft dieser Test nichts.',
        );
    }

    /**
     * Und die Auffrischung steht weiter im Protokoll, ohne Präfix.
     *
     * Die Gegenrichtung zu der Regel darüber: Ohne diesen Fall wäre sie auch
     * dadurch zu erfüllen, dass die Meldung ganz verschwindet und der Betreiber
     * nichts mehr sieht.
     *
     * **Der Gegenstand ist am 1. September 2026 umgezogen**, und dieser Wächter
     * mit ihm. Bis dahin schrieb `apt-run` die Zeile; seit Befund 2
     * (`docs/94 §4`) frischt `PanelUpdate` auf, weil nur dort je Quelle gelesen
     * wird — und schreibt sie selbst ins Protokoll, das `srvpanel update`
     * mitliest.
     *
     * > **Ein Wächter, dessen Gegenstand umzieht, wird stumpf und nicht rot,
     * > wenn man ihn an seinem alten Ort stehenlässt.** Hier ist er rot
     * > geworden, weil er an einer Zeichenkette hing, die es nicht mehr gibt —
     * > das ist der glückliche Fall.
     */
    public function test_the_refresh_still_reaches_the_log_without_the_prefix(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/PanelUpdate.php');

        $this->assertMatchesRegularExpression(
            '/file_put_contents\(\s*self::LOG,[^;]*Paketlisten aufgefrischt/',
            $quelle,
            'Die Auffrischung steht nicht mehr im Protokoll — der Betreiber sieht nicht mehr, dass sie stattfand.',
        );

        $this->assertStringNotContainsString(
            Outcome::PREFIX.'Paketlisten',
            $quelle,
            'Die Fortschrittszeile trägt den Präfix — dann liest der Leser sie als Urteil.',
        );

        // Und die alte Stelle schreibt sie nicht mehr: Stünde sie an beiden,
        // erschiene sie zweimal, und die zweite käme aus einem Lauf, der gar
        // nicht mehr auffrischt.
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        $this->assertStringNotContainsString(
            'Paketlisten werden aufgefrischt',
            $skript,
            'Auch `apt-run` meldet noch eine Auffrischung — es frischt aber keine mehr auf.',
        );
    }

    /**
     * Was das Skript aus apts Ausgabe liest, ist eine Marke und kein Satz.
     *
     * **Befund 2 wollte den Klartext lesen** (`docs/94 §4`): Drei Zeilen über
     * dem Urteil steht *„srvpanel ist schon die neueste Version (…)"*, und das
     * sah nach der fehlenden Auskunft aus. Gemessen am 1. September 2026 gegen
     * den deutschen Katalog von apt 2.8.3:
     *
     *     „%s is already the newest version (%s)."  -> übersetzt
     *     „Inst "                                   -> 0 von 387 Einträgen
     *
     * Auf `cloudsrv24` kommt der Satz deutsch heraus, im Container englisch.
     * Ein Ausdruck darüber hätte auf genau einer der beiden Maschinen
     * funktioniert — und auf der anderen wortlos nichts gefunden.
     *
     * > **Ein Satz, den apt übersetzt, ist keine Schnittstelle.**
     *
     * Gehalten wird die Form und keine Liste: Was hier aus apts Ausgabe gelesen
     * wird, steht am **Zeilenanfang** und ist **ein grossgeschriebenes Wort** —
     * so schreibt apt seine Marken (`Inst`, `Conf`, `Remv`), und so schreibt es
     * keine Prosa.
     */
    public function test_what_the_script_reads_from_apt_is_a_marker_and_not_prose(): void
    {
        // Ohne die Kommentarzeilen: Der Kopf dieser Datei **erklärt**, warum
        // kein Klartext gelesen wird, und nennt dabei den Klartext.
        $skript = $this->withoutHashComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run')
        );

        $this->assertSame(
            1,
            preg_match_all("/\\bgrep\\b[^|\n]*?'([^']*)'/", $skript, $treffer) > 0 ? 1 : 0,
            'In `apt-run` steht kein `grep` mit einem Muster mehr — dann prüft dieser Test nichts.',
        );

        $muster = $treffer[1];

        $this->assertGreaterThan(
            1,
            count($muster),
            'Es wird kaum ein Muster gefunden — dann prüft dieser Test nichts.',
        );

        foreach ($muster as $eines) {
            $this->assertMatchesRegularExpression(
                '/^\^[A-Z][A-Za-z-]*\s*$/D',
                $eines,
                sprintf(
                    '`apt-run` liest apts Ausgabe mit dem Muster %s. Das ist keine Marke am Zeilenanfang, '
                    .'sondern Prosa — und apt übersetzt Prosa: Der Ausdruck prüft dann die Sprache des '
                    .'Servers und nicht seinen Zustand.',
                    var_export($eines, true),
                ),
            );
        }
    }
}
