<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Audit\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Die Abnahmebedingung von P1, als ein Durchgang.
 *
 * „Ein Admin legt einen Kunden an, dieser meldet sich an, sieht seine (leere)
 * Übersicht, der Admin kann auf seiner Übersicht die Verläufe des Servers
 * ablesen, und kein Kunde kommt durch Manipulation von IDs an fremde Objekte."
 *
 * Die einzelnen Schichten haben ihre eigenen Tests. Dieser hier prüft, ob sie
 * zusammen das ergeben, was im Plan steht — ein Durchgang, wie ihn ein Mensch
 * gehen würde.
 */
final class PanelWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    /** Erfüllt docs/22 — die Richtlinie gilt beim Anlegen, nicht beim Anmelden. */
    private const PASSWORD = 'Ein-langes-Passwort9';

    public function test_the_operator_creates_a_customer_who_then_signs_in(): void
    {
        $admin = Account::factory()->admin()->create();

        // 1. Der Admin legt einen Kunden an — mit Anmeldekonto.
        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
            'email' => 'erika@example.test',
            'login_email' => 'erika@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/customers');

        $customer = Customer::query()->where('number', 'K10001')->firstOrFail();
        $account = $customer->accounts()->firstOrFail();

        $this->assertSame(AccountType::Customer, $account->type);
        $this->assertStringStartsWith('$argon2id$', $account->password);

        // 2. Der Kunde meldet sich an.
        $this->post('/logout');
        $this->post('/login', [
            'email' => 'erika@example.test',
            'password' => self::PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($account);

        // 3. Und sieht seine leere Übersicht.
        $this->get('/')->assertOk();
    }

    public function test_a_half_created_customer_does_not_stay_behind(): void
    {
        $admin = Account::factory()->admin()->create();
        Account::factory()->admin()->create(['email' => 'belegt@example.test']);

        // Die Anmeldeadresse ist schon vergeben — der Kunde darf davon nichts
        // zurücklassen, sonst ist die Nummer verbraucht und niemand kann sich
        // anmelden.
        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.test',
            'login_email' => 'belegt@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('login_email');

        // Vorher stand hier eine Prüfung auf die Nummer aus dem Formular. Die
        // vergibt jetzt der Server; geprüft wird deshalb, dass überhaupt kein
        // Kunde entstanden ist — das ist ohnehin die schärfere Aussage, denn
        // sie hinge nicht daran, unter welcher Nummer ein Rest liegenbliebe.
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_customer_number_comes_from_the_server(): void
    {
        $admin = Account::factory()->admin()->create();

        // Was im Formular steht, ist eine Vorschau. Geschickt wird es nicht —
        // und wer es trotzdem schickt, bekommt es nicht.
        $this->actingAs($admin)->post('/customers', [
            'number' => 'K99999',
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
            'email' => 'erika@example.test',
            'login_email' => 'erika@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/customers');

        $this->assertSame('K10001', Customer::query()->sole()->number);
    }

    public function test_the_next_number_beats_the_highest_and_not_the_newest(): void
    {
        // Die hohe Nummer bekommt die niedrige ID: So sieht ein Bestand aus,
        // in dem jemand die Nummer einmal von Hand gesetzt hat — möglich war
        // das, solange sie im Formular stand.
        //
        // Die alte Vergabe las die Nummer des jüngsten Datensatzes und käme
        // hier auf K10002. Die neue nimmt das Maximum.
        Customer::factory()->create(['number' => 'K90000']);
        Customer::factory()->create(['number' => 'K10001']);

        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.test',
            'login_email' => 'max@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/customers');

        $this->assertNotNull(
            Customer::query()->where('number', 'K90001')->first(),
            'Die nächste Nummer folgt der höchsten vergebenen, nicht der zuletzt angelegten.',
        );
    }

    public function test_a_withdrawn_customer_keeps_its_number(): void
    {
        // Der Kern des Soft-Deletes. Trüge eine Rechnung die K10001 und bekäme
        // der nächste Kunde sie erneut, stünden zwei Vertragspartner unter
        // derselben Nummer — und beim Nachsehen findet man einen davon.
        Customer::factory()->create(['number' => 'K10001'])->delete();

        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.test',
            'login_email' => 'max@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/customers');

        $this->assertNotNull(
            Customer::query()->where('number', 'K10002')->first(),
            'Die Nummer eines zurückgezogenen Kunden bleibt verbraucht.',
        );
    }

    public function test_a_withdrawn_customer_disappears_from_the_panel(): void
    {
        $customer = Customer::factory()->create();
        $admin = Account::factory()->admin()->create();

        $customer->delete();

        // Nicht ausgeblendet, sondern nicht gefunden: Die Bindung in der Route
        // sieht zurückgezogene Kunden nicht, und das ist ein 404 und kein 403.
        // Ein 403 wäre die Auskunft, dass es ihn gibt.
        $this->actingAs($admin)->get("/customers/{$customer->id}")->assertNotFound();

        $this->actingAs($admin)->get('/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customers.total', 0));
    }

    public function test_an_account_of_a_withdrawn_customer_cannot_sign_in(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->withoutTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $customer->delete();

        // Das Konto bleibt stehen, mit gültigem Passwort und Status „aktiv".
        // Ohne die Prüfung in der Anmeldung käme der gekündigte Kunde weiter
        // herein — er sähe zwar nichts, aber „kommt rein und sieht nichts" ist
        // keine Kündigung, sondern ein Fehler, der wie einer aussieht.
        $this->post('/login', [
            'email' => $account->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_admin_still_signs_in_when_customers_are_withdrawn(): void
    {
        // Die Gegenprobe. Ein Adminkonto hat keinen Kunden; die neue Prüfung
        // darf es nicht treffen.
        Customer::factory()->create()->delete();

        $admin = Account::factory()->admin()->withoutTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_weak_password_does_not_create_a_customer(): void
    {
        $admin = Account::factory()->admin()->create();

        // Zwölf Zeichen und sonst nichts. Das war bis docs/22 die ganze Regel.
        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
            'email' => 'erika@example.test',
            'login_email' => 'erika@example.test',
            'password' => 'passwortpasswort',
            'password_confirmation' => 'passwortpasswort',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_customer_does_not_see_the_operator_pages(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        // Die Navigation zeigt ihm den Weg nicht — und die Policy weist ihn
        // ab, wenn er die Adresse von Hand einträgt.
        $this->actingAs($account)->get('/customers')->assertForbidden();
        $this->actingAs($account)->get('/customers/create')->assertForbidden();
    }

    public function test_a_customer_does_not_reach_a_foreign_customer_page(): void
    {
        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        $account = Account::factory()->customer($own)->create();

        $this->actingAs($account)->get("/customers/{$foreign->id}")->assertForbidden();
    }

    public function test_the_operator_overview_carries_the_server_readings(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Overview')
                ->has('tiles', 5)
                ->has('server')
                ->has('services')
                ->has('filesystems')
                ->has('processes'));
    }

    public function test_the_customer_overview_never_carries_them(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        // Nicht ausgeblendet, sondern gar nicht geschickt: Wer die Antwort
        // ansieht, findet die Serverwerte auch dort nicht.
        $this->actingAs($account)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CustomerOverview')
                ->has('subscriptions', 0)
                ->missing('server')
                ->missing('tiles')
                ->missing('services')
                // Prozessliste und Dateisysteme sind die Auskunft über den
                // Server, die am wenigsten in eine Kundenantwort gehört: Sie
                // nennt fremde Dienste, fremde Speicherbelegung und die
                // Einhängepunkte des Betreibers.
                ->missing('filesystems')
                ->missing('processes'));
    }

    public function test_signing_in_as_a_customer_and_back_again(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->create();
        Subscription::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($admin)
            ->post("/customers/{$customer->id}/impersonate")
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($target);
        $this->assertSame((int) $admin->id, session(Impersonation::SESSION_KEY));

        // In fremder Sicht sieht er die Kundenfläche, nicht die des Betreibers.
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('CustomerOverview'));

        $this->post('/impersonation/stop')->assertRedirect('/customers');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    public function test_actions_during_the_switch_name_both_people(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->create();

        $this->actingAs($admin)->post("/customers/{$customer->id}/impersonate");
        $this->post('/impersonation/stop');

        $stop = AuditEvent::query()->where('action', 'impersonation.stop')->firstOrFail();

        // §6.3: handelnde Person und Kontext. Stünde hier nur der Kunde,
        // verschwiege das Protokoll genau den Fall, für den man es liest.
        $this->assertSame((int) $admin->id, (int) $stop->account_id);
        $this->assertSame((int) $target->id, (int) $stop->acting_as_account_id);
    }

    public function test_a_customer_cannot_switch_into_anyone(): void
    {
        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        Account::factory()->customer($foreign)->create();
        $account = Account::factory()->customer($own)->create();

        $this->actingAs($account)
            ->post("/customers/{$foreign->id}/impersonate")
            ->assertForbidden();
    }

    public function test_there_is_no_switch_out_of_a_switch(): void
    {
        $admin = Account::factory()->admin()->create();
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        Account::factory()->customer($first)->create();
        Account::factory()->customer($second)->create();

        $this->actingAs($admin)->post("/customers/{$first->id}/impersonate");

        // Aus fremder Sicht in eine dritte zu springen, machte den Rückweg
        // mehrdeutig — und im Protokoll stünde eine Kette, die niemand mehr
        // auflöst. Hier scheitert es schon an der Policy: In der Sicht des
        // Kunden gibt es die Fähigkeit nicht mehr.
        $this->post("/customers/{$second->id}/impersonate")->assertForbidden();
    }

    public function test_a_customer_without_an_account_cannot_be_switched_into(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($admin)
            ->post("/customers/{$customer->id}/impersonate")
            ->assertSessionHasErrors('impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_return_works_even_if_the_admin_account_was_disabled(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        Account::factory()->customer($customer)->create();

        $this->actingAs($admin)->post("/customers/{$customer->id}/impersonate");

        $admin->forceFill(['status' => 'disabled'])->save();

        // Der Rückweg ist versperrt — dann bleibt nur das Abmelden, und zwar
        // sofort. In fremder Sicht weiterzuarbeiten wäre der schlechtere
        // Zustand.
        $this->post('/impersonation/stop')->assertRedirect('/login');
        $this->assertGuest();
    }
}
