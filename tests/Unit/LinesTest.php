<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Lines;
use Tests\Support\WithoutPhpComments;

/**
 * Eine Stückgrenze ist keine Zeilengrenze.
 *
 * ## Befund 4 aus `docs/86`, und er war eine Frage
 *
 * Auf der Vorgangsseite stand `W` allein und `: …` darunter. Offen war, ob der
 * Umbruch aus `app.css` kommt oder aus dem gespeicherten Text.
 *
 * **Gemessen am 28. August 2026 gegen den echten Runner und echtes apt**
 * (`apt-get -q -s dist-upgrade`, 34 001 Bytes): **320** Zeilen auf dem
 * Rahmenweg gegen **317** in der vollständigen Ausgabe desselben Laufs. Die
 * Gegenprobe war die Ausgabe selbst — sie geht nicht durch den Rahmenweg.
 *
 * > **Eine Messung braucht einen zweiten Weg zum selben Gegenstand, sonst
 * > misst sie sich selbst.**
 *
 * Danach: 317 gegen 317.
 */
final class LinesTest extends TestCase
{
    use WithoutPhpComments;

    /** Die Zeile, an der der Betreiber es gesehen hat. */
    private const ECHT = 'W: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease: '
        .'Signature by key 14AA…6C uses weak algorithm (rsa1024)';

    public function test_a_line_torn_by_a_chunk_boundary_comes_back_whole(): void
    {
        $lines = new Lines;

        // Die Grenze faellt zwischen `W` und `:` — genau der Fall aus docs/86.
        $erste = $lines->feed('stdout', "vorher\nW");
        $zweite = $lines->feed('stdout', substr(self::ECHT, 1)."\n");

        $this->assertSame(['vorher'], $erste,
            'Das erste Stück gibt mehr als die eine ganze Zeile heraus — dann steht das `W` schon '
            .'als eigene Zeile da, und der Rest kommt nie mehr an sie heran.');

        $this->assertSame([self::ECHT], $zweite);
    }

    /**
     * Und die Gegenprobe: Ohne den Rest zerreisst dieselbe Grenze die Zeile.
     *
     * **Ohne sie belegte der Test darüber nichts.** Er wäre auch dann grün,
     * wenn `feed()` schlicht jedes Stück ganz zurückgäbe — die Zeile käme
     * zufällig auch heil an. Erst der Vergleich mit der alten Rechnung zeigt,
     * dass die Grenze überhaupt eine war.
     */
    public function test_the_old_way_would_have_torn_it(): void
    {
        $alt = static function (string $chunk): array {
            // Die Fassung vor dem 28. August 2026, wörtlich.
            return explode("\n", rtrim($chunk, "\n"));
        };

        $this->assertSame(['vorher', 'W'], $alt("vorher\nW"),
            'Der Prüfkörper zerreisst nichts — dann misst der Test daneben nicht die Grenze.');

        $this->assertSame(
            [substr(self::ECHT, 1)],
            $alt(substr(self::ECHT, 1)."\n"),
        );
    }

    /**
     * Ein Stück, das mit einem Umbruch beginnt, erfindet keine leere Zeile.
     *
     * Das war die Abweichung, die die Messung als **erste** gefunden hat: `W`
     * war der auffällige Fall, die eingeschobene Leerzeile der häufige.
     */
    public function test_a_chunk_starting_with_a_newline_invents_no_empty_line(): void
    {
        $lines = new Lines;

        $this->assertSame([], $lines->feed('stdout', 'Reading package lists...'));
        $this->assertSame(
            ['Reading package lists...', 'Building dependency tree...'],
            $lines->feed('stdout', "\nBuilding dependency tree...\n"),
        );
    }

    /**
     * Zwei Kanäle teilen sich keinen Rest.
     *
     * Ein gemeinsamer klebte das halbe Ende von `stdout` an den Anfang von
     * `stderr` — heraus käme eine Zeile, die keiner von beiden geschrieben hat.
     */
    public function test_the_channels_keep_their_own_remainder(): void
    {
        $lines = new Lines;

        $this->assertSame([], $lines->feed('stdout', 'halbe '));
        $this->assertSame([], $lines->feed('stderr', 'andere '));

        $this->assertSame(['halbe Zeile'], $lines->feed('stdout', "Zeile\n"));
        $this->assertSame(['andere Zeile'], $lines->feed('stderr', "Zeile\n"));
    }

    /**
     * Was am Ende ohne Umbruch dasteht, geht nicht verloren.
     *
     * **Ausgerechnet die letzte Zeile.** Bei `apt-run` steht dort das Urteil,
     * an dem seit heute die Nachlese eines abgesetzten Laufs hängt.
     */
    public function test_a_last_line_without_a_newline_is_still_a_line(): void
    {
        $lines = new Lines;

        $this->assertSame([], $lines->feed('stdout', 'apt-run: Fassung a wurde zu b.'));
        $this->assertSame(['stdout'], $lines->pending());
        $this->assertSame('apt-run: Fassung a wurde zu b.', $lines->flush('stdout'));

        $this->assertSame([], $lines->pending(), 'Der Rest kommt ein zweites Mal — dann steht die '
            .'letzte Zeile doppelt da.');
        $this->assertNull($lines->flush('stdout'));
    }

    /**
     * Ein leerer Rest ist keine Zeile.
     *
     * Sonst hinge an jeder Ausgabe, die sauber mit einem Umbruch endet, eine
     * leere Zeile — also an fast jeder.
     */
    public function test_an_empty_remainder_is_not_a_line(): void
    {
        $lines = new Lines;

        $this->assertSame(['fertig'], $lines->feed('stdout', "fertig\n"));
        $this->assertNull($lines->flush('stdout'));
    }

    /**
     * Und der Runner benutzt ihn — samt dem Rest am Ende.
     *
     * > **Ein Wächter über eine Methode sagt nichts darüber, dass jemand sie
     * > ruft.**
     */
    public function test_the_runner_assembles_and_flushes(): void
    {
        $runner = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Runner.php'),
        );

        $this->assertStringContainsString('$lines->feed($channel, $chunk)', $runner,
            'Der Runner setzt die Zeilen nicht mehr zusammen — dann zerreisst jede Stückgrenze '
            .'wieder eine Zeile.');

        $this->assertStringContainsString('$lines->flush($channel)', $runner,
            'Der Runner gibt den Rest am Ende nicht heraus — dann fällt die letzte Zeile weg, '
            .'wenn sie ohne Umbruch endet. Bei `apt-run` steht dort das Urteil.');

        $this->assertStringNotContainsString('explode("\\n", rtrim($chunk', $runner,
            'Die alte Rechnung steht wieder da: Sie schneidet nur hinten und macht aus jeder '
            .'Stückgrenze eine Zeilengrenze.');
    }
}
