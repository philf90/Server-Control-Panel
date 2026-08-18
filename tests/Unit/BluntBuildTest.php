<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die stumpfen Fassungen finden noch, was sie wegnehmen sollen.
 *
 * ## Wozu es sie gibt
 *
 * Das Abnahmekriterium von P6 (`docs/51 §4`) verlangt den Angriffsdurchgang
 * **zweimal**: scharf und gegen ein Panel, dem die Schranke genommen wurde.
 * Ohne die zweite Hälfte belegt die erste nichts —
 *
 * > **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die
 * > Abwehr.**
 *
 * `tests/stumpf.sh` baut diese zweite Hälfte, und zwar in drei Spielarten:
 * Zwischen einem Pfad aus dem Formular und einer Datei stehen **zwei** Wände,
 * und eine Gegenprobe, die beide zugleich wegnimmt, sagt über keine von beiden
 * etwas (`docs/61 §1`).
 *
 * ## Warum dieser Wächter und nicht nur das Skript
 *
 * Ein Eingriff greift an einer wörtlich genannten Zeile an. Zieht der Code um,
 * findet er sie nicht mehr — und das fällt erst im Lauf auf, auf einem echten
 * Server, mitten in einem Abnahmedurchgang.
 *
 * > **Ein Eintrag, den der Ausdruck nie erreicht, sieht aus wie eine Abdeckung
 * > und ist eine Lücke.**
 *
 * Dieselbe Aufgabe hat `Feature\BreakScriptTest` für
 * `tests/waechter-brechen.sh`, und dort hat sie an einem einzigen Tag fünf
 * verwaiste Eingriffe gefangen.
 */
final class BluntBuildTest extends TestCase
{
    /**
     * Jeder der drei Eingriffe findet seine Stelle **und** wirkt.
     *
     * Der Trockenlauf wendet jeden an, misst am laufenden Code nach, dass die
     * Wand danach weg ist, und setzt zurück. Ein Eingriff, der die Stelle nicht
     * mehr trifft, sähe im Durchgang aus wie eine Wand, die hält.
     */
    public function test_every_blunt_build_still_finds_and_removes_its_wall(): void
    {
        [$code, $ausgabe] = $this->run('--trocken');

        $this->assertSame(0, $code, "tests/stumpf.sh --trocken meldet einen Fehlschlag:\n".$ausgabe);

        foreach (['a', 'b', 'c'] as $spielart) {
            $this->assertStringContainsString(
                $spielart.': Zielstellen gefunden',
                $ausgabe,
                sprintf('Der Eingriff „%s" findet seine Stelle nicht mehr oder wirkt nicht.', $spielart),
            );
        }
    }

    /**
     * Und im sauberen Baum stehen alle drei Wände.
     *
     * **Das ist die Gegenprobe und nicht die Wiederholung von oben.** Der
     * Trockenlauf zeigt, dass sich die Wände *wegnehmen* lassen; erst diese
     * Prüfung zeigt, dass sie vorher überhaupt da waren.
     *
     * > **Eine Gegenprobe, die nicht treffen kann, ist keine.**
     */
    public function test_the_walls_stand_in_a_clean_tree(): void
    {
        [$code, $ausgabe] = $this->run('--pruefen');

        $this->assertSame(0, $code, "tests/stumpf.sh --pruefen meldet einen Fehlschlag:\n".$ausgabe);

        // Gezählt wird nicht das Wort — es steht auch in der Zeile, die die
        // Erwartung ansagt, und in der Übersicht darüber. Geprüft wird die
        // Zusage je Spielart.
        foreach (['a', 'b', 'c'] as $spielart) {
            $this->assertStringContainsString(
                $spielart.' ist scharf',
                $ausgabe,
                sprintf(
                    "Die Wand „%s“ steht im Arbeitsbaum nicht. Liegt hier noch eine stumpfe\n".
                    "Fassung? `sh tests/stumpf.sh --zurueck` nimmt sie weg. Gemeldet wurde:\n%s",
                    $spielart,
                    $ausgabe,
                ),
            );
        }
    }

    /**
     * Ein Schalter wäre ein dauerhaftes Loch im ausgelieferten Code.
     *
     * Die stumpfe Fassung ist ein **Bau**: eine Änderung am Arbeitsbaum, die
     * `--zurueck` wieder wegnimmt. Eine Umgebungsvariable, die dasselbe täte,
     * bliebe im Paket — und der Abnahmelauf hätte sie selbst hineingebaut.
     */
    public function test_no_switch_turns_the_wall_off_at_runtime(): void
    {
        foreach (['agent/src/Files/Workspace.php', 'agent/src/Sandbox.php'] as $datei) {
            $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/'.$datei);

            foreach (['getenv(', '$_ENV', '$_SERVER'] as $weg) {
                $this->assertStringNotContainsString(
                    $weg,
                    $quelle,
                    sprintf(
                        '%s liest die Umgebung. Ein Schalter, der die Schranke abschaltet, gehört '.
                        'nicht in den ausgelieferten Code — die stumpfe Fassung ist ein Bau.',
                        $datei,
                    ),
                );
            }
        }
    }

    /** @return array{0: int, 1: string} */
    private function run(string $argument): array
    {
        $wurzel = dirname(__DIR__, 2);

        exec(sprintf('cd %s && sh tests/stumpf.sh %s 2>&1', escapeshellarg($wurzel), $argument), $zeilen, $code);

        return [$code, implode("\n", $zeilen)];
    }
}
