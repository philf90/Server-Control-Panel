<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Der Agent bindet nichts ein, was es ohne ihn nicht gibt.
 *
 * **Die erste der drei Grenzen** (`docs/20 §4.1`): `agent/` ist ein framework-
 * und abhängigkeitsfreies PHP-CLI, geladen über `agent/src/autoload.php`. Was
 * dort steht, muss auf einem Server laufen, auf dem es weder Laravel noch
 * `vendor/` noch `tests/` gibt.
 *
 * ## Die echte Regression, die dieser Wächter nachstellt
 *
 * Gefunden am 26. August 2026: In `agent/src/PhpSettings.php` stand
 *
 *     use Tests\Unit\AnchoredPatternTest;
 *
 * — eine Testklasse, importiert in den Prozess, der als root Pakete
 * installiert und Systembenutzer anlegt. Geschrieben hat sie **niemand**:
 * Im Dokumentblock stand `{@see AnchoredPatternTest}`, und Pints
 * `fully_qualified_strict_types` macht aus einer ausgeschriebenen Marke einen
 * `use`-Eintrag samt Kurzform.
 *
 * > **Ein Werkzeug, das eine Schreibweise vereinheitlicht, verschiebt damit
 * > eine Abhängigkeit — und niemand hat sie geschrieben.**
 *
 * Folgenlos war es nur, weil ein Dokumentblock nichts lädt. Derselbe Griff an
 * einer Marke im Rumpf wäre ein `Class not found` auf einem echten Server, und
 * zwar erst dort: Im Container liegt `vendor/` daneben und alles löst auf.
 *
 * > **Eine Abhängigkeit, die in der Entwicklungsumgebung vorhanden ist, fällt
 * > erst dort auf, wo sie fehlt.**
 *
 * ## Was erlaubt ist
 *
 * Der eigene Namensraum, und was PHP selbst mitbringt — `Socket`, `Throwable`,
 * `RuntimeException` und ihresgleichen. Alles andere ist ein Fehler, auch als
 * blosser Dokumentverweis: Wer eine fremde Klasse nennen will, schreibt ihren
 * Namen ohne `@see`, damit der Formatierer nichts daraus macht.
 */
final class AgentIndependenceTest extends TestCase
{
    /**
     * Was PHP von sich aus kennt und was der Agent deshalb einbinden darf.
     *
     * **Eine Positivliste und keine Verneinung.** „Alles ausser `Tests\` und
     * `App\`" wäre die Regel von gestern — die nächste fremde Wurzel stünde
     * wieder drin, und niemand fiele es auf.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'SrvPanel\Agent',
        'Socket',
        'Throwable',
        'RuntimeException',
        'InvalidArgumentException',
        'JsonException',
        'Stringable',
        'Countable',
        'ArrayAccess',
        'Iterator',
        'IteratorAggregate',
        'Traversable',
        'Generator',
        'Closure',
        'DateTimeImmutable',
        'DateTimeInterface',
        'DateTimeZone',
        'SplFileInfo',
        'FilesystemIterator',
        'RecursiveDirectoryIterator',
        'RecursiveIteratorIterator',
        'ZipArchive',
        'PharData',

        // Klassen aus Erweiterungen, die der Zielserver ohnehin braucht:
        // `ext-openssl` für ACME und die Zertifikate, `ext-curl` für die
        // Anfragen dorthin. Sie kommen mit PHP und nicht mit `vendor/`.
        'OpenSSLAsymmetricKey',
        'OpenSSLCertificate',
        'CurlHandle',
    ];

    public function test_the_agent_imports_nothing_from_outside(): void
    {
        $fremd = [];
        $gelesen = 0;

        foreach ($this->sources() as $pfad => $quelltext) {
            preg_match_all('/^use\s+([^\s;]+)\s*;/m', $quelltext, $treffer);

            foreach ($treffer[1] as $klasse) {
                $gelesen++;

                foreach (self::ALLOWED as $erlaubt) {
                    if ($klasse === $erlaubt || str_starts_with($klasse, $erlaubt.'\\')) {
                        continue 2;
                    }
                }

                $fremd[] = sprintf('%s: use %s;', $pfad, $klasse);
            }
        }

        /*
         * Die Untergrenze, und sie ist hier nötig: Im heilen Zustand ist die
         * Liste oben leer, und eine leere Liste sieht genauso aus, ob der
         * Ausdruck nichts fand oder ob es nichts zu finden gab.
         */
        $this->assertGreaterThan(
            5,
            $gelesen,
            'Es werden kaum `use`-Zeilen unter agent/ gelesen — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $fremd, sprintf(
            "Der Agent bindet Fremdes ein:\n\n  %s\n\n"
            .'`agent/` ist framework- und abhängigkeitsfrei und wird über `agent/src/autoload.php` '
            .'geladen; auf dem Zielserver gibt es weder Laravel noch `tests/`. Gemeint war meist ein '
            .'Dokumentverweis — dann gehört der Name ohne `@see` in den Text, sonst macht Pint einen '
            .'`use`-Eintrag daraus.',
            implode("\n  ", $fremd),
        ));
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2).'/agent/src';
        $quellen = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $quellen[substr($datei->getPathname(), strlen(dirname(__DIR__, 2)) + 1)] = (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($quellen);

        return $quellen;
    }
}
