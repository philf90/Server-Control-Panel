<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\DbIsolationProbe;

/**
 * Die Fehlernummer aus der Meldung von MariaDB — an echter Ausgabe.
 *
 * **Dieser Test hat einen Anlass mit Datum.** Der Abnahmelauf vom 8. August
 * 2026 auf `cloudsrv24` meldete alle Kriterien erfüllt, und eine seiner Zeilen
 * lautete:
 *
 *     ok  p1118_abnahme: SELECT auf p1121_abnahme abgewiesen —
 *         Die Datenbank hat abgewiesen: --------------
 *
 * `--------------` ist keine Fehlermeldung. Der `mysql`-Client hatte die
 * gescheiterte Anweisung zwischen Strichzeilen ausgegeben, und der Lauf nahm
 * die erste Zeile. **Die sicherheitsrelevanteste Zeile der ganzen Ausgabe war
 * damit unlesbar** — und dahinter stand das grössere Problem: Der Lauf prüfte
 * nur, *dass* etwas scheiterte, nicht *woran*. Ein `ERROR 1146 Table doesn't
 * exist`, also ein Tippfehler im Tabellennamen, hätte sich genauso gelesen wie
 * eine funktionierende Abschottung.
 *
 * Das ist die Lehre aus dem P4-Abnahmelauf eine Ebene tiefer: dort eine Zahl
 * statt der Namen, hier ein Fehlschlag statt des richtigen Fehlschlags.
 *
 * **Die Ausgaben unten sind abgeschrieben und nicht erfunden.** Beide stammen
 * wörtlich aus dem Lauf; eine Nachbildung dessen, was der Client ausgeben
 * *sollte*, hätte den Fall gerade nicht getroffen.
 */
final class DbErrorCodeTest extends TestCase
{
    /**
     * So sah das gescheiterte `SELECT` auf dem Server aus.
     *
     * Mit den Strichzeilen davor — sie sind der ganze Grund für diesen Test.
     */
    private const SELECT_OUTPUT = "--------------\n"
        ."SELECT COUNT(*) FROM `p1121_abnahme`.`srvpanel_selbsttest`\n"
        ."--------------\n\n"
        .'ERROR 1142 (42000) at line 1: SELECT command denied to user '
        ."'p1118_abnahme'@'localhost' for table `p1121_abnahme`.`srvpanel_selbsttest`";

    /** Und so das gescheiterte `USE` — dort stand die Meldung gleich vorn. */
    private const USE_OUTPUT = 'ERROR 1044 (42000) at line 1: Access denied for user '
        ."'p1118_abnahme'@'localhost' to database 'p1121_abnahme'";

    public function test_the_code_is_found_behind_the_dashes(): void
    {
        $this->assertSame(
            1142,
            DbIsolationProbe::errorCode(self::SELECT_OUTPUT),
            'Die Nummer steht nicht in der ersten Zeile. Wo sie steht, entscheidet der Client.',
        );
    }

    public function test_the_code_is_found_without_dashes(): void
    {
        $this->assertSame(1044, DbIsolationProbe::errorCode(self::USE_OUTPUT));
    }

    /**
     * Eine fehlende Tabelle ist keine Abschottung.
     *
     * Die Nummer wird gefunden — und `AcceptanceDb::EXPECTED` lässt sie nicht
     * durch. Beides gehört zusammen: Fände sie dieser Ausdruck nicht, käme
     * `null` heraus, und `null` weist der Lauf ebenfalls ab.
     */
    public function test_a_missing_table_is_recognised_as_its_own_error(): void
    {
        $this->assertSame(
            1146,
            DbIsolationProbe::errorCode("ERROR 1146 (42S02) at line 1: Table 'p1121_abnahme.srvpanel_selbsttest' doesn't exist"),
        );
    }

    /**
     * Ohne Nummer kommt `null` — und nicht eine geratene.
     *
     * `null` heisst „keine Nummer gefunden" und ist etwas anderes als eine
     * unerwartete Nummer. Der Abnahmelauf muss beides melden können, denn die
     * Gründe sind verschieden: Das eine ist ein Client, der anders redet als
     * erwartet, das andere eine Abschottung, die nicht hält.
     */
    public function test_without_a_number_nothing_is_guessed(): void
    {
        $this->assertNull(DbIsolationProbe::errorCode('Abbruch mit Code 1, keine Ausgabe.'));
        $this->assertNull(DbIsolationProbe::errorCode('ERROR: irgendwas ohne Nummer'));
    }

    /**
     * Und die Nummer wird nicht aus einem Datenwert gelesen.
     *
     * Die Meldung enthält Namen, die der Kunde gewählt hat. Ein Ausdruck ohne
     * `\b`-Grenzen läse `ERROR 12345` als `1234` — und ein Schema, das
     * `xERROR 9999x` heisst, gibt es zwar nicht, aber die Grenze kostet nichts
     * und die Annahme „so heisst nichts" ist genau die, die dieses Projekt
     * schon mehrfach eingeholt hat.
     */
    public function test_only_a_four_digit_number_counts(): void
    {
        $this->assertNull(DbIsolationProbe::errorCode('ERROR 12345 (42000) at line 1: …'));
        $this->assertNull(DbIsolationProbe::errorCode('xERROR 1044x'));
    }
}
