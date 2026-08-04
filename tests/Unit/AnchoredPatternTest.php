<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `$` allein ist kein Ende — der Wächter über eine ganze Fehlerklasse.
 *
 * **Wie das aufgefallen ist.** `PhpVersionCatalogTest` versuchte, eine
 * Zeitzone mit angehängtem Zeilenumbruch unterzuschieben, und sie ging durch.
 * Der Grund steht in der PCRE-Dokumentation und wird trotzdem regelmässig
 * übersehen: **`$` passt auch vor einem abschliessenden Zeilenumbruch.**
 * `preg_match('/^[a-z]+$/', "abc\n")` ist wahr.
 *
 * Für eine Prüfung, deren Ergebnis in einer Konfigurationsdatei landet, ist
 * das der Unterschied zwischen einer Zeile und zweien. `memory_limit=256M\n`
 * in einem `fastcgi_param PHP_VALUE` ist eine Einstellung und ein Anfang für
 * die nächste.
 *
 * Betroffen waren neun Muster, davon vier aus P0 bis P2 — die Prüfung des
 * Abonnementnamens und die des Systembenutzers gehörten dazu. Sie einzeln zu
 * berichtigen hätte den Fehler von heute behoben und den von morgen nicht.
 * Deshalb steht hier eine Prüfung über die Regel und nicht über die Fälle:
 * **Jedes Muster im Agenten, das auf `$` endet, trägt den Modifikator `D`.**
 *
 * Ausgenommen ist `m`: Dort ist `D` wirkungslos, und ein Muster mit `m`
 * beschreibt ohnehin kein Ende einer Eingabe, sondern Zeilen in einer Ausgabe.
 */
final class AnchoredPatternTest extends TestCase
{
    public function test_every_anchored_pattern_in_the_agent_ends_only_at_the_end(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);

            // Muster in einfachen Anführungszeichen, wie sie im Agenten stehen:
            // '/…$/' oder '#…$#', gefolgt von den Modifikatoren.
            preg_match_all('/\'([\/#])((?:[^\'\\\\]|\\\\.)*)\$\1([a-zA-Z]*)\'/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $checked++;

                $modifiers = $match[3];

                if (str_contains($modifiers, 'D') || str_contains($modifiers, 'm')) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s  %s%s$%s%s',
                    basename($path),
                    $match[1],
                    mb_substr($match[2], 0, 60),
                    $match[1],
                    $modifiers,
                );
            }
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(10, $checked, 'Es werden kaum Muster gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $offenders, sprintf(
            "Diese Muster enden auf \$ ohne den Modifikator D:\n\n  %s\n\n".
            '`$` passt auch vor einem abschliessenden Zeilenumbruch — was hier durchgeht, '.
            'kann in einer Konfigurationsdatei eine zweite Zeile anfangen.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Und die Gegenprobe an einem lebenden Beispiel.
     *
     * Ohne sie wäre der Test oben eine Textsuche, die auch dann grün bliebe,
     * wenn `D` in PHP nichts täte.
     */
    public function test_the_modifier_is_what_makes_the_difference(): void
    {
        $this->assertSame(1, preg_match('/^[a-z]+$/', "abc\n"));
        $this->assertSame(0, preg_match('/^[a-z]+$/D', "abc\n"));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $found = [];
        $root = dirname(__DIR__, 2).'/agent/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
