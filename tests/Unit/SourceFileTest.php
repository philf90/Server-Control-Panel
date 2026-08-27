<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Sources;

/**
 * Welche Dateien in `/etc/apt/sources.list.d` überhaupt gelesen werden.
 *
 * ## Der Anlass: `docs/86`, Befund 13
 *
 * Auf `cloudsrv24` liegt seit der Installation ein
 * `ubuntu.sources.curtin.orig` — vom Ubuntu-Installer, keine Quelle. apt liest
 * es nicht, der Leser des Panels las es auch nicht, **und gehalten hat das
 * nichts.** `SourceListTest` prüft zehn Fragen über das *Zerlegen* einer Datei
 * und keine darüber, welche Dateien in die Liste kommen; der Ausdruck stand
 * alleinstehend in einer Ops-Datei, wo ihn kein Wächter erreicht, weil
 * `Context` `final` ist.
 *
 * > **Ein Filter, der stimmt, und ein Filter, den etwas hält, sehen heute
 * > gleich aus — und morgen nicht mehr.**
 *
 * Ein `*` an dieser Stelle meldete dem Betreiber `ubuntu.sources.curtin.orig`
 * als abgeschaltete Quelle — eine Datei, die er nicht einschalten kann, weil
 * apt sie nie ansieht.
 *
 * ## Die Prüfkörper sind gemessen und nicht ausgedacht
 *
 * Alle acht Namen stammen aus einem Lauf gegen echtes apt (27. August 2026,
 * apt 2.8.3, eigenes `Dir::Etc::sourceparts`): Von acht Dateien holt apt genau
 * zwei Ziele. Die anderen sechs ignoriert es **stumm** — kein Wort auf stderr,
 * Rückgabewert 0.
 *
 * Und die Gegenprobe desselben Laufs steht hier als eigener Fall: Dieselben
 * Bytes noch einmal mit der Endung `.sources`, und apt holt drei Ziele.
 *
 * > **Ein Prüfkörper, der nicht gelesen wird, kann auch kaputt sein — das sieht
 * > gleich aus.**
 *
 * ## Warum der Wächter seine Pfade übergibt
 *
 * {@see Sources::files()} hat keine Vorgabewerte. Läse dieser Wächter das echte
 * `/etc/apt`, hinge sein Ergebnis daran, was auf der messenden Maschine gerade
 * liegt — und genau daran war `SourceOwnershipTest` am 26. August in der CI rot
 * und im Container grün.
 *
 * > **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher liegt, misst
 * > die Umgebung mit.**
 */
final class SourceFileTest extends TestCase
{
    /**
     * Die acht Namen aus der Messung, mit apts Urteil daneben.
     *
     * @var array<string, bool>
     */
    private const PROBES = [
        'ubuntu.sources' => true,
        'zz-docker.list' => true,
        'ubuntu.sources.curtin.orig' => false,
        'php-sury.list.bak' => false,
        'notizen.txt' => false,
        'alt.disabled' => false,
        'test.sources.disabled' => false,
        'test.list.disabled' => false,
    ];

    public function test_only_the_two_extensions_apt_reads_are_read(): void
    {
        $wurzel = $this->layOutProbes();

        // Die Untergrenze: Ohne sie wäre ein leeres Verzeichnis eine bestandene
        // Prüfung. Eine Null ist nur dann eine Messung, wenn daneben etwas
        // anderes als Null steht.
        //
        // `+ 1`, weil die Hauptdatei im selben Verzeichnis liegt. Das ist
        // Absicht: So misst dieser Fall zugleich, dass sie **nicht** ein
        // zweites Mal aus dem Verzeichnis dazukommt.
        $this->assertCount(
            count(self::PROBES),
            glob($this->parts($wurzel).'/*') ?: [],
            'Die Prüfkörper liegen nicht.'
        );

        $gelesen = $this->namesOf(Sources::files($this->main($wurzel), $this->parts($wurzel)));

        $erwartet = array_keys(array_filter(self::PROBES));
        sort($erwartet);

        $this->assertSame(
            ['sources.list', ...$erwartet],
            $gelesen,
            'Gelesen wird etwas anderes, als apt liest.'
        );

        $this->cleanUp($wurzel);
    }

    /**
     * Die Gegenprobe: An den Bytes liegt es nicht.
     *
     * Ohne diesen Fall hiesse „`ubuntu.sources.curtin.orig` steht nicht in der
     * Liste" auch „sein Inhalt ist kaputt". Es ist derselbe Inhalt.
     */
    public function test_the_same_bytes_are_read_once_the_extension_changes(): void
    {
        $wurzel = $this->layOutProbes();

        $vorher = $this->namesOf(Sources::files($this->main($wurzel), $this->parts($wurzel)));
        $this->assertNotContains('ubuntu.sources.curtin.orig', $vorher);

        copy($this->parts($wurzel).'/ubuntu.sources.curtin.orig', $this->parts($wurzel).'/curtin.sources');
        $nachher = $this->namesOf(Sources::files($this->main($wurzel), $this->parts($wurzel)));

        $this->assertContains('curtin.sources', $nachher, 'Dann lag es doch am Inhalt.');
        $this->assertCount(count($vorher) + 1, $nachher);

        $this->cleanUp($wurzel);
    }

    /**
     * Jede Endung der Konstanten wird auch wirklich gelesen.
     *
     * **Die Richtung, in der ein toter Eintrag wirklich entsteht.** Wer
     * {@see Sources::EXTENSIONS} erweitert und die Schleife danebenstehen lässt,
     * bekommt eine Konstante, die etwas verspricht, was niemand einlöst — und
     * der erste Fall wäre eine Quelle, die auf der Seite fehlt.
     */
    public function test_every_extension_of_the_constant_is_read(): void
    {
        $this->assertNotEmpty(Sources::EXTENSIONS, 'Eine leere Positivliste ist kein Mechanismus.');

        foreach (Sources::EXTENSIONS as $endung) {
            $wurzel = $this->layOutProbes();
            file_put_contents($this->parts($wurzel).'/probe.'.$endung, "# leer\n");

            $this->assertContains(
                'probe.'.$endung,
                $this->namesOf(Sources::files($this->main($wurzel), $this->parts($wurzel))),
                'Die Endung "'.$endung.'" steht in der Konstanten und wird nicht gelesen.'
            );

            $this->cleanUp($wurzel);
        }
    }

    /**
     * Erst die Hauptdatei, dann das Verzeichnis in Namensfolge — apts Reihenfolge.
     *
     * **Der Prüfkörper überschreitet die Endungsgrenze, und das ist der Punkt.**
     * `glob()` sortiert von sich aus, und {@see Sources::files()} sucht je
     * Endung einmal — ohne ein abschliessendes `sort()` käme also
     * `[alle .list][alle .sources]` heraus. Mit `docker.list` und
     * `ubuntu.sources` wäre das zufällig schon die richtige Folge gewesen, und
     * der Fall hätte grün gemeldet, was er prüfen soll. Mit `zz-docker.list`
     * steht das `.list` hinter dem `.sources`, und die beiden Fassungen gehen
     * auseinander.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht.**
     */
    public function test_the_main_file_comes_first_and_the_parts_follow_in_name_order(): void
    {
        $wurzel = $this->layOutProbes();

        $gelesen = $this->namesOf(Sources::files($this->main($wurzel), $this->parts($wurzel)));

        $this->assertSame('sources.list', $gelesen[0], 'sources.list steht nicht vorn.');

        $rest = array_slice($gelesen, 1);
        $sortiert = $rest;
        sort($sortiert);
        $this->assertSame($sortiert, $rest, 'Das Verzeichnis kommt nicht in Namensfolge.');
    }

    /**
     * Eine fehlende Hauptdatei fällt weg — und nimmt das Verzeichnis nicht mit.
     *
     * Auf einem heutigen Ubuntu ist `sources.list` nur noch ein Hinweistext;
     * es kann auch ganz fehlen. Wer daraus eine leere Liste machte, verschwiege
     * jede Quelle des Servers.
     */
    public function test_a_missing_main_file_is_left_out_without_taking_the_parts(): void
    {
        $wurzel = $this->layOutProbes();

        $gelesen = $this->namesOf(Sources::files($wurzel.'/gibt-es-nicht.list', $this->parts($wurzel)));

        $this->assertNotContains('gibt-es-nicht.list', $gelesen);
        $this->assertContains('ubuntu.sources', $gelesen, 'Mit der Hauptdatei ist alles fort.');

        $this->cleanUp($wurzel);
    }

    /**
     * Die Operation fragt die Naht und sucht nicht selbst.
     *
     * **Das ist die Regression, die diesen Wächter wieder blind machte.** Ein
     * zweites `glob` in der Ops-Datei stünde dort, wo ihn nichts erreicht, und
     * beide Fassungen liefen auseinander — die zweite ist die, die veraltet.
     */
    public function test_the_operation_asks_the_seam_instead_of_globbing(): void
    {
        $datei = __DIR__.'/../../agent/src/Ops/SystemSourcesList.php';
        $quelle = file_get_contents($datei);

        $this->assertIsString($quelle);
        $this->assertStringContainsString('Sources::files(', $quelle, 'Die Naht wird nicht gefragt.');
        $this->assertDoesNotMatchRegularExpression(
            '/\bglob\s*\(/',
            $quelle,
            'Die Operation sucht wieder selbst — dort hält sie kein Wächter.'
        );
    }

    /**
     * Die echte Gestalt: `sources.list` **neben** `sources.list.d`, nicht darin.
     *
     * **Der erste Wurf legte beide in denselben Ordner**, und der Wächter
     * meldete `sources.list` zweimal — einmal als Hauptdatei, einmal aus dem
     * Verzeichnis. Das sah nach einem Fehler im Leser aus und war einer im
     * Prüfkörper: Auf keinem Server liegt die Hauptdatei in ihrem eigenen
     * Teilverzeichnis, die beiden Pfade können gar nicht zusammenfallen.
     *
     * > **Ein Prüfkörper, der eine Lage herstellt, die es nicht geben kann,
     * > verlangt eine Änderung, die niemand braucht.**
     *
     * @return string Die Wurzel
     */
    private function layOutProbes(): string
    {
        $wurzel = sys_get_temp_dir().'/srvpanel-quelldateien-'.bin2hex(random_bytes(6));
        mkdir($wurzel.'/sources.list.d', 0o700, true);

        file_put_contents($wurzel.'/sources.list', "# nur Kommentar\n");

        foreach (array_keys(self::PROBES) as $name) {
            // Derselbe Inhalt in jeder Datei — sonst entschiede am Ende der
            // Inhalt mit, und gemessen werden soll die Endung.
            file_put_contents(
                $wurzel.'/sources.list.d/'.$name,
                "Types: deb\nURIs: http://beispiel.invalid/deb\nSuites: noble\nComponents: main\n"
            );
        }

        return $wurzel;
    }

    private function main(string $wurzel): string
    {
        return $wurzel.'/sources.list';
    }

    private function parts(string $wurzel): string
    {
        return $wurzel.'/sources.list.d';
    }

    /**
     * @param  list<string>  $pfade
     * @return list<string>
     */
    private function namesOf(array $pfade): array
    {
        return array_map(static fn (string $p): string => basename($p), $pfade);
    }

    private function cleanUp(string $wurzel): void
    {
        foreach (glob($this->parts($wurzel).'/*') ?: [] as $datei) {
            unlink($datei);
        }

        rmdir($this->parts($wurzel));
        @unlink($this->main($wurzel));
        rmdir($wurzel);
    }
}
