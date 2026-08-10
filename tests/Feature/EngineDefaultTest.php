<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DatabaseEngine;
use App\Support\Databases\Databases;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Unit\PgHandoverTest;

/**
 * Welches Datenbanksystem gemeint ist, wird gesagt und nicht vorausgesetzt.
 *
 * **Der Anlass ist der dritte Fehler derselben Bauform an einem Tag**, und der
 * teuerste von den dreien. {@see Databases::createUser()} hatte
 * `DatabaseEngine $engine = DatabaseEngine::MariaDb`, und
 * `DatabaseController::createUserFor()` rief sie ohne dieses Argument. Folge:
 * **Jeder Zugang zu einer PostgreSQL-Datenbank entstand in MariaDB** —
 * `db.user.create` mit dem Systembenutzer als Präfix und dem PostgreSQL-Namen
 * in der Rechteliste. Gefunden am 10. August 2026 in Punkt 3 der
 * Zwischenabnahme (`docs/39`), auf einem echten Server, von einem Betreiber.
 *
 * Die anderen beiden desselben Tages: `env('SRVPANEL_VERSION', '0.1.0-dev')`
 * ({@see ReleaseVersionTest}) und `handed_over => false` im Grundzustand von
 * `Pg\Server::describe()` ({@see PgHandoverTest}).
 *
 * > **Ein Vorgabewert, den niemand überschreibt, ist kein Vorgabewert — er ist
 * > die Antwort.**
 *
 * **Warum ein Vorgabewert hier schlimmer ist als anderswo.** In einem System mit
 * einem Datenbanksystem war `MariaDb` keine Annahme, sondern eine Tatsache. Mit
 * dem zweiten wurde aus derselben Zeile eine stille Wahl — und zwar eine, die
 * *plausibel aussieht*: Der Aufruf ohne Argument liest sich in beiden Welten
 * richtig. **Wer ein zweites Etwas einführt, nimmt die Vorgabewerte des ersten
 * mit.**
 *
 * **Der eigentliche Wächter ist der Übersetzer und nicht dieser Test.** Ohne
 * Vorgabewert kann kein Aufrufer die Frage übersehen — auch keiner, den es
 * heute noch nicht gibt. Ein Test müsste jeden künftigen Aufruf einzeln
 * erwischen. Was hier steht, hält nur fest, dass der Vorgabewert nicht
 * zurückkommt.
 */
final class EngineDefaultTest extends TestCase
{
    /**
     * Die Methoden, die ein System brauchen — und es deshalb verlangen.
     *
     * Sie stehen abgeschrieben da und werden nicht aus der Klasse gelesen: Ein
     * Wächter, der seine Erwartung aus der geprüften Datei bezieht, ist mit
     * jeder Änderung einverstanden.
     *
     * @var list<string>
     */
    private const DEMANDING = ['create', 'createUser'];

    public function test_no_method_guesses_the_engine(): void
    {
        foreach (self::DEMANDING as $name) {
            $method = new ReflectionMethod(Databases::class, $name);
            $found = false;

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->getName() !== DatabaseEngine::class) {
                    continue;
                }

                $found = true;

                $this->assertFalse(
                    $parameter->isDefaultValueAvailable(),
                    sprintf(
                        'Databases::%s() hat wieder einen Vorgabewert für das Datenbanksystem. '
                        ."Genau daran ist am 10. August 2026 jeder PostgreSQL-Zugang in MariaDB \n"
                        .'gelandet: Der Aufrufer liess das Argument weg, und die Zeile sah richtig aus.',
                        $name,
                    ),
                );
            }

            // Die Untergrenze: Wandert der Parameter aus der Signatur, fände
            // die Schleife nichts und wäre damit einverstanden — der Wächter
            // meldete Grün für eine Methode, die das System gar nicht mehr
            // kennt.
            $this->assertTrue(
                $found,
                sprintf('Databases::%s() nimmt kein Datenbanksystem mehr entgegen.', $name),
            );
        }
    }

    /**
     * Und der Zugang bekommt das System der Datenbank, an der er hängt.
     *
     * Die Gegenrichtung. Ohne sie liesse sich der Fehler wiederholen, indem der
     * Steuerungscode ein festes `DatabaseEngine::MariaDb` einsetzt — die
     * Signatur wäre zufrieden, und das Ergebnis wäre dasselbe wie vorher.
     */
    public function test_the_access_follows_its_database(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/DatabaseController.php'
        );

        $this->assertStringContainsString(
            '$database->engine,',
            $controller,
            'Der Zugang bekommt das Datenbanksystem nicht mehr von seiner Datenbank. Steht dort '.
            'ein fester Wert, ist der Fehler vom 10. August 2026 zurück — nur eine Zeile weiter.',
        );
    }
}
