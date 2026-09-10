<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ein Adminkonto verschwindet — und was es getan hat, bleibt (`docs/901 §3.5`).
 *
 * ## Warum dieser Weg bis zum 10. September 2026 fehlte
 *
 * `audit_events.account_id` steht auf `nullOnDelete()`. Solange die Zeile nur
 * den Fremdschlüssel trug, zog ein gelöschtes Konto seine ganze Geschichte auf
 * `null` — und `docs/82 §9` hat das Löschen deshalb offengelassen. Die
 * Abschrift aus `RecordsTheActor` nimmt dem Satz seinen Grund.
 *
 * > **Löschen und Vergessen sind zwei Dinge. Die Zeile darf verschwinden; was
 * > sie getan hat, darf es nicht.**
 *
 * ## Was hier gemessen wird und was woanders steht
 *
 * Der **Aussperrschutz** steht in `LastOperatorTest` — dort mit dem Nachweis,
 * dass sich seine Ablehnung durch die Tür nicht von der Selbstprüfung trennen
 * lässt. Dass jede ändernde Kontenroute ihn **fragt**, hält
 * `AccountMutationTest` ohne Framework. Hier steht die Wirkung des Weges
 * selbst.
 */
final class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function betreiber(string $name = 'Anna Berger'): Account
    {
        return Account::factory()->admin()->create(['name' => $name]);
    }

    /** Das Konto ist danach fort — hart, ohne Grabstein. */
    public function test_the_account_is_gone(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = $this->betreiber();

        $this->actingAs($ich)
            ->delete("/accounts/{$andere->id}")
            ->assertRedirect('/accounts');

        $this->assertNull(Account::query()->find($andere->id));
    }

    /**
     * **Und seine Anmeldeadresse wird wieder frei.**
     *
     * Für Kundenkonten hält das `2026_08_06_140000_release_the_address_of_a_…`
     * über eine nullable Spalte, weil die Zeile dort **bleibt**. Hier ist die
     * Zeile fort, und der Unique-Index gibt die Adresse von selbst her — der
     * Unterschied ist der Grund, aus dem dieser Fall dasteht.
     */
    public function test_the_login_address_becomes_free_again(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = Account::factory()->admin()->create(['email' => 'anna@example.org']);

        $this->actingAs($ich)->delete("/accounts/{$andere->id}");

        $neu = Account::factory()->admin()->create(['email' => 'anna@example.org']);

        $this->assertNotSame($andere->id, $neu->id);
    }

    /**
     * **Der Eintrag steht vor dem Löschen, und sein Zusammenhang trägt die
     * Bindung.**
     *
     * `audit_events` benutzt `nullableMorphs`: `target_id` zeigt danach auf eine
     * Zeile, die es nicht mehr gibt. Dieser eine Eintrag ist die Stelle, an der
     * „dieser Name gehörte zu dieser Adresse und dieser Kennung" festgehalten
     * wird — die Abschrift auf den übrigen Zeilen trägt nur den Namen.
     */
    public function test_the_deletion_is_recorded_with_name_address_and_role(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = Account::factory()->admin()->create([
            'name' => 'Anna Berger',
            'email' => 'anna@example.org',
        ]);

        $this->actingAs($ich)->delete("/accounts/{$andere->id}");

        $eintrag = AuditEvent::query()->where('action', 'account.deleted')->sole();

        $this->assertSame(AuditResult::Success, $eintrag->result);
        $this->assertSame('Anna Berger', $eintrag->context['name'] ?? null);
        $this->assertSame('anna@example.org', $eintrag->context['email'] ?? null);
        $this->assertSame('operator', $eintrag->context['role'] ?? null);
    }

    /**
     * **Und seine Geschichte bleibt lesbar.**
     *
     * Das ist die Zusage, auf der der ganze Weg steht: Der Eintrag verliert
     * seinen Fremdschlüssel und behält seinen Namen.
     */
    public function test_the_history_of_the_deleted_account_stays_readable(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = $this->betreiber('Anna Berger');

        $frueher = AuditEvent::query()->create([
            'account_id' => $andere->id,
            'action' => 'settings.access',
            'result' => AuditResult::Success,
        ]);

        $this->actingAs($ich)->delete("/accounts/{$andere->id}");

        $frueher->refresh();

        $this->assertNull($frueher->account_id, 'nullOnDelete() greift nicht mehr — dann misst dieser Fall etwas anderes.');
        $this->assertSame('Anna Berger (gelöscht)', $frueher->actor());
    }

    /**
     * **Die offenen Sitzungen gehen mit.**
     *
     * `sessions.user_id` trägt als einziger der sechs Verweise auf ein Konto
     * **keinen** Fremdschlüssel — dort räumt sonst niemand auf, und die Zeile
     * bliebe bis zur Sitzungsbereinigung liegen.
     *
     * Die Gegenprobe steht daneben: Die Sitzung eines fremden Kontos bleibt.
     * Ohne sie bestünde dieser Fall auch für ein `delete()` ohne Bedingung.
     */
    public function test_the_open_sessions_go_with_it(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = $this->betreiber();

        foreach ([[$andere->id, 'a'], [$andere->id, 'b'], [$ich->id, 'c']] as [$konto, $id]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $konto,
                'ip_address' => '203.0.113.1',
                'user_agent' => 'Prüfkörper',
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->actingAs($ich)->delete("/accounts/{$andere->id}");

        $this->assertSame(0, DB::table('sessions')->where('user_id', $andere->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $ich->id)->count());
    }

    /**
     * **Das eigene Konto nicht** — auch wenn ein zweiter Betreiber übrig
     * bliebe, also gerade dann, wenn der Aussperrschutz nichts sagt.
     *
     * Der zweite Betreiber steht deshalb hier: Ohne ihn wiese schon
     * `LastOperator` ab, und dieser Fall bestünde, ohne die Selbstprüfung je
     * erreicht zu haben.
     *
     * > **Ein Prüfkörper, den zwei Regeln abweisen, sagt über keine von beiden
     * > etwas.**
     */
    public function test_nobody_deletes_their_own_account(): void
    {
        $ich = $this->betreiber('Ich');
        $this->betreiber('Zweiter Betreiber');

        $this->actingAs($ich)
            ->delete("/accounts/{$ich->id}")
            ->assertSessionHasErrors('account');

        $this->assertNotNull($ich->fresh());
    }

    /**
     * **Ein Kundenkonto ist über diese Route nicht erreichbar.**
     *
     * Die Bindung `{admin}` löst ausschliesslich Adminkonten auf
     * (`SrvPanelServiceProvider`), und das ist der Grund, aus dem
     * `account_subscription` mit seinem `cascadeOnDelete` hier keine Rolle
     * spielt (`docs/901 §4`).
     */
    public function test_a_customer_account_is_out_of_reach(): void
    {
        $ich = $this->betreiber('Ich');
        $kunde = Account::factory()->customer(Customer::factory()->create())->create();

        $this->actingAs($ich)->delete("/accounts/{$kunde->id}")->assertNotFound();

        $this->assertNotNull($kunde->fresh());
    }

    /**
     * **Und die Seite zeigt keinen Knopf, den der Aufruf danach abwiese.**
     *
     * Gemessen an der Ablage und nicht an der Vorlage: `is_self` und
     * `is_last_operator` kommen aus derselben Quelle, die `destroy()` später
     * fragt.
     */
    public function test_the_page_marks_the_rows_that_cannot_be_deleted(): void
    {
        $ich = $this->betreiber('Ich');
        $andere = $this->betreiber();

        $zeilen = $this->actingAs($ich)->get('/accounts')
            ->viewData('page')['props']['accounts']['data'];

        $nach = [];

        foreach ($zeilen as $zeile) {
            $nach[$zeile['id']] = [$zeile['is_self'], $zeile['is_last_operator']];
        }

        $this->assertSame([true, false], $nach[$ich->id], 'Die eigene Zeile ist nicht als eigene erkennbar.');
        $this->assertSame([false, false], $nach[$andere->id]);
    }
}
