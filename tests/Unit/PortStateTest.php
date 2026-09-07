<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\PortState;
use SrvPanel\Agent\Result;

/**
 * Der Leser für `ss -H -ltnp`.
 *
 * **Die Prüfkörper sind gemessen und nicht erfunden.** Sie stehen wörtlich so
 * in `docs/81 §2.3s` — mitsamt der ungleichmässigen Ausrichtung, die eine
 * Ausgabe hat, die sich an ihrer längsten Zeile orientiert.
 *
 * Framework-frei: Diese Klasse erbt nur die Behauptungen von PHPUnit, damit sie
 * im Gestell des Containers ohne Laravel läuft.
 */
final class PortStateTest extends TestCase
{
    /** Vier gemessene Zeilen, Ausrichtung und Leerraum unverändert. */
    private const GEMESSEN = "LISTEN 0      128          0.0.0.0:2024       0.0.0.0:*                                             \n"
        ."LISTEN 0      4096       127.0.0.1:33909      0.0.0.0:*    users:((\"environment-man\",pid=487,fd=13))\n"
        ."LISTEN 0      5        0.0.0.0:19001 0.0.0.0:* users:((\"python3\",pid=1953,fd=3))        \n"
        ."LISTEN 0      5      127.0.0.1:19002 0.0.0.0:* users:((\"python3\",pid=1953,fd=4))        \n";

    /**
     * Gelesen wird nach Feldern und nicht nach Spaltenbreite.
     *
     * Die Ausgabe richtet sich an der längsten Zeile aus; die ändert sich mit
     * dem Bestand. Ein Leser, der ab Zeichen 14 schneidet, trifft auf einem
     * Server mit einem langen Prozessnamen daneben — und zwar still.
     */
    public function test_the_reader_goes_by_field_and_not_by_column(): void
    {
        $zustand = PortState::read(new Result(0, self::GEMESSEN, ''), true);

        $this->assertTrue($zustand['readable']);
        $this->assertCount(4, $zustand['listeners']);

        $this->assertSame(2024, $zustand['listeners'][0]['port']);
        $this->assertSame('0.0.0.0', $zustand['listeners'][0]['address']);
        $this->assertSame(33909, $zustand['listeners'][1]['port']);
        $this->assertSame('environment-man', $zustand['listeners'][1]['process']);
        $this->assertSame(487, $zustand['listeners'][1]['pid']);
    }

    /**
     * Ohne root trägt kein Lauscher einen Eigentümer.
     *
     * **Das ist der teuerste Fund der Messrunde** (M4): `ss -ltnp` gibt ohne
     * root dieselben Zeilen, `rc=0`, und lässt die Prozessspalte wortlos leer.
     * Wer daraus „niemand" schlösse, machte aus einem Aufruf ohne Rechte eine
     * Aussage über den Server.
     */
    public function test_without_privilege_no_listener_carries_an_owner(): void
    {
        $zustand = PortState::read(new Result(0, self::GEMESSEN, ''), false);

        $this->assertFalse($zustand['privileged']);

        foreach ($zustand['listeners'] as $lauscher) {
            $this->assertNull($lauscher['process'], 'Ein Eigentümer aus einem Lauf ohne Rechte.');
            $this->assertNull($lauscher['pid']);
        }

        // Die Gegenprobe: mit Rechten steht er da. Ohne sie wäre der Fall oben
        // auch dann grün, wenn der Leser den Namen nirgends fände.
        $mit = PortState::read(new Result(0, self::GEMESSEN, ''), true);
        $this->assertSame('environment-man', $mit['listeners'][1]['process']);
    }

    /**
     * „Niemand sichtbar" und „nicht nachgesehen" sind zwei Zustände.
     *
     * Port 2024 hat **auch als root** keinen Eigentümer — der Sockel gehört
     * einem Prozess ausserhalb dieser PID-Namespace. Erst `privileged`
     * unterscheidet die beiden Fälle; ohne das Feld sähen sie gleich aus.
     */
    public function test_an_empty_owner_is_not_the_same_as_an_unprivileged_look(): void
    {
        $mit = PortState::read(new Result(0, self::GEMESSEN, ''), true);

        $this->assertTrue($mit['privileged']);
        $this->assertNull($mit['listeners'][0]['process'], 'Port 2024 hat auch als root keinen sichtbaren Eigentümer.');
        $this->assertNotNull($mit['listeners'][1]['process'], 'Port 33909 hat einen — sonst misst der Fall nichts.');
    }

    /** Drei Reichweiten und keine Wahrheitswerte. */
    public function test_the_scope_tells_local_from_everywhere(): void
    {
        $zustand = PortState::read(new Result(0, self::GEMESSEN, ''), true);

        $this->assertSame('any', $zustand['listeners'][0]['scope']);
        $this->assertSame('loopback', $zustand['listeners'][1]['scope']);

        $eigene = PortState::read(new Result(0, "LISTEN 0 128 10.0.0.5:443 0.0.0.0:*\n", ''), true);
        $this->assertSame('specific', $eigene['listeners'][0]['scope']);
    }

    /**
     * Ein Fehlschlag trägt keine Liste.
     *
     * `ss` gibt bei einer unbekannten Option `rc=255` und alles auf stderr
     * (M2). Eine leere Liste daneben wäre die Aussage „nichts lauscht".
     */
    public function test_a_failed_call_is_a_state_and_not_an_empty_list(): void
    {
        $zustand = PortState::read(new Result(255, '', "ss: unrecognized option '--json'\n"), true);

        $this->assertFalse($zustand['readable']);
        $this->assertSame('unreadable', $zustand['reason']);
        $this->assertArrayNotHasKey('listeners', $zustand, 'Ein Fehlschlag darf keine Liste tragen.');
        $this->assertContains($zustand['reason'], PortState::REASONS);
    }

    /**
     * Eine Zeile, die nicht passt, nimmt die anderen nicht mit.
     *
     * Der Zustand ist eine Liste; eine kaputte Zeile darf die übrigen zwanzig
     * nicht kosten. Geprüft wird an vier Formen, die alle keine sind.
     */
    public function test_a_line_that_is_not_one_is_skipped_and_not_fatal(): void
    {
        $ausgabe = "ESTAB 0 0 127.0.0.1:1 127.0.0.1:2\n"        // kein LISTEN
            ."LISTEN 0 128\n"                                    // zu wenig Felder
            ."LISTEN 0 128 0.0.0.0:abc 0.0.0.0:*\n"              // kein Port
            ."LISTEN 0 128 0.0.0.0:99999 0.0.0.0:*\n"            // ausserhalb 16 Bit
            ."LISTEN 0 128 0.0.0.0:443 0.0.0.0:*\n";             // die einzige echte

        $zustand = PortState::read(new Result(0, $ausgabe, ''), true);

        $this->assertTrue($zustand['readable']);
        $this->assertCount(1, $zustand['listeners'], 'Genau eine der fünf Zeilen ist eine.');
        $this->assertSame(443, $zustand['listeners'][0]['port']);
    }

    /**
     * Der Port steht hinter dem **letzten** Doppelpunkt.
     *
     * **Diese Form ist nicht gemessen**, und das steht auch im Kopf der Klasse:
     * Der Prüfstand hat kein IPv6. Sie steht hier nach der Dokumentation von
     * iproute2, und `docs/109 §7` Punkt 5 misst sie auf dem Server nach. Der
     * Fall bleibt trotzdem im Wächter, weil ein Leser, der von links trennt,
     * sonst gar nicht auffiele.
     */
    public function test_an_ipv6_address_keeps_its_colons(): void
    {
        $zustand = PortState::read(new Result(0, "LISTEN 0 128 [::]:80 [::]:*\nLISTEN 0 128 [::1]:631 [::]:*\n", ''), true);

        $this->assertSame('::', $zustand['listeners'][0]['address']);
        $this->assertSame(80, $zustand['listeners'][0]['port']);
        $this->assertSame('inet6', $zustand['listeners'][0]['family']);
        $this->assertSame('any', $zustand['listeners'][0]['scope']);

        $this->assertSame('::1', $zustand['listeners'][1]['address']);
        $this->assertSame('loopback', $zustand['listeners'][1]['scope']);
    }

    /**
     * Die Operation fragt mit `-H`.
     *
     * Ohne `-H` steht die Kopfzeile in der Ausgabe, und in ihr klebt
     * `Peer Address:PortProcess` ohne Leerzeichen (M1). Gefragt wird der
     * Quelltext der Operation, weil der Leser selbst davon nichts weiss.
     */
    public function test_the_operation_asks_without_the_header(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../agent/src/Ops/SystemPorts.php');

        $this->assertIsString($quelle);
        $this->assertMatchesRegularExpression(
            "/run\(\s*'ss',\s*\[[^\]]*'-H'/",
            $quelle,
            'Die Operation muss `ss` mit `-H` rufen — sonst liest der Leser die klebende Kopfzeile mit.',
        );
    }
}
