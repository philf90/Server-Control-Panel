<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ein Dokumentationsblock steht über der Sache, die er beschreibt.
 *
 * ## Woher dieser Wächter kommt
 *
 * Am 25. August 2026 ist beim Bau von A9 Schritt 5 eine neue Methode
 * **zwischen** `impersonation()` und ihren Block gerutscht. PHPStan hat die
 * Hälfte gemeldet, die ein Werkzeug sehen kann — den fehlenden `@return`-Typ an
 * der einen Methode — und eine CI-Runde gekostet.
 *
 * **Dasselbe hat dieses Repo schon einmal getroffen**, und dort war es teurer:
 * Zwei neue Methoden lagen zwischen `diskQuota()` und seinem Block, und der
 * versprach über `dnsAddresses()` ein `array{available: …}`, wo ein
 * `list<string>` steht.
 *
 * > **Ein Werkzeug bemerkt den fehlenden Kommentar. Den falschen bemerkt es
 * > nicht.**
 *
 * Genau das ist der Fall, den niemand sieht: Stehen **beide** Blöcke da, ist
 * PHPStan zufrieden — und die Dokumentation der einen Methode beschreibt seit
 * dem Umbau die andere.
 *
 * ## Was gemessen wird — und was der erste Wurf falsch gefragt hat
 *
 * Gesucht ist ein Block mit einer **Marke** (`@return`, `@param`, `@var`)
 * unmittelbar über einem zweiten Block. Die Marke macht eine Aussage über eine
 * Signatur; folgt statt der Deklaration ein weiterer Block, gilt sie für nichts.
 *
 * **Der erste Wurf fragte „zwei Blöcke hintereinander" und meldete zwanzig
 * Stellen — achtzehn davon zu Recht so geschrieben.** In diesem Repo ist es
 * üblich, eine lange Erklärung als eigenen Block über den Block der Methode zu
 * setzen. Ein Wächter, der zwanzig Dinge meldet, von denen achtzehn in Ordnung
 * sind, wird beim ersten Aufräumen abgeschaltet.
 *
 * > **Ein Wächter, der Richtiges mitmeldet, ist kein strenger Wächter — er ist
 * > einer, den man gleich wieder los ist.**
 *
 * Mit der Marke als Bedingung bleiben zwei, und beide waren echt: ein
 * zurückgebliebenes `@return array<string, string>` in `Databases.php`, dessen
 * Methode ihren Block längst selbst trägt, und der Block von
 * `PgDatabaseRemove::removeOwner()`, der über die Methode daneben gerutscht war.
 *
 * **Eine Leerzeile dazwischen ist ohnehin kein Befund** — ein Block, eine
 * Leerzeile und ein einzeiliger Block über einer Konstante sind gewöhnlich.
 *
 * ## Was er nicht kann
 *
 * Er findet den Block, der **gar nichts** mehr beschreibt. Ein Block, der über
 * der falschen Methode steht, weil jemand zwei Methoden vertauscht hat, sieht
 * für ihn richtig aus — dafür gibt es kein Merkmal im Text.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class DocblockAnchorTest extends TestCase
{
    /**
     * Ein Block **mit Marke** unmittelbar über einem zweiten.
     *
     * Zwei Bedingungen, und jede hat ihren Grund: Die Marke, weil nur sie eine
     * Aussage über eine Signatur macht — ohne sie ist ein zweiter Block der
     * übliche Stil dieses Repos. Und `[ \t]*` statt `\s*`, weil eine Leerzeile
     * zwei Blöcke trennt, die beide ihren Platz haben.
     *
     * `(?:(?!\*\/).)*?` hält den Ausdruck **innerhalb** eines Blocks: Ohne die
     * Sperre liefe `.` über das schliessende `*​/` hinweg und fände die Marke
     * irgendwo weiter oben in der Datei.
     */
    private const CONSECUTIVE =
        '/\/\*\*(?:(?!\*\/).)*?@(?:return|param|var)(?:(?!\*\/).)*?\*\/\n[ \t]*\/\*\*/s';

    /** Keine Marke steht über einem Block statt über einer Deklaration. */
    public function test_no_docblock_stands_directly_above_another(): void
    {
        $orphans = [];
        $files = 0;

        foreach ($this->phpFiles() as $relative => $source) {
            $files++;

            if (preg_match_all(self::CONSECUTIVE, $source, $_, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            preg_match_all(self::CONSECUTIVE, $source, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as $fund) {
                $orphans[] = sprintf('%s: Zeile %d', $relative, substr_count($source, "\n", 0, (int) $fund[1]) + 1);
            }
        }

        $this->assertGreaterThan(200, $files,
            'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $orphans, sprintf(
            "Hier steht ein Dokumentationsblock unmittelbar über einem zweiten:\n\n  %s\n\n"
            .'Dazwischen ist eine Deklaration verlorengegangen oder eine neue dazwischengerutscht — '
            .'der obere beschreibt seitdem nichts mehr. Stehen beide da, ist PHPStan zufrieden.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * Der Prüfkörper: Der Ausdruck trifft den Fall und nicht die zwei Nachbarn.
     *
     * **Ohne ihn wäre ein grüner Lauf oben bedeutungslos.** Ein Ausdruck, der
     * nichts findet, sieht genauso aus wie eine Datei ohne Befund — und der
     * erste Wurf dieses Ausdrucks fand mit `\s*` drei harmlose Stellen, der
     * zweite mit einem Tippfehler gar keine.
     */
    public function test_the_expression_tells_the_two_apart(): void
    {
        $kaputt = "    /**\n     * A\n     *\n     * @return array\n     */\n    /**\n     * B\n     */\n";
        $stil = "    /**\n     * Lange Erklärung ohne Marke.\n     */\n    /**\n     * B\n     */\n";
        $leerzeile = "    /**\n     * A\n     *\n     * @return array\n     */\n\n    /** B */\n";

        $this->assertSame(1, preg_match(self::CONSECUTIVE, $kaputt),
            'Der Ausdruck findet den Fall nicht, für den es diesen Test gibt.');

        $this->assertSame(0, preg_match(self::CONSECUTIVE, $stil),
            'Der Ausdruck meldet zwei Blöcke ohne Marke — das ist der übliche Stil dieses Repos.');

        $this->assertSame(0, preg_match(self::CONSECUTIVE, $leerzeile),
            'Der Ausdruck meldet zwei Blöcke mit einer Leerzeile dazwischen — die sind gewöhnlich.');
    }

    /**
     * @return array<string, string> Pfad => Quelltext
     */
    private function phpFiles(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        foreach (['app', 'agent/src', 'database', 'tests'] as $verzeichnis) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($wurzel.'/'.$verzeichnis, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $datei) {
                if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                    continue;
                }

                $dateien[substr($datei->getPathname(), strlen($wurzel) + 1)] =
                    (string) file_get_contents($datei->getPathname());
            }
        }

        return $dateien;
    }
}
