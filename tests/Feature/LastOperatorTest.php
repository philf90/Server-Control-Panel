<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Support\Authorization\LastOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Der letzte aktive Betreiber lässt sich nicht wegnehmen.
 *
 * ## Warum alle Wege hier stehen und nicht der eine, an den man denkt
 *
 * `docs/82 §8` sagt den Satz, der die Form dieses Wächters entscheidet:
 *
 * > **Eine Prüfung, die einen von drei Wegen kennt, ist keine Schranke, sondern
 * > ein Hinweisschild an einer von drei Türen.**
 *
 * Die Wege sind **herabstufen**, **sperren** und **löschen**. Im Formular sehen
 * sie verschieden aus — ein Auswahlfeld, ein zweites Auswahlfeld, ein Knopf —
 * und sie haben dieselbe Wirkung: Danach kommt niemand mehr an die
 * Einstellungen dieses Servers.
 *
 * ## Die Gegenproben sind kein Beiwerk
 *
 * Zwei der Fälle unten prüfen, dass etwas **durchgeht**. Ohne sie bestünde
 * dieser Wächter auch für eine Prüfung, die jede Änderung an jedem Konto
 * abweist — und das wäre genauso falsch, nur andersherum.
 *
 * > **Eine Schranke, die man nur von aussen prüft, ist von einer verschlossenen
 * > Tür nicht zu unterscheiden.**
 */
final class LastOperatorTest extends TestCase
{
    use RefreshDatabase;

    /** Weg 1: herabstufen. */
    public function test_the_last_operator_cannot_be_demoted(): void
    {
        $operator = Account::factory()->admin()->create(['name' => 'Der Einzige']);

        $this->actingAs($operator)
            ->patch("/accounts/{$operator->id}", [
                'name' => 'Der Einzige',
                'role' => AdminRole::Administrator->value,
                'status' => AccountStatus::Active->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(AdminRole::Operator, $operator->fresh()?->role,
            'Der letzte Betreiber wurde herabgestuft.');
    }

    /** Weg 2: sperren. */
    public function test_the_last_operator_cannot_be_disabled(): void
    {
        $operator = Account::factory()->admin()->create(['name' => 'Der Einzige']);

        $this->actingAs($operator)
            ->patch("/accounts/{$operator->id}", [
                'name' => 'Der Einzige',
                'role' => AdminRole::Operator->value,
                'status' => AccountStatus::Disabled->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(AccountStatus::Active, $operator->fresh()?->status,
            'Der letzte Betreiber wurde gesperrt.');
    }

    /**
     * Weg 3 gibt es nicht — und das ist hier ein Draht und keine Lücke.
     *
     * `docs/82 §9` lässt das Löschen von Adminkonten bewusst offen, solange das
     * Protokoll seinen Handelnden über `nullOnDelete()` verliert. Wer es später
     * baut, bekommt hier Rot und damit die Erinnerung, dass der Aussperrschutz
     * mitgehört — statt einer stillen dritten Tür.
     *
     * > **Ein Weg, den es noch nicht gibt, ist nur so lange kein Loch, wie
     * > jemand merkt, dass er entsteht.**
     */
    public function test_there_is_no_third_way(): void
    {
        $deleting = [];

        /*
         * **`->getRoutes()` auf der Sammlung und nicht die Sammlung selbst.**
         * `Route::getRoutes()` gibt ein `RouteCollectionInterface` zurück; das
         * ist zur Laufzeit iterierbar, sagt es aber im Typ nicht zu — PHPStan
         * meldet `foreach.nonIterable`. Die Methode darauf liefert das Array.
         */
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /*
             * **Die Adresse des Kontos selbst und nicht alles darunter.**
             *
             * Der erste Wurf fragte „irgendein `DELETE` unter `/accounts`" und
             * war beim ersten Zusatz rot: `DELETE /accounts/{admin}/sessions`
             * beendet eine Sitzung und nimmt niemandem seine Rolle. Ein
             * Wächter, der jede Unterressource mitmeldet, wird beim ersten
             * Aufräumen abgeschaltet.
             *
             * > **Ein Wächter, der Richtiges mitmeldet, ist kein strenger
             * > Wächter — er ist einer, den man gleich wieder los ist.**
             *
             * Gemeint ist die eine Adresse, hinter der das Konto verschwindet:
             * `accounts/{…}` und nichts dahinter.
             */
            if (preg_match('/\Aaccounts\/\{[^}\/]+\}\z/', $route->uri()) !== 1) {
                continue;
            }

            if (in_array('DELETE', $route->methods(), true)) {
                $deleting[] = $route->uri();
            }
        }

        $this->assertSame([], $deleting, sprintf(
            "Es gibt jetzt eine löschende Kontenroute:\n\n  %s\n\n"
            .'Löschen ist der dritte Weg in dieselbe Aussperrung (docs/82 §8). Er gehört durch '
            .'App\Support\Authorization\LastOperator, und dieser Wächter gehört um seinen Fall '
            .'erweitert — vorher ist der Schutz eine Schranke an zwei von drei Türen.',
            implode("\n  ", $deleting),
        ));
    }

    /**
     * **Die erste Gegenprobe.** Was den Betreiber nicht wegnimmt, geht durch.
     *
     * Ohne sie bestünden die beiden Fälle oben auch für eine Prüfung, die jede
     * Änderung am letzten Betreiber abweist — dann liesse sich nicht einmal
     * sein Name berichtigen.
     */
    public function test_the_last_operator_may_still_be_renamed(): void
    {
        $operator = Account::factory()->admin()->create(['name' => 'Falsch geschrieben']);

        $this->actingAs($operator)
            ->patch("/accounts/{$operator->id}", [
                'name' => 'Richtig geschrieben',
                'role' => AdminRole::Operator->value,
                'status' => AccountStatus::Active->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Richtig geschrieben', $operator->fresh()?->name);
    }

    /**
     * **Die zweite Gegenprobe.** Mit einem zweiten Betreiber geht beides.
     *
     * Das ist der Ausweg, den {@see LastOperator::refusal()}
     * nennt — und ein Ausweg, den niemand gegangen ist, ist eine Zusage und kein
     * Weg.
     */
    public function test_a_second_operator_makes_the_first_one_demotable(): void
    {
        $operator = Account::factory()->admin()->create(['name' => 'Der Erste']);
        Account::factory()->admin()->create(['name' => 'Der Zweite']);

        $this->actingAs($operator)
            ->patch("/accounts/{$operator->id}", [
                'name' => 'Der Erste',
                'role' => AdminRole::Administrator->value,
                'status' => AccountStatus::Active->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AdminRole::Administrator, $operator->fresh()?->role);
    }

    /**
     * Ein **gesperrter** Betreiber zählt nicht mit.
     *
     * Der Fall, der die Zählung entscheidet: Zwei Betreiber, einer davon
     * gesperrt — dann ist der aktive der letzte, und die Schranke muss halten.
     * Eine Zählung über `role = operator` allein hielte hier nicht.
     */
    public function test_a_disabled_operator_does_not_count(): void
    {
        $operator = Account::factory()->admin()->create(['name' => 'Der Aktive']);
        Account::factory()->admin()->create([
            'name' => 'Der Gesperrte',
            'status' => AccountStatus::Disabled,
        ]);

        $this->actingAs($operator)
            ->patch("/accounts/{$operator->id}", [
                'name' => 'Der Aktive',
                'role' => AdminRole::Administrator->value,
                'status' => AccountStatus::Active->value,
            ])
            ->assertSessionHasErrors('role');
    }

    /**
     * Ein Administrator lässt sich immer sperren.
     *
     * Er hält den Server nicht, also gibt es nichts zu schützen. Eine Schranke,
     * die auch hier zubisse, hätte die Frage „wer hält den Server" durch „wer
     * ist ein Adminkonto" ersetzt.
     */
    public function test_an_administrator_can_always_be_disabled(): void
    {
        $operator = Account::factory()->admin()->create();
        $administrator = Account::factory()->administrator()->create(['name' => 'Verwalter']);

        $this->actingAs($operator)
            ->patch("/accounts/{$administrator->id}", [
                'name' => 'Verwalter',
                'role' => AdminRole::Administrator->value,
                'status' => AccountStatus::Disabled->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Disabled, $administrator->fresh()?->status);
    }
}
