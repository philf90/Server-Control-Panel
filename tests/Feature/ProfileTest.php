<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Support\Audit\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Das eigene Konto — und die eine Stelle, an der es gefährlich wird.
 *
 * **„Anmelden als" darf kein Weg sein, ein fremdes Konto zu übernehmen.** Ein
 * Admin in der Sicht eines Kunden ist für die Anwendung dieser Kunde. Ohne
 * Sperre könnte er auf dieser Seite dessen Passwort setzen oder die
 * Anmeldeadresse auf seine eigene umschreiben — und hätte danach einen
 * dauerhaften Zugang, der in keinem Protokoll als das erscheint, was er ist.
 * Der Sichtwechsel ist zum Ansehen da.
 */
final class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Ein-langes-Passwort9';

    private function account(): Account
    {
        // Mit zweitem Faktor: Ein Adminkonto ohne ihn wird von
        // RequireTwoFactor auf die Einrichtung umgeleitet, bevor es irgendwohin
        // kommt — und dann prüfte dieser Test die Umleitung statt die Seite.
        return Account::factory()->admin()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    public function test_the_page_shows_the_own_account(): void
    {
        $account = $this->account();

        $this->actingAs($account)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Profile')
                ->where('profile.email', 'admin@example.test')
                ->where('impersonating', false));
    }

    public function test_the_page_never_carries_the_password_hash(): void
    {
        // Der Hash gehört nicht in die Antwort. Er stünde sonst im Quelltext
        // jeder Seite — und ein Argon2-Hash ist zwar teuer zu brechen, aber
        // nichts, was man verteilt.
        $account = $this->account();

        $response = $this->actingAs($account)->get('/settings/profile');

        $this->assertStringNotContainsString('argon2', $response->getContent() ?: '');
    }

    public function test_name_and_address_change_with_the_current_password(): void
    {
        $account = $this->account();

        $this->actingAs($account)->patch('/settings/profile', [
            'name' => 'Philipp',
            'email' => 'neu@example.test',
            'current_password' => self::PASSWORD,
        ])->assertRedirect();

        $account->refresh();

        $this->assertSame('Philipp', $account->name);
        $this->assertSame('neu@example.test', $account->email);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $account = $this->account();

        $this->actingAs($account)->patch('/settings/profile', [
            'name' => 'Fremd',
            'email' => 'fremd@example.test',
            'current_password' => 'falsch',
        ])->assertSessionHasErrors('current_password');

        $account->refresh();

        $this->assertSame('Administrator', $account->name);
        $this->assertSame('admin@example.test', $account->email);
    }

    public function test_the_address_cannot_be_taken_from_another_account(): void
    {
        $account = $this->account();
        Account::factory()->admin()->create(['email' => 'belegt@example.test']);

        // Zwei Konten mit derselben Adresse fänden bei der Anmeldung zwei
        // Treffer, und welcher gewinnt, wäre Zufall der Sortierung.
        $this->actingAs($account)->patch('/settings/profile', [
            'name' => 'Administrator',
            'email' => 'belegt@example.test',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertSame('admin@example.test', $account->refresh()->email);
    }

    public function test_keeping_the_own_address_is_not_a_collision(): void
    {
        // Die Gegenprobe zur Eindeutigkeit: Wer nur den Namen ändert, schickt
        // seine eigene Adresse mit — und die darf nicht als vergeben gelten.
        $account = $this->account();

        $this->actingAs($account)->patch('/settings/profile', [
            'name' => 'Philipp',
            'email' => 'admin@example.test',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Philipp', $account->refresh()->name);
    }

    public function test_the_password_changes_and_the_old_one_stops_working(): void
    {
        $account = $this->account();

        $this->actingAs($account)->put('/settings/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Ein-anderes-Passwort7',
            'password_confirmation' => 'Ein-anderes-Passwort7',
        ])->assertRedirect();

        $account->refresh();

        $this->assertTrue(Hash::check('Ein-anderes-Passwort7', $account->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $account->password));
    }

    public function test_a_weak_new_password_is_refused(): void
    {
        $account = $this->account();

        // Dieselbe Richtlinie wie beim Anlegen eines Kunden (docs/22). Ohne
        // sie wäre das eigene Profil der Weg, an ihr vorbeizukommen — und
        // ausgerechnet für das Konto, das alles darf.
        $this->actingAs($account)->put('/settings/password', [
            'current_password' => self::PASSWORD,
            'password' => 'passwortpasswort',
            'password_confirmation' => 'passwortpasswort',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, $account->refresh()->password));
    }

    public function test_the_password_does_not_change_without_the_current_one(): void
    {
        $account = $this->account();

        $this->actingAs($account)->put('/settings/password', [
            'current_password' => 'falsch',
            'password' => 'Ein-anderes-Passwort7',
            'password_confirmation' => 'Ein-anderes-Passwort7',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $account->refresh()->password));
    }

    public function test_no_account_change_while_impersonating(): void
    {
        $admin = $this->account();
        $customer = Customer::factory()->create();
        $victim = Account::factory()->customer($customer)->withoutTwoFactor()->create([
            'email' => 'kunde@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);

        // Der Angriff, um den es geht: Der Admin ist in der Sicht des Kunden
        // und schreibt dessen Anmeldeadresse auf seine eigene um.
        $this->actingAs($victim)
            ->withSession([Impersonation::SESSION_KEY => $admin->id])
            ->patch('/settings/profile', [
                'name' => 'Übernommen',
                'email' => 'angreifer@example.test',
                'current_password' => self::PASSWORD,
            ])
            ->assertForbidden();

        $victim->refresh();

        $this->assertSame('kunde@example.test', $victim->email);
    }

    public function test_no_password_change_while_impersonating(): void
    {
        $admin = $this->account();
        $customer = Customer::factory()->create();
        $victim = Account::factory()->customer($customer)->withoutTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($victim)
            ->withSession([Impersonation::SESSION_KEY => $admin->id])
            ->put('/settings/password', [
                'current_password' => self::PASSWORD,
                'password' => 'Ein-anderes-Passwort7',
                'password_confirmation' => 'Ein-anderes-Passwort7',
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::PASSWORD, $victim->refresh()->password));
    }

    public function test_the_refused_attempt_is_recorded(): void
    {
        // Ein Versuch an dieser Stelle ist keine Ungeschicklichkeit: Die
        // Oberfläche zeigt in diesem Zustand kein Formular. Wer hier ankommt,
        // hat die Anfrage selbst gestellt — und das gehört ins Protokoll.
        $admin = $this->account();
        $customer = Customer::factory()->create();
        $victim = Account::factory()->customer($customer)->withoutTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($victim)
            ->withSession([Impersonation::SESSION_KEY => $admin->id])
            ->put('/settings/password', [
                'current_password' => self::PASSWORD,
                'password' => 'Ein-anderes-Passwort7',
                'password_confirmation' => 'Ein-anderes-Passwort7',
            ]);

        $this->assertNotNull(
            AuditEvent::query()->where('action', 'profile.password.changed')->first(),
            'Der abgewiesene Versuch steht im Protokoll.',
        );
    }

    public function test_the_record_of_a_password_change_carries_no_context(): void
    {
        $account = $this->account();

        $this->actingAs($account)->put('/settings/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Ein-anderes-Passwort7',
            'password_confirmation' => 'Ein-anderes-Passwort7',
        ]);

        $event = AuditEvent::query()->where('action', 'profile.password.changed')->firstOrFail();

        // Nichts, was Rückschlüsse zulässt — auch nicht die Länge.
        $this->assertSame([], $event->context ?? []);
    }

    public function test_a_customer_reaches_the_page_too(): void
    {
        // Die Seite gehört jedem Konto und nicht nur dem Betreiber. Ein Kunde,
        // der sein Passwort nicht ändern kann, ruft an.
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->get('/settings/profile')->assertOk();
    }

    public function test_a_guest_does_not_reach_the_page(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }
}
