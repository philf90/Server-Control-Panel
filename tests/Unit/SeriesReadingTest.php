<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Store;
use PHPUnit\Framework\TestCase;

/**
 * Die Ablesung einer Kurve unterscheidet, was die Kurve unterscheidet.
 *
 * ## Der Anlass
 *
 * Die CPU-Kachel stand auf einem ruhigen Server dauerhaft auf `0 %` — in der
 * grossen Zahl und bei **jeder** Ablesung auf der Linie. Falsch war der Wert
 * nicht: `cpu` wurde mit null Nachkommastellen formatiert, und die Auslastung
 * lag den ganzen Tag zwischen 0,1 und 0,9.
 *
 * Die Kurve daneben zeichnete aus den **Rohwerten** und zeigte ihre Ausschläge
 * völlig richtig. Genau diese Mischung macht den Fehler unangenehm: Das Bild
 * sagt „da tut sich etwas", die Zahl sagt „nichts", und beide kommen aus
 * derselben Reihe. Wer das sieht, sucht den Fehler beim Sammler oder beim
 * Agenten — die Zahl steht ja da, sie ist nur null.
 *
 * > **Eine Zahl, die jeden Wert einer Reihe gleich schreibt, misst nichts mehr
 * > — sie behauptet nur noch.**
 *
 * ## Warum das hier geprüft wird und nicht an der Kachel
 *
 * Dieselbe Trennung wie bei {@see SeriesThresholdTest}: In `Store::series()`
 * entsteht die Aussage, in der Komponente wird sie nur gezeichnet. Die Kachel
 * bekommt `v` fertig geliefert und könnte den Fehler gar nicht bemerken.
 */
final class SeriesReadingTest extends TestCase
{
    private string $directory;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/srvpanel-reading-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->store = new Store($this->directory, 100);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /** @param  list<float>  $values */
    private function write(string $name, array $values): void
    {
        $time = 1_754_000_000.0;

        foreach ($values as $i => $value) {
            $this->store->buffer($name, 1)->write([$value], $time + $i * 10);
        }
    }

    /**
     * @param  array<string, mixed>  $series
     * @return list<string>
     */
    private function readings(array $series): array
    {
        /** @var list<array{v: string}> $points */
        $points = $series['points'];

        return array_map(static fn (array $point): string => $point['v'], $points);
    }

    /**
     * Eine bewegte Kurve unter einem Prozent liest sich nicht als lauter Nullen.
     *
     * Die Werte sind die eines Servers, der nichts tut — genau die Lage, in der
     * die Kachel gemeldet wurde.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in
     * `Store::plainFormatter()` wieder fest mit `$decimals` formatieren.
     */
    public function test_a_moving_curve_below_one_percent_is_not_all_zeroes(): void
    {
        $werte = [0.12, 0.34, 0.21, 0.88, 0.15, 0.42, 0.19, 0.71];

        $this->write('cpu', $werte);

        $ablesungen = $this->readings($this->store->series('cpu', 1, 0, 60, ' %', 0));

        /*
         * **„Mehr als eine verschiedene Ablesung" wäre zu wenig gewesen**, und
         * das ist beim Gegenprüfen herausgekommen: Mit der alten Formatierung
         * wird `0,88` zu `1 %` und alles andere zu `0 %` — zwei verschiedene
         * Ablesungen, Bedingung erfüllt, Wächter grün. Er hätte den Fehler
         * durchgelassen, wegen dem es ihn gibt.
         *
         * Verlangt ist deshalb die **Auflösung der Reihe**: Acht verschiedene
         * Messwerte ergeben acht verschiedene Ablesungen. Das ist genau die
         * Aussage, die die Kachel schuldet — die Linie unterscheidet diese
         * acht Punkte, die Zahl daneben muss es auch.
         */
        $this->assertCount(
            count(array_unique($werte)),
            array_unique($ablesungen),
            'Die Kurve unterscheidet mehr Punkte als ihre Ablesung: '
            .implode(' · ', array_unique($ablesungen)).'. Genau so stand die CPU-Kachel auf 0 %, '
            .'während die Linie daneben ihre Ausschläge zeigte.',
        );

        $this->assertNotContains(
            '0 %',
            $ablesungen,
            'Eine Ablesung schreibt einen Wert zwischen 0,1 und 0,9 als „0 %". Der Wert ist nicht '
            .'falsch, er ist nur weggerundet — und damit ist die Kachel keine Auskunft mehr.',
        );
    }

    /**
     * Und die Kurve selbst bleibt, wie sie war.
     *
     * **Die Untergrenze dieses Beitrags.** Es wäre ein leichter Fehler, die
     * Stellenzahl über die *Werte* zu lösen statt über ihre Darstellung — dann
     * änderte sich die Geometrie mit, und die Kachel zeigte eine andere Kurve
     * als vorher. Geprüft wird deshalb, dass die Spanne der Zeichenfläche
     * unberührt ist: `y` läuft weiter von 28 (kleinster Wert) bis 4 (grösster).
     */
    public function test_the_shape_of_the_curve_is_untouched(): void
    {
        $this->write('cpu', [0.12, 0.34, 0.21, 0.88, 0.15, 0.42, 0.19, 0.71]);

        /** @var list<array{y: float}> $points */
        $points = $this->store->series('cpu', 1, 0, 60, ' %', 0)['points'];
        $y = array_map(static fn (array $point): float => $point['y'], $points);

        $this->assertSame(28.0, max($y), 'Der kleinste Wert liegt nicht mehr auf der Grundlinie.');
        $this->assertSame(4.0, min($y), 'Der grösste Wert füllt die Kachel nicht mehr aus.');
    }

    /**
     * Über zehn bleibt es bei ganzen Zahlen.
     *
     * **Die Gegenrichtung, und sie zählt genauso.** Eine Regel, die überall
     * Nachkommastellen anhängt, macht aus „88 %" ein „88,00 %" — dieselbe
     * Kachel, dieselbe Unlesbarkeit, nur andersherum. Ohne diesen Fall wäre der
     * Test mit einer Regel zufrieden, die immer zwei Stellen schreibt.
     */
    public function test_a_busy_server_keeps_whole_numbers(): void
    {
        $this->write('cpu', [12.4, 47.9, 88.2, 63.5, 21.0]);

        $this->assertSame(
            ['12 %', '48 %', '88 %', '64 %', '21 %'],
            $this->readings($this->store->series('cpu', 1, 0, 60, ' %', 0)),
            'Eine Auslastung in ganzen Prozent bekommt Nachkommastellen, die niemand braucht.',
        );
    }

    /**
     * Und wer Stellen anfragt, behält sie — auch wenn der Wert gross wird.
     *
     * Die Load fragt zwei an. Ohne diese Richtung hinge ihre Genauigkeit daran,
     * wie ausgelastet der Server gerade ist: `0,15` und daneben `12` wären
     * zwei verschiedene Messungen derselben Reihe.
     */
    public function test_requested_decimals_are_never_taken_away(): void
    {
        $this->write('load', [0.15, 12.0, 3.5]);

        $this->assertSame(
            ['0,15', '12,00', '3,50'],
            $this->readings($this->store->series('load', 1, 0, 60, '', 2)),
            'Eine Reihe, die zwei Nachkommastellen anfragt, verliert sie bei grossen Werten.',
        );
    }
}
