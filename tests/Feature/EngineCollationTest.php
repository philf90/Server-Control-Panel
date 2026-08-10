<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Databases\Engines\EngineDriver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Eine Sortierung, die ein System nicht kennt, wird ihm auch nicht geschickt.
 *
 * **Der Anlass ist der Fehler, an dem P5b auf dem Server hängengeblieben ist.**
 * `DatabaseController::store()` füllte eine fehlende Sortierung mit
 * `?? $this->collations()[0]` — der ersten **MariaDB**-Sortierung. Für
 * PostgreSQL zeigt das Formular das Feld gar nicht (`docs/38 §5`), also griff
 * der Ersatzwert **immer**, und `pg.database.create` bekam
 * `utf8mb4_unicode_ci` als `LC_COLLATE`:
 *
 *     ERROR: invalid LC_COLLATE locale name: "utf8mb4_unicode_ci"
 *
 * **Keine PostgreSQL-Datenbank liess sich anlegen — seit es die Funktion gibt.**
 * Gefunden am 10. August 2026 in Punkt 3 der Zwischenabnahme (`docs/39`), von
 * einem Betreiber, auf einem echten Server. Kein Test hat es gesehen: Alle
 * Tests, die eine Datenbank anlegen, geben eine Sortierung mit, weil sie MariaDB
 * meinen — und für die war der Ersatzwert richtig.
 *
 * > **Ein Ersatzwert für etwas, das es nicht gibt, ist keine Vorsicht — er ist
 * > eine Behauptung.**
 *
 * Das ist der vierte Fehler derselben Bauform an einem Tag, nach
 * `env('SRVPANEL_VERSION', …)`, `handed_over => false` und dem Vorgabewert für
 * das Datenbanksystem ({@see EngineDefaultTest}). Alle vier haben dieselbe
 * Herkunft: **ein zweites System, das die Ersatzwerte des ersten geerbt hat.**
 */
final class EngineCollationTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }

    /**
     * Die Schnittstelle lässt „keine Sortierung" überhaupt zu.
     *
     * **Ohne diese Zeile gäbe es die Lücke, die den Ersatzwert erzwang.** Ein
     * `string` verlangt einen Wert; wer keinen hat, erfindet einen. Der Typ ist
     * hier der eigentliche Wächter — er macht „nicht gewählt" sagbar.
     */
    public function test_the_contract_allows_no_collation(): void
    {
        $parameters = (new ReflectionMethod(EngineDriver::class, 'createDatabase'))->getParameters();
        $collation = $parameters[2] ?? null;

        $this->assertNotNull($collation, 'createDatabase() nimmt keine Sortierung mehr entgegen.');

        $type = $collation->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(
            $type->allowsNull(),
            'Die Sortierung ist wieder verpflichtend. Dann braucht jeder Aufrufer einen Wert — '.
            'und wer keinen hat, nimmt den erstbesten. Genau daran ist am 10. August 2026 jede '.
            'PostgreSQL-Datenbank gescheitert.',
        );
    }

    /**
     * Der Steuerungscode erfindet keine.
     */
    public function test_the_controller_invents_nothing(): void
    {
        $controller = $this->source('app/Http/Controllers/DatabaseController.php');

        $this->assertStringContainsString(
            "\$data['collation'] ?? null,",
            $controller,
            'Die fehlende Sortierung wird nicht mehr als „nicht gewählt" weitergereicht.',
        );

        $this->assertStringNotContainsString(
            "\$data['collation'] ?? \$this->collations()",
            $controller,
            'Der Ersatzwert ist zurück: Die erste MariaDB-Sortierung gilt wieder für jedes System.',
        );
    }

    /**
     * Und PostgreSQL bekommt schlicht kein Gebietsschema geschickt.
     *
     * **Die Richtung, die zählt.** Die beiden Prüfungen oben halten den Weg
     * offen; diese hier prüft, dass er auch benutzt wird. Stünde `'locale' =>`
     * wieder in der Nutzlast, wäre jede Sortierung, die dort landet, aus
     * demselben Grund falsch wie beim ersten Mal — die Frage gehört zu
     * `CREATE DATABASE` und nicht zum Formular.
     */
    public function test_postgres_sends_no_locale(): void
    {
        $driver = $this->source('app/Support/Databases/Engines/PostgresDriver.php');

        $this->assertStringNotContainsString(
            "'locale' =>",
            $driver,
            'Der PostgreSQL-Treiber schickt wieder ein Gebietsschema mit. Was dort ankommt, '.
            'kann nur aus dem Formular stammen — und das Formular fragt für PostgreSQL nicht '.
            'danach.',
        );

        // Die Untergrenze: Ohne sie wäre der Wächter auch mit einer Datei
        // einverstanden, die gar kein `pg.database.create` mehr aufruft.
        $this->assertStringContainsString("'pg.database.create'", $driver);
    }

    /**
     * Und die Oberfläche versteckt die Sortierung nicht mehr nach System.
     *
     * **Der Grund für das Verstecken ist weggefallen, und damit das
     * Verstecken.** Bis zum 10. August 2026 stand in `row()` ein
     * `=== MariaDb ? … : null`, weil für PostgreSQL sonst der Vorgabewert aus
     * P5 in der Zeile gestanden hätte — eine Angabe über eine Datenbank, die
     * ihn nie gesehen hat. Seit der Agent das Gebietsschema beim Cluster
     * erfragt, ist der Wert gemessen.
     *
     * **Eine fehlende Angabe ist ehrlicher als eine falsche — eine
     * unterschlagene ist beides nicht.** Die Zeile hängt jetzt daran, ob es
     * etwas zu sagen gibt, und nicht daran, welches System gemeint ist.
     */
    public function test_the_page_does_not_hide_the_collation_by_engine(): void
    {
        $controller = $this->source('app/Http/Controllers/DatabaseController.php');

        $this->assertStringContainsString(
            "'collation' => (\$database->collation ?? '') === '' ? null : \$database->collation,",
            $controller,
            'Die Zeile „Sortierung" hängt wieder am Datenbanksystem statt an der Angabe.',
        );

        $this->assertStringNotContainsString(
            '$database->engine === DatabaseEngine::MariaDb ? $database->collation : null',
            $controller,
            'Die Sortierung wird wieder nach System versteckt. Für PostgreSQL steht dort '.
            'seit dem 10. August 2026 ein gemessener Wert — ihn zu verschweigen ist '.
            'schlechter, als ihn zu zeigen.',
        );
    }

    /**
     * MariaDB behält seine Vorgabe — dort, wo sie gilt.
     *
     * Die Gegenrichtung. Ein `null`, das nirgends aufgefangen wird, wäre der
     * neue Fehler: Aufrufer ohne Formular — `srvpanel acceptance-db`, ein
     * künftiger Import — bekämen dann eine Datenbank ohne Sortierung statt der
     * Vorgabe.
     */
    public function test_mariadb_keeps_its_default_where_it_applies(): void
    {
        $this->assertStringContainsString(
            "\$collation ??= DbDatabaseCreate::charsets()['utf8mb4'][0];",
            $this->source('app/Support/Databases/Engines/MariaDbDriver.php'),
            'Der MariaDB-Treiber fängt „keine Sortierung" nicht mehr ab.',
        );
    }
}
