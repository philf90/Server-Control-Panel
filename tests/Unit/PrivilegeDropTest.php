<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die Reihenfolge der Rechteabgabe ist die Sache selbst.
 *
 * **Drei Griffe, und jede falsche Reihenfolge macht einen davon wirkungslos:**
 *
 * - `posix_initgroups()` braucht root. Nach `setuid` ist es zu spät, und der
 *   Aufruf schlägt fehl — leise, denn sein Rückgabewert interessiert dann
 *   niemanden mehr. Das Kind behielte die Zusatzgruppen von root und läse
 *   damit Dateien, die `root:root 0640` gehören (gemessen, `docs/50 §5`).
 * - `posix_setgid()` braucht root. Nach `setuid` darf ein Prozess seine Gruppe
 *   nicht mehr wechseln.
 * - `chroot()` braucht root. Nach `setuid` gibt es keine Grenze mehr zu
 *   setzen.
 *
 * **Und das Ganze ist nur eine Schranke, weil es unwiderruflich ist.** Ein
 * roher `chroot(2)` als root bricht aus; derselbe Code nach der Rechteabgabe
 * nicht — beides gemessen (`docs/50 §5`).
 *
 * > Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern eine
 * > Voreinstellung.
 *
 * Der Wächter liest die Reihenfolge aus dem Quelltext, weil sie sich zur
 * Laufzeit nicht prüfen lässt, ohne selbst root abzugeben.
 */
final class PrivilegeDropTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Griffe in der Reihenfolge, in der sie stehen müssen.
     *
     * @var list<string>
     */
    private const ORDER = ['chroot', 'posix_initgroups', 'posix_setgid', 'posix_setuid'];

    private function source(): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Sandbox.php'),
        );
    }

    /**
     * Einsperren, Gruppen, Gruppe, Benutzer — in dieser Folge.
     */
    public function test_the_drop_happens_in_the_only_order_that_works(): void
    {
        $source = $this->source();
        $positions = [];

        foreach (self::ORDER as $call) {
            $offset = strpos($source, $call.'(');

            $this->assertIsInt($offset, sprintf('%s() steht nicht mehr in Sandbox.', $call));

            $positions[$call] = $offset;
        }

        $sorted = $positions;
        asort($sorted);

        $this->assertSame(
            self::ORDER,
            array_keys($sorted),
            implode("\n", [
                'Die Rechteabgabe steht in der falschen Reihenfolge.',
                'Erwartet: '.implode(' -> ', self::ORDER),
                'Gefunden: '.implode(' -> ', array_keys($sorted)),
                'Jeder dieser Griffe braucht root. Was nach setuid steht, schlaegt fehl —',
                'und zwar leise.',
            ]),
        );
    }

    /**
     * Nach der Abgabe wird nachgesehen, ob sie stattgefunden hat.
     *
     * **Der Gürtel zum Hosenträger, und er ist kein Zierat.** Wenn die Abgabe
     * misslänge, ohne es zu melden, liefe die Arbeitsfunktion als root im
     * Verzeichnis des Kunden — mit einem `chroot`, das root zurücknehmen kann.
     * Das ist genau der Zustand, in dem das ganze Kriterium aus `docs/51 §4`
     * grün wäre und nichts hielte.
     */
    public function test_the_drop_is_verified_afterwards(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/posix_geteuid\(\)\s*===\s*0/',
            $source,
            'Nach der Rechteabgabe wird nicht geprüft, ob sie gewirkt hat.',
        );

        $this->assertGreaterThan(
            (int) strpos($source, 'posix_setuid('),
            (int) strpos($source, 'posix_geteuid()'),
            'Die Prüfung steht vor der Abgabe — dann prüft sie den Zustand davor.',
        );
    }

    /**
     * Und das Ergebnis des Kindes wird nicht geglaubt, sondern nachgesehen.
     *
     * Das Kind meldet `uid` und `groups` mit. Ein Elternprozess, der sie nur
     * durchreicht, hat einen Beleg eingesammelt und nicht gelesen — und
     * `docs/48` hat teuer gelernt, was eine Angabe wert ist, die eine Seite
     * bekommt und nicht anschaut.
     */
    public function test_the_parent_checks_the_proof_it_gets_back(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$decoded\\['uid'\\][^;]*===\s*0/",
            $this->source(),
            implode("\n", [
                'Der Elternprozess prueft die gemeldete uid nicht.',
                'Ein Ergebnis, das behauptet als root gelaufen zu sein, ist ein Fehler',
                'und kein Ergebnis (docs/51 §4, Punkt 13).',
            ]),
        );
    }
}
