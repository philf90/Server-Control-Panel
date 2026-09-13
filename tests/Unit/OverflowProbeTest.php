<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Prüfkörper der Überlaufmessung hängt am Fenster und nicht an einer Zahl.
 *
 * **Das ist Befund 22 aus `docs/59`, und er hatte bis zum 19. August 2026
 * keinen Wächter** — die Messvorschrift stand in einem Dokument, und kein Test
 * liest ein Dokument. Genau das ist der Fehler, der in diesem Projekt am
 * häufigsten wiederkehrt: eine Regel, die auf etwas verweist, ohne dass ein
 * Typ, ein Test oder ein Werkzeug den Bezug prüft.
 *
 * **Was schiefging.** Der Prüfkörper war ein fester Block von 900 px. Bei
 * 390 px erzeugte er die erwarteten 510 px Überlauf; bei 1440 px passte er
 * hinein, und die Gegenprobe meldete `0` — also denselben Wert, den auch eine
 * kaputte Messung liefert. Die Bilderrunde von `docs/59` war damit nur an der
 * schmalen Breite als arbeitsfähig belegt.
 *
 * > **Eine Gegenprobe, deren Ausschlag von der Breite abhängt, ist bei der
 * > grösseren Breite keine.**
 *
 * **Und die berichtigte Vorschrift aus `docs/58 §12` reichte auch noch nicht.**
 * Sie band den Prüfkörper an `clientWidth + 200`. Am 19. August 2026 im echten
 * Chromium gemessen: Auf einer Seite, die *schon* schiebt, ist er damit nicht
 * mehr das Breiteste, und der Ausschlag fällt wieder auf `0` — ausgerechnet auf
 * der kaputten Seite, wo die Messung ihre Arbeitsfähigkeit am nötigsten belegen
 * müsste. Gebunden wird deshalb an `scrollWidth`.
 *
 * > **Ein Prüfkörper, der nur auf der heilen Seite ausschlägt, belegt die
 * > Messung dort, wo sie niemand braucht.**
 *
 * **Und die Erwartung gehört daneben.** Ein Prüfkörper von `clientWidth + 200`
 * muss genau 200 ergeben. Steht die Zahl nicht dabei, ist jedes Ergebnis
 * plausibel — und eine Gegenprobe, deren Ergebnis niemand vorher kennt,
 * schliesst nichts aus.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 */
final class OverflowProbeTest extends TestCase
{
    /** Die Messvorschrift, deren eigene Felder dieser Wächter liest. */
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/tests/bilder-messen.js');
    }

    /**
     * Jedes Messmittel, das eine **gerenderte Seite** ausliest.
     *
     * **Gefunden und nicht aufgezählt.** Seit dem 6. September 2026 gibt es
     * mehr als eines — `bilder-messen.js` für den Überlauf, `baender-messen.js`
     * für die Höhe eines Streifens (`docs/104 §3`) und seit dem 13. September
     * `kleben-messen.js` für die klebende Nummernspalte (`docs/916 §10`). Eine
     * Liste im Test nennt, woran der Schreiber gerade dachte; das nächste
     * stünde nicht darin, und seine Regeln wären ungeprüft.
     *
     * **Unterschieden wird an `querySelector` und nicht an einer der geprüften
     * Regeln.** Der erste Wurf nahm jedes `tests/*-messen.js` und meldete
     * `mandant-messen.js` und `takt-messen.js` — die fragen Routen ab und sehen
     * keine Seite an; für sie gibt es weder Stand noch gedruckte Zeile noch
     * einen zweiten Lauf, den man verweigern müsste. Das Merkmal auf
     * `scrollWidth` zu legen wäre der entgegengesetzte Fehler gewesen:
     *
     * > **Ein Wächter, der seine Prüflinge an der Regel auswählt, die er
     * > prüft, findet nur die, die sie schon einhalten.**
     *
     * @return array<string, string> Dateiname => Quelltext
     */
    private function instruments(): array
    {
        return $this->scripts(
            'querySelector',
            3,
            'Es wurden weniger als drei Messmittel gefunden — das Muster laeuft ins Leere.',
        );
    }

    /**
     * Die Teilmenge davon, die einen **Prüfkörper in die Seite setzt**.
     *
     * **Das ist Befund 4 aus `docs/916 §10`, und er ist die Umkehrung der Falle
     * darüber.** Bis zum 13. September 2026 gab es nur diese eine Menge, und ihr
     * Merkmal war `document.body.append(`. Damit galten **alle** Regeln nur für
     * die Messmittel, die etwas einsetzen — `kleben-messen.js` misst eine Seite,
     * setzt aber nichts ein, und war von Stand, gedruckter Zeile und
     * Wiederholungssperre nicht erreichbar. Es hielt alle drei; belegt war das
     * nicht, und die nächste Fassung hätte sie verlieren können, ohne dass
     * etwas rot wird.
     *
     * > **Ein Wächter, dessen Auswahlmerkmal enger ist als seine Regel, prüft
     * > eine Teilmenge und liest sich wie eine Zusage über alle.**
     *
     * Hier bleibt, was ohne Prüfkörper keinen Gegenstand hat: dass er an der
     * Seite hängt, dass er keine feste Breite ist, und dass seine Gegenprobe in
     * der gedruckten Zeile steht.
     *
     * @return array<string, string> Dateiname => Quelltext
     */
    private function probes(): array
    {
        return $this->scripts(
            'document.body.append(',
            2,
            'Es wurden weniger als zwei Pruefkoerper gefunden — das Muster laeuft ins Leere.',
        );
    }

    /**
     * Die Messmittel, deren Quelltext ein Merkmal enthält.
     *
     * Die Untergrenze steht als Argument und nicht als feste Zahl: Die beiden
     * Mengen sind verschieden gross, und eine gemeinsame Zahl wäre für die eine
     * zu hoch und für die andere zu niedrig.
     *
     * @return array<string, string> Dateiname => Quelltext
     */
    private function scripts(string $merkmal, int $mindestens, string $satz): array
    {
        $gefunden = [];

        foreach ((array) glob(dirname(__DIR__, 2).'/tests/*-messen.js') as $pfad) {
            $quelltext = (string) file_get_contents((string) $pfad);

            if (! str_contains($quelltext, $merkmal)) {
                continue;
            }

            $gefunden[basename((string) $pfad)] = $quelltext;
        }

        // Untergrenze: Läuft das Muster ins Leere, prüft die Schleife nichts —
        // und eine leere Schleife ist grün.
        $this->assertGreaterThanOrEqual($mindestens, count($gefunden), $satz);

        return $gefunden;
    }

    /**
     * Das Ergebnisobjekt eines Messmittels — der Text ab seiner Marke.
     *
     * **Bis zum 13. September 2026 stand an den drei Aufrufstellen
     * `strstr($quelltext, '  return {')`, und das war keine Marke, sondern ein
     * Zufall.** Zwei Einrückungen treffen dasselbe Muster: In
     * `bilder-messen.js` fand es den `return` der **Gegenprobe** — vier
     * Leerzeichen, und die zwei gesuchten stecken darin — und damit den ganzen
     * Rest der Datei. Jede Prüfung „steht das im Ergebnis" hiess dort in
     * Wahrheit „steht das irgendwo danach".
     *
     * > **Eine Marke, die auch etwas anderes trifft, ist keine — und solange
     * > sie zu viel trifft, fällt es niemandem auf.**
     *
     * Aufgedeckt hat es der erweiterte Zugriff aus Befund 4:
     * `kleben-messen.js` baut sein Ergebnis als `const ergebnis` und hat
     * überhaupt kein `return {`, also kam eine leere Zeichenkette heraus — und
     * die ist wenigstens ehrlich rot. Beide Schreibweisen sind zulässig;
     * gesucht wird die, die dasteht.
     *
     * `result()` heisst er nicht: Diesen Namen hat `PHPUnit\Framework\TestCase`
     * als `final` vergeben, und die Klasse stirbt beim Laden. Genau dafür gibt
     * es {@see BaseMethodClashTest} — hier hat er beim ersten Lauf zugebissen.
     */
    private function returnedObject(string $quelltext): string
    {
        foreach (['const ergebnis = {', "\n  return {"] as $marke) {
            $ab = strstr($quelltext, $marke);

            if ($ab !== false) {
                return $ab;
            }
        }

        return '';
    }

    /**
     * Die beiden Mengen sind nicht dieselbe.
     *
     * **Das ist die Zusicherung unter Befund 4 und nicht seine Behebung.** Wer
     * das Merkmal der weiten Menge auf das der engen zurückstellt, hat den
     * Befund wiederhergestellt — und jeder Fall darüber bliebe grün, weil er
     * dann eben nur die Prüfkörper prüft. Gemessen wird deshalb die Differenz:
     * Es gibt mindestens ein Messmittel, das eine Seite ausliest, ohne etwas
     * einzusetzen.
     *
     * > **Ein Wächter, der eine Verengung nicht bemerkt, ist nach ihr genauso
     * > grün wie davor.**
     */
    public function test_a_reading_instrument_need_not_insert_anything(): void
    {
        $ohnePruefkoerper = array_diff_key($this->instruments(), $this->probes());

        $this->assertNotSame(
            [],
            $ohnePruefkoerper,
            'Jedes gefundene Messmittel setzt einen Pruefkoerper ein — dann ist die weite Menge '.
            'die enge, und die drei Regeln darueber gelten wieder nur fuer die Haelfte (Befund 4 '.
            'aus docs/916 §10).',
        );
    }

    /**
     * Der Prüfkörper wird aus der Seite gerechnet und nicht hingeschrieben.
     */
    public function test_the_probe_is_bound_to_the_page(): void
    {
        foreach ($this->probes() as $datei => $quelltext) {
            $this->assertMatchesRegularExpression(
                '/scrollWidth \+ \d+/',
                $quelltext,
                implode("\n", [
                    "In {$datei} haengt der Pruefkoerper nicht mehr an der Breite der Seite.",
                    'Ein fester Block schlaegt unterhalb seiner Groesse aus und darueber',
                    'nicht — und liefert dort dieselbe Null wie eine kaputte Messung.',
                ]),
            );
        }
    }

    /**
     * Und er ist keine feste Breite, die zufällig einmal passt.
     *
     * **Der Bruch, den dieser Fall fangen soll, ist der Rückweg:** Jemand
     * schreibt `width:900px` hin, weil es bei 390 px funktioniert. Deshalb
     * steht hier nicht „irgendwo kommt `clientWidth` vor", sondern: In der
     * Zeile, die die Breite setzt, steht keine feste Zahl.
     */
    public function test_the_probe_has_no_fixed_width(): void
    {
        foreach ($this->probes() as $datei => $quelltext) {
            preg_match('/\.style\.cssText = `([^`]*)`/', $quelltext, $treffer);

            $this->assertCount(2, $treffer, "In {$datei} gibt es die Zeile nicht mehr, die den Pruefkoerper breit macht.");

            $this->assertStringContainsString(
                'scrollWidth',
                $treffer[1],
                "In {$datei} ist die Breite des Pruefkoerpers eine feste Zahl — dann gilt die Gegenprobe nur unterhalb dieser Zahl.",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/width:\s*\d+px/',
                $treffer[1],
                "In {$datei} steht die Breite des Pruefkoerpers als feste Zahl da (Befund 22 aus docs/59).",
            );
        }
    }

    /**
     * Die Gegenprobe ist Teil des Ergebnisses und kein eigener Aufruf.
     *
     * **Sonst wird sie vergessen.** Eine Messung, die ohne sie ein Ergebnis
     * liefert, wird irgendwann ohne sie gefahren — und `dokument: 0` ohne
     * Gegenprobe ist keine Aussage, sondern zwei mögliche.
     */
    public function test_the_counter_check_is_part_of_the_result(): void
    {
        $quelltext = $this->source();

        $ergebnis = $this->returnedObject($quelltext);

        $this->assertNotSame('', $ergebnis, 'Die Messung gibt nichts mehr zurueck.');

        $this->assertStringContainsString(
            'gegenprobe: gegenprobe()',
            $ergebnis,
            'Die Gegenprobe steht nicht im Ergebnis — dann laeuft die Messung irgendwann ohne sie.',
        );

        $this->assertStringContainsString(
            'erwartet: 200',
            $quelltext,
            'Der erwartete Ausschlag steht nicht neben dem gemessenen — dann ist jedes Ergebnis plausibel.',
        );
    }

    /**
     * Gemessen wird jedes Element und keine Liste von Selektoren.
     *
     * Eine Liste nennt, woran man beim Schreiben gerade dachte. Der Fund von
     * P5c Schritt 5 steckte in einer Textzelle, der von Schritt 4 in einem
     * Bereichstitel — beides Stellen, die in keiner Liste gestanden hätten.
     */
    public function test_every_element_is_measured(): void
    {
        $this->assertStringContainsString(
            "querySelectorAll('*')",
            $this->source(),
            'Die Messung sieht nur an genannten Stellen nach — dann misst sie das Erinnerungsvermoegen.',
        );
    }

    /**
     * Ein Fund nennt seinen Ort und nicht bloss seine Bauart.
     *
     * **Was schiefging.** Bis zum 19. August 2026 stand als Kennzeichen nur
     * `Marke.Klassen` da. Für einen Baustein mit Klasse genügt das — für ein
     * `div` ohne jede Klasse heisst die Zeile dann `div`, und genau diese Zeile
     * stand in der Bilderrunde viermal in vier Ansichten, ohne irgendwohin zu
     * zeigen. Vier Messungen, und keine sagte, welches Element gemeint war.
     *
     * > **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**
     *
     * Geprüft wird deshalb beides: der Weg von `body` herab und die ersten
     * Zeichen des Markups. Der Weg allein reicht nicht — `div > div > div`
     * zeigt zwar auf ein Element, sagt aber nicht, was drinsteht.
     */
    public function test_a_finding_names_where_it_is(): void
    {
        $ergebnis = (string) strstr($this->source(), 'roller.push({');

        $this->assertNotSame('', $ergebnis, 'Die Messung sammelt keine Funde mehr ein.');

        $this->assertStringContainsString(
            'pfad: pfad(element)',
            $ergebnis,
            'Ein Fund nennt seinen Weg nicht — ein Element ohne Klasse heisst dann nur „div".',
        );

        $this->assertStringContainsString(
            'anfang: element.outerHTML',
            $ergebnis,
            'Ein Fund zeigt sein Markup nicht — dann sagt der Weg zwar wo, aber nicht was.',
        );
    }

    /**
     * Jede Messung nennt den Stand des Messmittels, das sie erzeugt hat.
     *
     * **Was schiefging.** Dieses Skript lebt in der Konsole und verschwindet bei
     * jedem Neuladen — es kommt also aus der Zwischenablage zurück. Am
     * 19. August 2026 kam es mit den Feldern von vorgestern wieder, während die
     * Frage, die es beantworten sollte, gerade an den neuen hing. Der Ausdruck
     * sah dabei aus wie ein Ergebnis: eine Zahl, ein Fund, keine Fehlermeldung.
     *
     * > **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt,
     * > ist so alt wie die Zwischenablage und sagt es nicht.**
     *
     * Geprüft wird deshalb dasselbe wie bei `breite` und `thema`: dass die
     * Herkunft im Ergebnis steht. Ob der Stand gepflegt ist, kann kein Test
     * sagen — dass er dasteht, schon.
     */
    public function test_a_reading_names_the_instrument(): void
    {
        foreach ($this->instruments() as $datei => $quelltext) {
            /*
             * `\w*STAND` und nicht `STAND`: Zwei Messmittel, die in dieselbe
             * Konsole geklebt werden, dürfen den Namen nicht teilen — ein
             * zweites `const STAND` wirft, und dann misst gar nichts mehr.
             *
             * **Und ein Buchstabe hinter dem Datum ist erlaubt, seit der
             * 13. September 2026 drei Fassungen an einem Tag gebraucht hat.**
             * Der erste Wurf verlangte das blosse Datum, und der erweiterte
             * Zugriff aus Befund 4 hat ihn sofort daran rot gemacht:
             * `kleben-messen.js` trägt `2026-09-13c`. Nachgesehen war nicht der
             * Stand falsch, sondern der Ausdruck — ein Datum allein kann zwei
             * Fassungen desselben Tages nicht auseinanderhalten, und genau
             * dafür gibt es das Feld.
             *
             * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
             * > Gewohnheit und nicht die Regel.**
             */
            $this->assertMatchesRegularExpression(
                "/const \\w*STAND = '\\d{4}-\\d{2}-\\d{2}[a-z]?'/",
                $quelltext,
                "{$datei} fuehrt keinen Stand — dann traegt keine Zeile ihre Herkunft.",
            );

            $ergebnis = $this->returnedObject($quelltext);

            $this->assertNotSame('', $ergebnis, "In {$datei} gibt es kein Ergebnisobjekt mehr.");

            $this->assertMatchesRegularExpression(
                '/stand: \\w*STAND/',
                $ergebnis,
                "In {$datei} steht der Stand nicht im Ergebnis — dann sieht eine alte Messung aus wie eine neue.",
            );
        }
    }

    /**
     * Jedes Messmittel druckt sein Urteil als **eine Zeile**.
     *
     * **Am 6. September 2026 hat das eine Bilderrunde gekostet.** Die Konsole
     * klappt ein zurückgegebenes Objekt auf fünf Schlüssel zusammen; in allen
     * vier Lagen stand `gegenprobe: {…}` da und `schiebt` gar nicht. Sichtbar
     * war `dokument: 0` — also derselbe Wert, den auch eine Messung liefert,
     * die nichts misst.
     *
     * > **Ein Objekt in der Konsole zeigt fünf Schlüssel und klappt den Rest
     * > weg — und was man abschreibt, ist dann eine Auswahl, die niemand
     * > getroffen hat.**
     *
     * `baender-messen.js` hatte die Zeile am selben Vormittag bekommen und
     * `bilder-messen.js` nicht. Dass die Regel jetzt hier steht und nicht in
     * einem Kommentar, ist der Unterschied:
     *
     * > **Ein Fehler, den man an einer Stelle behoben hat, ist an der nächsten
     * > wieder da, wenn die Behebung nicht die Regel wurde.**
     *
     * Gefordert ist die **Gegenprobe** in der Zeile und nicht irgendein Druck:
     * Sie ist der Wert, ohne den die übrigen nichts bedeuten.
     */
    public function test_every_instrument_prints_one_line(): void
    {
        foreach ($this->instruments() as $datei => $quelltext) {
            preg_match_all('/console\\.log\\((.*?)\\)\\n/s', $quelltext, $treffer);

            $this->assertNotEmpty(
                $treffer[1],
                "{$datei} druckt sein Urteil nicht — dann klappt die Konsole das Objekt auf fuenf Schluessel zusammen.",
            );

        }
    }

    /**
     * Und bei einem Prüfkörper steht die Gegenprobe in dieser Zeile.
     *
     * **Getrennt vom Fall darüber, seit es Messmittel ohne Prüfkörper gibt.**
     * Die gedruckte Zeile braucht jedes; eine Gegenprobe hat nur, wer etwas
     * einsetzt. Zusammen in einem Fall wäre die eine Hälfte für
     * `kleben-messen.js` nicht erfüllbar — und die übliche Antwort darauf ist
     * eine Ausnahmeliste, also der Anfang vom Ende der Regel.
     *
     * > **Eine Regel, die für einen Teil ihrer Prüflinge keinen Gegenstand hat,
     * > wird nicht weicher gelesen, sondern geteilt.**
     *
     * Was dieser Wächter für die Messmittel **ohne** Prüfkörper nicht halten
     * kann: dass die *richtigen* Werte in der Zeile stehen. Welcher Wert ohne
     * die übrigen nichts bedeutet, weiss nur, wer die Messung kennt — bei
     * `kleben-messen.js` ist es `misst`, und das steht in seinem Kopf und nicht
     * hier, weil eine Liste im Test die schlechtere Zusage wäre.
     */
    public function test_a_probe_names_its_counter_check_in_the_printed_line(): void
    {
        foreach ($this->probes() as $datei => $quelltext) {
            preg_match_all('/console\\.log\\((.*?)\\)\\n/s', $quelltext, $treffer);

            $this->assertStringContainsString(
                'gegenprobe',
                implode("\n", $treffer[1]),
                "In {$datei} steht die Gegenprobe nicht in der gedruckten Zeile — ohne sie bedeuten die uebrigen Werte nichts.",
            );
        }
    }

    /**
     * Ein zweiter Aufruf ohne Neuladen wird verweigert, und zwar geworfen.
     *
     * **Die Falle ist gemessen** (`docs/96 §8`): Der Prüfkörper bemisst sich am
     * gegenwärtigen `scrollWidth`, und beim zweiten Aufruf in derselben
     * geladenen Seite ist sein eigener Block von eben schon Teil des Masses —
     * heraus kommen 400 statt 200.
     *
     * Bis zum 6. September 2026 war die Antwort darauf ein Kommentar an den
     * Menschen: „vor jeder Messung neu laden".
     *
     * > **Ein Prüfmittel, das seine eigene Falle nur beschreibt, überlässt sie
     * > dem, der sie am wenigsten sehen kann — dem Leser des Ergebnisses.**
     *
     * **Geworfen und nicht zurückgegeben.** Ein Rückgabewert, der eine
     * Weigerung ausdrückt, steht in derselben Spalte wie ein Ergebnis und wird
     * abgeschrieben; eine Ausnahme in der Konsole ist unübersehbar.
     */
    public function test_a_second_run_is_refused(): void
    {
        foreach ($this->instruments() as $datei => $quelltext) {
            $this->assertMatchesRegularExpression(
                '/let \\w*[gG]elaufen = false/',
                $quelltext,
                "{$datei} merkt sich nicht, dass es schon gemessen hat (docs/96 §8).",
            );

            $this->assertMatchesRegularExpression(
                '/if \\(\\w*[gG]elaufen\\) \\{\\s*throw new Error\\(/',
                $quelltext,
                "{$datei} verweigert den zweiten Aufruf nicht — oder gibt die Weigerung zurueck, statt sie zu werfen.",
            );
        }
    }

    /**
     * Eine Beschriftung nur für die Vorlesesoftware wird gezählt, nicht gelistet.
     *
     * **Befund 2 aus `docs/66`.** Die übliche Technik dafür ist
     * `width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)`; ein
     * solcher Kasten hat **immer** `scrollWidth > clientWidth`, und `hidden`
     * steht nicht in der Liste der erlaubten Roller. Gemessen im echten
     * Chromium gegen das gebaute Stylesheet, vorher und nachher:
     *
     *     vorher:   schiebt: [span.sr 42, span.sr 42, thead 379, tr 351, div 287]
     *     nachher:  schiebt: [div 287]   versteckt: 4
     *
     * > **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein
     * > Urteil.**
     *
     * ## Drei Eigenschaften, ohne die der Filter falsch wäre
     *
     * **Beide Merkmale zusammen.** Ein Filter über `overflow: hidden` allein
     * nähme die halbe Messung mit — jeder Rollbehälter fiele darunter.
     *
     * **Die Vorfahren gehören dazu.** Bei `.stacks thead` trägt der Kopf die
     * Klippung; das `tr` darin ist 1 px breit, weil sein Behälter es ist, und
     * klippt selbst nicht. Ohne den Weg nach oben bliebe die halbe Geisterzeile
     * stehen.
     *
     * **Und die Zahl steht daneben.** Eine Messung, die etwas weglässt, sagt
     * wie viel — sonst liest sich eine kurze Liste wie eine heile Seite.
     *
     * > **Kein stiller Deckel: Wer die Sicht begrenzt, nennt die Zahl dazu.**
     */
    public function test_a_screen_reader_label_is_counted_and_not_listed(): void
    {
        $quelltext = $this->source();

        /*
         * **Gelesen wird der Filter selbst und nicht die ganze Datei.** Stünde
         * `clipPath` irgendwo sonst — in einer Erklärung zum Beispiel —, wäre
         * dieser Wächter grün für einen Filter, der es gar nicht prüft. Das ist
         * derselbe Fehler wie bei `docs/62` Punkt 12b, wo ein Wächter einen
         * Satz suchte statt seiner Erreichbarkeit.
         */
        $filter = $this->between($quelltext, 'const nurFuerVorlesen', 'const roller');

        $this->assertNotSame('', $filter, 'Der Filter fuer versteckte Beschriftungen ist fort — dann prueft dieser Waechter nichts.');

        foreach ([
            "clipPath !== 'none'" => 'Der Filter fragt nicht mehr nach der Klippung.',
            'clientWidth <= 1' => 'Der Filter fragt nicht mehr nach der Breite.',
            'clientHeight <= 1' => 'Der Filter fragt nicht mehr nach der Hoehe.',
        ] as $merkmal => $satz) {
            $this->assertStringContainsString($merkmal, $filter, $satz.
                ' Verlangt sind beide Merkmale zusammen — geklippt **und** auf einen Punkt '.
                'zusammengezogen. Ueber `overflow: hidden` allein naehme er die halbe Messung mit.');
        }

        $this->assertStringContainsString(
            'parentElement',
            $filter,
            'Der Filter sieht nicht mehr bei den Vorfahren nach. Bei `.stacks thead` traegt nur der '.
            'Kopf die Klippung, und das `tr` darin bliebe als Geisterzeile stehen.',
        );

        $this->assertStringContainsString(
            'versteckt,',
            $this->returnedObject($quelltext),
            'Die Zahl der uebersprungenen Kaesten steht nicht mehr im Ergebnis. Dann liest sich '.
            'eine kurze Liste wie eine heile Seite.',
        );
    }

    /** Das Stück zwischen zwei Marken — leer, wenn eine davon fehlt. */
    private function between(string $quelle, string $von, string $bis): string
    {
        $anfang = strpos($quelle, $von);
        $ende = $anfang === false ? false : strpos($quelle, $bis, $anfang);

        if ($anfang === false || $ende === false) {
            return '';
        }

        return substr($quelle, $anfang, $ende - $anfang);
    }
}
