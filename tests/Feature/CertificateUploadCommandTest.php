<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eine Ursache, eine Meldung — beim Hochladen von der Kommandozeile.
 *
 * **Der Befund aus dem Abnahmelauf, 7. August 2026.** `srvpanel tls --upload`
 * mit einem Schlüssel, den der Dienstbenutzer nicht lesen darf, schrieb zwei
 * Sätze:
 *
 *     --key: Diese Datei gibt es nicht oder sie ist nicht lesbar: /tmp/pk.pem
 *     Es fehlt eine Angabe: --domain, --certificate und --key gehören zusammen.
 *
 * Der erste war richtig, der zweite falsch — die Angabe war da. **Und der
 * zweite ist der, den man glaubt**, weil er zuletzt steht und allgemeiner
 * klingt: Er schickt den Betreiber zurück an die Kommandozeile, wo alles
 * stimmt, statt zu den Dateirechten.
 *
 * Ursache war eine Hilfsmethode, die `null` für zwei verschiedene Dinge
 * zurückgab — „nicht angegeben" und „angegeben, aber nicht lesbar" —, und ein
 * Aufrufer, der beides gleich behandelte.
 *
 * **Was hier nicht geprüft wird:** der unlesbare Fall selbst. Die Tests laufen
 * in der CI als root, und root liest auch 0600. Geprüft werden die beiden
 * Ausgänge, die sich herstellen lassen; dass der dritte seine eigene Meldung
 * hat, steht im Code und ist im Abnahmelauf gesehen worden.
 */
final class CertificateUploadCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eine fehlende Angabe wird als fehlende Angabe gemeldet — und nur so.
     *
     * Ohne diese Richtung könnte die Meldung ganz wegfallen: Wer `--key`
     * vergisst, bekäme dann gar keine Auskunft, sondern nur einen
     * Rückgabewert.
     */
    public function test_a_missing_option_is_named_and_no_file_is_blamed(): void
    {
        $this->artisan('srvpanel:tls', [
            '--upload' => true,
            '--domain' => 'beispiel.de',
            '--certificate' => __FILE__,
        ])
            // **Eine Behauptung für eine Meldung.** Zwei `expectsOutputToContain`
            // auf denselben Satz sind zwei Prüfungen derselben Sache — und je
            // nach Fassung prüft die zweite gegen das, was die erste übrig
            // gelassen hat.
            ->expectsOutputToContain('Es fehlt: --key')
            ->doesntExpectOutputToContain('Diese Datei')
            ->assertExitCode(1);
    }

    /**
     * Und eine Datei, die es nicht gibt, wird als solche gemeldet — und nur so.
     *
     * **Das ist die Richtung, die im Abnahmelauf falsch war.** Der Zusatz „Es
     * fehlt eine Angabe" darf hier nicht stehen: Alle drei Angaben sind da.
     */
    public function test_a_missing_file_is_named_and_no_option_is_blamed(): void
    {
        $this->artisan('srvpanel:tls', [
            '--upload' => true,
            '--domain' => 'beispiel.de',
            '--certificate' => __FILE__,
            '--key' => '/tmp/dieses-verzeichnis-gibt-es-nicht/schluessel.pem',
        ])
            ->expectsOutputToContain('Diese Datei gibt es nicht')
            ->doesntExpectOutputToContain('Es fehlt:')
            ->assertExitCode(1);
    }
}
