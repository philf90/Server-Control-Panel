<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Support\Authorization\LastOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * Weg 3: löschen — und bis zum 10. September 2026 stand hier das Gegenteil.
     *
     * **Der Vorgänger war ein Draht und keine Prüfung.** Er suchte ein `DELETE`
     * auf `accounts/{…}` und meldete Rot, sobald es eines gab: `docs/82 §9`
     * liess das Löschen offen, solange das Protokoll seinen Handelnden über
     * `nullOnDelete()` verlor, und der Draht sollte den erinnern, der es baut.
     *
     * > **Ein Weg, den es noch nicht gibt, ist nur so lange kein Loch, wie
     * > jemand merkt, dass er entsteht.**
     *
     * Er hat zugebissen, als die Route entstand — und ist damit zu dem Fall
     * geworden, auf den er gewartet hat. Gemessen wird jetzt die **Wirkung**
     * und nicht mehr die Abwesenheit: Der letzte aktive Betreiber überlebt den
     * Aufruf.
     *
     * **Welche der beiden Regeln ihn dabei rettet, sagt dieser Fall nicht** —
     * und das ist gemessen, nicht vermutet: Nimmt man
     * `LastOperator::permits()` aus `destroy()` heraus, bleibt er grün, weil
     * die Selbstprüfung zuerst antwortet. Er misst den Ausgang, nicht den Weg.
     *
     * > **Ein Prüfkörper, den zwei Regeln abweisen, sagt über keine von beiden
     * > etwas.**
     *
     * Den Aufruf hält deshalb `AccountMutationTest` über den Quelltext, den
     * Zielzustand der Fall darunter. Warum es dazwischen nichts gibt, steht
     * ebenfalls dort.
     */
    public function test_the_last_operator_cannot_be_deleted(): void
    {
        $operator = Account::factory()->admin()->create();

        $this->actingAs($operator)
            ->delete("/accounts/{$operator->id}")
            ->assertSessionHasErrors('account');

        $this->assertNotNull($operator->fresh(), 'Der letzte Betreiber wurde gelöscht.');
    }

    /**
     * **Der Aussperrschutz kennt den Löschweg — gemessen am Zielzustand.**
     *
     * Hier stand zuerst eine Messung durch die Route: ein zweites Konto löscht
     * den letzten aktiven Betreiber. **Sie ist nicht herstellbar**, und das ist
     * keine Schwäche des Prüfkörpers, sondern eine Eigenschaft des Entwurfs.
     *
     * Wer die Route erreicht, trägt `operate-server`, und das löst seit A9 auf
     * den **aktiven Betreiber** auf. Gäbe es einen zweiten davon, wäre das Ziel
     * nicht mehr der letzte; gibt es keinen, kommt niemand bis zum Controller.
     * Gemessen: Ein gesperrtes Adminkonto bekommt **302 auf `/login`**, also
     * die Tür und nicht die Prüfung.
     *
     * > **Zwei Regeln, die sich nur an einem Zustand trennen lassen, den es
     * > nicht geben kann, lassen sich durch die Tür nicht auseinanderhalten.**
     *
     * Gefragt wird deshalb {@see LastOperator::permits()} unmittelbar, mit dem
     * Zielzustand eines gelöschten Kontos — keine Rolle, nicht aktiv. Was der
     * Controller damit tut, hält `AccountMutationTest` in beide Richtungen;
     * dass der Weg auch wirkt, hält der Fall darüber.
     */
    public function test_the_guard_refuses_the_target_state_of_a_deleted_account(): void
    {
        $operator = Account::factory()->admin()->create();
        Account::factory()->admin()->create(['status' => AccountStatus::Disabled]);

        $this->assertSame(1, LastOperator::active(), 'Der Prüfkörper stellt nicht genau einen aktiven Betreiber her.');

        $this->assertFalse(
            LastOperator::permits($operator, null, AccountStatus::Disabled),
            'Der Aussperrschutz lässt den Zielzustand eines gelöschten letzten Betreibers zu.',
        );

        /*
         * **Die Gegenprobe**, ohne die der Fall auch für eine Prüfung bestünde,
         * die jeden Zielzustand ablehnt: Mit einem zweiten aktiven Betreiber
         * geht derselbe Aufruf durch.
         */
        Account::factory()->admin()->create();

        $this->assertTrue(LastOperator::permits($operator, null, AccountStatus::Disabled));
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
