<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Auth\LoginThrottle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Fehlermeldung zum Feld „email" aus einer Antwort.
     *
     * Die Sitzung hält die Fehler in zwei Gestalten: als lebenden
     * ViewErrorBag, solange die Sitzung durchgehend besteht, und als
     * verschachteltes Array, wenn sie zwischendurch geleert und neu
     * geschrieben wurde. Beide Fälle kommen in dieser Datei vor — der Test
     * mit der Sperre leert die Sitzung zwischen den Versuchen, der zur
     * Kontoauskunft nicht.
     */
    /** @param TestResponse<Response> $response */
    private function emailError(TestResponse $response): string
    {
        $errors = session()->get('errors');

        if ($errors instanceof ViewErrorBag) {
            return (string) $errors->first('email');
        }

        return (string) ($errors['default']['messages']['email'][0] ?? '');
    }

    /**
     * Das Konto für diese Datei — bewusst ohne zweiten Faktor.
     *
     * Gegenstand hier ist der erste Schritt: Passwort, Sperre, Protokoll,
     * Sitzungskennung. Mit zweitem Faktor endete jede erfolgreiche Anmeldung
     * auf `/two-factor` statt auf der Übersicht, und die Tests prüften
     * nebenbei etwas, wofür es tests/Feature/TwoFactorTest.php gibt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): Account
    {
        return Account::factory()->admin()->withoutTwoFactor()->create(array_merge([
            'email' => 'betreiber@example.test',
            'password' => Hash::make('ein-langes-passwort'),
        ], $attributes));
    }

    public function test_the_login_page_is_reachable_without_an_account(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_correct_password_signs_in(): void
    {
        $account = $this->account();

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
    }

    public function test_the_address_is_not_case_sensitive(): void
    {
        $this->account();

        $this->post('/login', [
            'email' => 'Betreiber@Example.Test',
            'password' => 'ein-langes-passwort',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        $this->account();

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'falsch',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_message_does_not_reveal_whether_the_address_exists(): void
    {
        $this->account();

        $known = $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'falsch',
        ]);
        $knownMessage = $this->emailError($known);

        $this->flushSession();

        $unknown = $this->post('/login', [
            'email' => 'niemand@example.test',
            'password' => 'falsch',
        ]);
        $unknownMessage = $this->emailError($unknown);

        // Aus der Antwort darf nicht hervorgehen, welche Adressen es gibt —
        // sonst ist das Anmeldeformular ein Werkzeug zum Sammeln von Konten.
        $this->assertSame($knownMessage, $unknownMessage);
        $this->assertNotEmpty($knownMessage);
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $this->account();
        Account::query()->where('email', 'betreiber@example.test')->update(['status' => 'disabled']);

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_session_id_changes_on_sign_in(): void
    {
        $this->account();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ]);

        // Ohne das könnte jemand, der dem Opfer vorher eine Sitzungskennung
        // untergeschoben hat, sie danach weiterbenutzen.
        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_password_is_stored_with_argon2id(): void
    {
        $account = $this->account(['password' => Hash::make('ein-langes-passwort')]);

        // Nicht bcrypt: Der Präfix verrät das Verfahren, und genau darauf
        // stützt sich §6.4.
        $this->assertStringStartsWith('$argon2id$', $account->password);
    }

    public function test_repeated_failures_lead_to_a_lockout(): void
    {
        $this->account();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => 'betreiber@example.test',
                'password' => 'falsch',
            ]);
            $this->flushSession();
        }

        // Ab jetzt hilft auch das richtige Passwort nicht mehr.
        $response = $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ]);
        $response->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Zu viele Versuche', $this->emailError($response));
    }

    public function test_a_successful_sign_in_clears_the_counter(): void
    {
        $this->account();
        $throttle = app(LoginThrottle::class);

        $throttle->recordFailure('127.0.0.1', 'betreiber@example.test');
        $throttle->recordFailure('127.0.0.1', 'betreiber@example.test');

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ])->assertRedirect('/');

        $this->assertSame(0, $throttle->secondsUntilAllowed('127.0.0.1', 'betreiber@example.test'));
    }

    public function test_the_account_lockout_is_capped_lower_than_the_ip_lockout(): void
    {
        $throttle = app(LoginThrottle::class);

        // Viele Fehlversuche von wechselnden Adressen gegen dasselbe Konto:
        // der Versuch, jemanden auszusperren, ohne sein Passwort zu erraten.
        for ($i = 0; $i < 30; $i++) {
            $throttle->recordFailure('10.0.0.'.$i, 'opfer@example.test');
        }

        // Die Kontosperre ist gedeckelt — sonst wäre der Schutz selbst die
        // Angriffsfläche.
        $this->assertLessThanOrEqual(
            300,
            $throttle->secondsUntilAllowed('203.0.113.9', 'opfer@example.test'),
        );
    }

    public function test_sign_in_and_failure_are_recorded(): void
    {
        $this->account();

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'falsch',
        ]);
        $this->flushSession();

        $this->post('/login', [
            'email' => 'betreiber@example.test',
            'password' => 'ein-langes-passwort',
        ]);

        $failure = AuditEvent::query()->where('action', 'auth.login.failed')->first();
        $success = AuditEvent::query()->where('action', 'auth.login')->first();

        $this->assertNotNull($failure);
        $this->assertSame(AuditResult::Failure, $failure->result);
        // Der Grund steht im Protokoll, nur nicht in der Antwort.
        $this->assertSame('falsches Passwort', ($failure->context ?? [])['reason'] ?? null);

        $this->assertNotNull($success);
        $this->assertSame(AuditResult::Success, $success->result);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $account = $this->account();

        $this->actingAs($account)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_overview_requires_an_account(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_health_check_stays_open(): void
    {
        // Sie läuft, während das Paket umschaltet — da ist niemand angemeldet.
        $this->get('/health')->assertStatus(503);
    }

    public function test_the_tenancy_is_set_from_the_signed_in_account(): void
    {
        $customer = Customer::factory()->create();
        $own = Subscription::factory()->create(['customer_id' => $customer->id]);
        Subscription::factory()->create();

        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->get('/login');

        $this->assertFalse(app(Tenancy::class)->unrestricted());
        $this->assertSame([(int) $own->id], app(Tenancy::class)->subscriptionIds());
    }

    public function test_an_admin_gets_an_open_bracket(): void
    {
        Subscription::factory()->count(2)->create();
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->get('/login');

        $this->assertTrue(app(Tenancy::class)->unrestricted());
    }
}
