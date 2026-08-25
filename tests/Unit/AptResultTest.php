<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Apt;
use SrvPanel\Agent\Result;
use Tests\Support\WithoutPhpComments;

/**
 * `apt-get update` meldet Erfolg, auch wenn keine Quelle geantwortet hat.
 *
 * ## Die echte Regression, die dieser Wächter nachstellt
 *
 * Gemessen am 24. August 2026 im Container gegen apt 2.8.3, mit einer Quelle
 * auf `127.0.0.1:1` und getrennten Kanälen:
 *
 *     rc=0 · stdout 0 Bytes · stderr 244 Bytes
 *     W: Failed to fetch http://127.0.0.1:1/gibtsnicht/dists/noble/InRelease  Could not connect …
 *     W: Some index files failed to download. They have been ignored, or old ones used instead.
 *
 * Bis dahin stand an drei Stellen `if (! $update->successful())` und sonst
 * nichts. Diese Bedingung kann für eine tote Quelle nicht rot werden — sie
 * fragt, ob apt danach einen benutzbaren Zustand hat, und den hat es: Die alte
 * Liste bleibt liegen.
 *
 * > **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine
 * > Prüfung — er ist eine Zeile, die aussieht wie eine.**
 *
 * Was folgte, war an `php.version.install` zu besichtigen: *„Die Installation
 * ist fehlgeschlagen: Unable to locate package php8.4-fpm"*. Der Zustand
 * richtig, die Ursache falsch — und der Betreiber sucht am Paket, während der
 * Fehler an der Quelle sitzt.
 *
 * ## Warum dieser Wächter nicht nach `successful()` sucht
 *
 * Weil er dann grün wäre, sobald irgendwo daneben eine zweite Prüfung steht —
 * derselbe Fehler wie der Wächter aus `docs/62` Punkt 12, der einen Satz suchte
 * statt seiner Erreichbarkeit.
 *
 * > **Ein Wächter, der ein Wort sucht statt einer Wirkung, ist grün, sobald das
 * > Wort irgendwo steht.**
 *
 * Er tut deshalb zweierlei, und keines davon ist eine Wortsuche nach der
 * richtigen Lösung:
 *
 * 1. **Er zählt die Aufrufe** von `apt-get update` im PHP-Quelltext und
 *    besteht darauf, dass jeder einzelne in {@see Apt} liegt oder unten mit
 *    seinem Grund steht.
 * 2. **Er misst die Wirkung** an einem selbstgebauten {@see Result}: Rückgabe
 *    0, `W:`-Zeilen auf `stderr` — genau die Lage von oben. Wer den Leser
 *    herausnimmt und wieder nur `successful()` fragt, wird hier rot.
 *
 * ## Was ausserhalb dieser Regel liegt, und warum
 *
 * `packaging/php-source.sh` ruft am Ende ebenfalls `apt-get update -qq`. Das
 * ist ein Shell-Skript im `postinst` eines Pakets — es kann nicht über eine
 * PHP-Klasse gehen, und es installiert nichts, dessen Fassung von der
 * Auffrischung abhinge. Die Regel hier gilt für den Agenten und die Anwendung.
 */
final class AptResultTest extends TestCase
{
    use WithoutPhpComments;

    /** Die eine Stelle, an der `apt-get update` stehen darf. */
    private const HOME = 'agent/src/Apt.php';

    /**
     * Wer sonst noch `apt-get update` ruft — und warum das (noch) so ist.
     *
     * **Eine benannte Ausnahme ist keine Erlaubnis, sondern eine Schuld mit
     * Adresse.** Sie steht hier, damit sie nicht als erledigt durchgeht;
     * `docs/81 §9` führt sie als Teil 3 von M5 in Schritt 6.
     *
     * @var array<string,string>
     */
    private const EXCEPTIONS = [
        'agent/src/Ops/PanelUpdate.php' => 'Der Lauf liegt in einer eigenen transienten Unit, damit er den Neustart des Agenten '
            .'überlebt; seine Ausgabe geht in eine Datei. Wer sie hier läse, wartete auf ein Update, '
            .'das genau diesen Prozess beendet. Teil 3 von M5 liest statt dessen nach dem Neustart '
            .'die eigene Fassung nach — Schritt 6 in docs/81 §9.',
    ];

    /**
     * Woran ein Aufruf erkannt wird.
     *
     * Drei Formen, weil `apt-get update` in diesem Repo drei Gestalten hat:
     * als Zeile in einer Shell, als Argumentliste am Runner und als Konstante.
     * Jede wird unten an selbstgebauten Zeilen gegengeprüft — ein Ausdruck, der
     * ins Leere läuft, hat nicht wenig gemessen, sondern gar nicht.
     *
     * @var array<string,string>
     */
    private const SHAPES = [
        // `apt-get update` als Zeile in einer Shell. Der Vorausblick trennt den
        // **Aufruf** von der **Erwähnung**: Auf ein `update` in einer
        // Kommandozeile folgt eine Fahne, ein `&&`, eine Umleitung oder das
        // Ende — nie ein Wort. In „apt-get update ist fehlgeschlagen" folgt
        // eines, und das ist ein Satz und kein Befehl.
        'shell' => '/apt-get\s+update(?!\s*[a-zA-Z])/',
        'liste' => '/\'apt-get\'\s*,\s*(?:array\(|\[)[^\])]*\'update\'/',
        'konstante' => '/(?:self|Apt)::UPDATE_ARGUMENTS/',
    ];

    /**
     * Jeder Aufruf von `apt-get update` geht über {@see Apt} — oder steht oben.
     */
    public function test_every_call_of_apt_get_update_goes_through_one_place(): void
    {
        $strays = [];
        $found = 0;

        foreach ($this->phpFiles() as $path => $source) {
            $hits = $this->updateCallSites($source);

            if ($hits === 0) {
                continue;
            }

            $found += $hits;

            if ($path === self::HOME || array_key_exists($path, self::EXCEPTIONS)) {
                continue;
            }

            $strays[] = sprintf('%s (%dx)', $path, $hits);
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(1, $found, 'Es wird kaum ein Aufruf gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $strays, sprintf(
            "Diese Stellen rufen `apt-get update`, ohne über %s zu gehen:\n\n  %s\n\n"
            .'Der Rückgabewert allein kann eine unerreichbare Quelle nicht melden (M5). Wer hier '
            .'eine Stelle braucht, trägt sie mit ihrem Grund in AptResultTest::EXCEPTIONS ein.',
            self::HOME,
            implode("\n  ", $strays),
        ));
    }

    /**
     * Die Stelle und jede Ausnahme werden vom Ausdruck auch wirklich getroffen.
     *
     * **Der Prüfkörper des Wächters oben.** Zieht `apt-get update` um oder
     * ändert seine Schreibweise, findet die Suche nichts mehr — und meldete
     * dann Grün für eine Regel, die sie gar nicht mehr liest.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_home_and_every_exception_are_reached_by_the_scan(): void
    {
        $files = $this->phpFiles();

        foreach (array_merge([self::HOME], array_keys(self::EXCEPTIONS)) as $path) {
            $this->assertArrayHasKey($path, $files, $path.' gibt es nicht mehr.');

            $this->assertGreaterThan(0, $this->updateCallSites($files[$path]), sprintf(
                '%s ruft kein `apt-get update` mehr — entweder ist die Stelle umgezogen '
                .'(dann gehört sie hier berichtigt) oder der Ausdruck trifft sie nicht mehr '
                .'(dann misst dieser Wächter nichts).',
                $path,
            ));
        }
    }

    /**
     * **Die Wirkung**, und damit der Kern dieses Wächters.
     *
     * Ein Lauf, der mit 0 endet und dabei eine Quelle verloren hat, ist kein
     * gelungener Lauf. Wer den Leser herausnimmt und wieder nur den
     * Rückgabewert fragt, wird hier rot — die Zeilen sind Zeichen für Zeichen
     * die gemessenen.
     */
    public function test_a_run_that_ends_with_zero_can_still_have_lost_a_source(): void
    {
        $apt = Apt::of(new Result(0, '', $this->measuredStderr()));

        $this->assertTrue($apt->result->successful(), 'Der Rückgabewert ist 0 — genau darum geht es.');
        $this->assertFalse($apt->reachedEverything(), 'Eine unerreichbare Quelle muss den Lauf unvollständig machen.');
        $this->assertCount(1, $apt->unreachable);
        $this->assertSame('http://127.0.0.1:1/gibtsnicht/', $apt->unreachable[0]['base']);
        $this->assertStringContainsString('Connection refused', $apt->unreachable[0]['reason']);
    }

    /**
     * Und die Gegenprobe: Ein Lauf ohne `W:`-Zeile meldet nichts.
     *
     * Ohne sie bestünde der Test oben auch für einen Leser, der immer einen
     * Ausfall meldet — und der wäre genauso falsch, nur andersherum.
     */
    public function test_a_clean_run_reports_no_source(): void
    {
        $apt = Apt::of(new Result(0, '', ''));

        $this->assertTrue($apt->reachedEverything());
        $this->assertSame([], $apt->unreachable);
        $this->assertSame('', $apt->summary());
    }

    /**
     * Die Zusammenfassung am Ende ist keine Quelle.
     *
     * *„W: Some index files failed to download"* steht **einmal** da, egal wie
     * viele Quellen ausgefallen sind. Wer sie mitzählt, meldet bei einer toten
     * Quelle zwei — und bei zweien drei.
     */
    public function test_the_closing_summary_is_not_counted_as_a_source(): void
    {
        $stderr = $this->measuredStderr();

        $this->assertStringContainsString('Some index files failed to download', $stderr, 'Der Prüfkörper trägt die Zeile.');
        $this->assertCount(1, Apt::readFailures($stderr));
    }

    /**
     * Mit `--error-on=any` heisst dieselbe Zeile `E:` statt `W:`.
     *
     * Gemessen am selben Tag: `rc=100`, sonst Zeichen für Zeichen dieselbe
     * Ausgabe. Zwei Leser für eine Zeile wären zwei Fassungen derselben Regel.
     */
    public function test_the_error_variant_is_read_as_well(): void
    {
        $failures = Apt::readFailures(str_replace('W: ', 'E: ', $this->measuredStderr()));

        $this->assertCount(1, $failures);
        $this->assertSame('http://127.0.0.1:1/gibtsnicht/', $failures[0]['base']);
    }

    /**
     * Nur die eigene Quelle entscheidet — und ein Nachbarpfad ist nicht sie.
     *
     * Verglichen wird der Anfang der vollen Adresse, und beide Seiten bekommen
     * vorher einen Schrägstrich. Ohne ihn hielte `https://host/php` auch
     * `https://host/php-alt/…` für seine eigene Quelle: Ein Abbruch mit der
     * Begründung „deine Quelle ist tot" für eine Quelle, die lebt.
     */
    public function test_only_the_own_source_decides(): void
    {
        $apt = Apt::of(new Result(0, '', $this->failureLine('https://host/php-alt/dists/noble/InRelease')));

        $this->assertNull($apt->hitting(['https://host/php']), 'Ein Nachbarpfad ist nicht die eigene Quelle.');
        $this->assertNull($apt->hitting([]), 'Ohne eigene Quelle gibt es niemanden zu beschuldigen.');
        $this->assertNotNull($apt->hitting(['https://host/php-alt']), 'Ohne Schrägstrich am Ende muss sie trotzdem passen.');
        $this->assertNotNull($apt->hitting(['https://host/php-alt/']), 'Und mit ihm ebenso.');
    }

    /**
     * Die drei Formen treffen, was sie sollen — und nichts daneben.
     *
     * **Selbstgebaute Prüfkörper**, so wie `ArchiveDepthTest` seine Archive
     * baut. Zwei der drei Formen kommen im Repo heute gar nicht mehr vor: Die
     * Argumentliste stand bis zum 24. August in `PhpVersionInstall` und
     * `PgServerInstall` und ist genau das, was nicht zurückkommen darf. Ein
     * Ausdruck, der auf keinen lebenden Fall zeigt, wird sonst nie gemessen.
     */
    public function test_the_shapes_find_what_they_look_for(): void
    {
        $hits = [
            "\$context->stream('apt-get', ['update', '-qq'], 300);",
            "\$context->stream('apt-get', self::UPDATE_ARGUMENTS, \$timeout);",
            "'apt-get update -qq && apt-get install -y --only-upgrade srvpanel',",
            "\$runner->run('apt-get', array('update'), 300);",
            "'apt-get update && apt-get install -y srvpanel',",
            "'/bin/sh', '-c', 'apt-get update > /var/log/x.log',",
        ];

        foreach ($hits as $line) {
            $this->assertGreaterThan(0, $this->updateCallSites($line), 'Nicht getroffen: '.$line);
        }

        $misses = [
            "\$context->stream('apt-get', ['install', '-y', \$package], 900);",
            "\$context->stream('apt-get', ['remove', '-y', '--purge'], 600);",
            '// apt-get update steht hier nur im Kommentar',
            "\$this->assertSame('update', \$was);",
            "throw AgentException::execFailed('apt-get update ist fehlgeschlagen: '.\$result->message());",
        ];

        foreach ($misses as $line) {
            $this->assertSame(0, $this->updateCallSites($line), 'Fälschlich getroffen: '.$line);
        }
    }

    /**
     * Ein Aufruf im Kommentar ist kein Aufruf.
     *
     * `Apt`, `PanelUpdate` und `PhpVersionInstall` erklären den Befund M5 im
     * Fliesstext und schreiben `apt-get update` dabei wörtlich hin. Ohne den
     * Schnitt durch {@see WithoutPhpComments} meldete dieser Wächter sie alle
     * als Verstoss — und die naheliegende Reaktion wäre, die Erklärung zu
     * streichen.
     */
    public function test_a_call_in_a_comment_is_not_a_call(): void
    {
        $php = "<?php\n/** Hier steht apt-get update im Block. */\n// und apt-get update in der Zeile\n\$x = 1;\n";

        $this->assertSame(0, $this->updateCallSites($php));
    }

    /**
     * Die gemessene Ausgabe, Zeichen für Zeichen.
     *
     * @see AptResultTest Der Kopf dieser Datei nennt Lauf, Fassung und Datum.
     */
    private function measuredStderr(): string
    {
        return $this->failureLine('http://127.0.0.1:1/gibtsnicht/dists/noble/InRelease')
            ."W: Some index files failed to download. They have been ignored, or old ones used instead.\n";
    }

    /** Eine `W:`-Zeile in der gemessenen Form — zwei Leerzeichen vor der Begründung. */
    private function failureLine(string $uri): string
    {
        return 'W: Failed to fetch '.$uri
            .'  Could not connect to 127.0.0.1:1 (127.0.0.1). - connect (111: Connection refused)'."\n";
    }

    /** Wie oft ruft dieser Quelltext `apt-get update`? */
    private function updateCallSites(string $source): int
    {
        $source = $this->withoutComments(str_starts_with(ltrim($source), '<?php') ? $source : "<?php\n".$source);
        $hits = 0;

        foreach (self::SHAPES as $shape) {
            $hits += preg_match_all($shape, $source);
        }

        return $hits;
    }

    /**
     * Der PHP-Quelltext von Agent und Anwendung, nach Pfad ab der Wurzel.
     *
     * @return array<string,string>
     */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['agent/src', 'app'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
