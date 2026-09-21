<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jeder Eingriff in `tests/waechter-brechen.sh` greift seine Zieldatei noch.
 *
 * **Der Wächter über dem Werkzeug, das die Wächter prüft.** Das Skript hat
 * dafür `griff_datei`: Es merkt sich die Datei vor dem Eingriff und vergleicht
 * danach. Nur läuft das erst, wenn das Skript läuft, und das braucht ein
 * `vendor/` mit PHPUnit — in diesem Container gibt es keins, und in der CI läuft
 * das Skript nicht. Zwischen zwei Läufen kann also ein Eingriff still ins Leere
 * zeigen, und niemand erfährt es.
 *
 * **Genau das war in P5 der Fall, viermal unter 129 Eingriffen:**
 *
 *   packaging/bin/srvpanel               `|tls|vhost|`   — seit P4 stehen `dns` und `db` dazwischen
 *   app/Support/Tls/CertificateLifecycle `renew_after`   — die Zeile steht in CertificateRecord
 *   app/Support/Tls/CertificateLifecycle `coversAll`     — die Prüfung steht in CertificateChoice
 *   agent/src/Acme/Dns/Packet            `0xC0`          — das Lesen steht in Dns\Name
 *
 * Keiner davon war ein Fehler beim Schreiben: In allen vier Fällen ist der Code
 * **umgezogen**, und der Eingriff blieb stehen. Das ist wortwörtlich das Muster
 * aus CLAUDE.md — eine Zeichenkette, die auf etwas verweist, ohne dass jemand
 * den Bezug prüft — nur an der letzten Stelle, an der man es vermutet: im
 * Werkzeug gegen genau dieses Muster.
 *
 * **Und der Preis ist besonders hoch**, denn ein toter Eingriff ist schlimmer
 * als ein fehlender. Er sieht aus, als wäre die Regel abgesichert. Der Wächter,
 * den er prüfen soll, war vielleicht nie rot.
 *
 * Geprüft wird der Bezug und nicht die Wirkung: Ob der Wächter danach zubeisst,
 * kann nur ein Lauf des Skripts sagen. Was hier gemessen wird, ist die
 * Voraussetzung dafür.
 *
 * **Der Bruch zu diesem Test steht nicht im Skript, und das geht auch nicht.**
 * Er müsste das Skript selbst ändern; `wiederherstellen()` fasst `tests/` nicht
 * an, und täte es das, nähme es sich mitten im Lauf die eigene Grundlage weg.
 * Er wird deshalb von Hand geführt, und so:
 *
 *     # einen Eingriff auf seinen alten Ort zurückdrehen
 *     sed -i "s/'|db|vhost|', '|db|'/'|tls|vhost|', '|tls|'/" tests/waechter-brechen.sh
 *     ./vendor/bin/phpunit --filter BreakScriptTest      # muss rot sein
 *     git checkout -- tests/waechter-brechen.sh
 *
 * Am 8. August 2026 so gefahren: rot mit „packaging/bin/srvpanel: |tls|vhost|",
 * danach wieder grün.
 */
final class BreakScriptTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Aneinandergereihte Zeichenketten zu einer zusammenziehen.
     *
     * **Die Lücke, die dieser Wächter über sich selbst benannt hat — und die am
     * 22. August 2026 einen roten Lauf gekostet hat.** Python reiht benachbarte
     * Zeichenketten aneinander, und dreizehn Blöcke im Skript nutzen das für
     * mehrzeilige Stellen:
     *
     *     s.replace(
     *         "        foreach (…) {\n"
     *         "            if (…) {\n",
     *         …
     *     )
     *
     * Der Ausdruck darunter liest davon nur das erste Stück. Ein Eingriff, der
     * `resolver->txt()` suchte — seit P7 heisst die Methode `records()` —, galt
     * damit als heil: Sein erstes Stück (`foreach ($servers as $server) {`)
     * stand noch da, der Rest nicht. Im Lauf änderte er nichts, `griff_datei`
     * meldete es, und dieser Wächter war grün.
     *
     * > **Ein Wächter, der eine Zeichenkette nur bis zur ersten Naht liest,
     * > prüft den Anfang und nennt es das Ganze.**
     *
     * Zusammengezogen wird nur, was auf einer eigenen Zeile steht und dieselbe
     * Anführung trägt — was sich erst zur Laufzeit zusammensetzt (`alt + neu`,
     * `%`-Formatierung), bleibt weiter unlesbar und zählt weiter als nicht
     * vorhanden.
     */
    private function joinAdjacentStrings(string $block): string
    {
        do {
            $vorher = $block;

            $block = (string) preg_replace(
                '/"((?:[^"\\\\]|\\\\.)*)"\s*\n\s*"((?:[^"\\\\]|\\\\.)*)"/',
                '"$1$2"',
                $block,
            );
        } while ($block !== $vorher);

        return $block;
    }

    /**
     * Die Zeichenketten, die ein Eingriff in seiner Zieldatei sucht.
     *
     * Gelesen werden die eingebetteten Python-Blöcke: `p = '<datei>'` nennt das
     * Ziel, jedes `s.replace(<alt>, …)` den gesuchten Text. Beides steht im
     * Skript in derselben, immer gleichen Form — sie hier nachzubauen ist
     * billiger, als das Skript umzuschreiben, damit es sich selbst auskunftsfähig
     * macht.
     *
     * @return list<array{file: string, needle: string}>
     */
    private function interventions(): array
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        /*
         * **Beide Marken, und das ist ein Fund dieses Tests über sich selbst.**
         * Hier stand nur `PY2`. Neunzehn Blöcke im Skript tragen `PY` — und die
         * waren für diesen Wächter nicht vorhanden. Aufgefallen ist es am
         * 10. August 2026 beim Nachrechnen von Hand: Ein Eingriff in
         * `CertificateLifecycle` suchte eine Bedingung, die der zweite Wurf von
         * P4 längst durch `choice->satisfied()` ersetzt hatte, und dieser Test
         * war grün.
         *
         * > **Ein Wächter, der einen Teil seines Gegenstands nicht liest, meldet
         * > für den Rest „alles in Ordnung".**
         *
         * Die Rückreferenz `\1` schliesst den Block mit derselben Marke, mit der
         * er aufging — sonst endete ein `PY2`-Block an der ersten Zeile `PY`
         * darin.
         */
        preg_match_all("/python3 - <<'(PY2?)'\n(.*?)\n\\1\n/s", $script, $blocks);

        $found = [];

        foreach ($blocks[2] as $block) {
            if (preg_match("/^p = '([^']+)'$/m", $block, $target) !== 1) {
                continue;
            }

            /*
             * Beide Schreibweisen, die im Skript vorkommen: dreifach zitiert für
             * mehrzeilige Stellen, einfach für kurze. Die dreifache steht zuerst
             * — sonst risse die einfache Alternative das erste `"` eines
             * `"""`-Blocks an sich und läse ihn als leere Zeichenkette.
             */
            /*
             * **Und die Blöcke, die ihre Zeichenkette in einer Variablen
             * halten.** Hier stand nur der Ausdruck darunter, und der liest
             * ausschliesslich `s.replace("…", …)`. Zweiundfünfzig von 562
             * Blöcken schreiben aber
             *
             *     alt = "…"
             *     s.replace(alt, "", 1)
             *
             * — und die waren für diesen Wächter **nicht vorhanden**: weder für
             * die Frage, ob ihr Griff noch greift, noch für die, ob ihre Datei
             * im Rückweg liegt. Am 20. August ist genau daran ein Lauf
             * gescheitert: Zwei Eingriffe brachen `lang/de/validation.php`, das
             * ausserhalb des Rückwegs lag, blieben stehen und vergifteten die
             * Gegenproben dahinter. Dieser Wächter war grün.
             *
             * > **Ein Wächter, der eine Schreibweise liest, sieht die andere
             * > nicht — und meldet für sie „alles in Ordnung".**
             *
             * Aufgelöst wird **nur der blosse Name**. Steht dort
             * `alt.replace('Eintraege', 'Einträge')` — und ein Eingriff tut das,
             * um die Umlaute aus dem Shell-Skript herauszuhalten —, dann ist der
             * gesuchte Text nicht der zugewiesene. Der erste Anlauf hier hat
             * genau diesen Eingriff als tot gemeldet, obwohl er greift.
             *
             * > **Ein Wächter, der Fehlalarm gibt, wird abgeschaltet.**
             *
             * Was sich erst zusammensetzt (`alt + neu`, `%`-Formatierung, eine
             * Umformung), bleibt damit unlesbar und zählt weiter als nicht
             * vorhanden — eine Lücke, die jetzt wenigstens klein und benannt ist.
             */
            $block = $this->joinAdjacentStrings($block);

            $variablen = [];

            preg_match_all(
                '/^(\w+) = ("""(.*?)"""|"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')$/ms',
                $block,
                $zuweisungen,
                PREG_SET_ORDER,
            );

            foreach ($zuweisungen as $zuweisung) {
                $wert = ($zuweisung[3] ?? '') !== ''
                    ? $zuweisung[3]
                    : ((($zuweisung[4] ?? '') !== '') ? $zuweisung[4] : ($zuweisung[5] ?? ''));

                if ($wert !== '') {
                    $variablen[$zuweisung[1]] = $wert;
                }
            }

            preg_match_all('/s\.replace\(\s*("""(.*?)"""|"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(\w+)\s*(?=[,)]))/s', $block, $needles, PREG_SET_ORDER);

            foreach ($needles as $needle) {
                $raw = ($needle[2] ?? '') !== ''
                    ? $needle[2]
                    : ((($needle[3] ?? '') !== '') ? $needle[3] : ($needle[4] ?? ''));

                if ($raw === '' && ($needle[5] ?? '') !== '') {
                    $raw = $variablen[$needle[5]] ?? '';
                }

                if ($raw === '') {
                    continue;
                }

                $found[] = ['file' => $target[1], 'needle' => $this->unescape($raw)];
            }
        }

        /*
         * **Und die Eingriffe, die `sed` benutzen.** Hier standen nur die
         * Python-Blöcke — sechsundzwanzig Eingriffe dieses Skripts sind aber
         * ein `sed -i`, und die waren für jede Frage dieses Wächters
         * unsichtbar: ob ihr Griff noch greift, und ob ihre Datei im Rückweg
         * liegt.
         *
         * Am 20. August ist genau daran ein Lauf gescheitert. Die Beschriftung
         * „Datei anlegen" wurde zu `Datei<span class="verb"> anlegen</span>`,
         * das `sed`-Muster traf nichts mehr, und `griff_datei` meldete
         * „Eingriff hat nichts geändert" — während dieser Wächter grün stand.
         *
         * > **Ein Wächter, der eine Form von Eingriff liest, sagt über die
         * > andere Form nichts.**
         *
         * Gelesen wird die linke Hälfte eines `sed -i 's|…|…|' DATEI`.
         *
         * **Zwei Formen bleiben aussen vor, und beide sind gezählt.** Ein
         * Ausdruck mit einer Adresse davor (`0,/…/s//…/`) oder einer anderen
         * Bauart — davon gibt es **drei** — und eine linke Hälfte, die ein
         * Muster ist statt eines Textes (`^`, `$`, `.*`, `\(`) — davon
         * **zwölf**. Ein Muster liesse sich in einer Datei nicht suchen, und
         * ein Fehlalarm wäre schlimmer als eine Lücke.
         *
         * Gelesen werden damit **elf von sechsundzwanzig**; die fünfzehn
         * anderen sind gezählt und nicht verschwiegen.
         *
         * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl,
         * > die kleiner werden kann.**
         *
         * > **Ein Wächter, der Fehlalarm gibt, wird abgeschaltet.**
         *
         * Der erste Anlauf hier war zu gierig und las aus `0,/class="sections"/s//…/`
         * den Dateinamen `/s//class=`. Das ist die Sorte Fund, die einen
         * Wächter unglaubwürdig macht, bevor er einmal genützt hat.
         */
        preg_match_all('/^sed -i ([\'"])s(.)(.*?)\\2(.*?)\\2[a-z]*\\1 *\\\\?\n?\s*([^\s\'"]+)/m', $script, $seds, PREG_SET_ORDER);

        foreach ($seds as $sed) {
            $datei = trim($sed[5]);
            $suche = $sed[3];

            if (! str_contains($datei, '/')) {
                continue;
            }

            // Ein Muster mit Sonderzeichen ist kein Text — es zu suchen wäre ein
            // Fehlalarm, und ein Wächter, der Fehlalarm gibt, wird abgeschaltet.
            if (preg_match('/[\\^$*\\[\\]\\\\]|\\.\\*/', $suche) === 1) {
                continue;
            }

            $found[] = ['file' => $datei, 'needle' => $suche];
        }

        return $found;
    }

    /**
     * Ein Python-Literal in den Text, den es meint.
     *
     * **Von links nach rechts und nicht mit `str_replace`.** Der erste Anlauf
     * hier war eine Kette von Ersetzungen — `\\n` zu einem Zeilenumbruch, `\\\\`
     * zu einem Gegenschrägstrich —, und sie hat den Eingriff zu
     * `agent/src/Db/Sql.php` als tot gemeldet, obwohl er greift. Dort steht
     * genau die Zeile, in der die Unterstrich-Falle maskiert wird, also die mit
     * den meisten Gegenschrägstrichen im ganzen Repo. `str_replace` sucht auf
     * dem schon veränderten Text weiter und zählt Paare falsch ab.
     *
     * **Ein Wächter, der Fehlalarm gibt, wird abgeschaltet** — dieser Satz
     * steht in `ClassReachTest` schon einmal, und er hat sich beim ersten Lauf
     * dieses Tests sofort bestätigt.
     */
    private function unescape(string $literal): string
    {
        $out = '';
        $length = strlen($literal);

        for ($i = 0; $i < $length; $i++) {
            if ($literal[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $literal[$i];

                continue;
            }

            $next = $literal[++$i];

            $out .= match ($next) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                // Alles andere steht für sich selbst — `\\`, `\'`, `\"` und
                // jede Folge, die Python unverändert durchreicht.
                default => $next,
            };
        }

        return $out;
    }

    /**
     * Und irgendwo fährt das Skript von selbst.
     *
     * **Die Regel, die diesem Test drei Jahre gefehlt hätte.** `waechter-brechen.sh`
     * steht seit dem Optik-Rework im Repo, `BreakScriptTest` prüft seit P4, dass
     * jeder Eingriff seinen Text findet — **gelaufen ist das Skript als Ganzes
     * nie.** In der Entwicklungsumgebung fehlt `vendor/`, also wurde es von Hand
     * und stückweise gefahren, und genau dort war es dreimal in einer Woche
     * fündig: dreimal ein Wächter, der grün blieb, während seine Regel gebrochen
     * war.
     *
     * > **Ein Werkzeug, das man nur von Hand fährt, fährt irgendwann niemand
     * > mehr.**
     *
     * Gesucht wird der **Aufruf** und nicht der Dateiname: Ein Kommentar, der
     * das Skript erwähnt, ist keine Ausführung — dieselbe Unterscheidung, an der
     * `DbCommandReachTest` beim Gegenbruch gescheitert wäre.
     */
    public function test_a_workflow_runs_the_script(): void
    {
        $workflows = glob($this->root().'/.github/workflows/*.yml') ?: [];

        $this->assertNotSame([], $workflows, 'Es gibt keine Abläufe — dann prüft dieser Test nichts.');

        $running = array_filter($workflows, fn (string $file): bool => str_contains(
            (string) file_get_contents($file),
            'run: tests/waechter-brechen.sh',
        ));

        $this->assertNotSame([], $running, sprintf(
            "Kein Ablauf in .github/workflows/ führt tests/waechter-brechen.sh aus.\n\n".
            'Das Skript prüft, ob die Wächter dieses Projekts ihre Regel halten — und ist damit '.
            'selbst einer. Einer, den niemand fährt, meldet nie etwas: %s',
            implode(', ', array_map('basename', $workflows)),
        ));

        /*
         * **Und er hängt am Pull Request und nicht nur am Zeitplan.**
         *
         * Der Auslöser ist seit dem 13. August 2026 Teil der Regel, und der
         * Anlass ist gemessen: Der Wochenlauf meldete an diesem Tag drei
         * Prüfungen ohne Biss, und alle drei hingen an einem Umbau vom 11. —
         * zwei Tage lang stand im Repo ein Wächter, den es nicht mehr gab.
         *
         * > **Ein Lauf, der wöchentlich prüft, findet Fehler, die eine Woche
         * > alt sein dürfen.**
         *
         * Der Zeitplan bleibt daneben stehen: Er ist der einzige, der auch dann
         * fährt, wenn wochenlang niemand etwas ändert. Geprüft wird deshalb
         * `pull_request` und nicht „irgendein Auslöser".
         */
        foreach ($running as $file) {
            $on = (string) preg_replace(
                ['/^.*?\non:\n/su', '/\n[a-z]+:\n.*$/su'],
                '',
                (string) file_get_contents($file),
            );

            $this->assertMatchesRegularExpression(
                '/^\s*pull_request:/m',
                $on,
                sprintf(
                    "%s fährt das Bruchskript, aber nicht an einem Pull Request.\n\n".
                    'Dann findet es einen verlorenen Wächter erst im nächsten Zeitplanlauf — an dem '.
                    'Beitrag, der ihn verliert, wäre es sofort aufgefallen. Der Lauf dauert gemessen '.
                    'fünf Minuten.',
                    basename($file),
                ),
            );
        }
    }

    /**
     * Und es beweist zuerst, dass es messen kann.
     *
     * **Der erste vollständige Lauf hat 473 gesunde Wächter als kaputt
     * gemeldet.** `pruefe()` las die Ausgabe von PHPUnit als JSON — die Fassung
     * war gegen eine Umgebung geschrieben, die Werkzeugaufrufe in
     * `{"tool":…,"result":…}` verpackt, und `vendor/bin/phpunit` tut das nicht.
     * Jede Prüfung fiel in den Zweig „kein Ergebnis", und die Schlusszeile las
     * sich als Urteil über zweihundert fremde Regeln.
     *
     * > **Ein Werkzeug, das über Wächter urteilt, muss zuerst beweisen, dass es
     * > messen kann.**
     *
     * `vorpruefung` fährt deshalb vor dem ersten Eingriff einen Test, von dem
     * feststeht, dass er grün ist, und bricht sonst ab — mit der Ausgabe von
     * PHPUnit statt mit einem Befund.
     */
    public function test_the_script_proves_it_can_measure(): void
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        $this->assertStringContainsString('vorpruefung()', $script,
            'Die Selbstprobe ist weg. Ohne sie meldet ein kaputter Testaufruf jeden Wächter '
            .'dieses Projekts als gebrochen — genau so am 10. August 2026 geschehen.');

        $this->assertStringNotContainsString("json.load(sys.stdin)['result']", $script,
            'Die Ausgabe von PHPUnit wird wieder als JSON gelesen. PHPUnit schreibt kein JSON; '
            .'dieser Ausdruck trifft nie und meldet statt dessen „kein Ergebnis".');
    }

    /**
     * Und jeder eingebettete Block ist überhaupt lauffähig.
     *
     * **Der Fund des zweiten vollständigen Laufs.** Der Eingriff zu
     * `EngineDefaultTest` enthielt eine Zeichenkette mit einem echten
     * Zeilenumbruch zwischen einfachen Anführungszeichen — kein gültiges
     * Python. Der Block brach mit einem Syntaxfehler ab, die Datei blieb
     * unberührt, und das Skript meldete „Eingriff hat nichts geändert".
     *
     * **Der Test darüber sah davon nichts.** Er prüft, ob der gesuchte Text in
     * der Zieldatei vorkommt — und weil sein Ausdruck über Zeilen hinweg passt,
     * fand er ihn. Ein Eingriff kann also gleichzeitig „greift" und „läuft
     * nicht" sein.
     *
     * > **Ein Wächter, der den Inhalt prüft, hat nichts über die Ausführbarkeit
     * > gesagt.**
     *
     * Gefragt wird deshalb der Interpreter selbst: `ast.parse` sagt, ob der
     * Block Python ist. Fehlt `python3`, ist das kein Grund zu schweigen —
     * dann kann das Bruchskript ohnehin nichts ausrichten.
     */
    /**
     * Und das Skript selbst liest sich ein.
     *
     * ## Der Fund, der diesen Fall ausgelöst hat
     *
     * Am 26. August 2026 stand in einer Überschrift:
     *
     *     echo "── UnattendedStateTest: eine fehlende Zeile als „aus" gelesen ──"
     *
     * Deutsche Anführungszeichen stehen in diesem Repo als `„…"` — die
     * schliessende ist ein **gewöhnliches** `"`, und in einer Shell beendet sie
     * die Zeichenkette. Alles danach wurde zu etwas anderem, und `bash -n`
     * meldete den Fehler achtzig Zeilen später an einer Klammer, die nichts
     * damit zu tun hatte.
     *
     * **Die vier Überschriften daneben machen es richtig** und schreiben
     * `„aus\"`. Eine Gewohnheit, an die sich vier Stellen halten, ist trotzdem
     * keine Regel, solange die fünfte sie brechen darf.
     *
     * ## Warum die anderen Fälle das nicht gefangen haben
     *
     * Weil sie den Text **lesen** und nicht die Shell fragen: Jeder Eingriff
     * fand weiter seine Zielstelle, jeder Python-Block war gültig, jede Prüfung
     * nannte einen Test, den es gibt. Nur ausführen liess sich das Ganze nicht.
     *
     * > **Ein Bruchskript, das sich nicht einliest, prüft keine einzige
     * > Regel — und jede Prüfung darüber bleibt grün.**
     */
    /**
     * Hinter dem `exit` des Skripts steht kein Eingriff mehr.
     *
     * **Gefunden am 12. September 2026, und zwar nicht von einem Werkzeug.**
     * Zwei volle Läufe hintereinander meldeten dieselben 940 Eingriffe und
     * dieselben 2371 Zeilen — nachdem dreizehn dazugekommen waren. Sie standen
     * hinter `exit "$fehler"`: in der Datei, im Bruchskript gezählt, und nie
     * gelaufen. Die ältesten davon seit der Runde zum Prozesszustand.
     *
     * > **Zwei Läufe mit derselben Zahl nach einer Erweiterung sind kein
     * > Beleg, sondern ein Verdacht.**
     *
     * **Kein anderes Mittel hätte es gesehen.** `bash -n` parst die Zeilen und
     * sagt über Erreichbarkeit nichts; shellcheck meldete über dieselbe Datei
     * **null** `SC2317`; und die CI fährt shellcheck ohnehin nur über
     * `packaging/`. Die übrigen Fälle dieser Klasse lesen den **Text** des
     * Skripts — für sie sah ein toter Eingriff aus wie ein lebender.
     *
     * > **Ein Wächter, der den Text eines Skripts liest, sagt nichts darüber,
     * > ob die Zeile jemals an die Reihe kommt.**
     */
    public function test_nothing_stands_behind_the_exit(): void
    {
        $quelle = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        $bei = strpos($quelle, "\nexit \"\$fehler\"");
        $this->assertNotFalse($bei, implode("\n", [
            'Das Bruchskript endet nicht mehr auf `exit "$fehler"`.',
            '',
            'Ohne diesen Anker misst dieser Fall nichts — und er ist der einzige, der',
            'einen Eingriff hinter dem Ende überhaupt sehen kann.',
        ]));

        $danach = trim(substr($quelle, $bei + strlen("\nexit \"\$fehler\"")));

        $this->assertSame('', $danach, implode("\n", [
            'Hinter dem `exit` des Bruchskripts steht noch etwas:',
            '',
            substr($danach, 0, 400),
            '',
            'Diese Zeilen laufen nie. Ein Eingriff dort ist in der Datei, wird von den',
            'übrigen Fällen dieser Klasse mitgezählt — und hat noch nie eine Regel',
            'gebrochen. Angehängt wird deshalb **vor** der Bilanz und nicht ans Dateiende.',
        ]));
    }

    public function test_the_script_itself_parses(): void
    {
        $pfad = $this->root().'/tests/waechter-brechen.sh';

        $ausgabe = [];
        $status = 0;
        exec('bash -n '.escapeshellarg($pfad).' 2>&1', $ausgabe, $status);

        if ($status === 127) {
            $this->markTestSkipped('bash ist hier nicht da.');
        }

        /*
         * **Die Gegenprobe, und sie steht hier statt im Bruchskript.** Ein
         * Eingriff, der `tests/waechter-brechen.sh` selbst veränderte, liesse
         * das Skript sich beim Laufen unter den Füssen wegziehen — und
         * `test_every_touched_file_lies_on_the_way_back` nimmt es zu Recht vom
         * Rückweg aus. Die Regel wird deshalb an einer Wegwerfdatei belegt.
         *
         * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes
         * > als Null steht.**
         */
        $kaputt = tempnam(sys_get_temp_dir(), 'waechter');

        if ($kaputt === false) {
            $this->fail('Kein Platz für die Zwischendatei.');
        }

        file_put_contents($kaputt, "echo \"eine fehlende Zeile als „aus\" gelesen\"\nif [ 1 ]; then\n");

        $probe = [];
        $probeStatus = 0;
        exec('bash -n '.escapeshellarg($kaputt).' 2>&1', $probe, $probeStatus);

        @unlink($kaputt);

        $this->assertNotSame(0, $probeStatus,
            'bash -n meldet nichts an einem Skript, das nachweislich kaputt ist — dann misst dieser Fall nichts.');

        $this->assertSame(0, $status, implode("\n", array_merge(
            ['tests/waechter-brechen.sh liest sich nicht ein:'],
            $ausgabe,
            [
                '',
                'Ein Skript, das die Shell nicht parst, führt keinen einzigen Eingriff aus — und',
                'die übrigen Fälle hier bleiben grün, weil sie den Text lesen statt ihn zu fahren.',
                '',
                'Der häufigste Grund ist ein deutsches Anführungszeichen in einer Überschrift:',
                'Die schliessende ist ein gewöhnliches " und beendet die Zeichenkette. Sie gehört',
                'als \\" geschrieben.',
            ],
        )));
    }

    /**
     * Und es nimmt eine Sperre, bevor es den Arbeitsbaum anfasst.
     *
     * ## Der Fund, der diesen Fall ausgelöst hat
     *
     * Am 26. August 2026 lief das Skript im Hintergrund, während daneben
     * weitergearbeitet wurde. `wiederherstellen()` fährt nach **jedem** Eingriff
     * ein `git checkout --` über zwölf Bäume — und beide Richtungen gingen
     * schief, lautlos und in einem einzigen Commit:
     *
     * - Ergänzungen an `docs/81` wurden zwischen Schreiben und Committen
     *   zurückgesetzt; der Commit ging ohne sie durch und meldete Erfolg.
     * - Ein `git add -A` fiel in ein offenes Bruchfenster und nahm
     *   `app/Console/Commands/Databases.php` mit — `$fehlt = null;` statt seiner
     *   Prüfung, committet und gepusht.
     *
     * > **Ein Werkzeug, das den Arbeitsbaum herstellt, duldet keinen zweiten
     * > Schreiber** — es nimmt ihm seine Arbeit weg und schiebt ihm seine
     * > eigene unter.
     *
     * ## Was die Sperre hält, und was nicht
     *
     * Sie weist einen zweiten **Lauf** ab. Einen Menschen, der nebenher eine
     * Datei schreibt, kann sie nicht abweisen — das ist eine Regel in
     * `CLAUDE.md` und kein Mechanismus.
     *
     * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
     * > nicht als Zusage.**
     *
     * Geprüft wird deshalb genau das, was prüfbar ist: dass die Sperre
     * genommen wird, **bevor** der erste Eingriff läuft, und dass sie
     * nicht-blockierend ist. Ein `flock` ohne `-n` wartete auf den anderen
     * Lauf, statt ihn zu melden — und aus dem Befund würde eine Stunde
     * Stillstand ohne Fehlermeldung.
     *
     * > **Eine Sperre, die man zweimal nimmt, ist ein Stillstand ohne
     * > Fehlermeldung.** Derselbe Satz wie in P5b, nur eine Ebene höher.
     *
     * **Der Bruch dazu** steht nicht im Skript — er änderte das Skript selbst.
     * Von Hand, und so:
     *
     *     sed -i "s/flock -n 9/flock -s 9/" tests/waechter-brechen.sh
     *     ./vendor/bin/phpunit --filter BreakScriptTest   # muss rot sein
     *     cp <sicherung> tests/waechter-brechen.sh
     *
     * Gesichert wird mit `cp` und nicht mit `git checkout --`: Wer diesen Fall
     * bricht, hat an dieser Datei fast immer eine eigene Änderung liegen.
     */
    public function test_the_script_takes_a_lock_before_it_touches_the_tree(): void
    {
        $skript = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        /*
         * **Gefragt wird über einen Wahrheitswert und nicht über den Text.**
         * `assertMatchesRegularExpression` druckt im Fehlerfall den ganzen
         * Gegenstand — dieses Skript ist 7500 Zeilen lang, und die Meldung war
         * beim ersten Wurf 1,8 MB gross. Gemessen beim Gegenprüfen.
         *
         * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von
         * > dem, der ihn gebaut hat.**
         */
        $this->assertSame(1, preg_match('/^exec 9>/m', $skript), implode(' ', [
            'tests/waechter-brechen.sh nimmt keine Laufmarke mehr.',
            'Zwei Läufe zugleich stellen einander den Arbeitsbaum um, und der zweite misst',
            'einen Zustand, den niemand hergestellt hat.',
        ]));

        $this->assertSame(1, preg_match('/flock -n 9/', $skript), implode(' ', [
            'Die Sperre wird ohne -n genommen — dann wartet der zweite Lauf auf den ersten,',
            'statt ihn zu melden. Aus einem Befund wird so ein Stillstand ohne Fehlermeldung.',
        ]));

        /*
         * **Und sie muss vor dem ersten Eingriff stehen.** Eine Sperre hinter
         * dem ersten `git checkout --` hätte den Fall vom 26. August nicht
         * verhindert: Da war der Baum schon einmal umgestellt.
         */
        $sperre = strpos($skript, 'flock -n 9');
        $ersterGriff = strpos($skript, 'vorher_datei ');

        $this->assertIsInt($sperre);
        $this->assertIsInt($ersterGriff, implode(' ', [
            'Im Skript steht kein einziger Eingriff mehr (`vorher_datei`) —',
            'dann prüft dieser Fall nichts.',
        ]));

        $this->assertLessThan($ersterGriff, $sperre, implode(' ', [
            'Die Sperre steht hinter dem ersten Eingriff. Dann ist der Arbeitsbaum bereits',
            'einmal umgestellt worden, bevor irgendjemand abgewiesen wird.',
        ]));
    }

    public function test_every_embedded_block_is_valid_python(): void
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        preg_match_all("/python3 - <<'(PY2?)'\n(.*?)\n\\1\n/s", $script, $blocks);

        $this->assertNotSame([], $blocks[2], 'Es werden keine Blöcke gefunden — dann prüft dieser Test nichts.');

        $broken = [];

        foreach ($blocks[2] as $index => $block) {
            $file = tempnam(sys_get_temp_dir(), 'waechter');

            if ($file === false) {
                $this->fail('Kein Platz für die Zwischendatei.');
            }

            file_put_contents($file, $block);

            $output = [];
            $status = 0;
            exec(sprintf('python3 -c %s 2>&1', escapeshellarg(
                'import ast,sys; ast.parse(open(sys.argv[1], encoding="utf-8").read())'
            )).' '.escapeshellarg($file), $output, $status);

            @unlink($file);

            if ($status === 127) {
                $this->fail('python3 fehlt — ohne ihn kann tests/waechter-brechen.sh nichts brechen.');
            }

            if ($status !== 0) {
                $broken[] = sprintf(
                    'Block %d (%s): %s',
                    $index + 1,
                    trim(explode("\n", $block)[0]),
                    implode(' ', $output),
                );
            }
        }

        $this->assertSame([], $broken, sprintf(
            "Diese Blöcke in tests/waechter-brechen.sh sind kein gültiges Python:\n  %s\n\n".
            'Ein Block, der nicht läuft, ändert nichts — und der Wächter darüber meldet dann '.
            '„Eingriff hat nichts geändert", ohne den Grund zu nennen.',
            implode("\n  ", $broken),
        ));
    }

    /**
     * Und jede Prüfung nennt einen Test, den es **mit diesem Namen** gibt.
     *
     * **Der Fund vom 13. August 2026, und er lag zwei Tage da.** Am 11. August
     * hat der Fund zu `RememberPageUrl` die Datei `PreviousUrlTest` übernommen —
     * gleicher Name, gleiches Thema, **anderer Gegenstand**. Die zwei Fälle zu
     * `KeepPreviousUrl`, die vorher darin standen, sind dabei ersatzlos
     * verschwunden; die Mittelschicht blieb, ihr Eintrag in `routes/web.php`
     * blieb, der Wächter darüber war fort.
     *
     * Gemerkt hat es **nur der Lauf des Skripts**, mit „kein Test" — und der
     * läuft wöchentlich. {@see self::test_every_intervention_still_grips_its_file()}
     * sah nichts: Die *Datei* gab es noch, und der gesuchte Text stand darin.
     * `GuardReachTest` sah nichts: Die *Klasse* gab es noch.
     *
     * > **Ein Wächter, der die Klasse prüft, hat über die Methode nichts
     * > gesagt.**
     *
     * Deshalb liest dieser Test die **Zielangabe** jeder Prüfung. Er kostet
     * nichts, läuft an jedem Pull Request und meldet denselben Befund sechs Tage
     * früher.
     */
    public function test_every_check_names_a_test_that_exists(): void
    {
        /*
         * **Erst die Fortsetzungen zusammenziehen.** Eine Prüfung steht mal auf
         * einer Zeile und mal auf zweien, mit `\` am Ende — und ein Ausdruck,
         * der nur die zweite Form kennt, liest zwei Drittel des Skripts nicht.
         * Beim ersten Anlauf war es andersherum: Er fand nur die umbrochenen und
         * hätte einen falschen Klassennamen auf einer einzeiligen Prüfung
         * durchgelassen. Gemerkt hat es der Gegenbruch, der genau das versucht
         * hat.
         *
         * > **Ein Ausdruck, der eine von zwei Schreibweisen kennt, meldet für
         * > die andere „alles in Ordnung".**
         */
        $script = (string) preg_replace(
            '/\\\\\n\s*/',
            ' ',
            (string) file_get_contents($this->root().'/tests/waechter-brechen.sh'),
        );

        preg_match_all('/pruefe\s+"[^"]*"\s+(\w+)(?:::(\w+))?\s/', $script, $ziele, PREG_SET_ORDER);

        $this->assertGreaterThan(
            200,
            count($ziele),
            'Es werden kaum Prüfungen gefunden — dann prüft dieser Test nichts.',
        );

        $quellen = [];

        foreach ((array) glob($this->root().'/tests/{Feature,Unit}/*.php', GLOB_BRACE) as $path) {
            $quellen[basename((string) $path, '.php')] = (string) file_get_contents((string) $path);
        }

        $tot = [];

        foreach ($ziele as $ziel) {
            $klasse = $ziel[1];
            $methode = $ziel[2] ?? '';

            if (! isset($quellen[$klasse])) {
                $tot[$klasse.($methode === '' ? '' : '::'.$methode)] = 'die Klasse gibt es nicht';

                continue;
            }

            if ($methode !== '' && ! str_contains($quellen[$klasse], 'function '.$methode.'(')) {
                $tot[$klasse.'::'.$methode] = 'die Klasse gibt es, diesen Fall nicht';
            }
        }

        $this->assertSame([], $tot, sprintf(
            "Diese Prüfungen in tests/waechter-brechen.sh nennen einen Test, den es nicht gibt:\n  %s\n\n".
            'Der Filter trifft dann nichts, und die Prüfung meldet „kein Test" — im Wochenlauf und '.
            'nirgends sonst. Meistens ist der Fall umbenannt oder in eine andere Klasse gezogen '.
            'worden; dann zeigt die Prüfung auf seinen neuen Namen. Ist die Regel weggefallen, geht '.
            'der Eingriff mit ihr.',
            implode("\n  ", array_map(
                static fn (string $name, string $grund): string => $name.': '.$grund,
                array_keys($tot),
                $tot,
            )),
        ));
    }

    public function test_every_intervention_still_grips_its_file(): void
    {
        $interventions = $this->interventions();

        /*
         * Die Untergrenze zählt, wo die Regel stehen *darf*: Wer Eingriffe
         * zusammenlegt, soll hier kein Rot bekommen. Fünfzig ist weit unter dem
         * Bestand und weit über dem, was ein kaputter Ausdruck liefert — und
         * genau davor steht diese Zeile, denn ein Muster, das nichts findet,
         * meldet „alles in Ordnung".
         */
        $this->assertGreaterThan(
            50,
            count($interventions),
            'Es werden kaum Eingriffe gefunden — dann prüft dieser Test nichts.',
        );

        $dead = [];

        foreach ($interventions as $intervention) {
            $path = $this->root().'/'.$intervention['file'];

            if (! is_file($path)) {
                $dead[] = sprintf('%s (Datei fehlt)', $intervention['file']);

                continue;
            }

            if (! str_contains((string) file_get_contents($path), $intervention['needle'])) {
                $dead[] = sprintf(
                    '%s: %s',
                    $intervention['file'],
                    explode("\n", trim($intervention['needle']))[0],
                );
            }
        }

        $this->assertSame([], $dead, sprintf(
            "Diese Eingriffe in tests/waechter-brechen.sh finden ihren Text nicht mehr:\n  %s\n\n".
            'Ein Eingriff, der nichts ändert, prüft nichts — und sieht dabei aus, als wäre die Regel '.
            'abgesichert. Meistens ist der Code umgezogen: Dann zeigt der Eingriff auf seinen neuen '.
            'Ort. Ist die Regel weggefallen, geht der Eingriff mit ihr.',
            implode("\n  ", $dead),
        ));
    }

    /**
     * Und keine Überschrift verschluckt den Eingriff darunter.
     *
     * **Gefunden am 11. August 2026, und es lag seit P4 da.** Eine Zeile
     * `echo "── X: „Noch" an einem Vorgang ──"` trägt drei ASCII-Anführungs-
     * zeichen: Das mittlere beendet die Zeichenkette der Shell, das letzte
     * öffnet eine neue — und die läuft weiter, bis irgendwo unten das nächste
     * kommt. Alles dazwischen ist dann **Text und kein Befehl**: Der Eingriff
     * wird nicht ausgeführt, die Prüfung nicht gefahren, und `bash -n` ist
     * zufrieden, weil sich die Anzahl über die Datei hinweg wieder ausgleicht.
     *
     * > **Ein Wächter, dessen Bruch verschluckt wird, war nie rot — und sieht
     * > aus wie einer, der immer grün ist.**
     *
     * Zwei der vier betroffenen Überschriften standen seit P4 im Skript.
     * {@see self::interventions()} hat davon nichts gemerkt: Er liest die
     * Python-Blöcke, und die waren in Ordnung — nur unerreichbar.
     *
     * Der Bruch dazu wird von Hand geführt, wie beim Test darüber:
     *
     *     sed -i '0,/^echo "──/{s/^echo "── \(.*\) ──"$/echo "── \1 „x" ──"/}' tests/waechter-brechen.sh
     *     ./vendor/bin/phpunit --filter BreakScriptTest      # muss rot sein
     *     git checkout -- tests/waechter-brechen.sh
     */
    public function test_no_heading_swallows_the_intervention_below_it(): void
    {
        $lines = explode("\n", (string) file_get_contents($this->root().'/tests/waechter-brechen.sh'));

        $headings = 0;
        $broken = [];

        foreach ($lines as $number => $line) {
            $doppelt = str_starts_with($line, 'echo "');
            $einfach = str_starts_with($line, "echo '");

            if (! $doppelt && ! $einfach) {
                continue;
            }

            $headings++;

            /*
             * **Je Form ihr eigenes Zeichen.** Bis zum 24. September 2026 las
             * dieser Fall nur die doppelt zitierte Form; die andere war für
             * ihn nicht vorhanden, und ein fehlendes Schlusszeichen dort hätte
             * dasselbe angerichtet, ohne dass es jemand meldet.
             *
             * In doppelten Anführungszeichen zählt ein maskiertes `\"` nicht
             * mit — es beendet die Zeichenkette nicht. In einfachen gibt es
             * keine Maskierung: Dort beendet **jedes** zweite `'` die
             * Zeichenkette, und genau deshalb ist die Zählung die richtige
             * Prüfung.
             */
            $ungerade = $doppelt
                ? substr_count(str_replace('\\"', '', $line), '"') % 2 !== 0
                : substr_count($line, "'") % 2 !== 0;

            if ($ungerade) {
                $broken[] = sprintf('Zeile %d: %s', $number + 1, $line);
            }
        }

        $this->assertGreaterThan(50, $headings,
            'Kaum Überschriften gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $broken, sprintf(
            'Diese Überschriften beenden ihre Zeichenkette nicht. Was darunter steht, wird zu Text '
            ."statt zu einem Befehl — der Eingriff läuft nicht.\n\n  %s",
            implode("\n  ", $broken),
        ));
    }

    /**
     * Jeder Helfer, den das Skript ruft, ist auch einer.
     *
     * ## Der Anlass
     *
     * Am 18. September 2026 ist `abschnitt "…"` als Kurzform für eine
     * Abschnittsüberschrift entstanden — **ohne dass es die Funktion gibt**.
     * Neun Stellen riefen sie, bash meldete neunmal `command not found` und lief
     * weiter, und der Lauf zählte seine Prüfungen wie sonst auch.
     *
     * > **Ein Skript, das eine Zeile nicht ausführen kann, läuft weiter — und
     * > die Meldung darüber steht neben der Bilanz und nicht darin.**
     *
     * **Kein bestehendes Mittel konnte es sehen.** `bash -n` prüft die Form und
     * nicht, ob es den Befehl gibt; shellcheck kann einen Namen nicht
     * nachschlagen; und
     * {@see self::test_no_heading_swallows_the_intervention_below_it()} liest
     * ausschliesslich Zeilen, die mit `echo "` beginnen — die neun waren für ihn
     * gar nicht da. Seine Untergrenze von 50 war durch die 1100 richtig
     * geschriebenen längst erfüllt.
     *
     * > **Eine Untergrenze, die die Mehrheit erfüllt, sieht eine zweite
     * > Schreibweise nicht — sie zählt ja weiter genug.**
     *
     * ## Was er prüft
     *
     * Eine Zeile, die mit einem nackten Wort und einer Zeichenkette beginnt, ist
     * ein Aufruf eines Helfers dieses Skripts. Erlaubt sind `echo`, `printf` und
     * jede Funktion, die das Skript selbst definiert — sonst nichts.
     *
     * **Die Rümpfe der Here-Dokumente bleiben aussen vor:** Dort steht Python,
     * und dessen Zeilen fangen genauso mit kleingeschriebenen Wörtern an.
     *
     * ## Warum es zu dieser Regel keinen Eingriff im Skript gibt
     *
     * Ihr Bruch steht in `tests/waechter-brechen.sh` selbst, und genau diese
     * Datei nimmt der Rückweg zu Recht aus (`$SELBST`) — derselbe Grund wie bei
     * {@see self::test_every_touched_file_lies_on_the_way_back()}. Gebrochen
     * wurde sie deshalb **von Hand**, am 20. September 2026: eine Überschrift
     * zurück auf `abschnitt "…"`, gemeldet als *Zeile 31244: abschnitt*, und
     * nach dem Zurücksetzen wieder grün.
     */
    /**
     * Keine Zeile führt aus, was sie nur drucken will.
     *
     * ## Der Anlass
     *
     * Eine Überschrift erklärte ihren Gegenstand in Markdown:
     *
     *     echo "── LogSourceTest: `-- No entries --` kommt als Zeile durch ──"
     *
     * In einer **doppelt** gequoteten Zeichenkette sind Backticks aber keine
     * Auszeichnung, sondern Befehlsersetzung. bash hat `-- No entries --`
     * ausgeführt, `--: command not found` gemeldet und das Ergebnis — nichts —
     * eingesetzt. Gedruckt stand da *„── LogSourceTest:  kommt als Zeile durch
     * ──"*: Der Gegenstand der Überschrift war fort.
     *
     * **Die harmlose Hälfte ist die gedruckte.** Die andere ist, dass zwischen
     * den Backticks ein Befehl steht, den dieses Skript **ausführt** — hier war
     * es keiner, beim nächsten Mal steht dort ein `rm`, weil jemand einen Befund
     * zitiert.
     *
     * > **Ein Zitat in doppelten Anführungszeichen ist in einer Shell kein
     * > Zitat, sondern ein Auftrag.**
     *
     * Gefunden hat es der volle Bruchlauf vom 20. September 2026 — die Meldung
     * stand seit langem im Protokoll neben der Bilanz und nicht darin, genau wie
     * bei {@see self::test_every_helper_the_script_calls_is_defined()}. Gesehen
     * hat sie kein Wächter:
     * {@see self::test_no_heading_swallows_the_intervention_below_it()} zählt
     * Anführungszeichen und keine Backticks.
     *
     * ## Warum es dazu keinen Eingriff im Skript gibt
     *
     * Aus demselben Grund wie nebenan: Der Bruch stünde in der einen Datei, die
     * der Rückweg auslässt. Gebrochen wurde er **von Hand** am 20. September —
     * die Maskierung zurückgenommen, gemeldet, und nach dem Zurücksetzen wieder
     * grün.
     */
    public function test_no_line_runs_a_command_it_only_means_to_print(): void
    {
        $zeilen = explode("\n", (string) file_get_contents($this->root().'/tests/waechter-brechen.sh'));

        $gelesen = 0;
        $funde = [];
        $marke = null;

        foreach ($zeilen as $nummer => $zeile) {
            if ($marke !== null) {
                if (rtrim($zeile) === $marke) {
                    $marke = null;
                }

                continue;
            }

            if (preg_match("/<<\s*'([A-Za-z0-9_]+)'/", $zeile, $treffer) === 1) {
                $marke = $treffer[1];

                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]* "/', $zeile) !== 1) {
                continue;
            }

            $gelesen++;

            // Ein maskierter Backtick ist einer — er wird gedruckt und nicht
            // ausgeführt. Gesucht wird der unmaskierte.
            if (preg_match('/(?<!\\\\)`/', $zeile) === 1) {
                $funde[] = sprintf('Zeile %d: %s', $nummer + 1, trim($zeile));
            }
        }

        $this->assertGreaterThan(500, $gelesen,
            'Es werden kaum Zeilen gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $funde, sprintf(
            'Diese Zeilen tragen einen unmaskierten Backtick in einer doppelt gequoteten '
            .'Zeichenkette. bash führt aus, was dazwischen steht, und druckt an seiner Stelle '
            ."das Ergebnis:\n\n  %s",
            implode("\n  ", array_slice($funde, 0, 12)),
        ));
    }

    public function test_every_helper_the_script_calls_is_defined(): void
    {
        $zeilen = explode("\n", (string) file_get_contents($this->root().'/tests/waechter-brechen.sh'));

        // `cd` und `exit` sind Eingebaute der Shell und keine Helfer — sie
        // stehen je einmal im Skript, am Anfang und am Ende.
        $definiert = ['echo', 'printf', 'cd', 'exit'];

        foreach ($zeilen as $zeile) {
            if (preg_match('/^([a-z_][a-z0-9_]*)\(\)/', $zeile, $treffer) === 1) {
                $definiert[] = $treffer[1];
            }
        }

        $gerufen = 0;
        $fehlend = [];
        $marke = null;

        foreach ($zeilen as $nummer => $zeile) {
            // Im Rumpf eines Here-Dokuments steht kein Shell-Befehl.
            if ($marke !== null) {
                if (rtrim($zeile) === $marke) {
                    $marke = null;
                }

                continue;
            }

            if (preg_match("/<<\s*'([A-Za-z0-9_]+)'/", $zeile, $treffer) === 1) {
                $marke = $treffer[1];

                continue;
            }

            if (preg_match('/^([a-z_][a-z0-9_]*) "/', $zeile, $treffer) !== 1) {
                continue;
            }

            $gerufen++;

            if (! in_array($treffer[1], $definiert, true)) {
                $fehlend[] = sprintf('Zeile %d: %s', $nummer + 1, $treffer[1]);
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf* — und sie zählt
        // jeden Aufruf und nicht nur die Überschriften, damit sie nicht wieder
        // von einer Mehrheit erfüllt wird, die eine zweite Schreibweise deckt.
        $this->assertGreaterThan(500, $gerufen,
            'Es werden kaum Aufrufe gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $fehlend, sprintf(
            'Diese Zeilen rufen einen Helfer, den das Skript nicht definiert. bash meldet '
            .'`command not found`, läuft weiter, und der Abschnitt darunter verliert seine '
            ."Überschrift:\n\n  %s",
            implode("\n  ", array_slice($fehlend, 0, 12)),
        ));
    }

    /**
     * Jede angefasste Datei liegt im Rückweg.
     *
     * **Der Anlass ist der erste Lauf dieses Skripts an einem Pull Request.**
     * `tests/` stand nicht in der Liste, die `wiederherstellen()` zurückholt —
     * ein Eingriff, der einen Wächter bricht, um dessen Gegenprobe zu prüfen,
     * blieb also stehen. Alles danach mass einen Arbeitsbaum, den niemand
     * hergestellt hat, und gemeldet wurde es erst zwei Blöcke später an einer
     * Rückstellprüfung.
     *
     * > **Ein Rückweg, der eine Datei nicht kennt, die ein Eingriff ändert, ist
     * > keiner — und was danach kommt, misst etwas anderes als es glaubt.**
     *
     * **Das Skript hatte den Fall schon einmal**, und die Lösung war das
     * Problem: Ein Eingriff aus P5b half sich mit einem eigenen
     * `git checkout -- tests/Feature/RemovalPathTest.php`, statt die Lücke zu
     * melden. Damit gab es zwei Fassungen desselben Rückwegs, und der nächste
     * Eingriff hat die falsche geerbt. Deshalb prüft dieser Test **beide**
     * Richtungen: dass jede Datei im Baum liegt, und dass sich kein Block selbst
     * behilft.
     *
     * ## Warum es zu dieser Regel keinen Eingriff im Skript gibt
     *
     * **Weil das Skript sie nicht brechen kann, ohne sich selbst zu ändern** —
     * und genau seine eigene Datei ist die eine, die der Rückweg auslässt. Ein
     * Eingriff darauf bliebe stehen, und das nächste `wiederherstellen` machte
     * es nicht besser: Er stünde in derselben Datei, die bash gerade liest.
     *
     * Gebrochen wurde die Regel deshalb **von Hand**, am 14. August 2026, in
     * drei Richtungen — `tests/` aus der Liste genommen, ein eigener
     * `git checkout` eingeschmuggelt, die Liste umbenannt. Alle drei waren rot.
     *
     * > **Eine Regel, deren Bruch das Werkzeug selbst beschädigt, wird von Hand
     * > gebrochen — und dass sie es wurde, gehört aufgeschrieben.**
     */
    /**
     * Ein Eingriff prüft seinen eigenen Griff und nicht den einer anderen Datei.
     *
     * ## Zwei Helfer, die fast gleich heissen
     *
     * `vorher` merkt sich `resources/css/app.css`, `griff` vergleicht dagegen.
     * `vorher_datei <pfad>` merkt sich eine beliebige Datei, `griff_datei
     * <pfad> <name>` vergleicht dagegen. Wer sie kreuzt, bekommt keinen Fehler
     * — sondern eine Auskunft über die falsche Datei.
     *
     * **Genau das ist in P6 passiert, zweimal in einem Abschnitt.** Zwei
     * Eingriffe in `Subscriptions/Show.vue` und `routes/web.php` riefen `griff`,
     * das `app.css` mit einem Abzug von vor drei Abschnitten verglich. Beide
     * meldeten „Eingriff hat nichts geändert", obwohl beide gegriffen hatten —
     * und die zwei Wächter darunter liefen nie.
     *
     * > **Ein Werkzeug, das die falsche Datei vergleicht, meldet nicht „ich habe
     * > die falsche verglichen" — es meldet „nichts passiert".**
     *
     * Gefunden hat es die CI, nicht der Container: Hier laufen die Wächter über
     * ein Gestell ohne PHPUnit, und dieses Skript fährt dort gar nicht.
     */
    public function test_every_intervention_checks_its_own_file(): void
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        /*
         * **Gelesen werden die Paare in ihrer Reihenfolge und nicht die
         * Abschnitte.**
         *
         * Hier stand `preg_split('/^echo "── /m', …)`. Das Skript trägt aber
         * **zwei** Überschriftenformen — `── … ──` und `== … ==` —, und die
         * zweite kam 298-mal vor: Ihre Eingriffe fielen in den Abschnitt
         * darüber, und geprüft wurde dort nur das **erste** `vorher_datei`.
         * Gemessen am 21. September 2026 erreichte dieser Fall **1167 von 1558**
         * Eingriffen; **391** lagen ausserhalb.
         *
         * > **Eine Untergrenze, die die Mehrheit erfüllt, sieht eine zweite
         * > Schreibweise nicht — sie zählt ja weiter genug.**
         *
         * Der Abschnitt war ausserdem die falsche Einheit: Einer von ihnen
         * trägt vierunddreissig Eingriffe, und das ist beabsichtigt. Die
         * Einheit ist das **Paar**, und Paare stehen in einer Reihenfolge.
         */
        preg_match_all(
            '/^(vorher_datei|griff_datei|vorher|griff)(?=\s|$)[ \t]*(\S*)/m',
            $script,
            $treffer,
            PREG_SET_ORDER,
        );

        $offen = null;
        $geprueft = 0;
        $befunde = [];

        foreach ($treffer as $t) {
            $helfer = $t[1];
            $ziel = $t[2];

            if ($helfer === 'vorher_datei' || $helfer === 'vorher') {
                if ($offen !== null) {
                    $befunde[] = sprintf(
                        '`%s%s` steht ein zweites Mal, bevor der erste Abzug geprüft wurde.',
                        $offen[0],
                        $offen[1] === '' ? '' : ' '.$offen[1],
                    );
                }

                $offen = [$helfer, $ziel];

                continue;
            }

            if ($offen === null) {
                $befunde[] = sprintf('`%s` prüft einen Abzug, den niemand gemacht hat.', $helfer);

                continue;
            }

            $geprueft++;

            $erwartet = $offen[0] === 'vorher_datei' ? 'griff_datei' : 'griff';

            if ($helfer !== $erwartet || ($erwartet === 'griff_datei' && $ziel !== $offen[1])) {
                $befunde[] = sprintf(
                    'Gemerkt wurde `%s%s`, geprüft wird `%s%s`.',
                    $offen[0],
                    $offen[1] === '' ? '' : ' '.$offen[1],
                    $helfer,
                    $ziel === '' ? '' : ' '.$ziel,
                );
            }

            $offen = null;
        }

        $this->assertSame([], $befunde, implode("\n", array_merge(
            ['Diese Eingriffe prüfen den Griff einer anderen Datei:'],
            $befunde,
            [
                '',
                '`griff` vergleicht `resources/css/app.css` gegen einen Abzug, den dieser',
                'Eingriff gar nicht gemacht hat — die Antwort lautet dann „Eingriff hat',
                'nichts geändert", ganz gleich, was der Eingriff getan hat.',
            ],
        )));

        /*
         * **Die Untergrenze zählt Paare.** Heissen die Helfer anders, findet
         * dieser Wächter null und ist grün. Gemessen sind es 1558; die Grenze
         * liegt darunter, weil sie nicht die Zahl festschreiben soll.
         */
        $this->assertGreaterThan(
            1000,
            $geprueft,
            'Es werden kaum Paare aus Abzug und Griff gefunden. Dann liest dieser Wächter das '.
            'Skript nicht mehr, und seine Zusage ist wertlos.',
        );
    }

    /**
     * Eine Überschrift, eine Form.
     *
     * ## Warum es diesen Wächter gibt
     *
     * Das Skript trug **zwei** Formen: `── … ──` und `== … ==`, die zweite
     * 298-mal. Beide drucken, beide werden gefahren — und genau deshalb fällt
     * die zweite niemandem auf. Gekostet hat sie die Reichweite des Wächters
     * daneben: `test_every_intervention_checks_its_own_file` trennte an
     * `echo "── ` und erreichte **1167 von 1558** Eingriffen.
     *
     * Dasselbe ist diesem Skript schon einmal passiert — `abschnitt "…"` stand
     * am 18. September neunmal darin, und die Funktion gab es nicht. Behoben
     * wurde es damals wie hier: **Die zweite Schreibweise verschwindet.**
     *
     * > **Zwei Schreibweisen für dasselbe laufen auseinander, und welche der
     * > beiden ein Werkzeug kennt, sagt das Werkzeug nicht.**
     *
     * ## Gefragt wird von unten und nicht von oben
     *
     * „Jede `echo`-Zeile ist eine Überschrift" wäre falsch — das Skript druckt
     * auch seine Bilanz. Gefragt wird deshalb von jedem **Eingriff** aus nach
     * oben: Die nächste `echo "`-Zeile über ihm ist seine Überschrift, und die
     * hat die eine Form. Damit braucht dieser Wächter keine Liste dessen, was
     * sonst noch gedruckt wird.
     */
    public function test_every_heading_uses_the_one_form(): void
    {
        $zeilen = explode("\n", (string) file_get_contents($this->root().'/tests/waechter-brechen.sh'));

        $geprueft = 0;
        $befunde = [];

        foreach ($zeilen as $nr => $zeile) {
            if (preg_match('/^(vorher_datei|vorher)(?=\s|$)/', $zeile) !== 1) {
                continue;
            }

            $ueberschrift = null;

            /*
             * **Gesucht wird nach **beiden** Formen und nicht nach einer.**
             *
             * Bis zum 24. September 2026 stand hier nur `echo "`. Eine
             * Überschrift in der anderen Form wurde damit nicht etwa gemeldet
             * — sie wurde **übergangen**, und der Eingriff darunter bekam die
             * Überschrift des Eingriffs davor zugeschrieben. Gefunden hat es
             * keine Prüfung, sondern eine Zahl: 1541 Abschnitte im Protokoll
             * des Bruchlaufs gegen 1540 im Skript.
             *
             * > **Ein Wächter, der beim Suchen nur eine Form kennt, meldet die
             * > andere nicht — er läuft an ihr vorbei und urteilt über die
             * > falsche Zeile.**
             *
             * Das ist der Satz aus der Fehlermeldung unten, angewandt auf den
             * Wächter selbst.
             */
            for ($i = $nr - 1; $i >= 0; $i--) {
                if (str_starts_with($zeilen[$i], 'echo "') || str_starts_with($zeilen[$i], "echo '")) {
                    $ueberschrift = $zeilen[$i];

                    break;
                }
            }

            if ($ueberschrift === null) {
                $befunde[] = sprintf('Zeile %d: Über diesem Eingriff steht keine Überschrift.', $nr + 1);

                continue;
            }

            $geprueft++;

            if (! self::formGehaltenVon($ueberschrift)) {
                $befunde[] = sprintf('Zeile %d: %s', $i + 1, $ueberschrift);
            }
        }

        $this->assertSame([], array_values(array_unique($befunde)), implode("\n", array_merge(
            ['Diese Überschriften halten die Form nicht:'],
            array_unique($befunde),
            [
                '',
                'Zugelassen sind `echo "── … ──"` und `echo \'── … ──\'` — die zweite Form für',
                'Überschriften, die einen Backtick tragen und ihn nicht maskieren wollen.',
                '',
                'Zwei Formen laufen auseinander: Ein Werkzeug, das nur die eine kennt, liest',
                'die Abschnitte der anderen gar nicht — und meldet trotzdem eine Zahl.',
            ],
        )));

        /*
         * **Die Zahl ist die Untergrenze und zugleich die Grenze dieses
         * Falls.** Gezählt werden Eingriffe und nicht Überschriften: Gemessen
         * am 24. September 2026 trägt das Skript **1541** Abschnitte, und
         * **16** davon greifen ohne `vorher_datei` zu — sie setzen ihr `sed`
         * unmittelbar ab. Über deren Überschriften sagt dieser Fall nichts,
         * weil er von einem Griff aus nach oben liest und nicht von einer
         * Überschrift aus nach unten.
         *
         * > **Ein Wächter, der von der einen Seite einer Naht aus liest, sieht
         * > die Abschnitte nicht, an deren anderer Seite nichts steht, wonach
         * > er sucht.**
         *
         * Das ist eine benannte Grenze und kein Mangel:
         * {@see self::test_no_heading_swallows_the_intervention_below_it()}
         * liest die Überschriften **direkt** und deckt die sechzehn mit ab.
         * Wer sie auch hier will, liest von der Überschrift nach unten — und
         * muss dann entscheiden, was ein Abschnitt ohne Griff überhaupt ist.
         */
        $this->assertGreaterThan(
            1000,
            $geprueft,
            'Es werden kaum Eingriffe gefunden — dann prüft dieser Fall nichts.',
        );
    }

    /**
     * Hält diese Überschrift ihre Form?
     *
     * **Zwei Zitierungen sind zugelassen, und das ist keine Nachsicht.** Eine
     * Überschrift, die einen Befund zitiert, trägt einen Backtick — und der
     * ist in doppelten Anführungszeichen kein Zitat, sondern eine
     * Befehlsersetzung. Dagegen gibt es genau zwei richtige Antworten: ihn
     * maskieren oder die Zeile einfach zitieren. Beide sind gemessen richtig,
     * und eine davon zu verbieten wäre ein Urteil und keine Regel — das Skript
     * führt heute beide.
     *
     * **Der gefährliche Fall gehört nicht hierher.** Dass kein *unmaskierter*
     * Backtick in einer doppelt zitierten Zeile steht, hält
     * {@see self::test_no_line_runs_a_command_it_only_means_to_print()} — und
     * zwar für **jede** Zeile des Skripts und nicht nur für Überschriften. Ihn
     * hier ein zweites Mal zu prüfen wäre die zweite Fassung derselben Regel,
     * und die zweite ist die, die veraltet.
     *
     * > **Ein Wächter, der die Regel eines anderen nachbaut, prüft seine
     * > eigene Fassung davon.**
     *
     * Was dieser Fall hält, ist deshalb die **Gestalt**: Eine Überschrift ist
     * als solche erkennbar und endet, wie sie anfängt. Dass sie ihre
     * Zeichenkette auch schliesst, hält
     * {@see self::test_no_heading_swallows_the_intervention_below_it()} je
     * Form.
     */
    private static function formGehaltenVon(string $ueberschrift): bool
    {
        return preg_match('/^echo "── .* ──"$/D', $ueberschrift) === 1
            || preg_match("/^echo '── .* ──'\$/D", $ueberschrift) === 1;
    }

    /**
     * Eine Nummer, die ein Eingriff für frei hält, bleibt frei.
     *
     * ## Warum es diesen Wächter gibt
     *
     * Ein Eingriff dieses Skripts baut einen Verweis auf ein Dokument ein, das
     * es **nicht geben darf** — sonst hat `DocLinkTest` nichts zu melden und
     * der Eingriff beisst nicht. Das ist zweimal passiert, beide Male ohne
     * jede Meldung:
     *
     * - `docs/39` wurde am 10. August 2026 vergeben, im selben Wurf, in dem der
     *   Eingriff entstand.
     * - `docs/99` wurde am 2. September 2026 der Nachlauf zu A10 — und der
     *   Kommentar daneben erklärte diese Nummer ausdrücklich für unerreichbar.
     *   Gemeldet hat es die CI, nicht der Bruch.
     *
     * > **Eine Zusage über die Zukunft ist kein Mechanismus** — und ein Bruch,
     * > dessen Gegenstand von aussen kommt, wird von aussen repariert, ohne das
     * > zu melden.
     *
     * ## Was er nicht kann
     *
     * Er hält die Nummer nicht frei — er meldet den Tag, an dem sie vergeben
     * wird. Mehr ist von hier aus nicht zu haben: Welche Nummer ein Dokument
     * bekommt, entscheidet, wer es schreibt.
     *
     * ## Der Bruch
     *
     * **Er steht nicht im Skript, und das geht auch nicht** — er müsste die
     * Marke im Skript ändern, und `wiederherstellen()` fasst `tests/` zu Recht
     * nicht an. Von Hand, am 2. September 2026 in allen vier Richtungen
     * gefahren:
     *
     *     # 1 · die Nummer ist vergeben
     *     sed -i 's/^# PLATZHALTER-NUMMERN: 00$/# PLATZHALTER-NUMMERN: 98/' tests/waechter-brechen.sh
     *     # 2 · sie ist nicht zweistellig
     *     sed -i 's/^# PLATZHALTER-NUMMERN: 00$/# PLATZHALTER-NUMMERN: 9999/' tests/waechter-brechen.sh
     *     # 3 · kein Eingriff benutzt sie
     *     sed -i 's/^# PLATZHALTER-NUMMERN: 00$/# PLATZHALTER-NUMMERN: 01/' tests/waechter-brechen.sh
     *     # 4 · die Marke ist fort
     *     sed -i '/^# PLATZHALTER-NUMMERN: 00$/d' tests/waechter-brechen.sh
     *
     *     ./vendor/bin/phpunit --filter test_every_placeholder_number_stays_free
     *     git checkout -- tests/waechter-brechen.sh
     *
     * Und der Eingriff selbst, gegengeprüft am selben Tag: Mit `docs/9999`
     * blieb `DocLinkTest` **grün** (er las `99`, und das Dokument gibt es), mit
     * `docs/00` meldet er „docs/38-postgresql.md nennt docs/00, dieses Dokument
     * gibt es nicht."
     */
    public function test_every_placeholder_number_stays_free(): void
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        $this->assertSame(
            1,
            preg_match('/^# PLATZHALTER-NUMMERN: ([0-9 ]+)$/m', $script, $treffer),
            'Die Marke mit den Platzhalter-Nummern ist fort — dann hält nichts mehr die Lücke, '
            .'aus der ein Eingriff seinen Biss zieht.',
        );

        $nummern = preg_split('/\s+/', trim($treffer[1])) ?: [];

        $this->assertNotSame([], $nummern, 'Die Marke nennt keine Nummer — dann misst dieser Wächter nichts.');

        $muster = $this->documentNumberPattern();

        foreach ($nummern as $nummer) {
            /*
             * **Der Leser muss die Nummer ganz lesen, und das wird gemessen
             * statt behauptet.** `DocLinkTest` sucht `docs/` plus eine feste
             * Zahl von Ziffern; was darüber hinausgeht, schneidet er ab. Aus
             * `docs/9999` wurde am 2. September 2026 ein `99`, das Dokument
             * dazu gibt es, und der Eingriff lief ohne Biss durch.
             *
             * > **Eine Nummer, die weiter aus dem Weg liegt, ist nicht sicherer
             * > — sie wird vom Leser gekürzt.**
             *
             * Hier stand dafür bis zum 3. September `^\d{2}$` samt der
             * Begründung „DocLinkTest liest `docs/(\d{2})`". Das war eine
             * zweite Fassung jenes Ausdrucks — und als er dreistellig wurde,
             * stimmte die Begründung nicht mehr, während die Zeile weiter
             * grün war. Gefragt wird deshalb der Ausdruck selbst: Liest er
             * `docs/<nummer>` als genau diese Nummer?
             */
            $gelesen = null;

            if (preg_match($muster, 'docs/'.$nummer, $wie) === 1) {
                $gelesen = $wie[1];
            }

            $this->assertSame($nummer, $gelesen, sprintf(
                'DocLinkTest liest aus docs/%s die Nummer %s. Der Eingriff bricht dann etwas '
                .'anderes oder nichts — die Platzhalter-Nummer muss der Ausdruck ganz lesen.',
                $nummer,
                $gelesen ?? '(gar nichts)',
            ));

            // **Gefragt wird über die Nummer und nicht über den Dateinamen** —
            // genau wie DocLinkTest selbst es tut: `docs/37` findet
            // `37-postgresql.md`, und der Titel dahinter ändert sich.
            $dateien = glob($this->root().'/docs/'.$nummer.'-*.md') ?: [];

            $this->assertSame([], $dateien, sprintf(
                'docs/%s ist vergeben (%s). Ein Eingriff in tests/waechter-brechen.sh baut daraus '
                .'einen Verweis auf ein Dokument, das es nicht geben darf — mit dieser Datei beisst '
                .'er nicht mehr. Entweder bekommt das Dokument eine andere Nummer, oder der Eingriff '
                .'und diese Marke bekommen eine neue.',
                $nummer,
                implode(', ', array_map('basename', $dateien)),
            ));

            // **Und der Eingriff benutzt sie auch wirklich.** Eine Marke, die
            // eine Nummer nennt, die im Skript nicht vorkommt, hält eine Lücke
            // frei, aus der niemand etwas zieht — die Verzierung, vor der
            // `docs/81 §2.3` warnt.
            $this->assertStringContainsString('docs/'.$nummer, $script, sprintf(
                'Die Marke hält docs/%s frei, aber kein Eingriff verweist darauf.',
                $nummer,
            ));
        }
    }

    public function test_every_touched_file_lies_on_the_way_back(): void
    {
        $script = (string) file_get_contents($this->root().'/tests/waechter-brechen.sh');

        $this->assertSame(
            1,
            preg_match('/^BAEUME="([^"]+)"$/m', $script, $treffer),
            'Es gibt keine Liste der Bäume mehr, in denen dieses Skript arbeitet.',
        );

        $baeume = preg_split('/\s+/', trim($treffer[1])) ?: [];

        $this->assertGreaterThan(
            5,
            count($baeume),
            'Kaum Bäume in der Liste — dann prüft dieser Test nichts.',
        );

        /*
         * **Was der Rückweg ausdrücklich auslässt, zählt nicht als abgedeckt.**
         * Das Skript nimmt sich selbst aus — es liegt unter `tests/`, und bash
         * liest es während der Ausführung weiter. Ein Eingriff auf das Skript
         * wäre also von der Liste gedeckt und würde trotzdem stehenbleiben.
         */
        $this->assertSame(
            1,
            preg_match('/^SELBST=":\(exclude\)([^"]+)"$/m', $script, $selbst),
            'Der Rückweg nimmt das Skript nicht mehr aus — dann stellt es sich mitten im Lauf '
            .'selbst wieder her, während bash es liest.',
        );

        $draussen = [];

        foreach ($this->interventions() as $intervention) {
            $datei = $intervention['file'];

            if ($datei === $selbst[1]) {
                $draussen[$datei] = $datei.' (vom Rückweg ausgenommen)';

                continue;
            }

            foreach ($baeume as $baum) {
                if (str_starts_with($datei, $baum)) {
                    continue 2;
                }
            }

            $draussen[$datei] = $datei;
        }

        $this->assertSame([], array_values($draussen), sprintf(
            "Diese Dateien werden von einem Eingriff geändert und liegen nicht im Rückweg:\n  %s\n\n"
            .'Sie bleiben nach dem Eingriff verändert stehen, und jede Prüfung danach misst einen '
            .'Arbeitsbaum, den niemand hergestellt hat.',
            implode("\n  ", $draussen),
        ));

        /*
         * **Und niemand behilft sich mit einem eigenen Rückweg.** Ein
         * `git checkout --` mitten im Skript ist kein Fix, sondern eine zweite
         * Fassung von `wiederherstellen()` — und die zweite ist die, die
         * veraltet. Die Definition selbst steht in einer Funktion und wird von
         * dieser Zählung nicht getroffen.
         */
        preg_match_all('/^\s*git checkout .*$/m', $script, $eigene);

        $this->assertSame([], $eigene[0], sprintf(
            "Diese Zeilen stellen an `wiederherstellen()` vorbei her:\n  %s\n\n"
            .'Wer eine Datei zurückholt, die der Rückweg nicht kennt, behebt seinen eigenen Fall '
            .'und lässt die Lücke für den nächsten stehen.',
            implode("\n  ", $eigene[0]),
        ));
    }

    /**
     * Der Ausdruck, mit dem {@see DocLinkTest} eine Dokumentnummer liest.
     *
     * **Gelesen und nicht nachgebaut.** Ein zweiter Ausdruck derselben Regel
     * wäre der, der beim nächsten Mal veraltet — und diese Stelle hat genau
     * das schon einmal getan.
     */
    private function documentNumberPattern(): string
    {
        $quelle = (string) file_get_contents($this->root().'/tests/Feature/DocLinkTest.php');

        // Der Aufruf, wie er dort steht: `preg_match_all('~docs/(\d{2,3})~', …`.
        // Gefangen wird das Ausdrucksliteral zwischen den Hochkommata, samt
        // seinen Trennzeichen — es geht danach unverändert an `preg_match()`.
        $ausdruck = "#preg_match_all\\('(?<muster>[^']*docs/[^']*)'#";

        $this->assertSame(1, preg_match($ausdruck, $quelle, $treffer), implode("\n", [
            'In DocLinkTest steht kein preg_match_all über docs/<nummer> mehr.',
            'Ohne ihn misst dieser Wächter die Platzhalter-Nummer gegen nichts.',
        ]));

        return $treffer['muster'];
    }
}
