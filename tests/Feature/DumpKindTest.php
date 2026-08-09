<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DumpKind;
use App\Enums\DumpStatus;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Herkunft einer Sicherung wird nicht getippt.
 *
 * **Der Anlass ist eine Kleinigkeit aus dem P5-Abnahmelauf** (`docs/36
 * §22.3w`). `database_dumps.kind` kannte zwei Werte — verschieden gebaut, ein
 * Stamm und ein Partizip —, und sie standen als nackte Zeichenketten an vier
 * Stellen: zweimal im Dienst, einmal im Agenten, einmal im Vue-Template.
 * Nebenan war {@see DumpStatus} längst eine Aufzählung.
 *
 * (Ausgeschrieben stehen sie hier nicht: Dieser Test verbietet sie überall
 * ausser in der Aufzählung, und ein Zitat ist auch ein Vorkommen. Er hat das
 * schon zweimal an Kommentaren gezeigt, die beim Bauen dieses Beitrags
 * entstanden sind.)
 *
 * **Was daran gefährlich ist, ist nicht die Asymmetrie, sondern das Tippen.**
 * Wer `'exported'` schreibt, trifft nichts — kein Fehler, keine Meldung, nur
 * eine Bedingung, die nie zutrifft. Genau dieselbe Bauart wie der teure Fund
 * desselben Laufs, bei dem der Agent `pct` schickte und das Panel `percent`
 * las.
 *
 * Geprüft wird deshalb, dass die Werte **nur** in der Aufzählung stehen — und
 * dass über die Grenze zum Browser der fertige Text geht und nicht der Wert.
 */
final class DumpKindTest extends TestCase
{
    /**
     * Wo ein Wert stehen darf: in der Aufzählung selbst und in der Migration,
     * die die Spalte anlegt.
     *
     * Die Migration nennt ihn im Kommentar, und das soll sie: Wer das Schema
     * liest, soll nicht erst eine Klasse suchen müssen.
     *
     * @var list<string>
     */
    private const ERLAUBT = [
        'app/Enums/DumpKind.php',
        'database/migrations/2026_08_08_100100_create_database_dumps_table.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Alles, was das Panel ausmacht — ohne den Agenten.
     *
     * **`tests/` steht seit dem ersten roten Lauf darin, und das ist der
     * Anlass.** Der erste Wurf dieses Wächters las nur `app/`, `resources/js/`
     * und `database/` — und genau in `tests/` blieb eine Behauptung stehen, die
     * den Wert als Zeichenkette gegen die Spalte hielt. Sie fiel erst in der
     * CI auf, mit dem Cast auf die Aufzählung. *Ein Wächter, der den Ort
     * auslässt, an dem die zweite Fassung einer Regel am häufigsten steht, ist
     * der halbe Wächter.*
     *
     * **`agent/` steht dagegen bewusst nicht darin.** Der Agent ist framework- und
     * abhängigkeitsfrei; eine Aufzählung aus `app/` darf er nicht kennen
     * (CLAUDE.md, Leitbild 1). Er meldet `kind` in seiner Antwort, und das
     * Panel liest sie an dieser Stelle nicht — es weiss es besser, weil es die
     * Zeile selbst geschrieben hat.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'resources/js', 'database', 'tests'] as $verzeichnis) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root().'/'.$verzeichnis, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'vue', 'ts'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace($this->root().'/', '', $path);
    }

    public function test_no_kind_is_written_as_a_literal(): void
    {
        $werte = array_map(static fn (DumpKind $kind): string => $kind->value, DumpKind::cases());

        $this->assertNotSame([], $werte, 'Die Aufzählung ist leer — dann prüft dieser Test nichts.');

        $funde = [];
        $gelesen = 0;

        foreach ($this->sources() as $file) {
            $kurz = $this->relative($file);

            if (in_array($kurz, self::ERLAUBT, true)) {
                continue;
            }

            $gelesen++;

            foreach (file($file) ?: [] as $nummer => $zeile) {
                foreach ($werte as $wert) {
                    // In Anführungszeichen und für sich allein: ein längeres
                    // Wort mit demselben Anfang trifft nicht, und ein Wort im
                    // Fliesstext eines Kommentars auch nicht.
                    if (preg_match('/([\'"])'.preg_quote($wert, '/').'\1/', $zeile) === 1) {
                        $funde[] = sprintf('%s:%d  %s', $kurz, $nummer + 1, trim($zeile));
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $gelesen, 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $funde, sprintf(
            "Die Herkunft einer Sicherung steht hier als Zeichenkette:\n  %s\n\n".
            "Sie gehört in App\\Enums\\DumpKind. Wer sie tippt, kann sich vertippen — und ein\n".
            'Vergleich, der nie zutrifft, meldet sich nicht.',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Und über die Grenze zum Browser geht die Marke, nicht der Wert.
     *
     * **Bis zum 9. August verglich das Template die Herkunft selbst.** Das ist
     * derselbe Bau wie der Frame-Fehler, nur zwischen PHP und JavaScript: ein
     * Wert über eine Grenze, die kein Typ prüft. Hinüber geht jetzt
     * {@see DumpKind::label()}.
     */
    public function test_the_interface_gets_the_label_and_not_the_value(): void
    {
        $template = (string) file_get_contents($this->root().'/resources/js/Pages/Databases/Show.vue');

        $this->assertStringContainsString('kind_label', $template, implode("\n", [
            'Die Oberfläche bekommt die Herkunft nicht als fertigen Text.',
            '',
            'Dann vergleicht sie wieder Werte, die auf der anderen Seite jemand ändern kann,',
            'ohne dass hier etwas rot wird.',
        ]));

        $this->assertStringNotContainsString('dump.kind ===', $template);
    }

    /**
     * Nur die mitgebrachte trägt eine Marke.
     *
     * Eine Marke an jeder Zeile wäre keine Auskunft mehr: Der Regelfall braucht
     * kein Etikett, und eine Spalte, in der überall dasselbe steht, liest nach
     * zwei Tagen niemand mehr.
     */
    public function test_only_the_imported_one_is_marked(): void
    {
        $this->assertNull(DumpKind::Export->label());
        $this->assertSame('mitgebracht', DumpKind::Imported->label());
    }
}
