<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Support\Auth\RecoveryCodes;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ein-langes-passwort';

    /** @return array{0: Account, 1: string, 2: list<string>} */
    private function accountWithTwoFactor(bool $admin = false): array
    {
        $secret = Totp::generateSecret();
        $codes = RecoveryCodes::generate();

        $customer = $admin ? null : Customer::factory()->create();

        $account = $admin
            ? Account::factory()->admin()->create(['password' => Hash::make(self::PASSWORD)])
            : Account::factory()->customer($customer)->create(['password' => Hash::make(self::PASSWORD)]);

        $account->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$account, $secret, $codes];
    }

    private function currentCode(string $secret): string
    {
        return Totp::codeAt($secret, intdiv(time(), Totp::PERIOD));
    }

    public function test_the_password_alone_does_not_sign_in(): void
    {
        [$account] = $this->accountWithTwoFactor();

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD])
            ->assertRedirect('/two-factor');

        // Der entscheidende Punkt: Das Panel ist an dieser Stelle noch zu.
        // Wäre hier schon angemeldet, liesse sich der zweite Schritt durch
        // Eintippen einer anderen Adresse überspringen.
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_second_step_signs_in(): void
    {
        [$account, $secret] = $this->accountWithTwoFactor();

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => $this->currentCode($secret)])->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
    }

    public function test_a_used_code_cannot_be_used_again(): void
    {
        [$account, $secret] = $this->accountWithTwoFactor();
        $code = $this->currentCode($secret);

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => $code])->assertRedirect('/');
        $this->post('/logout');

        // Das Fenster ist neunzig Sekunden breit. Ohne Wiederholungssperre
        // hätte jemand, der den Code mitliest, so lange Zeit.
        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_works_and_is_then_gone(): void
    {
        [$account, , $codes] = $this->accountWithTwoFactor();

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => $codes[0]])->assertRedirect('/');
        $this->assertAuthenticatedAs($account);

        $account->refresh();
        $this->assertCount(RecoveryCodes::COUNT - 1, $account->two_factor_recovery_codes ?? []);

        $this->post('/logout');
        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_is_accepted_regardless_of_spelling(): void
    {
        [$account, , $codes] = $this->accountWithTwoFactor();

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => strtolower(str_replace('-', ' ', $codes[1]))])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
    }

    public function test_a_wrong_code_is_rejected_and_recorded(): void
    {
        [$account] = $this->accountWithTwoFactor();

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNotNull(AuditEvent::query()->where('action', 'auth.two_factor.failed')->first());
    }

    public function test_the_challenge_is_rate_limited(): void
    {
        [$account] = $this->accountWithTwoFactor();
        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/two-factor', ['code' => '000000']);
        }

        // Sechs Stellen sind eine Million Möglichkeiten — für ein Programm
        // kein Hindernis, wenn es unbegrenzt probieren darf.
        $response = $this->post('/two-factor', ['code' => '000000']);
        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_setting_it_up_needs_a_matching_code_first(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->get('/settings/two-factor')->assertOk();
        $secret = session('two_factor_setup_secret');
        $this->assertIsString($secret);

        // Ein falscher Code darf nichts festschreiben — sonst sperrt sich aus,
        // wer den QR-Code falsch abfotografiert hat.
        $this->post('/settings/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $account->refresh();
        $this->assertFalse($account->hasTwoFactor());

        $this->post('/settings/two-factor', ['code' => $this->currentCode($secret)])
            ->assertRedirect('/settings/two-factor');

        $account->refresh();
        $this->assertTrue($account->hasTwoFactor());
        $this->assertCount(RecoveryCodes::COUNT, $account->two_factor_recovery_codes ?? []);
    }

    public function test_the_recovery_codes_are_not_readable_from_the_database(): void
    {
        [$account, , $codes] = $this->accountWithTwoFactor();

        // Gespeichert sind Hashes. Wer die Datenbank liest, kann sie nicht
        // benutzen — das ist der Unterschied zu „verschlüsselt".
        foreach ($account->two_factor_recovery_codes ?? [] as $stored) {
            $this->assertNotContains($stored, $codes);
            $this->assertSame(64, strlen((string) $stored));
        }
    }

    public function test_an_admin_without_a_second_factor_gets_no_further(): void
    {
        $admin = Account::factory()->admin()->withoutTwoFactor()->create();

        // §6.4: Für Betreiber verpflichtend. Ein Konto, das als root arbeitet,
        // hinter einem einzelnen Passwort ist genau die Lage, die ein Panel
        // für andere nicht schaffen sollte.
        $this->actingAs($admin)->get('/')->assertRedirect('/settings/two-factor');
        $this->actingAs($admin)->get('/customers')->assertRedirect('/settings/two-factor');

        // Die Einrichtungsseite und das Abmelden bleiben erreichbar, sonst
        // wäre die Umleitung eine Schleife.
        $this->actingAs($admin)->get('/settings/two-factor')->assertOk();
        $this->actingAs($admin)->post('/logout')->assertRedirect('/login');
    }

    public function test_an_admin_cannot_switch_it_off(): void
    {
        [$admin, $secret] = $this->accountWithTwoFactor(admin: true);

        $this->actingAs($admin)
            ->delete('/settings/two-factor', ['code' => $this->currentCode($secret)])
            ->assertSessionHasErrors('code');

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactor());
    }

    public function test_a_customer_can_switch_it_off_with_a_valid_code(): void
    {
        [$account, $secret] = $this->accountWithTwoFactor();

        $this->actingAs($account)->delete('/settings/two-factor', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->actingAs($account)
            ->delete('/settings/two-factor', ['code' => $this->currentCode($secret)])
            ->assertRedirect('/settings/two-factor');

        $account->refresh();
        $this->assertFalse($account->hasTwoFactor());
    }

    public function test_an_account_without_a_second_factor_signs_in_as_before(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->post('/login', ['email' => $account->email, 'password' => self::PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
    }
}
