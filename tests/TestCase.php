<?php

namespace Tests;

use FilesystemIterator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPUnit\Framework\AssertionFailedError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Was unter `storage/app` lag, bevor dieser Test begann.
     *
     * @var list<string>
     */
    private array $storageBefore = [];

    protected function setUp(): void
    {
        // Vor `parent::setUp()`: Was beim Hochfahren der Anwendung entsteht,
        // gehört zum Test, der sie hochfährt.
        $this->storageBefore = self::storageFiles();

        parent::setUp();

        // Ohne gebautes Bundle gibt es kein Vite-Manifest, und jede Seite
        // bricht mit „manifest not found" ab. Diese Tests prüfen Verhalten,
        // nicht das Bundle: Ob es entsteht, sagt der Zweig „Oberfläche"; ob es
        // ausgeliefert wird, der Integrationslauf mit dem gebauten Paket.
        $this->withoutVite();
    }

    /**
     * **Nach** `parent::tearDown()`, und das ist der Punkt: Erst dann haben die
     * Testklasse und Laravel aufgeräumt — ein `tearDown()` der Klasse ruft
     * dieses hier am Ende, und `beforeApplicationDestroyed()` läuft in dem
     * von Laravel. Früher gefragt, meldete der Wächter jede Datei, die gleich
     * darauf ordentlich verschwindet.
     *
     * @throws AssertionFailedError wenn der Test unter `storage/app` etwas liegen liess
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->assertStorageAsFound();
    }

    /**
     * Ein Test verlässt `storage/app`, wie er es vorgefunden hat.
     *
     * **Gefunden am 24. September 2026, an 386 Dateien in fünf Tagen.** Jeder
     * volle Lauf liess vier zurück: drei Proben aus `DumpSizeTest`, die
     * niemand löschte, und eine Übergabe aus `UploadLimitTest`, die im
     * Betrieb der Agent abholt — im Test läuft wegen `Queue::fake()` kein
     * Vorgang, und so holte sie keiner. Falsch wurde dadurch kein Ergebnis;
     * aber jede Arbeitskopie wuchs mit jedem Lauf, und wer den Ordner ansah,
     * musste raten, was davon Absicht war.
     *
     * **Der Test, der eine Datei hinterlässt, fällt durch — und nicht irgendein
     * späterer.** Verglichen wird mit dem Stand zu Beginn dieses Tests; eine
     * Datei, die schon da war, zählt nicht, auch wenn sie selbst ein Rest ist.
     * Deshalb sieht der Wächter in einer frischen Arbeitskopie mehr als in
     * einer alten: Eine Datei mit festem Namen fällt nur dort auf, wo sie noch
     * nicht liegt — in der CI also immer.
     *
     * **Im Erfolgsfall zählt er keine Zusicherung.** Sonst stiege jede Summe um
     * die Zahl der Tests, und ein Test ohne eigene Prüfung hiesse nicht mehr
     * „risky".
     *
     * @throws AssertionFailedError mit den Dateien, die liegen blieben
     */
    protected function assertStorageAsFound(): void
    {
        $neu = array_values(array_diff(self::storageFiles(), $this->storageBefore));

        if ($neu !== []) {
            $this->fail(sprintf(
                "Dieser Test hat unter storage/app liegen lassen:\n\n  %s\n\n"
                .'Was ein Test anlegt, räumt er weg — sonst wächst jede Arbeitskopie mit jedem Lauf.',
                implode("\n  ", $neu),
            ));
        }
    }

    /**
     * Die Dateien unter `storage/app`, relativ und sortiert.
     *
     * **Ohne `.gitignore`** — die liegen dort, damit git die Verzeichnisse
     * kennt, und gehören keinem Test.
     *
     * **Was nicht lesbar ist, wird übersprungen und nicht gemeldet.**
     * `private/imports` steht auf 0700; ein Lauf unter einem anderen Konto kann
     * nicht hineinsehen — und in aller Regel auch nichts hineinlegen. Würde der
     * Wächter dort werfen, fiele unter diesem Konto jeder Test durch, und keiner
     * davon hätte etwas liegen lassen.
     *
     * @return list<string>
     */
    protected static function storageFiles(): array
    {
        $wurzel = dirname(__DIR__).'/storage/app';

        try {
            $dateien = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            $gefunden = [];

            /** @var SplFileInfo $datei */
            foreach ($dateien as $datei) {
                if ($datei->isFile() && $datei->getFilename() !== '.gitignore') {
                    $gefunden[] = substr($datei->getPathname(), strlen($wurzel) + 1);
                }
            }
        } catch (UnexpectedValueException) {
            return [];
        }

        sort($gefunden);

        return $gefunden;
    }
}
