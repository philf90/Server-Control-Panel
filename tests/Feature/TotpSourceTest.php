<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Totp;

/**
 * Ein zeitbasiertes Einmalkennwort entsteht an genau einer Stelle.
 *
 * **Warum es diesen Wächter gibt.** {@see Totp} hat in `app/` angefangen, für
 * den zweiten Faktor der Anmeldung. Mit INWX kam ein zweiter Aufrufer dazu:
 * Dessen Schnittstelle verlangt bei einem gesicherten Konto einen TAN, und der
 * entsteht aus einem Geheimnis, das der Agent hält. Der Agent kann nicht auf
 * `app/` zugreifen — die naheliegende Antwort wäre also eine zweite Umsetzung
 * gewesen, und die zweite ist erfahrungsgemäss die, die veraltet.
 *
 * **Der Fehler wäre teuer und still zugleich.** Eine zweite Fassung, die die
 * Abschneideregel aus RFC 4226 §5.3 um ein Byte danebenlegt, erzeugt Codes, die
 * *manchmal* stimmen — nämlich immer dann, wenn das Versatz-Halbbyte klein
 * genug ist. Ein Anbieter, der sich alle paar Stunden nicht anmelden lässt, ist
 * schwerer zu finden als einer, der es nie tut.
 *
 * Geprüft wird an `hash_hmac` mit SHA1: Das ist die Rechnung, um die es geht.
 * Taucht die Zeichenkette anderswo auf, ist entweder eine zweite Umsetzung
 * entstanden — oder es gibt einen neuen, legitimen Grund für HMAC-SHA1, und
 * dann gehört die Entscheidung hierher und nicht in eine stille Zeile.
 */
final class TotpSourceTest extends TestCase
{
    /** Die eine Stelle. */
    public const SOURCE = 'agent/src/Totp.php';

    /** Wo gesucht wird. */
    private const ROOTS = ['app', 'agent/src', 'database'];

    /** @return list<string> */
    private function sources(): array
    {
        $files = [];
        $root = dirname(__DIR__, 2);

        foreach (self::ROOTS as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace($root.'/', '', $file->getPathname());
                }
            }
        }

        sort($files);

        $this->assertGreaterThan(100, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    public function test_only_one_place_computes_a_one_time_code(): void
    {
        $found = [];

        foreach ($this->sources() as $relative) {
            if ($relative === self::SOURCE) {
                continue;
            }

            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);

            if (preg_match('/hash_hmac\(\s*[\'"]sha1[\'"]/i', $source) === 1) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Dateien rechnen selbst mit HMAC-SHA1, statt %s zu benutzen:\n  %s\n\n".
            'Eine zweite Fassung der Abschneideregel erzeugt Codes, die manchmal stimmen — und das ist '.
            'schwerer zu finden als eine, die nie stimmt.',
            self::SOURCE,
            implode("\n  ", $found),
        ));
    }

    /**
     * Und die Stelle selbst rechnet noch — samt amtlichem Testvektor.
     *
     * Der Wert stammt aus RFC 6238 Anhang B: Geheimnis `12345678901234567890`
     * in Base32, Zeitpunkt 59 Sekunden, also Schritt 1.
     */
    public function test_the_one_place_still_computes(): void
    {
        $this->assertSame('287082', Totp::codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }
}
