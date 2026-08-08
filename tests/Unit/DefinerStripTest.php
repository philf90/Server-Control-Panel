<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Dump;

/**
 * Der DEFINER-Filter — und die Zusicherung, dass er Nutzdaten nicht anfasst.
 *
 * **Warum es ihn gibt.** `mysqldump` schreibt zu jeder Prozedur, jedem Trigger
 * und jeder Sicht eine `DEFINER`-Angabe. Beim Zurückspielen unter einem anderen
 * Benutzer — nach einem zurückgesetzten Passwort, oder wenn der Dump aus einem
 * anderen Abonnement stammt — bricht MariaDB mit „Access denied; you need SUPER
 * privileges" ab. `SUPER` bekommt hier niemand: Es ist ein globales Recht, und
 * die Rechtevergabe bleibt auf Schemaebene (`docs/36 §3.1`).
 *
 * **Warum er beide Richtungen braucht.** Ein blindes Suchen-und-Ersetzen über
 * den ganzen Dump verändert Nutzdaten: Eine Tabelle mit dem Text `DEFINER=` in
 * einer Spalte — ein Forum, in dem jemand über MySQL schreibt — käme verändert
 * zurück. Das fiele erst auf, wenn ein Kunde seine Daten vermisst, und es wäre
 * stille Datenkorrektur durch ein Sicherungswerkzeug, also das Gegenteil dessen,
 * wofür man es benutzt.
 *
 * Die zweite Richtung ist deshalb die wichtigere. Ein Filter, der zu wenig
 * streicht, erzeugt einen Fehlschlag mit einer Meldung; einer, der zu viel
 * streicht, erzeugt einen Erfolg mit falschen Daten.
 */
final class DefinerStripTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function statements(): array
    {
        return [
            // Der Regelfall, wie mysqldump ihn schreibt: versionierter
            // Kommentar, Backticks um Benutzer und Wirt.
            [
                '/*!50017 DEFINER=`p1001_web`@`localhost`*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `a` FOR EACH ROW SET @x=1 */;',
                '/*!50017 */ /*!50003 TRIGGER `t` BEFORE INSERT ON `a` FOR EACH ROW SET @x=1 */;',
            ],

            // Ohne Kommentar — ältere Fassungen und `--compact`.
            [
                'CREATE DEFINER=`p1001_web`@`localhost` PROCEDURE `p`() BEGIN END;',
                'CREATE PROCEDURE `p`() BEGIN END;',
            ],

            // Anführungszeichen statt Backticks.
            [
                "CREATE DEFINER='p1001_web'@'localhost' VIEW `v` AS SELECT 1;",
                'CREATE VIEW `v` AS SELECT 1;',
            ],

            // Ohne jede Anführung.
            [
                'CREATE DEFINER=root@localhost SQL SECURITY DEFINER VIEW `v` AS SELECT 1;',
                'CREATE SQL SECURITY DEFINER VIEW `v` AS SELECT 1;',
            ],

            // Mit Leerraum um das Gleichheitszeichen.
            [
                'CREATE DEFINER = `a`@`b` VIEW `v` AS SELECT 1;',
                'CREATE VIEW `v` AS SELECT 1;',
            ],
        ];
    }

    /**
     * @param  string  $line  Wie mysqldump sie schreibt
     * @param  string  $expected  Wie sie in der Sicherung stehen soll
     */
    #[DataProvider('statements')]
    public function test_the_definer_is_stripped_from_a_statement(string $line, string $expected): void
    {
        $this->assertSame($expected, Dump::withoutDefiner($line));
    }

    /**
     * **`SQL SECURITY DEFINER` bleibt stehen.**
     *
     * Das ist kein Benutzername, sondern die Angabe, in wessen Rechten die
     * Prozedur läuft. Ohne die `DEFINER=`-Zeile ist das der aufrufende Benutzer
     * — also genau der befristete Zugang, der gerade einspielt. Sie mit
     * wegzustreichen hiesse, das Verhalten der Prozedur zu ändern, und das ist
     * etwas anderes als sie einspielbar zu machen.
     */
    public function test_sql_security_definer_survives(): void
    {
        $this->assertStringContainsString(
            'SQL SECURITY DEFINER',
            Dump::withoutDefiner('CREATE DEFINER=`a`@`b` SQL SECURITY DEFINER VIEW `v` AS SELECT 1;'),
        );
    }

    /** @return list<array{0: string}> */
    public static function dataLines(): array
    {
        return [
            // Der Fall, um den es geht: ein Kunde schreibt in seinem Forum
            // über MySQL. Ein blindes Ersetzen verstümmelte seinen Beitrag.
            ["INSERT INTO `posts` VALUES (1,'Warum DEFINER=`root`@`localhost` beim Restore stört');"],
            ["INSERT INTO `posts` VALUES (2,'DEFINER=\\'x\\'@\\'y\\' ist eine Angabe von mysqldump');"],

            // Eine mehrzeilige Datenzeile, deren Fortsetzung mit CREATE
            // anfängt — sie beginnt keine Anweisung, sie ist Text.
            ["'CREATE DEFINER=`a`@`b` VIEW …'),"],

            // Kommentare und Sitzungsvariablen von mysqldump.
            ['-- Dump completed on 2026-08-07  0:00:00'],
            ['/*!40101 SET character_set_client = @saved_cs_client */;'],
            ['LOCK TABLES `posts` WRITE;'],
            [''],
            ["\n"],
        ];
    }

    /**
     * **Und nichts anderes wird angefasst — Byte für Byte.**
     *
     * `assertSame` und nicht `assertStringContainsString`: Ein Filter, der eine
     * Datenzeile auch nur um ein Leerzeichen ändert, hat die Daten des Kunden
     * verändert.
     */
    #[DataProvider('dataLines')]
    public function test_a_data_line_is_returned_byte_for_byte(string $line): void
    {
        $this->assertSame($line, Dump::withoutDefiner($line));
    }

    /**
     * Der Ablagename geht durch eine Positivliste.
     *
     * Kein Punkt (er trennt die Endung `.sql.gz`), kein Schrägstrich, kein
     * `..` — der Pfad entsteht im Agenten aus diesem Namen.
     */
    public function test_a_storage_name_is_checked(): void
    {
        $this->assertSame('p1001-shop-20260807-a1b2', Dump::storageName('p1001-shop-20260807-a1b2'));

        foreach (['../etc/passwd', 'a/b', 'a.b', 'A', '', '-x', str_repeat('a', 97)] as $wrong) {
            try {
                Dump::storageName($wrong);
                $this->fail(sprintf('%s ist als Ablagename durchgegangen.', var_export($wrong, true)));
            } catch (AgentException $error) {
                $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
            }
        }
    }

    /**
     * Der Pfad wird gebaut und nicht entgegengenommen.
     *
     * Beide Hälften gehen durch ihre Prüfung: der Abonnementname durch die des
     * Agenten (`SubscriptionProvision::subscriptionName()`), der Ablagename
     * durch die oben.
     */
    public function test_the_path_is_built_from_checked_halves(): void
    {
        $this->assertSame(
            Dump::ROOT.'/beispiel.de/p1001-shop-20260807-a1b2.sql.gz',
            Dump::path('beispiel.de', 'p1001-shop-20260807-a1b2'),
        );

        $this->expectException(AgentException::class);

        Dump::path('../../etc', 'p1001-shop');
    }

    /**
     * Der Filter über eine ganze Datei — der Weg, den `db.dump` geht.
     *
     * Hier steht auch die Zusicherung, die der zeilenweise Test nicht geben
     * kann: **Eine Zeile, die länger ist als jeder Lesepuffer, kommt heil
     * durch.** Genau daran wäre der erste Entwurf gescheitert, der den Filter
     * in den Rückkanal des Runners setzen wollte — dort zerschneidet
     * `fread($pipe, 65536)` sie in zwei Stücke (`docs/36 §22.3`). `fgets` tut
     * das nicht.
     */
    public function test_a_whole_file_is_filtered_without_cutting_long_lines(): void
    {
        $raw = tempnam(sys_get_temp_dir(), 'dump');
        $gz = $raw.'.gz';

        // Deutlich über der 64-KiB-Grenze des Runners.
        $langeDatenzeile = "INSERT INTO `posts` VALUES (1,'".str_repeat('x', 200_000)."DEFINER=`a`@`b`');\n";

        file_put_contents($raw, implode('', [
            "-- Sicherung\n",
            "CREATE DEFINER=`p1001_web`@`localhost` VIEW `v` AS SELECT 1;\n",
            $langeDatenzeile,
            "LOCK TABLES `posts` WRITE;\n",
        ]));

        try {
            $size = Dump::compress($raw, $gz);

            $this->assertGreaterThan(0, $size);

            $zurueck = (string) file_get_contents('compress.zlib://'.$gz);

            $this->assertStringContainsString('CREATE VIEW `v` AS SELECT 1;', $zurueck);
            $this->assertStringNotContainsString('DEFINER=`p1001_web`', $zurueck);

            // Die Datenzeile — unverändert, samt ihrem `DEFINER=`.
            $this->assertStringContainsString($langeDatenzeile, $zurueck);
        } finally {
            @unlink($raw);
            @unlink($gz);
        }
    }

    /** Und wieder auspacken ergibt dasselbe zurück. */
    public function test_decompress_returns_what_compress_wrote(): void
    {
        $raw = tempnam(sys_get_temp_dir(), 'dump');
        $gz = $raw.'.gz';
        $back = $raw.'.back';

        file_put_contents($raw, "SELECT 1;\nSELECT 2;\n");

        try {
            Dump::compress($raw, $gz);
            Dump::decompress($gz, $back);

            $this->assertSame("SELECT 1;\nSELECT 2;\n", (string) file_get_contents($back));
        } finally {
            @unlink($raw);
            @unlink($gz);
            @unlink($back);
        }
    }
}
