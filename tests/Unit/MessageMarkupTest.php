<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Meldung des Agenten trägt keine Auszeichnung.
 *
 * **Der Fund** (`docs/59`, Befund 20, zweiter Durchgang). Auf der Seite stand
 *
 * > Das ist der \*\*Fingerabdruck\*\* eines Schlüssels, wie ihn \`ssh-keygen -l\`
 * > ausgibt
 *
 * — mit den Sternchen und den Schrägstrichzeichen als Zeichen. Die Meldungen sind
 * in Markdown geschrieben, und niemand übersetzt sie: Das Panel zeigt sie als
 * Text, und das ist richtig so — eine Meldung, die HTML erzeugt, ist eine
 * Meldung, in der Kundeneingaben stehen.
 *
 * > **Eine Auszeichnung, die niemand übersetzt, ist ein Zeichen im Satz.**
 *
 * **Und der teuerste Teil daran ist nicht der Fehler, sondern wie er überlebt
 * hat.** Er stand in **vier** Aufnahmen dieses Laufs — schon in Punkt 11 des
 * ersten Durchgangs —, und er ist viermal übersehen worden, weil der Blick auf
 * dem Inhalt lag und nicht auf der Form.
 *
 * > **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
 * > verdeckt alles, was daneben steht.**
 *
 * ## Wie geprüft wird, und warum so grob
 *
 * Gesucht wird in Zeichenketten mit einem Umlaut darin: Das ist der Marker für
 * „deutscher Satz" und trifft weder SQL noch einen Ausdruck. Ein Bruchstück ohne
 * Umlaut kann durchfallen — dafür hat dieser Wächter **keine** Fehlalarme, und
 * die kosten mehr: Ein Wächter, der bei jedem SQL-Bezeichner meckert, wird
 * abgeschaltet.
 */
final class MessageMarkupTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/agent/src', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_no_german_message_carries_markup(): void
    {
        $checked = 0;
        $broken = [];

        foreach ($this->files() as $path) {
            // Kommentare weg: Dort **gehört** die Auszeichnung hin, sie ist für
            // den Leser des Quelltexts geschrieben und erreicht niemanden sonst.
            $source = (string) preg_replace(['#/\*.*?\*/#su', '#//[^\n]*#'], '', (string) file_get_contents($path));

            preg_match_all("/'(?:[^'\\\\]|\\\\.)*'/", $source, $literals);

            foreach ($literals[0] as $literal) {
                if (preg_match('/[äöüßÄÖÜ]/u', $literal) !== 1) {
                    continue;
                }

                $checked++;

                if (preg_match('/\*\*|`/', $literal) === 1) {
                    $broken[] = sprintf(
                        '%s: %s',
                        str_replace(dirname(__DIR__, 2).'/', '', $path),
                        mb_substr($literal, 0, 70),
                    );
                }
            }
        }

        $this->assertGreaterThan(
            80,
            $checked,
            'Es werden kaum deutsche Zeichenketten gefunden — dann prüft dieser Wächter nichts.',
        );

        $this->assertSame([], $broken, sprintf(
            "Diese Meldungen tragen Auszeichnung, und niemand übersetzt sie:\n  %s\n\n".
            'Sternchen und Schrägstrichzeichen stehen im Panel als Zeichen im Satz. Für hervorgehobene '.
            'Wörter und Bezeichner nehmen die Meldungen dieses Panels deutsche Anführungszeichen.',
            implode("\n  ", $broken),
        ));
    }
}
