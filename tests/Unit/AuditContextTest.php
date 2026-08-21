<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Was ins Protokoll geschrieben wird, muss auch herauszulesen sein.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * `docs/66`, Befund 7. Auf `/audit` stand nach dem Eintragen eines
 * SSH-Schlüssels:
 *
 *     AKTION   sftp.key.add
 *     ZIEL     —
 *
 * Der Fingerabdruck war aufgezeichnet — `context: ['fingerprint' => …]` — und
 * durch keine Oberfläche zu erreichen: `toArrayRow()` legte acht Felder auf die
 * Seite und `context` war keines davon, `Audit/Index.vue` hatte fünf Spalten,
 * und der Export baute seine Zeile aus derselben Ablage.
 *
 * Das galt für die **ganze** Stufe P6. Ausgezählt am 21. August über `app/`:
 * 19 Aufrufe mit `target:`, **18 mit `context:` und ohne** — und alle achtzehn
 * waren P6 oder Anmeldevorgänge. Bei den Anmeldungen ist es richtig, dort gibt
 * es kein Ziel. Bei den anderen fünfzehn gab es eines.
 *
 * Was das Protokoll damit sagte: `file.removed` — nicht welche Datei.
 * `file.chmod` — nicht welche und nicht worauf. `sftp.key.remove` — nicht
 * welcher Schlüssel. Für einen Schlüssel, der Zugang zu allen Dateien eines
 * Abonnements gibt, ist „welcher" die einzige Frage, für die man ein Protokoll
 * aufschlägt.
 *
 * > **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
 * > beantwortet die Frage, die niemand stellt.**
 *
 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
 * > einem zu unterscheiden, das es nicht gibt.**
 *
 * ## Und warum drei Stellen geprüft werden und nicht eine
 *
 * Die Ablage, die Seite und der Export. Fehlt eine davon, ist der Zusammenhang
 * genau dort weg, wo man ihn braucht — beim Export sogar Jahre später, wenn
 * niemand mehr nachsehen kann, was gemeint war.
 */
final class AuditContextTest extends TestCase
{
    private const QUERY = 'app/Support/Audit/AuditQuery.php';

    private const CONTROLLER = 'app/Http/Controllers/AuditController.php';

    private const PAGE = 'resources/js/Pages/Audit/Index.vue';

    /** Die Ablage für die Oberfläche trägt den Zusammenhang. */
    public function test_the_row_carries_the_context(): void
    {
        $quelle = $this->read(self::QUERY);

        $this->assertStringContainsString(
            "'details' => self::details(",
            $quelle,
            'Die Ablage traegt den Zusammenhang nicht mehr. Dann ist er geschrieben und nirgends '.
            'zu lesen — das Protokoll nennt die Art der Handlung und nie ihren Gegenstand.',
        );

        $this->assertStringContainsString(
            'context',
            (string) strstr($quelle, 'private static function details('),
            'Der Satz zum Zusammenhang liest den Zusammenhang nicht mehr.',
        );
    }

    /**
     * Die Seite zeigt ihn, und sie baut ihn nicht selbst.
     *
     * **Der Satz entsteht in `AuditQuery`**, damit Liste und Export denselben
     * lesen. Eine Zusammensetzung in der Seite wäre eine zweite Fassung, und
     * die zweite ist die, die abweicht — derselbe Grund, aus dem beide Wege
     * schon durch dieselbe Sichtbarkeit gehen.
     */
    public function test_the_page_shows_it_without_building_it(): void
    {
        $quelle = $this->read(self::PAGE);

        $this->assertStringContainsString(
            'row.details',
            $quelle,
            'Die Seite zeigt den Zusammenhang nicht mehr an.',
        );

        $this->assertStringNotContainsString(
            'row.context',
            $quelle,
            'Die Seite baut den Satz selbst aus dem rohen Zusammenhang. Dann gibt es ihn zweimal '.
            '— hier und im Export —, und die zweite Fassung ist die, die abweicht.',
        );
    }

    /**
     * Der Export trägt ihn mit, samt seiner Überschrift.
     *
     * **Der Export ist der Beleg, den jemand aufhebt.** Stünde der
     * Zusammenhang nur auf der Seite, wäre die Datei ausgerechnet dort ärmer,
     * wo man sie Jahre später liest.
     */
    public function test_the_export_carries_it_too(): void
    {
        $quelle = $this->read(self::CONTROLLER);

        $this->assertStringContainsString(
            "'Einzelheiten'",
            $quelle,
            'Der Export hat keine Spalte fuer den Zusammenhang mehr — dann fehlt die Ueberschrift '.
            'zu einem Wert, oder der Wert selbst.',
        );

        $this->assertStringContainsString(
            "\$row['details']",
            $quelle,
            'Der Export schreibt den Zusammenhang nicht mehr. Aus „eine Datei wurde geloescht" '.
            'wird dann nie „welche".',
        );

        $this->assertSame(
            substr_count($quelle, "'Einzelheiten'") > 0 ? 1 : 0,
            substr_count($quelle, "\$row['details']"),
            'Ueberschrift und Wert stehen nicht gleich oft da — dann rutschen die Spalten der '.
            'Datei gegeneinander, und jede Zeile liest sich falsch.',
        );
    }

    /**
     * Der Deckel ist genannt und nicht still.
     *
     * Ein Satz, der aussieht wie der ganze Zusammenhang und es nicht ist, wäre
     * die schlechtere Antwort auf dieselbe Grenze.
     *
     * > **Kein stiller Deckel: Wer die Sicht begrenzt, nennt es dazu.**
     */
    public function test_the_cap_says_that_it_capped(): void
    {
        $quelle = $this->read(self::QUERY);

        $this->assertMatchesRegularExpression(
            '/const DETAILS_MAX = [1-9]\d*/',
            $quelle,
            'Der Satz zum Zusammenhang hat keine Grenze mehr — dann macht eine Liste von dreissig '.
            'Pfaden die Zeile unlesbar.',
        );

        /*
         * **Gelesen wird der Rumpf und nicht die Datei.** Der erste Anlauf
         * suchte „gekürzt" im ganzen Quelltext — und war grün, als der Bruch
         * die Ansage aus dem Code nahm: Das Wort stand noch in der Erklärung
         * darüber. Derselbe Fehler wie bei `docs/62` Punkt 12b.
         *
         * > **Ein Wächter, der einen Satz sucht statt seiner Erreichbarkeit,
         * > ist grün, sobald der Satz irgendwo steht.**
         */
        $rumpf = $this->body($quelle, 'private static function details(');

        $this->assertNotSame('', $rumpf, 'Der Satz zum Zusammenhang wird nicht mehr gebaut — dann prueft dieser Waechter nichts.');

        $this->assertStringContainsString(
            'gekürzt',
            $rumpf,
            'Ein gekuerzter Satz sagt nicht mehr, dass er gekuerzt ist. Dann sieht er aus wie der '.
            'ganze Zusammenhang.',
        );
    }

    /**
     * Jede P6-Handlung mit einem Modell nennt es als Ziel.
     *
     * **Die Spalte `Ziel` gab es die ganze Zeit**, und `Audit::record()` hat
     * seit P0 einen Parameter dafür — die früheren Stufen benutzen ihn
     * (`plan.created`, `domain.created`, `operation.started`). P6 hat es nicht
     * getan. Dazwischen liegt keine Entscheidung, nur eine andere Woche.
     *
     * > **Eine Gewohnheit, die kein Wächter hält, endet an der Datei, in der
     * > niemand mehr hinsieht.**
     */
    public function test_every_action_with_a_model_names_it_as_the_target(): void
    {
        $ohneZiel = [];
        $gesehen = 0;

        foreach ([
            'app/Http/Controllers/CronController.php' => ['cron.job.add', 'cron.job.change', 'cron.job.remove'],
            'app/Http/Controllers/SftpController.php' => ['sftp.key.add', 'sftp.key.remove'],
        ] as $datei => $aktionen) {
            $quelle = $this->read($datei);

            foreach ($aktionen as $aktion) {
                if (preg_match("/record\('".preg_quote($aktion, '/')."'([^;]*?)\);/s", $quelle, $t) !== 1) {
                    continue;
                }

                $gesehen++;

                if (! str_contains($t[1], 'target:')) {
                    $ohneZiel[] = $datei.' — '.$aktion;
                }
            }
        }

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertSame(5, $gesehen, sprintf(
            'Es werden %d der fuenf Handlungen gefunden statt fuenf — dann prueft dieser Waechter '.
            'die uebrigen nicht.',
            $gesehen,
        ));

        $this->assertSame([], $ohneZiel, sprintf(
            "Diese Handlungen nennen kein Ziel:\n\n  %s\n\n".
            'Die Spalte `Ziel` bleibt dann leer, obwohl ein Modell zur Hand ist — und das '.
            'Protokoll sagt die Art der Handlung und nicht, welches Stueck gemeint war.',
            implode("\n  ", $ohneZiel),
        ));
    }

    /**
     * Der Rumpf einer Methode, von `{` bis zur passenden `}`.
     *
     * Ohne die Erklärung davor — die trägt oft genau die Wörter, nach denen
     * hier gesucht wird.
     */
    private function body(string $quelle, string $marke): string
    {
        $stelle = strpos($quelle, $marke);

        if ($stelle === false) {
            return '';
        }

        $auf = strpos($quelle, '{', $stelle);

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
