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

    /**
     * Die fünf Ausgänge, wie `apt-run` sie schreibt.
     *
     * **Der Prüfkörper `wirkungslos` stand hier bis zum 31. August 2026 mit
     * einer `0` — und dieser Wächter behauptete daneben, das sei ein
     * Fehlschlag.** Genau diese Zeile hat auf `cloudsrv24` einen roten Vorgang
     * erzeugt, obwohl nichts anstand (`docs/91 §17`).
     *
     * > **Ein Prüfkörper, der den Fehler enthält, hält ihn fest statt ihn zu
     * > melden — wenn die Behauptung daneben ihn für richtig erklärt.**
     *
     * Er trägt jetzt eine Zahl grösser null, denn das ist der Fall, den er
     * meint: Es stand etwas an, und es ist nichts angekommen. Der Fall mit der
     * Null steht daneben als `nichts_offen` und ist **kein** Fehlschlag.
     */
    private const ECHT = [
        'gescheitert' => 'apt-run: apt-get endete mit 100. offene Aktualisierungen: vorher 5, jetzt 5.',
        'wirkungslos' => 'apt-run: Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 7.',
        'nichts_offen' => 'apt-run: Es stand nichts an — offene Aktualisierungen: 0.',
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
            substr(self::ECHT['wirkungslos'], strlen(Outcome::PREFIX)),
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
     * Welche der fünf Formen ein Fehlschlag ist.
     *
     * **Beide Richtungen in einem Fall.** Ein `failed()`, das immer `true`
     * sagt, bestünde die eine Hälfte genauso.
     *
     * **Und `nichts_offen` ist die Zeile, um die es seit dem 31. August geht.**
     * „Nichts zu tun" und „nicht geschafft" sind zwei Ausgänge, und nur einer
     * ist ein Fehlschlag — die Spiegelung von M5, dem Befund, mit dem P7b
     * angefangen hat.
     */
    public function test_two_of_the_five_verdicts_are_failures(): void
    {
        $ab = static fn (string $zeile): string => substr($zeile, strlen(Outcome::PREFIX));

        $this->assertTrue(Outcome::failed($ab(self::ECHT['gescheitert'])));
        $this->assertTrue(Outcome::failed($ab(self::ECHT['wirkungslos'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['nichts_offen'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['panel'])));
        $this->assertFalse(Outcome::failed($ab(self::ECHT['pakete'])));
    }

    /**
     * `apt-run` unterscheidet die beiden Fälle, und zwar an der Zahl.
     *
     * Gehalten wird am Skript und nicht am Leser: Der Leser braucht dafür keine
     * Zeile — was nicht in `BAD` steht, ist bei ihm ein Erfolg mit einer
     * Meldung. Der Fehler steckte allein darin, dass das Skript beide Fälle
     * gleich benannte und mit `3` endete.
     */
    public function test_the_script_tells_nothing_pending_from_nothing_changed(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        $this->assertStringContainsString(
            'Es stand nichts an',
            $skript,
            'Ein Lauf ohne Anlass meldet wieder „Der Lauf hat nichts verändert" — und damit einen Fehlschlag.',
        );

        // Der Ausstieg muss `0` sein und ausdrücklich auf den Zählmodus
        // beschränkt: Bei `--fassung` ist `nachher` eine Versionsnummer und nie
        // null, und dort bleibt „vorher wie nachher" ein Fehlschlag.
        $this->assertMatchesRegularExpression(
            '/\[ "\$mass" = offen \].*?\[ "\$nachher" -eq 0 \]/s',
            $skript,
            'Die Nachsicht ist nicht auf den Zählmodus beschränkt.',
        );

        $this->assertMatchesRegularExpression(
            '/Es stand nichts an[^\n]*\n\s*exit 0\n/',
            $skript,
            'Ein Lauf ohne Anlass endet nicht mit 0 — dann steht die Unit auf `failed`.',
        );
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
