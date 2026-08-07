<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Acme\Dns\TxtValue;

/**
 * Ein TXT-Wert wird an genau einer Stelle in Anführungszeichen gesetzt.
 *
 * **Warum es diesen Wächter gibt.** Ein TXT-Eintrag besteht aus
 * „character-strings" in Anführungszeichen (RFC 1035 §3.3.14), und mehrere
 * Anbieter nehmen den Wert genau so entgegen — Hetzner unter `records[].value`,
 * Cloudflare unter `content`. Bei Hetzner stand die Regel zuerst als private
 * Methode; mit Cloudflare wäre sie zum zweiten Mal geschrieben worden.
 *
 * Dasselbe Muster wie bei der Zonenauflösung ({@see ZoneSourceTest}), nur eine
 * Runde früher erkannt: Dort stand die Regel dreimal, bevor jemand hinsah.
 *
 * **Der Fehler wäre nicht still, aber teuer.** Die zweite Fassung vergisst die
 * Abweisung — den Wert mit einem Anführungszeichen darin, oder den zu langen,
 * den der Anbieter dann stillschweigend in zwei character-strings teilt. Ein
 * TXT-Satz aus zwei Teilen ist für die Zertifizierungsstelle ein anderer Wert,
 * und der Vorgang scheitert an einer Ursache, die nirgends steht.
 */
final class TxtValueSourceTest extends TestCase
{
    /** Die eine Stelle. */
    public const SOURCE = 'agent/src/Acme/Dns/TxtValue.php';

    /**
     * Gelesen wird `agent/src/Acme` und nicht der ganze Agent.
     *
     * Anführungszeichen um einen Wert gibt es auch anderswo — `EnvFile` setzt
     * sie um einen Wert mit Leerzeichen, und das ist die Regel einer
     * Umgebungsdatei und nicht die von RFC 1035. Ein Wächter, der beides in
     * einen Topf wirft, meldet eine Ordnung, die es nicht gibt.
     *
     * @return list<string>
     */
    private function dnsSources(): array
    {
        $files = [];
        $root = dirname(__DIR__, 2).'/agent/src/Acme';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname());
            }
        }

        sort($files);

        $this->assertGreaterThan(10, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    public function test_only_one_place_wraps_a_value_in_quotes(): void
    {
        $found = [];

        foreach ($this->dnsSources() as $relative) {
            if ($relative === self::SOURCE) {
                continue;
            }

            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);

            // `'"'.$etwas` — ein Anführungszeichen, das an eine Zeichenkette
            // geklebt wird. Genau so sähe die zweite Fassung aus.
            if (preg_match('/\'"\'\s*\./', $source) === 1) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Dateien setzen selbst Anführungszeichen um einen Wert, statt %s zu benutzen:\n  %s\n\n".
            'Die zweite Fassung ist die, die die Abweisung vergisst — den Wert mit einem '.
            'Anführungszeichen darin, oder den zu langen, den der Anbieter dann stillschweigend teilt.',
            self::SOURCE,
            implode("\n  ", $found),
        ));
    }

    /** Und die Stelle selbst setzt sie auch wirklich. */
    public function test_the_one_place_still_quotes(): void
    {
        $this->assertSame('"wert"', TxtValue::quoted('wert'));
    }
}
