<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Names;

/**
 * Wie heisst dieser Rechner — und was davon gehört in ein Zertifikat?
 *
 * **Warum es diesen Test gibt.** Der Knotenname aus dem Kernel ist auf den
 * meisten Servern der kurze: „cloudsrv24" statt „cloudsrv24.de". Das erste
 * Mal fiel es bei der Ersteinrichtung auf — der Link am Ende zeigte auf einen
 * Namen, den ausserhalb des Rechners niemand auflöst. Dort wurde es behoben.
 *
 * Beim Zertifikat wurde dieselbe Frage im August 2026 **neu und falsch**
 * beantwortet: Der Knotenname kam in den subjectAltName, und aus ihm wurde
 * noch eine Kurzform abgeleitet — die falsche Richtung. Auf einem Server,
 * dessen Knotenname schon kurz ist, stand am Ende ausschliesslich
 * „cloudsrv24" im Zertifikat, und wer „cloudsrv24.de" aufrief, bekam eine
 * Warnung über einen Namen, der nicht passt.
 *
 * Deshalb steht die Regel jetzt an einer Stelle, und deshalb steht sie hier
 * unter einem Test: Sie ist zweimal gebraucht worden und einmal vergessen.
 */
final class NamesTest extends TestCase
{
    public function test_the_debian_line_yields_the_full_name(): void
    {
        // Was Debian anlegt, wenn beim Einrichten ein voller Name angegeben
        // wurde: `127.0.1.1 <fqdn> <kurz>`.
        $this->assertSame(
            'cloudsrv24.de',
            Names::fromHosts(['127.0.0.1 localhost', '127.0.1.1 cloudsrv24.de cloudsrv24'], 'cloudsrv24'),
        );
    }

    public function test_the_order_on_the_line_does_not_matter(): void
    {
        $this->assertSame(
            'cloudsrv24.de',
            Names::fromHosts(['127.0.1.1 cloudsrv24 cloudsrv24.de'], 'cloudsrv24'),
        );
    }

    public function test_a_commented_line_does_not_count(): void
    {
        /*
         * Hier stand `strstr($line, '#', true) ?: $line`, und eine vollständig
         * auskommentierte Zeile kam trotzdem durch: `strstr` liefert die leere
         * Zeichenkette, die ist unwahr, und `?:` nahm daraufhin die ganze
         * Zeile samt Raute. Wer eine Zeile auskommentiert, hat einen Grund.
         */
        $this->assertNull(Names::fromHosts(['# 127.0.1.1 cloudsrv24.de cloudsrv24'], 'cloudsrv24'));

        // Ein Kommentar am Zeilenende ändert dagegen nichts an der Zeile.
        $this->assertSame(
            'cloudsrv24.de',
            Names::fromHosts(['127.0.1.1 cloudsrv24.de cloudsrv24 # der Server'], 'cloudsrv24'),
        );
    }

    public function test_a_foreign_line_is_not_a_name_for_this_machine(): void
    {
        // /etc/hosts steht voll mit Namen anderer Rechner. Ohne diese Schranke
        // wäre jeder davon ein Name, den dieses Zertifikat behauptet.
        $this->assertNull(Names::fromHosts(['10.0.0.5 fremd.example.com fremd'], 'cloudsrv24'));
    }

    public function test_a_name_must_continue_the_node_name(): void
    {
        /*
         * Die Zeile trägt den Knotennamen — aber daneben steht ein Name, der
         * mit diesem Rechner nichts zu tun hat. Ein Zertifikat ist eine
         * Behauptung darüber, wer man ist; ein fremder Eintrag in einer Datei
         * darf sie nicht schreiben.
         */
        $this->assertNull(Names::fromHosts(['127.0.1.1 boese.example.com cloudsrv24'], 'cloudsrv24'));
    }

    public function test_a_node_name_that_already_has_a_domain_is_the_answer(): void
    {
        // Dann gibt es nichts nachzuschlagen — und vor allem keinen Grund, in
        // /etc/hosts oder einem Namensdienst zu suchen.
        $this->assertSame('srv.example.com', Names::fqdn('srv.example.com'));
    }

    public function test_the_certificate_names_start_with_the_full_name(): void
    {
        $names = Names::forThisHost();

        // Der erste Name wird der CommonName. Er ist der, den jemand eintippt.
        $this->assertNotSame([], $names['dns']);
        $this->assertContains('localhost', $names['dns']);

        $node = trim(php_uname('n'));
        $fqdn = Names::fqdn();

        if ($fqdn !== null) {
            $this->assertSame($fqdn, $names['dns'][0], 'Der vollständige Name steht vorn.');

            // Und die Kurzform gehört dazu: Im eigenen Netz tippt man sie.
            $this->assertContains($node, $names['dns']);
        } else {
            $this->assertSame($node, $names['dns'][0]);
        }
    }
}
