<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Der Agent spricht an genau einer Stelle nach draussen — und dort mit Zusagen.
 *
 * **Warum es diesen Wächter gibt.** Der Agent läuft als root. Alles, was er
 * tut, geht sonst über einen Unix-Socket mit Aufruferprüfung oder über
 * Programme von einer Positivliste; eine Verbindung zu einem fremden Rechner
 * ist eine eigene Art von Oberfläche. Ihre vier Zusagen standen bis Schritt 9
 * **nur als Kommentar** in `CurlTransport` — kein Test nannte sie. Gefunden
 * beim Bauen des ersten DNS-Anbieters, also genau in dem Moment, in dem eine
 * zweite Stelle dazugekommen wäre, die dieselben Optionen setzt.
 *
 * Das ist der Fehler dieses Projekts in Reinform: eine Regel, die als Prosa
 * dasteht, und eine zweite Umsetzung daneben, in der eine Zeile davon fehlt —
 * und nichts meldet es.
 *
 * **Was hier geprüft wird, ist die Grenze und nicht das Verhalten.** Ob eine
 * Antwort richtig gelesen wird, prüfen die Tests der Anbieter gegen ihre
 * Drehbücher. Hier steht nur: Es gibt eine Stelle, und sie hält vier Zusagen.
 */
final class OutboundSourceTest extends TestCase
{
    /** Die eine Stelle. */
    public const GATE = 'agent/src/Acme/Curl.php';

    /**
     * Was dort stehen muss — und warum es nicht fehlen darf.
     *
     * @var array<string, string>
     */
    private const PROMISES = [
        'CURLOPT_FOLLOWLOCATION => false' => 'Ohne diese Zeile trägt eine Umleitung die signierte Anfrage — '.
            'oder das DNS-Token — an eine Adresse, die niemand hinterlegt hat.',
        'CURLOPT_SSL_VERIFYPEER => true' => 'Ohne Zertifikatsprüfung ist https nur noch Verschlüsselung '.
            'ohne Gegenüber.',
        'CURLOPT_SSL_VERIFYHOST => 2' => 'Ein gültiges Zertifikat für einen anderen Namen ist kein gültiges '.
            'Zertifikat.',
        'CURLOPT_CONNECTTIMEOUT' => 'Eine Gegenstelle, die nicht antwortet, hielte den Vorgang bis zu seinem '.
            'eigenen Zeitlimit fest.',
        'CURLOPT_TIMEOUT' => 'Dasselbe für eine, die annimmt und dann schweigt.',
        "str_starts_with(\$url, 'https://')" => 'Eine Adresse ohne TLS muss abgelehnt werden, bevor curl '.
            'sie sieht.',
    ];

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

    public function test_only_one_place_reaches_out(): void
    {
        $found = [];

        foreach ($this->agentSources() as $relative) {
            if ($relative === self::GATE) {
                continue;
            }

            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);

            // `curl_init` allein genügt als Fund: Ohne Handle keine Anfrage,
            // und mit Handle ist die Grenze umgangen.
            if (preg_match('/\bcurl_(init|setopt|setopt_array|exec)\s*\(/', $source) === 1) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found, sprintf(
            "Diese Dateien sprechen an %s vorbei nach draussen:\n  %s\n\n".
            'Der Agent läuft als root. Eine zweite Stelle mit curl ist eine zweite Stelle, an der '.
            'die vier Zusagen gelten müssten — und die zweite ist die, in der eine davon fehlt.',
            self::GATE,
            implode("\n  ", $found),
        ));
    }

    public function test_the_one_place_keeps_its_promises(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::GATE);

        $this->assertNotSame('', $source, self::GATE.' ist leer oder fehlt — dann prüft dieser Test nichts.');

        $missing = [];

        foreach (self::PROMISES as $needle => $why) {
            if (! str_contains($source, $needle)) {
                $missing[] = $needle.' — '.$why;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "In %s fehlt eine Zusage:\n  %s",
            self::GATE,
            implode("\n  ", $missing),
        ));
    }
}
