<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Acme\Dns\Name;

/**
 * Welche Zone zu einem Namen gehört, entscheidet genau eine Stelle.
 *
 * **Warum es diesen Wächter gibt.** Die Regel ist bei jedem Anbieter dieselbe:
 * Von allen Zonen, in denen ein Name liegt, gewinnt die längste. Sie stand als
 * eigene Schleife bei RFC 2136 und noch einmal bei IPv64.net, und mit Hetzner
 * wäre sie zum dritten Mal geschrieben worden — dasselbe Muster wie beim
 * Rechnernamen, den dieses Projekt viermal neu erfunden hat, bis
 * `HostnameSourceTest` dazwischenging.
 *
 * **Der Fehler wäre still.** Eine Schleife, die statt der längsten die erste
 * passende Zone nimmt, legt den Eintrag eine Ebene zu hoch an. Der Anbieter
 * nimmt das an, es gibt keine Fehlermeldung — die Prüfung findet den Eintrag
 * nur nie, und der Vorgang scheitert nach Minuten mit „nicht ausgeliefert".
 *
 * Geprüft wird an {@see Name::within()}: Wer den Zonenvergleich anstellt, ruft
 * sie auf, und rufen darf sie nur `Zones`.
 */
final class ZoneSourceTest extends TestCase
{
    /** Die eine Stelle. */
    public const SOURCE = 'agent/src/Acme/Dns/Zones.php';

    /** Und die Datei, in der `within()` selbst steht. */
    public const HOME = 'agent/src/Acme/Dns/Name.php';

    /** @return list<string> */
    private function agentSources(): array
    {
        $files = [];
        $root = dirname(__DIR__, 2).'/agent/src';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname());
            }
        }

        sort($files);

        $this->assertGreaterThan(30, count($files), 'Es werden kaum Agent-Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    public function test_only_one_place_decides_which_zone_a_name_belongs_to(): void
    {
        $found = [];

        foreach ($this->agentSources() as $relative) {
            if ($relative === self::SOURCE || $relative === self::HOME) {
                continue;
            }

            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);

            if (str_contains($source, 'Name::within(')) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Dateien vergleichen selbst gegen Zonen, statt %s zu fragen:\n  %s\n\n".
            'Die Regel „die längste passende Zone gewinnt" gehört an eine Stelle. Eine zweite Schleife '.
            'daneben nimmt irgendwann die erste passende — und legt den Eintrag eine Ebene zu hoch an, '.
            'ohne dass irgendetwas es meldet.',
            self::SOURCE,
            implode("\n  ", $found),
        ));
    }

    /** Und die Stelle selbst stellt den Vergleich auch wirklich an. */
    public function test_the_one_place_still_compares(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::SOURCE);

        $this->assertStringContainsString('Name::within(', $source, sprintf(
            '%s vergleicht nicht mehr gegen Zonen — dann prüft die Gegenrichtung dieses Tests nichts.',
            self::SOURCE,
        ));
    }
}
