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
            preg_match_all('/s\.replace\(\s*("""(.*?)"""|"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/s', $block, $needles, PREG_SET_ORDER);

            foreach ($needles as $needle) {
                $raw = ($needle[2] ?? '') !== ''
                    ? $needle[2]
                    : ((($needle[3] ?? '') !== '') ? $needle[3] : ($needle[4] ?? ''));

                if ($raw === '') {
                    continue;
                }

                $found[] = ['file' => $target[1], 'needle' => $this->unescape($raw)];
            }
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
}
