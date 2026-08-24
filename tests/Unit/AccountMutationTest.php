<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Jede ändernde Kontenroute fragt den Aussperrschutz — oder steht mit
 * Begründung daneben.
 *
 * ## Was dieser Wächter kann, was `LastOperatorTest` nicht kann
 *
 * `LastOperatorTest` misst die **Wirkung**: Der letzte Betreiber lässt sich
 * nicht herabstufen und nicht sperren, und die Gegenproben zeigen, dass die
 * Schranke nicht einfach alles abweist. Er misst sie an den Wegen, **die es
 * beim Schreiben gab**.
 *
 * Hier steht die Frage, die er nicht stellen kann: Gibt es einen **neuen** Weg?
 * Baut jemand morgen ein `POST /accounts/{admin}/disable`, weil das bequemer
 * ist als das Auswahlfeld, dann kennt der Feature-Test diesen Weg nicht — und
 * bleibt grün, während die Schranke daneben steht.
 *
 * > **Ein Wächter, der die bekannten Wege prüft, sagt nichts über den nächsten,
 * > den jemand baut.**
 *
 * Das ist dieselbe Bauart wie bei `AdminAbility` und `RouteGuard`: eine
 * Registratur, **in beide Richtungen** geprüft — der Name steht hier ohne
 * `{@see}`, weil Pint daraus einen `use`-Eintrag zöge und dieser Wächter dann
 * nicht mehr ohne Framework liefe. Die
 * Voreinstellung ist „muss fragen"; wer eine Ausnahme will, schreibt sie
 * unten hin und begründet sie.
 *
 * ## Und er läuft ohne Framework
 *
 * `LastOperatorTest` braucht Laravel und eine Datenbank, läuft also nur in der
 * CI. Dieser hier liest Text und läuft dort, wo `vendor/` fehlt.
 *
 * > **Zwei Wächter für eine Regel sind keine Verdopplung, wenn der eine die
 * > Wirkung misst und der andere sie dort hält, wo die Wirkung nicht messbar
 * > ist.**
 */
final class AccountMutationTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Ändernde Kontenrouten, die **keinen** Betreiber wegnehmen können.
     *
     * **Die Begründung ist der Zweck dieser Liste.** Ein Name allein wäre eine
     * Abkürzung; der Satz daneben zwingt dazu, die Frage wirklich zu stellen.
     *
     * @var array<string, string> Methode => Begründung
     */
    private const HARMLESS = [
        'store' => 'Legt ein Konto an. Ein neues Konto nimmt keinem bestehenden seine Rolle und '
            .'sperrt keines — die Zahl der aktiven Betreiber kann dabei nur steigen.',
        'password' => 'Setzt ein Passwort. Rolle und Zustand bleiben unberührt; wer vorher '
            .'Betreiber und aktiv war, ist es danach.',
    ];

    /** Die eine Stelle, die entscheidet, ob eine Änderung jemanden aussperrt. */
    private const CHECK = 'LastOperator::permits(';

    /**
     * Jede ändernde Route fragt — oder steht in der Liste.
     */
    public function test_every_mutating_account_route_asks_the_guard(): void
    {
        $methods = $this->mutatingMethods();

        $this->assertGreaterThanOrEqual(3, count($methods), sprintf(
            'Es werden nur %d ändernde Kontenrouten gefunden. Entweder sind sie umgezogen oder '
            .'der Ausdruck oben trifft nicht mehr — in beiden Fällen misst dieser Test nichts.',
            count($methods),
        ));

        $source = $this->controller();
        $unchecked = [];

        foreach ($methods as $method) {
            if (array_key_exists($method, self::HARMLESS)) {
                continue;
            }

            if (! str_contains($this->body($source, $method), self::CHECK)) {
                $unchecked[] = $method;
            }
        }

        $this->assertSame([], $unchecked, sprintf(
            "Diese ändernden Kontenrouten fragen den Aussperrschutz nicht:\n\n  %s\n\n"
            .'Entweder ruft die Methode %s — oder sie gehört mit einer Begründung in die Liste '
            .'HARMLESS in diesem Test. Ohne beides ist der Schutz eine Schranke an einer von '
            .'mehreren Türen (docs/82 §8).',
            implode("\n  ", $unchecked),
            self::CHECK.')',
        ));
    }

    /**
     * Und die Gegenrichtung: keine Ausnahme für etwas, das es nicht gibt.
     *
     * **Ohne diese Frage wächst die Liste über Jahre.** Eine Methode `destroy`,
     * die einmal harmlos war und ausgebaut wurde, entschuldigte sonst eine
     * neue Methode desselben Namens — und die wäre der dritte Weg in dieselbe
     * Aussperrung.
     *
     * > **Eine Registratur, die nur in eine Richtung geprüft wird, wächst über
     * > Jahre und deckt irgendwann etwas, an das niemand mehr gedacht hat.**
     */
    public function test_no_exception_stands_for_a_route_that_is_gone(): void
    {
        $methods = $this->mutatingMethods();
        $stale = array_values(array_diff(array_keys(self::HARMLESS), $methods));

        $this->assertSame([], $stale, sprintf(
            "Für diese Methoden steht eine Ausnahme, und die Route dazu gibt es nicht mehr:\n\n  %s",
            implode("\n  ", $stale),
        ));
    }

    /**
     * Die Methodennamen aller ändernden Routen unter `/accounts`.
     *
     * @return list<string>
     */
    private function mutatingMethods(): array
    {
        $routes = $this->withoutComments($this->read('routes/web.php'));

        preg_match_all(
            "/Route::(?:post|patch|put|delete)\('\/accounts[^']*',\s*\[AccountController::class,\s*'(\w+)'\]/",
            $routes,
            $treffer,
        );

        /** @var list<string> $namen */
        $namen = array_values(array_unique($treffer[1]));

        return $namen;
    }

    private function controller(): string
    {
        return $this->withoutComments($this->read('app/Http/Controllers/AccountController.php'));
    }

    /**
     * Der Rumpf einer Methode — bis zur nächsten Deklaration.
     *
     * **Grob und mit Absicht.** Ein Parser für PHP wäre hier die zweite Fassung
     * von etwas, das es gibt; gebraucht wird nur die Frage „steht der Aufruf in
     * dieser Methode und nicht in der daneben". Findet der Ausdruck die Methode
     * nicht, ist das ein Fehlschlag und keine leere Zeichenkette: Eine Methode,
     * die es laut Route gibt und im Controller nicht, ist selbst ein Befund.
     */
    private function body(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');

        $this->assertNotFalse($start, sprintf(
            'Die Route zeigt auf %s(), und im Controller gibt es die Methode nicht.',
            $method,
        ));

        $rest = substr($source, $start);
        $next = preg_split('/\n    (?:public|private|protected) (?:static )?function /', $rest, 2);

        return $next[0];
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($path, $relative.' gibt es nicht mehr.');

        return (string) file_get_contents($path);
    }
}
