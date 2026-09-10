<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Access;
use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Support\Audit\AuditQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wer gehandelt hat, bleibt lesbar — auch ohne das Konto (`docs/901 §3.4`).
 *
 * ## Der Prüfkörper ist der Fall, den es ohne die Spalte nicht gäbe
 *
 * `audit_events.account_id` steht auf `nullOnDelete()`. **Und `NULL` trägt dort
 * schon eine Bedeutung:** {@see Access} schreibt seinen
 * Eintrag ohne Konto, weil auf der Kommandozeile niemand angemeldet ist, und
 * `Operations::dispatch()` tut dasselbe für jede Automatik.
 *
 * Ohne die Abschrift fielen deshalb zwei Zustände zusammen, und die bequeme
 * Lesart gewänne: Ein Sammelname für Gelöschte beschriftete jeden Cron-Lauf als
 * gelöschten Benutzer.
 *
 * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen —
 * > die beiden Fälle sehen danach gleich aus.**
 *
 * Der Fall „kein Konto, keine Abschrift" ist deshalb der wichtigste hier: Er
 * muss **`System`** ergeben und nicht „gelöscht".
 *
 * ## Gemessen wird die Wirkung und nicht der Quelltext
 *
 * `test_the_page_carries_the_actor` und `test_the_export_carries_the_actor`
 * gehen durch dieselbe Abbildung wie Seite und Ausfuhr. Ein Wächter über den
 * Quelltext sagt, dass die Teile zusammenpassen — nicht, dass sie zusammen
 * etwas tun.
 *
 * > **Ein Feld im Payload ist noch keine Spalte.**
 */
final class ActorLabelTest extends TestCase
{
    use RefreshDatabase;

    private function event(?Account $account): AuditEvent
    {
        return AuditEvent::query()->create([
            'account_id' => $account?->id,
            'action' => 'settings.access',
            'result' => AuditResult::Success,
        ]);
    }

    /**
     * **Der wichtigste Fall.** Kein Konto und keine Abschrift heisst „niemand
     * war angemeldet" und nicht „das Konto ist fort".
     */
    public function test_a_row_without_an_account_reads_as_the_system(): void
    {
        $event = $this->event(null);

        $this->assertNull($event->account_name);
        $this->assertSame('System', $event->actor());
    }

    /** Ein lebendes Konto steht mit seinem Namen da, ohne Zusatz. */
    public function test_a_row_of_a_living_account_reads_as_its_name(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);

        $this->assertSame('Anna Berger', $this->event($account)->actor());
    }

    /**
     * **Der Fall, für den es die Spalte gibt.**
     *
     * Das Konto ist fort, `account_id` steht über `nullOnDelete()` auf `null` —
     * und der Eintrag trägt weiter seinen Namen.
     */
    public function test_a_row_of_a_deleted_account_keeps_its_name(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);
        $event = $this->event($account);

        $account->delete();
        $event->refresh();

        $this->assertNull($event->account_id, 'nullOnDelete() greift nicht mehr — dann prüft dieser Test etwas anderes.');
        $this->assertSame('Anna Berger (gelöscht)', $event->actor());
    }

    /**
     * **Die Abschrift hält fest, was damals galt** — und das ist der Grund, aus
     * dem sie beim Anlegen entsteht und nicht beim Löschen.
     *
     * Wer erst beim Löschen schriebe, stempelte den **letzten** Namen auf
     * Zeilen, die unter einem früheren entstanden sind.
     */
    public function test_the_transcript_keeps_the_name_that_was_true_then(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);
        $event = $this->event($account);

        $account->forceFill(['name' => 'Anna Krause'])->save();
        $account->delete();
        $event->refresh();

        $this->assertSame('Anna Berger (gelöscht)', $event->actor());
    }

    /**
     * Dieselbe Regel am Vorgang — beide Modelle tragen denselben Trait, und
     * dieser Fall belegt, dass er an beiden **wirkt**.
     */
    public function test_an_operation_keeps_the_name_of_its_actor(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);

        $operation = Operation::query()->create([
            'type' => 'system.info',
            'account_id' => $account->id,
        ]);

        $account->delete();
        $operation->refresh();

        $this->assertNull($operation->account_id);
        $this->assertSame('Anna Berger (gelöscht)', $operation->actor());
    }

    /**
     * **Eine Abschrift, die der Aufrufer mitgibt, wird nicht überschrieben.**
     *
     * Wer sie ausdrücklich setzt, weiss mehr als die Abfrage im Ereignis.
     * Geprüft über die Spalte und nicht über `create()`, weil sie mit Absicht
     * nicht füllbar ist.
     */
    public function test_a_transcript_that_is_already_there_stays(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);

        $event = new AuditEvent([
            'account_id' => $account->id,
            'action' => 'settings.access',
            'result' => AuditResult::Success,
        ]);
        $event->account_name = 'Von Hand';
        $event->save();

        $this->assertSame('Von Hand', $event->refresh()->account_name);
    }

    /**
     * **Die Seite trägt ihn.** Bis zum 9. September 2026 stand `account_id` in
     * der Ablage und wurde von keiner Zeile gerendert.
     */
    public function test_the_page_carries_the_actor(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);
        $row = AuditQuery::toArrayRow($this->event($account));

        $this->assertArrayHasKey('account', $row);
        $this->assertSame('Anna Berger', $row['account']);
    }

    /**
     * **Und die Ausfuhr trägt ihn statt der Kennung.** Der Beleg, den jemand
     * drei Jahre aufhebt, sagte „Konto 3".
     */
    public function test_the_export_carries_the_actor(): void
    {
        /*
         * **`admin()` und nicht die Vorgabe.** §6.4 macht den zweiten Faktor
         * für Adminkonten verpflichtend, und die Mittelschicht setzt das durch:
         * Ein Konto ohne ihn bekommt eine 302 auf die Einrichtungsseite — und
         * die läse sich hier wie ein Befund an der Ausfuhr.
         */
        $account = Account::factory()->admin()->create(['name' => 'Anna Berger']);
        $this->event($account);

        $this->actingAs($account);

        $response = $this->get('/audit/export');
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Anna Berger', $csv);
        $this->assertStringContainsString('Wer', $csv);
    }

    /**
     * **Der Nachtrag trägt die Namen der Bestandszeilen nach** — und es gibt
     * ihn genau einmal. Gemessen an einer Zeile, die **ohne** die Abschrift
     * entsteht, wie jede vor dem 9. September 2026.
     */
    public function test_the_migration_carries_the_names_of_existing_rows(): void
    {
        $account = Account::factory()->create(['name' => 'Anna Berger']);
        $event = $this->event($account);

        /*
         * **Zurückgebaut und noch einmal gefahren, statt die Spalte von Hand zu
         * leeren.** Der Rückbau nimmt die Spalte mit — danach steht die Zeile
         * genau so da wie jede vor dem 9. September 2026, und die zweite Fahrt
         * misst den Nachtrag und nicht ein `UPDATE`, das der Test selbst
         * geschrieben hat.
         */
        $pfad = database_path('migrations/2026_09_09_120000_the_log_keeps_the_name_of_a_deleted_account.php');

        $this->artisan('migrate:rollback', ['--path' => $pfad, '--realpath' => true])->assertOk();

        $this->assertFalse(
            Schema::hasColumn('audit_events', 'account_name'),
            'Der Rückbau hat die Spalte stehen lassen — dann misst die Fahrt danach nichts.',
        );

        $this->artisan('migrate', ['--path' => $pfad, '--realpath' => true])->assertOk();

        $this->assertSame('Anna Berger', $event->refresh()->account_name);
    }
}
