<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Acceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Abnahmelauf — die Teile, die ohne echten Server prüfbar sind.
 *
 * **Was hier nicht geprüft wird, und warum das in Ordnung ist.** Der Lauf
 * selbst braucht `useradd`, ein Dateisystem mit Quota und einen Arbeiter unter
 * systemd. Nichts davon gibt es in einem Test, und ein Test mit einem
 * erfundenen Agenten prüfte genau das nicht, worum es geht: ob nach hundert
 * echten Rückbauten etwas auf einem echten Server zurückbleibt. Deshalb steht
 * der Lauf als Kommando da; wie man ihn ausführt, steht in docs/26 §7.
 *
 * Prüfbar ist trotzdem die Stelle, an der er sein Urteil fällt. Ein
 * Abnahmelauf, der Rückstände findet und trotzdem 0 zurückgibt, ist
 * schlimmer als keiner: Er bescheinigt ein Kriterium, das nicht erfüllt ist.
 */
final class AcceptanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_run_needs_a_customer_and_a_plan(): void
    {
        /*
         * Beide legt es **nicht** selbst an. Eine Kundennummer ist auf Dauer
         * verbraucht, auch nach dem Zurückziehen — ein Abnahmelauf soll keine
         * Lücke in der Nummernfolge hinterlassen, über die in einem Jahr
         * jemand stolpert.
         */
        $this->artisan('srvpanel:acceptance', ['--force' => true, '--count' => 1])
            ->expectsOutputToContain('Es braucht mindestens einen Kunden und einen Plan')
            ->assertExitCode(1);
    }

    public function test_a_prefix_that_could_be_a_path_is_refused(): void
    {
        // Die Vorsilbe wird Teil des Verzeichnisnamens unter /var/www/vhosts.
        // Der Agent weist sie später ohnehin ab — hier scheitert der Lauf,
        // bevor er das erste Abonnement angelegt hat.
        foreach (['../etc', 'Abnahme', 'abnahme/tief', ''] as $prefix) {
            $this->artisan('srvpanel:acceptance', ['--force' => true, '--prefix' => $prefix])
                ->expectsOutputToContain('Vorsilbe')
                ->assertExitCode(1);
        }
    }

    public function test_every_kind_of_leftover_fails_the_run(): void
    {
        $leer = ['users' => [], 'groups' => [], 'directories' => [], 'quotas' => []];

        $this->assertTrue(Acceptance::passed($leer), 'Ohne Rückstand ist das Kriterium erfüllt.');

        foreach (array_keys($leer) as $art) {
            $this->assertFalse(
                Acceptance::passed([...$leer, $art => ['p1000']]),
                sprintf(
                    'Ein Rückstand der Art „%s" lässt den Lauf durchgehen. Dann bescheinigt er ein '.
                    'Kriterium, das nicht erfüllt ist.',
                    $art,
                ),
            );
        }
    }

    public function test_the_group_is_looked_for_separately_from_the_user(): void
    {
        /*
         * `userdel` entfernt die Gruppe nicht mit, wenn sie nicht die primäre
         * ist — und beim Anlegen steht ausdrücklich `--no-user-group`. Eine
         * Gegenprobe, die nur nach dem Benutzer sucht, übersieht deshalb
         * genau den Rückstand, den dieser Aufbau wahrscheinlich macht.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/Acceptance.php');

        $this->assertStringContainsString('posix_getpwnam', $source);
        $this->assertStringContainsString('posix_getgrnam', $source);
        $this->assertStringContainsString("call('subscription.usage')", $source);
    }
}
