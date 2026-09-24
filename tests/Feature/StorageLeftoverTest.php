<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Der Wächter über `storage/app` beisst — und zwar dort, wo er sitzt.
 *
 * **Geprüft wird die Verdrahtung, nicht nur der Vergleich.** Ein Test, der
 * {@see TestCase::assertStorageAsFound()} selbst ruft, bliebe grün, wenn der
 * Aufruf aus `tearDown()` verschwände — und dann liesse wieder jeder Test
 * liegen, was er will, ohne dass etwas rot wird. Dieser hier lässt deshalb
 * absichtlich eine Datei liegen und fängt in seinem eigenen `tearDown()` ab,
 * was `parent::tearDown()` dazu sagt.
 *
 * > **Ein Wächter, den niemand ruft, ist ein Kommentar.**
 */
final class StorageLeftoverTest extends TestCase
{
    /** Die Datei, die dieser Test mit Absicht liegen lässt. */
    private ?string $absichtlich = null;

    public function test_a_file_left_behind_fails_the_test_that_left_it(): void
    {
        $this->absichtlich = storage_path('app/private/waechter-'.bin2hex(random_bytes(6)).'.probe');
        file_put_contents($this->absichtlich, 'liegengelassen');

        $this->assertFileExists($this->absichtlich);
    }

    protected function tearDown(): void
    {
        $datei = $this->absichtlich;
        $meldung = null;

        try {
            parent::tearDown();
        } catch (AssertionFailedError $gemeldet) {
            $meldung = $gemeldet->getMessage();
        } finally {
            // Aufräumen, was hier mit Absicht liegt — sonst hinterliesse
            // ausgerechnet der Test über liegengebliebene Dateien eine.
            if ($datei !== null && is_file($datei)) {
                unlink($datei);
            }
        }

        $this->assertNotNull($meldung, 'tearDown() hat die liegengelassene Datei nicht gemeldet — der Wächter wird nicht gerufen.');
        $this->assertStringContainsString(
            'private/'.basename((string) $datei),
            (string) $meldung,
            'Die Meldung nennt die Datei nicht, die liegen blieb.',
        );
    }
}
