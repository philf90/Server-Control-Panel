<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Zahl und ein Wort, das nicht zu ihr passt.
 *
 * **Warum es diesen Wächter gibt.** Im Abnahmelauf von P5c stand auf einer
 * Tabelle mit genau einer Zeile „geschätzt 1 Zeilen" — auf beiden Systemen
 * (`docs/48 §3.3`). Das Wort war an die Zahl geklebt, weil beim Schreiben eine
 * Tabelle mit 16384 Zeilen offen war.
 *
 * > **Ein Plural, der immer stimmt, stimmt nur, solange niemand eine Zeile
 * > anlegt.**
 *
 * Die Einzahl ist im Betrieb der Normalfall und in der Entwicklung der
 * Sonderfall — eine frisch angelegte Tabelle, ein einziger Treffer, ein
 * Abonnement. Deshalb fällt sie beim Bauen nicht auf und beim Benutzen sofort.
 *
 * Geprüft wird die **Form** und nicht die Grammatik: Eine eingesetzte Zahl, an
 * die unmittelbar ein Mehrzahlwort anschliesst, hat über die Einzahl nie
 * entschieden.
 */
final class CountedNounTest extends TestCase
{
    /**
     * Mehrzahlwörter, die in dieser Oberfläche hinter einer Zahl stehen können.
     *
     * **Eine Liste und keine Regel**, weil es im Deutschen keine gibt: `Zeile`
     * wird zu `Zeilen`, `Zugang` zu `Zugänge`, `Treffer` bleibt. Die Liste wächst
     * mit der Oberfläche; was fehlt, findet dieser Wächter nicht — was drinsteht,
     * findet er zuverlässig.
     */
    private const PLURALS = [
        'Zeilen', 'Tabellen', 'Spalten', 'Indexe', 'Zugänge', 'Datenbanken',
        'Sicherungen', 'Einträge', 'Zertifikate', 'Domains', 'Abonnements',
        'Konten', 'Vorgänge', 'Treffer', 'Zeichen',
    ];

    private function pattern(): string
    {
        return '/(?:\}\}|\})\s+(?:'.implode('|', self::PLURALS).')\b/u';
    }

    /** @return array<string, string> */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Keine Zahl klebt an einem Mehrzahlwort.
     *
     * Erfasst beide Schreibweisen: `${zahl} Zeilen` im Skript und
     * `{{ zahl }} Zeilen` in der Vorlage.
     */
    public function test_no_count_is_glued_to_a_plural_noun(): void
    {
        $treffer = [];

        foreach ($this->templates() as $pfad => $source) {
            foreach (explode("\n", $source) as $nummer => $zeile) {
                /*
                 * **Wer die Einzahl in derselben Zeile entscheidet, ist fein
                 * raus.** In `Customers/Show.vue` steht die Mehrzahl in einem
                 * Zweig, dessen Bedingung `=== 1` lautet — und dort wechselt
                 * nicht nur das Wort, sondern der halbe Satz („Das Abonnement
                 * wird" gegen „2 Abonnements werden"). Eine Mengenangabe kann
                 * das nicht leisten, und sie soll es auch nicht.
                 *
                 * Die Ausnahme ist bewusst grob: Sie glaubt der Zeile. Wer
                 * `=== 1` für etwas anderes benutzt und daneben einen Plural
                 * klebt, kommt durch — das ist der Preis dafür, dass dieser
                 * Wächter Text liest und keinen Syntaxbaum.
                 */
                if (str_contains($zeile, '=== 1')) {
                    continue;
                }

                if (preg_match($this->pattern(), $zeile) === 1) {
                    $treffer[] = sprintf('%s:%d — %s', $pfad, $nummer + 1, trim($zeile));
                }
            }
        }

        $this->assertSame(
            [],
            $treffer,
            "Eine eingesetzte Zahl mit fest angehängtem Mehrzahlwort. Bei genau eins liest sich das \n".
            "als „1 Zeilen\". Die Entscheidung über das Wort gehört an den Wert:\n  ".
            implode("\n  ", $treffer),
        );
    }

    /**
     * Und der Ausdruck findet auch etwas.
     *
     * **Ohne diese Gegenprobe ist der Test oben wertlos.** Er behauptet eine
     * leere Liste, und eine leere Liste liefert ein kaputtes Muster genauso
     * zuverlässig wie eine saubere Oberfläche. Genau diese Falle hat der
     * Abnahmelauf von P5c dreimal gestellt.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist keine.**
     */
    public function test_the_pattern_would_find_one(): void
    {
        $this->assertSame(
            1,
            preg_match($this->pattern(), 'geschätzt ${formatRows(tabelle.rows)} Zeilen'),
            'Das Muster findet den Fehler nicht mehr, an dem es entstanden ist.',
        );

        $this->assertSame(
            1,
            preg_match($this->pattern(), '<span>{{ anzahl }} Zugänge</span>'),
            'Das Muster sieht die Schreibweise der Vorlage nicht.',
        );

        $this->assertSame(
            0,
            preg_match($this->pattern(), 'Zeilen {{ von }}–{{ bis }} von {{ summe }}'),
            'Das Muster hält eine Spanne für eine Mengenangabe — dort steht das Wort davor.',
        );
    }

    /**
     * Und die Entscheidung steht an einer Stelle.
     *
     * **Der erste Lauf dieses Wächters hat drei Fundstellen gemeldet und nicht
     * eine** — die Konsole aus P5c, das Protokoll („1 Einträge") und die
     * Planvorlage („1 Abonnements gebunden"). Die beiden letzten stehen seit P2
     * im Repo. Dreimal derselbe Fehler heisst, dass die Stelle fehlte, an der er
     * einmal richtig gemacht wird.
     */
    public function test_the_decision_lives_in_one_place(): void
    {
        $pfad = dirname(__DIR__, 2).'/resources/js/Composables/useCounted.ts';

        $this->assertFileExists($pfad, 'Die Stelle, an der die Einzahl entschieden wird, ist fort.');

        $source = (string) file_get_contents($pfad);

        $this->assertMatchesRegularExpression(
            '/value === 1 \? one : many/',
            $source,
            'Die Entscheidung hängt nicht mehr am Wert.',
        );

        $benutzer = 0;

        foreach ($this->templates() as $vorlage) {
            if (str_contains($vorlage, "from '../../Composables/useCounted'")) {
                $benutzer++;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $benutzer,
            'Weniger als drei Vorlagen holen die Entscheidung von dort. Eine, die sie wieder selbst '.
            'trifft, ist die vierte Fassung derselben Regel.',
        );
    }
}
