<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AcceptanceDb;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Ops\DbIsolationProbe;

/**
 * Ein Abnahmekriterium fragt nach Namen und nicht nach einer Anzahl.
 *
 * **Der Satz, den dieser Test durchsetzt, hat einen Preis gehabt.** Der
 * P4-Abnahmelauf meldete `1 fällig, 1 bestellt` — genau die Zahl, die das
 * Kriterium verlangte — und hatte ein gewöhnliches Zertifikat statt eines
 * Platzhalters bestellt. Gefunden hat es der Betreiber, weil er nach der grünen
 * Meldung die Seriennummern verglich. CLAUDE.md hält daraus fest:
 *
 * > Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.
 *
 * Für P5 ist genau das die Gefahr. „Ein Datenbankbenutzer sieht keine fremde
 * Datenbank" lässt sich als `count($visible) === 1` prüfen — und das wäre grün,
 * wenn er *eine* fremde sieht und die eigene nicht. Deshalb gibt
 * {@see DbIsolationProbe} die **Liste** zurück, und der Abnahmelauf vergleicht
 * sie mit einer erwarteten Menge.
 *
 * **Geprüft wird als Text, und das ist hier kein Behelf.** In diesem Container
 * läuft kein MariaDB; die Frage ist ohnehin eine an den Quelltext — gibt die
 * Operation Namen heraus, und vergleicht der Lauf sie als Menge? Ein laufender
 * Server beantwortete das nicht besser.
 */
final class IsolationVerdictTest extends TestCase
{
    /**
     * Nur die Konstante mit den erwarteten Fehlernummern.
     *
     * Von `EXPECTED = [` bis zur schliessenden Klammer auf Feldebene. Findet
     * der Ausdruck sie nicht, ist das ein Fehlschlag und keine leere
     * Zeichenkette: Eine Prüfung auf „enthält 1146 nicht" wäre sonst grün,
     * gerade weil sie nichts gelesen hat.
     */
    private function expectedCodes(string $source): string
    {
        $this->assertMatchesRegularExpression(
            '/EXPECTED = \[.*?\n    \];/s',
            $source,
            'Die Konstante mit den erwarteten Fehlernummern ist nicht zu finden.',
        );

        preg_match('/EXPECTED = \[.*?\n    \];/s', $source, $match);

        return $match[0];
    }

    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        $this->assertIsString($file);

        return (string) file_get_contents($file);
    }

    /**
     * Die Probe gibt die Namen heraus.
     *
     * Gesucht wird `'visible' =>` im Rückgabefeld — und dass daneben kein
     * `count(` steht. Eine Operation, die zählt, hat die Entscheidung schon
     * getroffen, bevor der Lauf sie treffen konnte.
     */
    public function test_the_probe_returns_names(): void
    {
        $source = $this->source(DbIsolationProbe::class);

        $this->assertStringContainsString(
            "'visible' => \$visible,",
            $source,
            'Die Probe muss die sichtbaren Datenbanken als Liste zurückgeben.',
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'visible'\s*=>\s*count\(/",
            $source,
            'Die Probe zählt statt zu nennen. Dann prüft das Kriterium nicht, was gezählt wurde.',
        );
    }

    /**
     * Und der Abnahmelauf vergleicht sie als Menge.
     *
     * **Nicht `count($visible) === 2`.** Der Unterschied ist der ganze Test:
     * Zwei sichtbare Namen sind zwei sichtbare Namen, gleichgültig welche. Der
     * Vergleich muss die erwarteten Namen nennen — den eigenen und
     * `information_schema` — und beide Seiten vorher sortieren, denn `SHOW
     * DATABASES` gibt keine Reihenfolge zu.
     */
    public function test_the_acceptance_run_compares_the_set_and_not_its_size(): void
    {
        $source = $this->source(AcceptanceDb::class);

        $this->assertStringContainsString(
            "'information_schema'",
            $source,
            'Der Lauf muss die erwarteten Namen nennen.',
        );

        $this->assertStringContainsString(
            '$visible !== $expected',
            $source,
            'Der Lauf muss die Mengen vergleichen und nicht ihre Grösse.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/count\(\$visible\)\s*[!=]==/',
            $source,
            'Der Lauf zählt die sichtbaren Datenbanken. Zwei sichtbare Namen sind zwei sichtbare '
            .'Namen, gleichgültig welche — genau der Fehler, der P4 eine Auslieferung gekostet hat.',
        );
    }

    /**
     * Abgewiesen genügt nicht — es muss die richtige Abweisung sein.
     *
     * **Der Anlass ist der Abnahmelauf vom 8. August 2026.** Er meldete alle
     * Kriterien erfüllt, und für das `SELECT` auf die fremde Datenbank stand da:
     * „abgewiesen — Die Datenbank hat abgewiesen: `--------------`". Keine
     * Fehlernummer, keine Meldung; der Lauf prüfte nur, *dass* etwas
     * scheiterte. Ein `ERROR 1146 Table doesn't exist` — ein Tippfehler im
     * Tabellennamen — hätte sich genauso gelesen wie eine funktionierende
     * Abschottung.
     *
     * docs/36 §17 nennt die Nummern seit jeher: `1044` beim `USE`, `1142` oder
     * `1044` beim `SELECT`. Gebaut war „es ist gescheitert". Das ist die Lehre
     * aus P4 eine Ebene tiefer — dort eine Zahl statt der Namen, hier ein
     * Fehlschlag statt des richtigen Fehlschlags.
     */
    public function test_the_acceptance_run_checks_which_error_it_was(): void
    {
        $source = $this->source(AcceptanceDb::class);

        foreach (['1044', '1142'] as $code) {
            $this->assertStringContainsString(
                $code,
                $source,
                sprintf('Der Lauf muss ERROR %s als erwartete Nummer nennen (docs/36 §17).', $code),
            );
        }

        $this->assertStringContainsString(
            'in_array($code, $codes, true)',
            $source,
            'Der Lauf muss die gemeldete Fehlernummer gegen die erwarteten halten.',
        );

        /*
         * Und die Gegenrichtung: 1146 ist „Tabelle gibt es nicht". Stünde sie
         * in der Erwartung, wäre ein Tippfehler wieder ein Beleg für
         * Abschottung — genau der Zustand, aus dem dieser Test entstanden ist.
         *
         * **Ausgeschnitten und nicht mit einem Ausdruck über die ganze Datei
         * gesucht.** Der erste Anlauf war `/EXPECTED = \[.*?1146.*?\];/s`, und
         * er schlug an: `.*?` ist zwar faul, aber unbegrenzt — er lief über das
         * Ende der Konstante hinaus bis in den Kommentar weiter unten, der
         * `ERROR 1146` erklärt. Ein Wächter, der Fehlalarm gibt, wird
         * abgeschaltet; derselbe Satz steht in `BreakScriptTest`, aus demselben
         * Tag.
         */
        $this->assertStringNotContainsString(
            '1146',
            $this->expectedCodes($source),
            'ERROR 1146 („Tabelle gibt es nicht") darf nicht als Abschottung durchgehen.',
        );
    }

    /**
     * Und die Meldung wird gesucht, nicht an einer Stelle vermutet.
     *
     * `explode("\n", $message)[0]` stand hier und lieferte am 8. August
     * `--------------`: Der `mysql`-Client gab die gescheiterte Anweisung
     * zwischen Strichzeilen aus. Wo die `ERROR`-Zeile steht, entscheidet der
     * Client.
     */
    public function test_the_error_line_is_searched_and_not_assumed(): void
    {
        $source = $this->source(AcceptanceDb::class);

        $this->assertStringContainsString(
            "str_contains(\$line, 'ERROR ')",
            $source,
            'Die Meldung muss nach der ERROR-Zeile durchsucht werden.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/private function firstLine/',
            $source,
            'firstLine() nahm die erste Zeile — und die war die Strichzeile.',
        );
    }

    /**
     * Drei Fragen und nicht eine.
     *
     * `SHOW DATABASES` ist eine Anzeige, `USE` der Wechsel, das `SELECT` der
     * Zugriff. Ein Server kann die Anzeige filtern und den Zugriff zulassen;
     * wer nur die Liste prüft, hat die Anzeige geprüft (docs/36 §17).
     */
    public function test_all_three_questions_are_asked(): void
    {
        $source = $this->source(DbIsolationProbe::class);

        foreach (['SHOW DATABASES', 'USE ', 'SELECT COUNT(*) FROM'] as $statement) {
            $this->assertStringContainsString(
                $statement,
                $source,
                sprintf('Die Probe stellt „%s" nicht mehr — dann prüft sie weniger als docs/36 §17 verlangt.', $statement),
            );
        }
    }

    /**
     * Und sie gibt nichts heraus, woran sie nicht hätte kommen dürfen.
     *
     * Der Zugriffsversuch meldet `refused` und die Fehlermeldung — nie eine
     * Zeile. Ein Selbsttest, der bei einem Fehlschlag ausgibt, woran er nicht
     * hätte kommen dürfen, hat aus einem Beleg ein Leck gemacht; derselbe Satz
     * steht bei `web.isolation.probe`.
     */
    public function test_the_probe_never_returns_foreign_rows(): void
    {
        $source = $this->source(DbIsolationProbe::class);

        $this->assertMatchesRegularExpression(
            "/'refused' => true, 'code' => self::errorCode\(\\\$message\), 'error' => \\\$message/",
            $source,
            'Der abgewiesene Zugriff muss als Befund zurückkommen und nicht als Ergebnis.',
        );

        $this->assertStringContainsString(
            "'code' => self::errorCode(\$message)",
            $source,
            'Die Probe muss die Fehlernummer melden — sonst kann der Lauf sie nicht prüfen.',
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'rows'\s*=>/",
            $source,
            'Die Probe gibt Zeilen heraus. Bei einem Fehlschlag der Abschottung stünde damit im '
            .'Vorgang genau das, wovor sie schützen soll.',
        );
    }
}
