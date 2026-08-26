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

        /*
         * **Hier stand `assertGreaterThanOrEqual(3, …)`, und die Zahl ist am
         * 15. August 2026 stumm geworden.**
         *
         * Der Bruch dazu nahm **einer** Vorlage die Einbindung weg; die Grenze
         * zählte **alle**. Solange es genau drei Benutzer gab, fiel sie damit auf
         * zwei und der Wächter biss. Der Dateimanager wurde der vierte — und
         * seitdem blieben nach dem Bruch drei übrig. Der Wächter war grün, der
         * Bruchlauf meldete „ohne Biss".
         *
         * > **Eine Untergrenze prüft eine Regel nur an ihrer Grenze — und ein
         * > neuer Benutzer schiebt sie weg.**
         *
         * Das ist die Kehrseite der Falle aus `CLAUDE.md`: Dort meldete ein
         * Zähler Rot für die Ordnung, die er durchsetzen soll. Hier meldet er
         * Grün für ihren Bruch. Beide Male ist der Fehler derselbe — eine feste
         * Zahl über einen Bestand, der wächst.
         *
         * Gezählt wird deshalb nicht mehr gegen eine Zahl, sondern gegen sich
         * selbst: Wer einbindet, ruft auch auf, und wer aufruft, bindet ein.
         */
        $einbinder = [];
        $aufrufer = [];

        foreach ($this->templates() as $pfad => $vorlage) {
            if (str_contains($vorlage, "from '../../Composables/useCounted'")) {
                $einbinder[] = $pfad;
            }

            if (preg_match('/\b(?:counted|formatCount)\(/', $vorlage) === 1) {
                $aufrufer[] = $pfad;
            }
        }

        $this->assertNotSame(
            [],
            $aufrufer,
            'Keine einzige Vorlage holt die Entscheidung von dort. Dann ist `useCounted` toter Code, '.
            'und die Regel darüber eine Zusage ins Leere.',
        );

        $this->assertSame(
            $einbinder,
            $aufrufer,
            "Einbinden und Aufrufen fallen auseinander.\n\n".
            'Eine Vorlage, die einbindet und nicht aufruft, hat die Entscheidung wieder selbst '.
            'getroffen; eine, die aufruft und nicht einbindet, lässt sich gar nicht bauen. Beides '.
            'ist ein Befund über dieselbe Stelle.',
        );
    }

    /**
     * Und keine Seite entscheidet das Wort selbst.
     *
     * **Das ist die Prüfung, die der Untergrenze darüber gefehlt hat.** Eine
     * Zahl über alle Vorlagen kann nicht sehen, dass **eine** ausgeschert ist —
     * genau das war der Bruch, den sie prüfen sollte.
     *
     * Gesucht wird die Form, die `counted()` ersetzt: ein Fragezeichen hinter
     * einer `1`, und dahinter zwei Wörter. Ein Satz, der sich als Ganzes ändert,
     * ist ausdrücklich erlaubt und kommt hier viermal vor („Das Abonnement wird"
     * gegen „2 Abonnements werden", „seit 1 Tag" gegen „seit 3 Tagen") — eine
     * Mengenangabe kann das nicht leisten und soll es nicht.
     *
     * > **Zwei einzelne Wörter hinter einer Eins sind eine Mengenangabe. Ein
     * > halber Satz ist eine Entscheidung.**
     */
    public function test_no_page_decides_the_word_itself(): void
    {
        $muster = "/1\\s*\\)?\\s*\\?\\s*'(\\p{L}+)'\\s*:\\s*'(\\p{L}+)'/u";
        $treffer = [];

        foreach ($this->templates() as $pfad => $vorlage) {
            if (preg_match($muster, $vorlage, $wort) === 1) {
                $treffer[] = sprintf('%s — „%s" gegen „%s"', $pfad, $wort[1], $wort[2]);
            }
        }

        $this->assertSame(
            [],
            $treffer,
            "Eine Vorlage entscheidet die Einzahl selbst:\n  ".implode("\n  ", $treffer)."\n\n".
            'Das ist die nächste Fassung derselben Regel, und die nächste ist die, die beim '.
            'Nachziehen vergessen wird. `counted()` aus `useCounted` trifft die Entscheidung.',
        );

        /*
         * **Und die Gegenprobe**, aus demselben Grund wie oben: Eine leere Liste
         * liefert ein kaputtes Muster genauso zuverlässig wie eine saubere
         * Oberfläche.
         */
        $this->assertSame(
            1,
            preg_match($muster, "counted = anzahl === 1 ? 'Eintrag' : 'Einträge'"),
            'Das Muster findet die Form nicht, gegen die es geschrieben ist.',
        );

        $this->assertSame(
            0,
            preg_match($muster, "anzahl === 1 ? 'Das Abonnement wird' : `\${anzahl} Abonnements werden`"),
            'Das Muster hält einen ganzen Satz für eine Mengenangabe — den kann `counted()` nicht.',
        );
    }

    /**
     * Und in einem Wort für `counted()` steht kein `:count`.
     *
     * ## Der Fund, der diese Regel ausgelöst hat
     *
     * Am 26. August 2026 stand auf der Updates-Seite in einer Rückfrage:
     *
     * > **2 :count ausgewählte Pakete installieren?**
     *
     * `counted()` **setzt die Zahl selbst davor** und nimmt daneben nur das
     * Wort. Ein `:count` darin ist die Schreibweise von
     * `lang/de/validation.php` — dort ersetzt Laravel den Platzhalter, hier
     * ersetzt ihn niemand.
     *
     * > **Wissen aus zweiter Hand sieht aus wie Wissen.**
     *
     * **Und gefunden hat es kein Test, sondern eine Aufnahme.** Die Messung
     * daneben war fehlerfrei: `dokument=0`, Gegenprobe 200/200. Ein
     * Platzhalter, der als Text dasteht, lässt nichts überlaufen.
     *
     * > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
     * > Betrachter.**
     */
    public function test_no_word_for_counted_carries_a_placeholder(): void
    {
        $muster = '/\bcounted\s*\([^)]*:count/';
        $treffer = [];

        foreach ($this->templates() as $pfad => $vorlage) {
            if (preg_match($muster, $vorlage) === 1) {
                $treffer[] = $pfad;
            }
        }

        $this->assertSame(
            [],
            $treffer,
            "Hier steht ein `:count` in einem Wort für `counted()`:\n  ".implode("\n  ", $treffer)."\n\n".
            '`counted()` setzt die Zahl selbst davor; den Platzhalter ersetzt niemand, und er '.
            'erscheint wörtlich auf der Seite. Die Schreibweise mit `:count` gehört nach '.
            'lang/de/validation.php und nirgendwo sonst hin.',
        );

        // Die Gegenprobe: Das Muster findet die Form, gegen die es geschrieben ist.
        $this->assertSame(
            1,
            preg_match($muster, "counted(n, 'ein Paket', ':count Pakete')"),
            'Das Muster findet den Platzhalter nicht — dann prüft dieser Fall nichts.',
        );

        $this->assertSame(
            0,
            preg_match($muster, "counted(n, 'Paket', 'Pakete')"),
            'Das Muster hält eine richtige Mengenangabe für einen Fehler.',
        );
    }
}
