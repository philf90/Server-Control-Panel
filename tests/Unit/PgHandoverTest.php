<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Pg\Server;

/**
 * „Die Rolle fehlt" wird gemessen und nicht aus einem Vorgabewert gelesen.
 *
 * **Der Anlass steht in `docs/39` Punkt 2 und ist auf einem Bild gefunden
 * worden.** Bei gestopptem Cluster zeigte „Einstellungen → Datenbankserver" den
 * Hinweis *Rolle anlegen* samt Befehl — und dieser Befehl kann in genau diesem
 * Zustand nicht laufen: `psql` erreicht keinen Server. Die Seite gab also eine
 * Anweisung, deren einziges mögliches Ergebnis eine Fehlermeldung war.
 *
 * Die Ursache war kein falscher Zweig, sondern ein **Vorgabewert, der wie eine
 * Messung gelesen wurde.** `Server::describe()` legte `handed_over` im Grundsatz
 * auf `false`, und drei der sieben Zustände überschrieben es nie — sie kommen
 * gar nicht so weit, sich anzumelden. Das Panel sah `false` und schloss
 * „die Rolle fehlt", wo die Wahrheit „nicht nachgesehen" war.
 *
 * **Es ist derselbe Fehler wie `env('SRVPANEL_VERSION', '0.1.0-dev')` zwei Tage
 * davor**, nur in einem Array statt in einer Konfiguration: *Ein Vorgabewert,
 * den niemand überschreibt, ist kein Vorgabewert — er ist die Antwort.* Und er
 * hat dieselbe Eigenschaft, die ihn so teuer macht: Die Zeile sieht richtig aus.
 *
 * **Was dieser Wächter prüft, ist deshalb die Form und nicht ein Fall.**
 * `describe()` gegen einen echten Cluster zu fahren beantwortete die Frage für
 * den Zustand, den dieser Cluster gerade hat; die anderen sechs blieben offen.
 * Geprüft wird stattdessen, dass **kein Zweig `handed_over` unbeantwortet
 * lässt** — und dass die Oberfläche die dritte Antwort auch als dritte liest.
 */
final class PgHandoverTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }

    /**
     * Der Grundzustand sagt „nicht nachgesehen" und nicht „nein".
     */
    public function test_the_default_is_not_an_answer(): void
    {
        $server = $this->source('agent/src/Pg/Server.php');

        $this->assertMatchesRegularExpression(
            "/'handed_over' => null,/",
            $server,
            'Der Grundzustand von describe() beantwortet die Frage nach der Rolle, ohne '.
            'nachgesehen zu haben. Genau daran hing der Hinweis „Rolle anlegen" bei '.
            'gestopptem Cluster.',
        );

        $this->assertDoesNotMatchRegularExpression(
            "/\\\$blank = \[[^]]*'handed_over' => false/s",
            $server,
            'Im Grundzustand steht wieder `false`. Drei Zustände überschreiben ihn nie — '.
            'sie melden dann „die Rolle fehlt" für etwas, das niemand geprüft hat.',
        );
    }

    /**
     * Und wo die Antwort feststeht, steht sie ausdrücklich da.
     *
     * **Die Gegenrichtung, und ohne sie wäre die erste eine Falle.** Ein `null`
     * im Grundzustand, das nirgends überschrieben wird, hiesse: Der Hinweis
     * erscheint nie — auch dort nicht, wo er hingehört. Der Wächter verlangt
     * deshalb beide Werte je einmal: das gemessene `false` in
     * `not_handed_over`, das `true` in `ready`/`unusable`.
     */
    public function test_the_measured_cases_say_so_explicitly(): void
    {
        $server = $this->source('agent/src/Pg/Server.php');

        $this->assertMatchesRegularExpression(
            "/'state' => 'not_handed_over',.*?'handed_over' => false,/s",
            $server,
            'Der Zustand „not_handed_over" sagt nicht mehr, dass die Rolle fehlt — dann '.
            'zeigt das Panel den Befehl nicht mehr an, und der Betreiber kommt nicht weiter.',
        );

        $this->assertMatchesRegularExpression(
            "/'handed_over' => true,/",
            $server,
            'Kein Zweig meldet mehr eine geglückte Übergabe.',
        );
    }

    /**
     * Die Oberfläche liest die dritte Antwort als dritte.
     *
     * `!handed_over` wäre in JavaScript für `null` **und** für `false` wahr —
     * die Bedingung sähe richtig aus und hätte den Fehler zurückgebracht, ohne
     * dass am Agenten etwas falsch gewesen wäre. Das ist die Stelle, an der die
     * Dreiwertigkeit verlorengeht, wenn sie verlorengeht.
     */
    public function test_the_page_distinguishes_unknown_from_no(): void
    {
        $page = $this->source('resources/js/Pages/Settings/Database.vue');

        $this->assertStringContainsString(
            'props.postgresql.handed_over === false',
            $page,
            'Der Hinweis „Rolle anlegen" hängt nicht mehr am ausdrücklichen Nein.',
        );

        $this->assertStringNotContainsString(
            '!props.postgresql.handed_over',
            $page,
            'Die Seite prüft wieder auf Falschheit statt auf das Nein. `null` ist in '.
            'JavaScript ebenfalls falsch — und `null` heisst hier „nicht nachgesehen".',
        );
    }

    /**
     * Und der Controller ebnet sie nicht ein.
     *
     * Die dritte Stelle, an der die Angabe durchgeht, und die einzige, an der
     * sie unauffällig stirbt: `($info['handed_over'] ?? false) === true` macht
     * aus drei Werten zwei, ohne dass es jemandem auffällt. Genau so stand es
     * hier.
     */
    public function test_the_controller_passes_the_third_value_through(): void
    {
        $controller = $this->source('app/Http/Controllers/DatabaseSettingsController.php');

        $this->assertStringContainsString(
            "is_bool(\$info['handed_over'] ?? null) ? \$info['handed_over'] : null",
            $controller,
            'Der Controller reicht die Angabe nicht mehr dreiwertig durch — dann sieht die '.
            'Seite nie ein `null`, und die Unterscheidung dort ist wirkungslos.',
        );
    }

    /**
     * Der Typ sagt es auch.
     *
     * Ohne diese Zeile wäre `describe()` weiter als `bool` beschrieben, und
     * PHPStan hielte jedes `=== null` für einen toten Vergleich — ein Werkzeug,
     * das die Regel für einen Fehler hält, ist die halbe Miete für ihre
     * Rücknahme.
     */
    public function test_the_type_admits_the_third_value(): void
    {
        $this->assertStringContainsString(
            'handed_over: bool|null',
            $this->source('agent/src/Pg/Server.php'),
        );

        $this->assertStringContainsString(
            'handed_over: bool|null',
            $this->source('app/Http/Controllers/DatabaseSettingsController.php'),
        );
    }

    /**
     * Und `Server` ist wirklich die Klasse, um die es geht.
     *
     * Ein Wächter, der nur Dateien als Text liest, überlebt jede Umbenennung
     * der Klasse darin. Diese Zeile bindet ihn an den Typ.
     */
    public function test_the_guard_watches_the_class_it_names(): void
    {
        $this->assertTrue(method_exists(Server::class, 'describe'));
    }
}
