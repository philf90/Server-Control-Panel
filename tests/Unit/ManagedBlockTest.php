<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Pg\Hba;

/**
 * Wer in eine fremde Datei schreibt, nimmt die Sperre — und nur einmal.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Eine „fremde Datei" ist eine, die jemand anderem gehört und in die auch
 * jemand anderes schreibt: `pg_hba.conf` gehört PostgreSQL und dem Betreiber,
 * `sshd_config` gehört OpenSSH und dem Betreiber. Das Panel trägt dort einen
 * Bereich ein und lässt alles andere in Ruhe.
 *
 * **Jede der Regeln unten kommt aus einem Fehler, der schon passiert ist**, und
 * die beiden teuersten standen in derselben Woche:
 *
 * - Der **Rückweg** von `pg.remote.access` legte den Stand von Schritt 1 zurück
 *   und warf dabei die Zeile weg, die {@see Hba::ensure()} aus einem anderen
 *   Prozess dazwischen ergänzt hatte (`docs/45`). Gefunden hat das kein
 *   Nachdenken, sondern ein Wegwerf-Cluster.
 *   > **Ein zweiter Schreiber in derselben Datei ist kein zweiter Schreiber,
 *   > solange nur einer die Sperre nimmt.**
 * - Und die Sperre, zweimal genommen, wartete auf sich selbst — ohne Fehler und
 *   ohne Meldung, weil `flock` je *offener Datei* sperrt und nicht je Prozess.
 *   > **Eine Sperre, die man zweimal nimmt, ist ein Stillstand ohne
 *   > Fehlermeldung.**
 *
 * ## Warum er einen Zeitgeber trägt
 *
 * Die Wiedereintrittsfähigkeit lässt sich nur dadurch prüfen, dass man die
 * Sperre verschachtelt nimmt — und **wenn die Regel bricht, hängt genau dieser
 * Aufruf**, statt fehlzuschlagen. Ein Wächter, der bei einem Rückfall stehen
 * bleibt, meldet nichts; er hält die ganze Prüfung an. Das ist die Verwandte
 * der Lehre vom 13. August:
 *
 * > **Ein Bruch muss die Regel verletzen und nicht den Code zerstören.** Ein
 * > Testfall, der abbricht, meldet „übersprungen" statt „rot".
 *
 * Ein hängender meldet noch weniger. {@see self::test_the_lock_is_reentrant()}
 * läuft deshalb in einem Kindprozess mit Frist.
 *
 * **Die Brüche dazu** (`tests/waechter-brechen.sh`): ein `ManagedBlock::put()`
 * aus der Sperre herausziehen; die Sperre auf die Datei selbst legen statt
 * daneben; den Zähler entfernen, der den inneren Aufruf durchreicht.
 */
final class ManagedBlockTest extends TestCase
{
    /**
     * Die fremden Dateien, in die dieses Panel schreibt — und wer sie verwaltet.
     *
     * **Sie steht hier und nicht im Code**, weil sie eine Regel über den Code
     * ist: Zu jeder dieser Dateien gibt es genau eine Klasse, die sie kennt,
     * und die schreibt selbst nichts — sie lässt {@see ManagedBlock} schreiben.
     *
     * Wächst die Liste, wächst die Prüfung mit. Schrumpft sie auf null, ist der
     * Wächter blind, und die Untergrenze unten meldet es.
     *
     * @var array<string,class-string>
     */
    private const FOREIGN = [
        'pg_hba.conf' => Hba::class,
    ];

    /**
     * Was als Schreiben zählt.
     *
     * `rename` steht mit in der Liste, obwohl es nichts hineinschreibt: Es
     * *ersetzt* die Datei, und für den, dem sie gehört, ist das dasselbe.
     *
     * @var list<string>
     */
    private const WRITES = [
        'file_put_contents(',
        'fwrite(',
        'ftruncate(',
        'rename(',
        'unlink(',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/srvpanel-managed-'.getmypid().'-'.count(get_included_files());
        file_put_contents($this->path, $this->existing());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'*') ?: [] as $rest) {
            @unlink($rest);
        }
    }

    /** Ein Bestand, wie ihn eine Distribution mitbringt. */
    private function existing(): string
    {
        return "# vom Betreiber\nlocal   all   all   peer\nhost    all   all   127.0.0.1/32   scram-sha-256\n";
    }

    // ------------------------------------------------------------------ statisch

    /**
     * Wer eine fremde Datei verwaltet, schreibt nicht selbst.
     *
     * Die Regel ist nicht „schreib vorsichtig", sondern „schreib gar nicht" —
     * eine zweite Schreibstelle wäre eine zweite Fassung von Sperre, Ersetzung
     * und Rechteübernahme, und die zweite ist die, die veraltet.
     */
    public function test_a_manager_of_a_foreign_file_writes_nothing_itself(): void
    {
        $this->assertGreaterThan(
            0,
            count(self::FOREIGN),
            'Der Katalog der fremden Dateien ist leer — dieser Wächter prüft dann nichts.',
        );

        foreach (self::FOREIGN as $file => $class) {
            $source = $this->sourceOf($class);

            foreach (self::WRITES as $write) {
                $this->assertStringNotContainsString(
                    $write,
                    $this->withoutComments($source),
                    sprintf(
                        '%s verwaltet %s und schreibt mit „%s" selbst. Schreiben gehört in '
                        .'ManagedBlock::put() — sonst gibt es zwei Fassungen von Sperre, '
                        .'Ersetzung und Rechteübernahme.',
                        $class,
                        $file,
                        rtrim($write, '('),
                    ),
                );
            }
        }
    }

    /**
     * Jedes Lesen und jedes Schreiben steht **innerhalb** der Sperre.
     *
     * Das ist der Fehler aus `docs/45` in seiner allgemeinen Form: Ein
     * `put()` einen Schritt neben der Klammer ist genau so falsch wie eines
     * ganz ohne sie, und beim Lesen sieht es richtig aus. Geprüft wird deshalb
     * an den Klammern und nicht am Augenschein.
     */
    public function test_every_read_and_write_sits_under_the_lock(): void
    {
        $gefunden = 0;

        foreach ($this->agentSources() as $file => $source) {
            foreach ($this->callsOutsideTheLock($source) as $call) {
                $this->fail(sprintf(
                    '%s ruft ManagedBlock::%s() ausserhalb von ManagedBlock::locked() auf. '
                    .'Ein zweiter Prozess kann sich dazwischenschieben, und der Rückweg legt '
                    .'danach einen Stand zurück, in dem dessen Zeile fehlt.',
                    $file,
                    $call,
                ));
            }

            $gefunden += substr_count($source, 'ManagedBlock::locked(');
        }

        $this->assertGreaterThan(
            0,
            $gefunden,
            'Kein einziger Aufruf von ManagedBlock::locked() gefunden — der Ausdruck läuft ins Leere.',
        );
    }

    /**
     * Die Sperre liegt **neben** der Datei und nie auf ihr.
     *
     * Ein `flock` auf die verwaltete Datei müsste sie zum Schreiben öffnen, und
     * ein `fopen` mit `w` kürzt, bevor irgendjemand das Schloss geprüft hat.
     */
    public function test_the_lock_lies_beside_the_file(): void
    {
        $vorher = file_get_contents($this->path);

        ManagedBlock::locked($this->path, static fn (): bool => true);

        $this->assertFileExists(
            $this->path.'.srvpanel.lock',
            'Es gibt keine Sperrdatei neben der verwalteten Datei.',
        );
        $this->assertSame(
            $vorher,
            file_get_contents($this->path),
            'Das blosse Nehmen der Sperre hat die Datei verändert. Dann liegt sie auf ihr.',
        );
    }

    // ------------------------------------------------------------------- fahrend

    /**
     * Zweimal genommen ist kein Stillstand.
     *
     * **Im Kindprozess mit Frist**, weil ein Rückfall sonst nicht fehlschlägt,
     * sondern hängt — und ein hängender Wächter hält die ganze Prüfung an,
     * statt etwas zu melden.
     */
    public function test_the_lock_is_reentrant(): void
    {
        $this->assertTrue(
            function_exists('pcntl_fork'),
            'Ohne pcntl lässt sich diese Regel nicht mit Frist prüfen — und der Agent braucht es ohnehin.',
        );

        $marke = $this->path.'.durch';
        $kind = pcntl_fork();

        if ($kind === 0) {
            // Im Kind: verschachtelt sperren. Kommt es durch, hinterlässt es
            // die Marke; hängt es, bleibt sie aus und der Vater bricht ab.
            ManagedBlock::locked($this->path, function (): void {
                ManagedBlock::locked($this->path, function (): void {
                    ManagedBlock::put($this->path, ManagedBlock::read($this->path));
                });
            });

            file_put_contents($marke, 'durch');
            exit(0);
        }

        $this->assertGreaterThan(0, $kind, 'Der Kindprozess liess sich nicht starten.');

        $frist = microtime(true) + 10.0;
        $stand = 0;

        while (microtime(true) < $frist) {
            if (pcntl_waitpid($kind, $stand, WNOHANG) === $kind) {
                break;
            }

            usleep(20_000);
        }

        if (! file_exists($marke)) {
            posix_kill($kind, SIGKILL);
            pcntl_waitpid($kind, $stand);

            $this->fail(
                'Die verschachtelte Sperre ist nicht durchgekommen. flock sperrt je offener Datei '
                .'und nicht je Prozess — ohne den Zähler in ManagedBlock::locked() wartet der '
                .'zweite Aufruf auf den ersten und damit auf sich selbst.',
            );
        }

        $this->assertSame(0, pcntl_wexitstatus($stand), 'Der verschachtelte Aufruf endete mit einem Fehler.');
    }

    /**
     * Geschrieben wird ganz oder gar nicht.
     *
     * Belegt an der **Inode**: Wird die Datei ersetzt statt beschrieben, ist es
     * hinterher eine andere. Ein `file_put_contents` behielte sie und hätte
     * zwischendurch eine leere Datei hinterlassen — die ist syntaktisch
     * fehlerfrei und weist jeden ab.
     */
    public function test_the_file_is_replaced_and_never_truncated(): void
    {
        $inode = fileinode($this->path);

        ManagedBlock::locked($this->path, function (): void {
            ManagedBlock::put($this->path, $this->existing()."# dazu\n");
        });

        clearstatcache();

        $this->assertGreaterThan(0, $inode, 'Die Inode liess sich nicht lesen.');
        $this->assertTrue(
            $inode !== fileinode($this->path),
            'Die Datei hat dieselbe Inode wie vorher — sie wurde beschrieben statt ersetzt.',
        );
        $this->assertSame(
            $this->existing()."# dazu\n",
            file_get_contents($this->path),
            'Der Inhalt stimmt nicht.',
        );
        $this->assertSame(
            [],
            array_values(array_filter(
                glob($this->path.'.srvpanel.*') ?: [],
                static fn (string $p): bool => ! str_ends_with($p, '.lock'),
            )),
            'Eine Nachbardatei ist liegengeblieben.',
        );
    }

    /** Alles ausserhalb der Marken bleibt Byte für Byte stehen. */
    public function test_everything_outside_the_markers_stays(): void
    {
        $mit = ManagedBlock::render($this->existing(), ['host  a  b  c  d'], $this->path);
        $ohne = ManagedBlock::render($mit, [], $this->path);

        $this->assertStringContainsString($this->existing(), $mit, 'Der Bestand ist beim Setzen verändert worden.');
        $this->assertSame($this->existing(), $ohne, 'Der Bestand ist beim Entfernen verändert worden.');
        $this->assertSame(['host  a  b  c  d'], ManagedBlock::managed($mit), 'Der Bereich liest sich nicht zurück.');
    }

    /**
     * `BEGIN` ohne `END` ist ein Abbruch und keine Reparatur.
     *
     * Und die Meldung nennt die Datei: Dieselbe Klasse bedient inzwischen
     * zwei, und „in irgendeiner Datei" schickt den Betreiber suchen.
     */
    public function test_a_block_without_an_end_stops_instead_of_guessing(): void
    {
        $kaputt = $this->existing()."\n".ManagedBlock::BEGIN."\nhost  a  b  c  d\n";

        try {
            ManagedBlock::render($kaputt, ['host  e  f  g  h'], '/etc/ssh/sshd_config');
        } catch (AgentException $fehler) {
            $this->assertStringContainsString('sshd_config', $fehler->getMessage(), 'Die Meldung nennt die Datei nicht.');
            $this->assertStringContainsString('Zeile 5', $fehler->getMessage(), 'Die Meldung nennt die Zeile nicht.');

            return;
        }

        $this->fail('Ein BEGIN ohne END ist durchgegangen — geraten wird hier nicht.');
    }

    // ------------------------------------------------------------------ Werkzeug

    /**
     * Die Aufrufe von `put()`/`read()`, die ausserhalb einer Sperre stehen.
     *
     * **Gezählt wird an den Klammern und nicht an der Einrückung.** `locked()`
     * bekommt seine Arbeit als Aufruf-Argument; alles zwischen seiner
     * öffnenden und schliessenden Klammer steht unter der Sperre, und sonst
     * nichts. Ein Ausdruck über Zeilen wäre eine Vermutung über Formatierung.
     *
     * @return list<string>
     */
    private function callsOutsideTheLock(string $source): array
    {
        $tokens = token_get_all($source);
        $depth = 0;
        $locks = [];
        $draussen = [];

        foreach ($tokens as $index => $token) {
            if ($token === '(') {
                $depth++;

                continue;
            }

            if ($token === ')') {
                $depth--;

                while ($locks !== [] && $depth <= end($locks)) {
                    array_pop($locks);
                }

                continue;
            }

            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'ManagedBlock') {
                continue;
            }

            $name = $this->methodAfter($tokens, $index);

            if ($name === 'locked') {
                // Die Tiefe *vor* der öffnenden Klammer merken: Alles darüber
                // gehört zu diesem Aufruf.
                $locks[] = $depth;

                continue;
            }

            if (in_array($name, ['put', 'read', 'validated'], true) && $locks === []) {
                $draussen[] = $name;
            }
        }

        return $draussen;
    }

    /** @param array<int,array{0:int,1:string,2:int}|string> $tokens */
    private function methodAfter(array $tokens, int $index): ?string
    {
        $doppelpunkt = false;

        for ($i = $index + 1, $ende = min($index + 4, count($tokens)); $i < $ende; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                $doppelpunkt = true;

                continue;
            }

            if ($doppelpunkt && is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /** @return array<string,string> Pfad => Quelltext */
    private function agentSources(): array
    {
        $wurzel = dirname(__DIR__, 2).'/agent/src';
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));
        $quellen = [];

        foreach ($dateien as $datei) {
            if (! $datei instanceof \SplFileInfo || $datei->getExtension() !== 'php') {
                continue;
            }

            $inhalt = file_get_contents($datei->getPathname());

            if (is_string($inhalt) && str_contains($inhalt, 'ManagedBlock::')) {
                $quellen[substr($datei->getPathname(), strlen($wurzel) + 1)] = $inhalt;
            }
        }

        return $quellen;
    }

    /** @param class-string $class */
    private function sourceOf(string $class): string
    {
        $datei = (new \ReflectionClass($class))->getFileName();

        $this->assertTrue(is_string($datei), 'Zu '.$class.' gibt es keine Datei.');

        return (string) file_get_contents((string) $datei);
    }

    /**
     * Kommentare heraus — sie erklären hier gerade, warum **nicht** geschrieben wird.
     *
     * Ohne diesen Schritt meldete der Wächter jede Erklärung als Verstoss, und
     * die Erklärung ist das, was dieses Projekt an seinen Klassen am meisten
     * wert ist.
     */
    private function withoutComments(string $source): string
    {
        $ohne = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $ohne .= is_array($token) ? $token[1] : $token;
        }

        return $ohne;
    }
}
