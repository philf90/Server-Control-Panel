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
            "/return \['refused' => true, 'error' => \\\$error->getMessage\(\)\];/",
            $source,
            'Der abgewiesene Zugriff muss als Befund zurückkommen und nicht als Ergebnis.',
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'rows'\s*=>/",
            $source,
            'Die Probe gibt Zeilen heraus. Bei einem Fehlschlag der Abschottung stünde damit im '
            .'Vorgang genau das, wovor sie schützen soll.',
        );
    }
}
