<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Audit\AuditQuery;
use App\Support\Web\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Account $admin;

    private Account $customer;

    private Subscription $ownSubscription;

    private Subscription $foreignSubscription;

    protected function setUp(): void
    {
        parent::setUp();

        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();

        $this->ownSubscription = Subscription::factory()->create(['customer_id' => $own->id]);
        $this->foreignSubscription = Subscription::factory()->create(['customer_id' => $foreign->id]);

        $this->admin = Account::factory()->admin()->create();
        $this->customer = Account::factory()->customer($own)->create();
    }

    private function seedEvents(): void
    {
        AuditEvent::factory()->create([
            'action' => 'subscription.updated',
            'subscription_id' => $this->ownSubscription->id,
        ]);
        AuditEvent::factory()->create([
            'action' => 'subscription.updated',
            'subscription_id' => $this->foreignSubscription->id,
        ]);
        AuditEvent::factory()->create([
            'action' => 'auth.login',
            'account_id' => $this->customer->id,
        ]);
        AuditEvent::factory()->create([
            'action' => 'auth.login.failed',
            'result' => AuditResult::Failure,
        ]);
    }

    /**
     * Der Test, der die eigentliche Zusage prüft.
     *
     * Sichtbarkeit ist zweimal formuliert: als Abfrage in AuditQuery und als
     * Policy an AuditEvent. Zwei Formulierungen derselben Regel laufen
     * irgendwann auseinander — hier wird Zeile für Zeile verglichen, ob sie
     * dasselbe sagen.
     */
    public function test_the_query_and_the_policy_say_the_same_thing(): void
    {
        $this->seedEvents();

        foreach ([$this->admin, $this->customer] as $account) {
            $visibleIds = AuditQuery::visibleTo($account)->pluck('id')->map(intval(...))->all();

            foreach (AuditEvent::query()->get() as $event) {
                $byQuery = in_array((int) $event->id, $visibleIds, true);
                $byPolicy = $account->can('view', $event);

                $this->assertSame(
                    $byPolicy,
                    $byQuery,
                    sprintf(
                        'Ereignis %d (%s): Abfrage sagt %s, Policy sagt %s.',
                        $event->id,
                        $event->action,
                        $byQuery ? 'sichtbar' : 'verborgen',
                        $byPolicy ? 'sichtbar' : 'verborgen',
                    ),
                );
            }
        }
    }

    public function test_a_customer_sees_only_their_own_events(): void
    {
        $this->seedEvents();

        $actions = AuditQuery::visibleTo($this->customer)->pluck('action')->all();

        $this->assertContains('subscription.updated', $actions);
        $this->assertContains('auth.login', $actions);

        // Der fehlgeschlagene Anmeldeversuch einer unbekannten Adresse gehört
        // niemandem — dort steht, unter welchen Adressen jemand geklopft hat.
        $this->assertNotContains('auth.login.failed', $actions);
        $this->assertSame(2, AuditQuery::visibleTo($this->customer)->count());
        $this->assertSame(4, AuditQuery::visibleTo($this->admin)->count());
    }

    public function test_the_page_is_reachable_and_needs_an_account(): void
    {
        $this->get('/audit')->assertRedirect('/login');
        $this->actingAs($this->customer)->get('/audit')->assertOk();
    }

    /**
     * Der Fehler, den eine Export-Funktion typischerweise hat.
     */
    public function test_the_export_shows_nothing_the_list_would_hide(): void
    {
        $this->seedEvents();

        $secret = AuditEvent::factory()->create([
            'action' => 'geheime.aktion.des.fremden',
            'subscription_id' => $this->foreignSubscription->id,
        ]);

        $csv = $this->actingAs($this->customer)
            ->get('/audit/export')
            ->streamedContent();

        $this->assertStringContainsString('subscription.updated', $csv);
        $this->assertStringNotContainsString('geheime.aktion.des.fremden', $csv);
        $this->assertStringNotContainsString('auth.login.failed', $csv);
        $this->assertNotNull($secret);
    }

    public function test_the_export_does_not_hand_a_spreadsheet_a_formula(): void
    {
        AuditEvent::factory()->create([
            'action' => '=cmd|\' /c calc\'!A1',
            'subscription_id' => $this->ownSubscription->id,
            'ip_address' => '@SUM(1+1)',
        ]);

        $csv = $this->actingAs($this->customer)
            ->get('/audit/export')
            ->streamedContent();

        // Mit vorangestelltem Hochkomma ist es Text, ohne wäre es in Excel
        // eine Formel — und `=cmd|…` ist der Standardfall dieser Lücke.
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_the_filters_narrow_and_do_not_widen(): void
    {
        $this->seedEvents();

        $onlyAuth = AuditQuery::filter(
            AuditQuery::visibleTo($this->admin),
            ['action' => 'auth.'],
        )->pluck('action')->all();

        $this->assertCount(2, $onlyAuth);
        $this->assertNotContains('subscription.updated', $onlyAuth);

        // Ein Platzhalter in der Eingabe darf die Einschränkung nicht
        // aufheben — sonst fände „%" alles.
        $wildcard = AuditQuery::filter(
            AuditQuery::visibleTo($this->admin),
            ['action' => '%'],
        )->count();

        $this->assertSame(0, $wildcard);
    }

    public function test_filtering_by_result_works(): void
    {
        $this->seedEvents();

        $failures = AuditQuery::filter(
            AuditQuery::visibleTo($this->admin),
            ['result' => 'failure'],
        )->count();

        $this->assertSame(1, $failures);

        // Ein unbekanntes Ergebnis wird verworfen und filtert nicht.
        $ignored = AuditQuery::filter(
            AuditQuery::visibleTo($this->admin),
            ['result' => 'gibt-es-nicht'],
        )->count();

        $this->assertSame(4, $ignored);
    }

    public function test_a_foreign_subscription_in_the_filter_yields_nothing(): void
    {
        $this->seedEvents();

        $rows = AuditQuery::filter(
            AuditQuery::visibleTo($this->customer),
            ['subscription_id' => (string) $this->foreignSubscription->id],
        )->count();

        $this->assertSame(0, $rows);
    }

    public function test_the_export_says_when_it_was_cut_off(): void
    {
        config(['srvpanel.audit.export_max' => 2]);

        AuditEvent::factory()->count(5)->create([
            'subscription_id' => $this->ownSubscription->id,
        ]);

        $csv = $this->actingAs($this->customer)->get('/audit/export')->streamedContent();

        // Eine Datei, die aussieht wie das ganze Protokoll und es nicht ist,
        // wäre die schlechtere Antwort auf dieselbe Grenze.
        $this->assertStringContainsString('abgeschnitten', $csv);
    }

    public function test_an_invalid_date_is_rejected_instead_of_ignored(): void
    {
        $this->actingAs($this->admin)
            ->get('/audit?from=irgendwann')
            ->assertSessionHasErrors('from');
    }

    /**
     * Die zweite Seite zeigt, was auf der ersten nicht steht.
     *
     * **Warum das ein eigener Test ist.** `PaginationTest` prüft die Naht —
     * dass der Controller `Page::from()` benutzt und die Seite einen `<Pager>`
     * zeigt. Er kann nicht prüfen, dass am Ende auch andere Zeilen ankommen.
     * Genau das war ein Jahr lang der Zustand: Der Controller paginierte
     * richtig, und trotzdem kam niemand über die erste Seite hinaus.
     */
    public function test_the_second_page_shows_rows_the_first_one_does_not(): void
    {
        AuditEvent::factory()->count(Page::SIZE + 5)->create([
            'subscription_id' => $this->ownSubscription->id,
        ]);

        $erste = $this->actingAs($this->admin)->get('/audit')->viewData('page')['props']['events'];
        $zweite = $this->actingAs($this->admin)->get('/audit?page=2')->viewData('page')['props']['events'];

        $this->assertSame(1, $erste['current_page']);
        $this->assertSame(2, $zweite['current_page']);
        $this->assertSame(2, $erste['last_page']);
        $this->assertCount(Page::SIZE, $erste['data']);
        $this->assertCount(5, $zweite['data']);

        $aufErster = array_column($erste['data'], 'id');
        $aufZweiter = array_column($zweite['data'], 'id');

        $this->assertSame([], array_intersect($aufErster, $aufZweiter),
            'Beide Seiten zeigen dieselben Zeilen — dann blättert man nicht, man lädt neu.');
    }

    /**
     * Und der Filter überlebt das Blättern.
     *
     * Ohne `withQueryString()` trägt der Verweis auf Seite 2 die eingestellte
     * Auswahl nicht mit: Man filtert, blättert weiter und steht wieder in der
     * ungefilterten Liste — mit einem Formular, das weiter den Filter anzeigt.
     */
    public function test_a_filter_still_applies_on_the_second_page(): void
    {
        AuditEvent::factory()->count(Page::SIZE + 5)->create([
            'subscription_id' => $this->ownSubscription->id,
            'result' => AuditResult::Success,
        ]);
        AuditEvent::factory()->count(3)->create([
            'subscription_id' => $this->ownSubscription->id,
            'result' => AuditResult::Failure,
        ]);

        $zweite = $this->actingAs($this->admin)
            ->get('/audit?result=success&page=2')
            ->viewData('page')['props']['events'];

        $this->assertSame(Page::SIZE + 5, $zweite['total']);

        foreach ($zweite['data'] as $zeile) {
            $this->assertSame(AuditResult::Success->value, $zeile['result']);
        }
    }
}
