<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Outcome;
use Tests\Support\WithoutPhpComments;

/**
 * Das Urteil eines abgesetzten Laufs, gelesen ab seinem eigenen Versatz.
 *
 * **Die Prüfkörper sind selbst gebaut und nicht aus dem Prüfling geholt.** Ein
 * Log, das dieser Leser erzeugt hat, prüfte ihn gegen sich selbst; die Zeilen
 * hier stehen so, wie `apt-run` sie im Abnahmelauf wirklich geschrieben hat
 * (`docs/86`, Punkte 5, 5d, 10).
 */
final class OutcomeTest extends TestCase
{
    use WithoutPhpComments;

    /** Die vier Ausgänge, wie `apt-run` sie schreibt. */
    private const ECHT = [
        'gescheitert' => 'apt-run: apt-get endete mit 100. offene Aktualisierungen: vorher 5, jetzt 5.',
        'wirkungslos' => 'apt-run: Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 0.',
        'panel' => 'apt-run: Fassung 0.7.2~rc.3 wurde zu 0.7.2~rc.4.',
        'pakete' => 'apt-run: 5 von 5 Aktualisierungen eingespielt, 0 bleiben offen.',
    ];

    private function datei(): string
    {
        return sys_get_temp_dir().'/srvpanel-outcome-'.getmypid().'.log';
    }

    private function schreibe(string $inhalt): string
    {
        $pfad = $this->datei();
        file_put_contents($pfad, $inhalt);

        return $pfad;
    }

    protected function tearDown(): void
    {
        @unlink($this->datei());
    }

    public function test_the_verdict_is_read_from_the_own_offset(): void
    {
        $vorher = "0 aktualisiert, 0 neu installiert.\n".self::ECHT['pakete']."\n";
        $pfad = $this->schreibe($vorher);
        $versatz = strlen($vorher);

        file_put_contents($pfad, "Reading package lists...\n".self::ECHT['wirkungslos']."\n", FILE_APPEND);

        $this->assertSame(
            'Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 0.',
            Outcome::verdict(Outcome::lines($pfad, $versatz)),
        );
    }

    /**
     * Und die Gegenprobe: Ohne den Versatz gewinnt der falsche Lauf.
     *
     * **Ohne sie belegte der Test darüber nichts.** Er wäre auch dann grün,
     * wenn der Leser schlicht die letzte Zeile der Datei nähme — die ist hier
     * zufällig dieselbe. Erst ein Versatz **hinter** dem zweiten Lauf zeigt,
     * dass die Grenze wirkt.
     */
    public function test_without_the_offset_an_earlier_run_would_be_read(): void
    {
        $erster = "Lauf eins\n".self::ECHT['pakete']."\n";
        $pfad = $this->schreibe($erster);

        $this->assertSame(
            '5 von 5 Aktualisierungen eingespielt, 0 bleiben offen.',
            Outcome::verdict(Outcome::lines($pfad, 0)),
            'Der Prüfkörper trägt gar kein Urteil — dann misst der Test daneben nichts.',
        );

        $this->assertNull(
            Outcome::verdict(Outcome::lines($pfad, strlen($erster))),
            'Ein Versatz hinter dem letzten Lauf muss leer zurückkommen — sonst liest dieser '
            .'Leser das Urteil eines fremden Laufs als das eigene.',
        );
    }

    /**
     * Eine Datei, die kürzer geworden ist, hat neu angefangen.
     *
     * `PanelUpdate` leert sein Log zu Beginn jedes Laufs. Ein Versatz aus dem
     * vorigen Lauf ist dann grösser als die ganze Datei — und ohne diesen Fall
     * käme „noch kein Urteil" zurück, während eines dasteht.
     */
    public function test_a_truncated_log_is_read_from_the_start(): void
    {
        $pfad = $this->schreibe(self::ECHT['panel']."\n");

        $this->assertSame(
            'Fassung 0.7.2~rc.3 wurde zu 0.7.2~rc.4.',
            Outcome::verdict(Outcome::lines($pfad, 9_000)),
        );
    }

    public function test_a_run_without_a_verdict_yet_answers_null(): void
    {
        $pfad = $this->schreibe("Reading package lists...\nBuilding dependency tree...\n");

        $this->assertNull(Outcome::verdict(Outcome::lines($pfad, 0)));
    }

    public function test_a_missing_log_is_not_a_verdict(): void
    {
        $this->assertSame([], Outcome::lines('/nicht/vorhanden/upgrade.log', 0));
        $this->assertNull(Outcome::verdict([]));
    }

    /**
     * Welche der vier Formen ein Fehlschlag ist.
     *
     * **Beide Richtungen in einem Fall.** Ein `failed()`, das immer `true`
     * sagt, bestünde die eine Hälfte genauso.
     */
    public function test_two_of_the_four_verdicts_are_failures(): void
    {
        $ab = static fn (string $zeile): string => substr($zeile, strlen(Outcome::PREFIX));

        $this->assertTrue(Outcome::failed($ab(self::ECHT['gescheitert'])));
        $this->assertTrue(Outcome::failed($ab(self::ECHT['wirkungslos'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['panel'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['pakete'])));
    }

    /**
     * Der Präfix steht in `apt-run`, und hier steht derselbe.
     *
     * **Die teuerste Naht dieses Bauteils.** Liefen die beiden auseinander,
     * fände der Leser nie ein Urteil und meldete „läuft noch", bis die Frist
     * abläuft — ein Fehler, der wie Geduld aussieht.
     */
    public function test_the_prefix_is_the_one_apt_run_writes(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        $this->assertSame(
            1,
            preg_match('/^NAME=(\S+)$/m', $skript, $treffer),
            'In `apt-run` steht kein `NAME=` mehr — dann weiss dieser Test nicht, wogegen er hält.',
        );

        $this->assertSame(
            $treffer[1].': ',
            Outcome::PREFIX,
            'Der Präfix in `Outcome` und der Name in `apt-run` sind auseinandergelaufen. '
            .'Der Leser fände dann nie ein Urteil und meldete „läuft noch", bis die Frist abläuft.',
        );
    }

    /**
     * Und jede Form aus `BAD` steht wirklich in `apt-run`.
     *
     * Die Gegenrichtung zum Test darüber: Ein Eintrag, der dort nicht mehr
     * geschrieben wird, ist ein Fehlschlag, den niemand mehr melden kann.
     */
    public function test_every_bad_verdict_is_a_line_apt_run_writes(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        foreach (Outcome::BAD as $anfang) {
            $this->assertStringContainsString(
                $anfang,
                $skript,
                sprintf('„%s" steht in BAD, aber `apt-run` schreibt es nicht mehr.', $anfang),
            );
        }
    }
}
