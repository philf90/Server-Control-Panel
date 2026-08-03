<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TestMessage;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Setting;
use App\Support\Settings\MailConfiguration;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Der Mailversand — und die drei Stellen, an denen ein Zugang verloren geht
 * oder auftaucht, wo er nicht hingehört.
 *
 * Erstens: das Passwort in der Antwort des Servers. Zweitens: das Passwort im
 * Klartext in der Datenbank. Drittens: das Passwort, das beim Speichern einer
 * anderen Einstellung verschwindet, weil das Formular sein Feld leer anzeigt.
 */
final class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Account
    {
        return Account::factory()->admin()->create(['email' => 'admin@example.test']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'host' => 'mail.example.net',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'panel@example.net',
            'password' => 'geheim',
            'password_clear' => false,
            'from_address' => 'panel@example.net',
            'from_name' => 'SrvPanel',
        ], $overrides);
    }

    public function test_the_page_is_reachable_for_the_operator(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/mail')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Mail'));
    }

    public function test_a_customer_does_not_reach_the_page(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->actingAs($account)->get('/settings/mail')->assertForbidden();
        $this->actingAs($account)->put('/settings/mail', $this->payload())->assertForbidden();
        $this->actingAs($account)->post('/settings/mail/test')->assertForbidden();
    }

    public function test_the_settings_are_stored(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/mail', $this->payload())
            ->assertRedirect('/settings/mail');

        $mail = app(Settings::class)->mail();

        $this->assertSame('mail.example.net', $mail->host);
        $this->assertSame(587, $mail->port);
        $this->assertSame('geheim', $mail->password);
        $this->assertTrue($mail->usable());
    }

    public function test_the_password_is_not_in_the_database_in_clear(): void
    {
        // Der Wert wird als Ganzes verschlüsselt abgelegt. Ohne das stünde ein
        // fremder Zugang im Klartext in einer Tabelle, die jede Sicherung der
        // Datenbank mitnimmt.
        $this->actingAs($this->admin())->put('/settings/mail', $this->payload());

        $raw = (string) DB::table('settings')->where('key', 'mail')->value('value');

        $this->assertStringNotContainsString('geheim', $raw);
        $this->assertStringNotContainsString('mail.example.net', $raw);
        $this->assertNotSame('', $raw);
    }

    public function test_the_password_never_reaches_the_browser(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', $this->payload());

        $response = $this->actingAs($admin)->get('/settings/mail');

        $response->assertOk();
        $this->assertStringNotContainsString('geheim', $response->getContent() ?: '');

        // Statt des Werts steht dort, *dass* einer hinterlegt ist.
        $response->assertInertia(fn ($page) => $page->where('mail.password_set', true));
    }

    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        // Der Fall, der in der Praxis zuschlägt: Der Betreiber ändert den Port
        // und speichert. Das Passwortfeld ist leer, weil es immer leer ist.
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', $this->payload());
        $this->actingAs($admin)->put('/settings/mail', $this->payload(['password' => '', 'port' => 2525]));

        $mail = app(Settings::class)->mail();

        $this->assertSame(2525, $mail->port);
        $this->assertSame('geheim', $mail->password);
    }

    public function test_the_password_is_removed_only_on_request(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', $this->payload());
        $this->actingAs($admin)->put('/settings/mail', $this->payload(['password' => '', 'password_clear' => true]));

        $this->assertSame('', app(Settings::class)->mail()->password);
    }

    public function test_the_record_of_a_change_carries_no_password(): void
    {
        $this->actingAs($this->admin())->put('/settings/mail', $this->payload());

        $event = AuditEvent::query()->where('action', 'settings.mail.updated')->firstOrFail();
        $context = $event->context ?? [];

        $this->assertSame('mail.example.net', $context['host'] ?? null);
        $this->assertArrayNotHasKey('password', $context);
        $this->assertStringNotContainsString('geheim', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_an_unknown_encryption_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/mail', $this->payload(['encryption' => 'quantum']))
            ->assertSessionHasErrors('encryption');
    }

    public function test_a_port_outside_the_range_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/mail', $this->payload(['port' => 70000]))
            ->assertSessionHasErrors('port');
    }

    public function test_the_test_mail_goes_to_the_own_address(): void
    {
        // An die eigene und an keine andere: Ein Empfängerfeld machte aus
        // dieser Seite ein Formular, mit dem sich über das Relay des
        // Betreibers an beliebige Adressen schreiben lässt.
        Mail::fake();

        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', $this->payload());
        $this->actingAs($admin)->post('/settings/mail/test')->assertRedirect();

        Mail::assertSentCount(1);
        Mail::assertSent(TestMessage::class, static fn (TestMessage $mail): bool => $mail->hasTo('admin@example.test'));
    }

    public function test_no_test_mail_without_settings(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/settings/mail/test');

        Mail::assertNothingSent();
    }

    public function test_the_configuration_is_applied(): void
    {
        $settings = app(Settings::class);
        $settings->saveMail(new MailSettings(
            host: 'relay.example.net',
            port: 465,
            encryption: 'ssl',
            username: 'u',
            password: 'p',
            from_address: 'panel@example.net',
            from_name: 'Panel',
        ));

        $this->assertTrue(MailConfiguration::apply($settings, config()));

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('relay.example.net', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('ssl', config('mail.mailers.smtp.encryption'));
        $this->assertSame('panel@example.net', config('mail.from.address'));
    }

    public function test_none_becomes_no_encryption_at_all(): void
    {
        // „none" ist kein Verfahren dieses Namens. Stünde es so in der
        // Konfiguration, fiele der Transport mit einer Meldung aus, die nach
        // einem Fehler des Relays aussieht.
        $settings = app(Settings::class);
        $settings->saveMail(new MailSettings(
            host: 'relay.example.net',
            encryption: 'none',
            from_address: 'panel@example.net',
        ));

        MailConfiguration::apply($settings, config());

        $this->assertNull(config('mail.mailers.smtp.encryption'));
    }

    public function test_nothing_is_applied_without_settings(): void
    {
        $this->assertFalse(MailConfiguration::apply(app(Settings::class), config()));
    }

    public function test_an_unreadable_value_does_not_break_the_panel(): void
    {
        // Wechselt der APP_KEY, sind die abgelegten Zugangsdaten nicht mehr
        // lesbar. Die Antwort darauf sind leere Einstellungen — mit einer
        // Ausnahme beim Hochfahren käme niemand mehr an das Panel.
        DB::table('settings')->insert([
            'key' => 'mail',
            'value' => 'kein gültiger Geheimtext',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse((new Settings)->mail()->usable());
        $this->assertSame(1, Setting::query()->count());
    }
}
