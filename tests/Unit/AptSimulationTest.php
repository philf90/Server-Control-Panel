<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Apt;
use Tests\Support\WithoutHashComments;
use Tests\Support\WithoutPhpComments;

/**
 * Nachgesehen wird dort, wo eingespielt wird — und die Naht dahin hält.
 *
 * ## Die echte Regression, die dieser Wächter nachstellt
 *
 * Gemessen auf `cloudsrv24` am 26. August 2026 (`docs/86`, Befund 6). Derselbe
 * Befehl, zwei Orte, zwei Antworten:
 *
 *     im Agenten (PrivateTmp & Co.)   → 11 `Inst`-Zeilen
 *     transiente Unit (apt-run all)   →  4
 *     RestrictNamespaces              →  4   (legt keinen Namensraum an)
 *
 * Die Seite zeigte elf, der Knopf spielte vier ein, und `apt-run` schrieb ins
 * Protokoll *„offen: vorher 4, jetzt 0"* — eine Zahl, die der Betreiber nie
 * gesehen hatte. Die Ursache ist `ischroot`: In einem Mount-Namensraum meldet
 * es `rc=0`, und in einem chroot wendet Ubuntu sein *Phasing* nicht an.
 *
 * > **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen und nicht
 * > eine.**
 *
 * ## Warum die Prüfung so aussieht
 *
 * Der eigentliche Rückfall wäre, dass jemand die Simulation wieder unmittelbar
 * in den Agenten holt — eine Zeile, die für sich völlig harmlos aussieht. Ein
 * Ausdruck, der sie sucht, findet im heilen Zustand aber **nichts**, und eine
 * Null ohne etwas daneben ist keine Messung. Deshalb steht neben der Suche
 * eine Untergrenze: Die beiden bekannten Aufrufer müssen `Apt::simulate()`
 * auch wirklich rufen.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * Und die Marke steht an zwei Enden — in `Apt` und im Skript. Liefe sie
 * auseinander, fände {@see Apt::sections()} nichts, und die Seite bekäme eine
 * Ausnahme statt einer Zahl.
 */
final class AptSimulationTest extends TestCase
{
    use WithoutHashComments;
    use WithoutPhpComments;

    /** Der Weg zum Skript, das apt für uns ruft. */
    private const SCRIPT = 'packaging/bin/apt-run';

    /** Beide Enden der Naht tragen dieselbe Marke. */
    public function test_the_mark_is_the_same_on_both_ends(): void
    {
        $skript = $this->script();

        $this->assertSame(
            1,
            preg_match('/^MARKE=\'([^\']+)\'$/m', $skript, $treffer),
            'In '.self::SCRIPT.' steht keine Zeile `MARKE=\'…\'` mehr — dann liest dieser Test die falsche Stelle.',
        );

        $this->assertSame(
            Apt::MARK,
            $treffer[1],
            'Die Marke in '.self::SCRIPT.' und `Apt::MARK` sind verschieden. `Apt::sections()` findet dann '
            .'keinen Abschnitt, und die Updates-Seite bekommt eine Ausnahme statt einer Zahl.',
        );
    }

    /** Und das Skript schreibt genau die Abschnitte, die der Leser erwartet. */
    public function test_the_script_writes_exactly_the_expected_sections(): void
    {
        preg_match_all('/^\s*echo "\$MARKE ([a-z-]+)"$/m', $this->script(), $treffer);

        $this->assertSame(
            Apt::RUNS,
            $treffer[1],
            'Die Abschnitte, die `apt-run simulate` schreibt, und `Apt::RUNS` laufen auseinander — '
            .'in Reihenfolge oder Bestand. `Apt::sections()` wirft dann für einen Abschnitt, den es gibt.',
        );
    }

    /** Den Modus gibt es, und er steigt vor dem gemeinsamen Rumpf aus. */
    public function test_the_script_knows_the_mode_and_leaves_early(): void
    {
        $skript = $this->script();

        $this->assertSame(1, preg_match('/^\s*simulate\)$/m', $skript), sprintf(
            '%s kennt den Modus `simulate` nicht (mehr). Der Agent ruft ihn und bekäme '
            .'„Unbekannter Modus" mit Rückgabewert 2.',
            self::SCRIPT,
        ));

        $this->assertMatchesRegularExpression(
            '/simulate\)(?:.|\n)*?exit "\$zweit"/',
            $skript,
            'Der Modus `simulate` fällt in den gemeinsamen Rumpf. Der misst ein Vorher, spielt ein '
            .'und misst ein Nachher — beim Nachsehen gibt es nichts einzuspielen, und `set -- ` steht '
            .'dort gar nicht: apt-get liefe ohne Unterbefehl.',
        );
    }

    /** Zwei Abschnitte werden getrennt, und was davor steht, gehört niemandem. */
    public function test_sections_splits_on_the_mark(): void
    {
        $stdout = implode("\n", [
            'Running as unit: gibt-es-nicht.service',
            Apt::MARK.' dist-upgrade',
            'Inst libheif1 [1.17.6-1ubuntu4.7] (1.17.6-1ubuntu4.8 Ubuntu:24.04/noble-security [amd64])',
            'Conf libheif1 (1.17.6-1ubuntu4.8 Ubuntu:24.04/noble-security [amd64])',
            Apt::MARK.' upgrade',
            'The following upgrades have been deferred due to phasing:',
            '  procps',
        ]);

        $abschnitte = Apt::sections($stdout);

        $this->assertSame(Apt::RUNS, array_keys($abschnitte));

        $this->assertStringContainsString('Inst libheif1', $abschnitte['dist-upgrade']);
        $this->assertStringNotContainsString('Running as unit', $abschnitte['dist-upgrade']);
        $this->assertStringNotContainsString('deferred due to phasing', $abschnitte['dist-upgrade']);

        $this->assertStringContainsString('deferred due to phasing', $abschnitte['upgrade']);
        $this->assertStringNotContainsString('Inst libheif1', $abschnitte['upgrade']);
    }

    /**
     * Ein fehlender Abschnitt wirft — er wird nicht zur leeren Liste.
     *
     * **Das ist der teure Fall.** Ein leerer Abschnitt läuft ohne ein Zeichen
     * durch `Packages::read()` und ergibt „nichts zu aktualisieren" — also
     * genau die Antwort, die ein Betreiber für bare Münze nimmt.
     */
    public function test_a_missing_section_throws(): void
    {
        $this->expectException(AgentException::class);

        Apt::sections(Apt::MARK.' dist-upgrade'."\n".'Inst tar [1.0] (1.1 Ubuntu:24.04/noble [amd64])');
    }

    /** Und eine Ausgabe ganz ohne Marke erst recht. */
    public function test_output_without_any_mark_throws(): void
    {
        $this->expectException(AgentException::class);

        Apt::sections("Reading package lists...\nBuilding dependency tree...");
    }

    /**
     * Keine Operation fragt apt mehr unmittelbar nach einer Simulation.
     *
     * Die Untergrenze steht daneben und ist der Prüfkörper: Ohne sie meldete
     * dieser Test Grün für einen Agenten, der gar kein apt mehr ruft.
     */
    public function test_no_operation_simulates_apt_inside_the_agent(): void
    {
        $strays = [];
        $rufer = 0;

        foreach ($this->operationSources() as $pfad => $quelltext) {
            if (str_contains($quelltext, 'Apt::simulate(')) {
                $rufer++;
            }

            if (preg_match('/run\(\s*\'apt-get\'\s*,\s*\[?\s*\'-s\'/', $quelltext) === 1) {
                $strays[] = $pfad;
            }
        }

        $this->assertGreaterThanOrEqual(2, $rufer, sprintf(
            'Nur %d Operation(en) rufen `Apt::simulate()`. Erwartet werden mindestens zwei — die '
            .'Liste für die Seite und die Positivliste des Laufs. Ist eine davon fort, prüft dieser '
            .'Test die andere Hälfte nicht mehr.',
            $rufer,
        ));

        $this->assertSame([], $strays, sprintf(
            "Diese Operationen fragen apt unmittelbar mit `-s`:\n\n  %s\n\n"
            .'Im Agenten antwortet apt anders als in der transienten Unit, in der eingespielt wird — '
            .'gemessen elf gegen vier (docs/86, Befund 6). Gefragt wird über `Apt::simulate()`.',
            implode("\n  ", $strays),
        ));
    }

    /** Der Quelltext des Skripts, ohne seine Kommentare. */
    private function script(): string
    {
        return $this->withoutHashComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.self::SCRIPT),
        );
    }

    /** @return array<string, string> Pfad => Quelltext ohne Kommentare */
    private function operationSources(): array
    {
        $root = dirname(__DIR__, 2);
        $quellen = [];

        foreach (glob($root.'/agent/src/Ops/*.php') ?: [] as $pfad) {
            $quellen[substr($pfad, strlen($root) + 1)] = $this->withoutComments(
                (string) file_get_contents($pfad),
            );
        }

        return $quellen;
    }
}
