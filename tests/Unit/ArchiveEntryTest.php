<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Files\Archive;
use SrvPanel\Agent\Runner;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Archiv entpackt sich dorthin, wo es entpackt wird.
 *
 * **Was dieser Wächter nicht ist: die Schranke.** Ein Eintrag
 * `../../../../etc/cron.d/x` verlässt die Wurzel des Abonnements nicht, ganz
 * gleich was {@see Archive::normalise()} täte — das hält das Chroot
 * (`docs/51 §5`). Gemessen mit einem naiven Entpacker daneben, der genau das
 * tut, was man hinschreibt, wenn man an Zip-Slip nicht denkt: Er legt
 * `/tmp/AUSGEBROCHEN.txt` an. In der Sandbox wird derselbe Eintrag
 * übersprungen und **gemeldet**.
 *
 * Was hier geprüft wird, ist die Verlegung *innerhalb* des Abonnements:
 *
 * > Ein Archiv, das nach `httpdocs/` entpackt wird, hat in `.ssh/` nichts zu
 * > suchen. Nicht, weil der Kunde dort nicht schreiben dürfte — er darf —,
 * > sondern weil er es an dieser Stelle nicht gemeint hat.
 */
final class ArchiveEntryTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Was hinaus will, wird verworfen — und nicht zurechtgebogen.
     */
    public function test_entries_that_lead_out_are_dropped(): void
    {
        foreach ([
            '../../../../etc/passwd' => 'zwei Punkte',
            '/etc/passwd' => 'absolut',
            'C:/windows/system32' => 'Laufwerksbuchstabe',
            '..\\..\\windows.txt' => 'Backslash als Trenner',
            "harmlos\0/../../etc/passwd" => 'Nullbyte',
            'a/../../b' => 'zwei Punkte in der Mitte',
            '..' => 'nur zwei Punkte',
            '' => 'leer',
        ] as $name => $warum) {
            $this->assertNull(
                Archive::normalise($name),
                sprintf('Der Eintrag „%s" (%s) wird entpackt.', $name, $warum),
            );
        }
    }

    /**
     * **`a/../../b` wird verworfen und nicht zu `b`.**
     *
     * Ein `array_pop` auf die Bestandteile wäre der naheliegende Weg und der
     * falsche: Er machte aus einem Eintrag, der hinaus will, einen gültigen —
     * und entpackte damit etwas, das das Archiv so nie benannt hat. Was hinaus
     * will, wird übersprungen.
     */
    public function test_a_way_out_is_not_bent_into_a_way_in(): void
    {
        $this->assertNull(Archive::normalise('a/../../b'));
        $this->assertNull(Archive::normalise('a/../b'), 'Auch ein einzelnes .. wird nicht zurechtgebogen.');
    }

    /**
     * Und was harmlos ist, wird entpackt — der Nachbar der Nullen oben.
     *
     * Ohne diesen Fall wäre ein `normalise()`, das immer `null` liefert, für
     * den Test darüber der sauberste Zustand überhaupt.
     */
    public function test_harmless_entries_survive(): void
    {
        $this->assertSame('harmlos.txt', Archive::normalise('harmlos.txt'));
        $this->assertSame('unter/tief/tiefer.txt', Archive::normalise('unter/tief/tiefer.txt'));
        $this->assertSame('mit punkt/datei.tar.gz', Archive::normalise('./mit punkt//datei.tar.gz'));
        $this->assertSame('a.txt', Archive::normalise('./a.txt'));
    }

    /**
     * Entpacken und Packen kommen ohne ein neues Programm aus.
     *
     * **`unzip`, `tar` und `zip` stehen nicht auf der Positivliste**, und das
     * ist keine Sparsamkeit: Jedes von ihnen bekäme einen Pfad des Kunden als
     * Argument, und die Positivliste ist die Stelle, an der dieses Projekt
     * entscheidet, welcher Code als root läuft. `ZipArchive` und `PharData`
     * laufen im Kind der Sandbox — ohne Rechte und im Chroot.
     */
    public function test_archives_need_no_new_program(): void
    {
        $programs = array_keys(Runner::programs());

        foreach (['unzip', 'zip', 'tar', 'gzip', 'bsdtar', '7z'] as $forbidden) {
            $this->assertNotContains($forbidden, $programs, sprintf(
                '%s steht auf der Positivliste. Archive werden in der Sandbox entpackt, '
                .'nicht von einem Programm, das als root einen Kundenpfad bekommt.',
                $forbidden,
            ));
        }

        $this->assertGreaterThan(10, count($programs), 'Die Positivliste ist leer — dann prueft dieser Test nichts.');
    }

    /**
     * Die Suche bekommt keinen regulären Ausdruck des Kunden.
     *
     * Ein `(a+)+b` gegen eine lange Zeile bringt den Vorgang zum Stillstand,
     * und es gibt kein Zeitlimit, das den Prozess rechtzeitig einholt.
     * `str_contains` kann das nicht — und `preg_match` mit einem Muster aus der
     * Anfrage wäre genau der Unterschied.
     */
    public function test_the_search_matches_literally(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/FilesSearch.php'),
        );

        $this->assertMatchesRegularExpression('/str_contains\(/', $source, 'Die Suche vergleicht nicht mehr wörtlich.');

        foreach (['preg_match', 'preg_grep', 'fnmatch'] as $muster) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![\w$>])'.preg_quote($muster, '/').'\s*\(/',
                $source,
                sprintf('Die Suche benutzt %s() — ein Muster aus der Anfrage kann den Vorgang anhalten.', $muster),
            );
        }
    }

    /**
     * Ein abgebrochener Suchlauf sagt, dass er abgebrochen ist.
     *
     * > Eine leere Ergebnisliste, die einen Abbruch verschweigt, behauptet
     * > etwas, das sie nicht weiss.
     */
    public function test_a_truncated_search_says_so(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/FilesSearch.php'),
        );

        $this->assertMatchesRegularExpression(
            "/'truncated'\s*=>/",
            $source,
            'Der Suchlauf meldet nicht mehr, ob er zu Ende gelaufen ist.',
        );
    }

    /**
     * Und ein Eintrag, der an einem Verweis hängenbleibt, wird gemeldet.
     *
     * **Er stand zuerst in keiner der beiden Listen.** Der Lauf meldete „0
     * geschrieben, 0 übersprungen", und der Kunde hätte ein leeres Verzeichnis
     * gehabt und keine Auskunft — das ist kein Befund, sondern ein Rätsel.
     */
    public function test_a_redirected_entry_is_reported(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/FilesExtract.php'),
        );

        $this->assertMatchesRegularExpression(
            "/'redirected'\s*=>/",
            $source,
            'Einträge, die an einem Verweis hängenbleiben, verschwinden spurlos.',
        );
    }
}
