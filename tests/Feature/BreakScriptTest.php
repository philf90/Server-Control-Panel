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
            if (! str_starts_with($line, 'echo "')) {
                continue;
            }

            $headings++;

            // Maskierte zählen nicht — sie beenden die Zeichenkette nicht.
            if (substr_count(str_replace('\\"', '', $line), '"') % 2 !== 0) {
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

        // Die Abschnitte, an ihren Überschriften getrennt. Ein Eingriff gehört
        // zu genau einem davon.
        $abschnitte = preg_split('/^echo "── /m', $script) ?: [];

        $geprueft = 0;

        foreach ($abschnitte as $abschnitt) {
            $mitDatei = preg_match('/^vorher_datei\s+(\S+)/m', $abschnitt, $gemerkt) === 1;
            $mitCss = preg_match('/^vorher\(\)|^vorher$/m', $abschnitt) === 1;

            if (! $mitDatei && ! $mitCss) {
                continue;
            }

            $geprueft++;

            if ($mitDatei) {
                $this->assertMatchesRegularExpression(
                    '/^griff_datei\s+'.preg_quote($gemerkt[1], '/').'\s/m',
                    $abschnitt,
                    sprintf(
                        'Ein Abschnitt merkt sich `%s` mit `vorher_datei` und prüft den Griff
'.
                        'nicht mit `griff_datei %s`.

'.
                        '`griff` vergleicht `resources/css/app.css` gegen einen Abzug, den dieser
'.
                        'Abschnitt gar nicht gemacht hat — die Antwort lautet dann „Eingriff hat
'.
                        'nichts geändert", ganz gleich, was der Eingriff getan hat.',
                        $gemerkt[1],
                        $gemerkt[1],
                    ),
                );
            }
        }

        /*
         * **Die Untergrenze, und sie zählt Abschnitte mit einem Abzug.** Zieht
         * die Form der Überschriften um oder heissen die Helfer anders, findet
         * dieser Wächter null Abschnitte und ist grün.
         */
        $this->assertGreaterThan(
            20,
            $geprueft,
            'Es werden kaum Abschnitte mit einem Abzug gefunden. Dann liest dieser Wächter das '.
            'Skript nicht mehr, und seine Zusage ist wertlos.',
        );
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
}
