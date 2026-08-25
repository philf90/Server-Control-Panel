<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Audit\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein gesperrtes Konto verliert seine offene Sitzung.
 *
 * ## Befund 6 aus `docs/84`, als Test
 *
 * Der Zustand wurde beim **Anmelden** gefragt und bei einer laufenden Anfrage
 * nie. Der Leerlauf einer Sitzung setzt sich bei jedem Klick zurück, die
 * absolute Obergrenze liegt bei 30 Tagen — so lange behielt ein gesperrtes
 * Adminkonto seine Rechte.
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * ## Warum jede Gegenprobe danebensteht
 *
 * Ein Test, der nur „gesperrt fliegt raus" misst, bestünde auch für eine
 * Mittelschicht, die **jeden** hinauswirft. Neben jedem Rauswurf steht deshalb
 * der Fall, der durchkommen muss.
 */
final class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Die Gegenprobe zuerst.** Ein aktives Adminkonto arbeitet weiter.
     */
    public function test_an_active_admin_keeps_working(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->get('/')
            ->assertOk();
    }

    /**
     * **Der Befund:** Die offene Sitzung endet, sobald das Konto gesperrt ist.
     *
     * Gesperrt wird **nach** dem Anmelden — sonst prüfte der Test die Tür und
     * nicht den Raum dahinter.
     */
    public function test_a_live_session_of_a_disabled_account_is_ended(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk();

        $admin->forceFill(['status' => AccountStatus::Disabled])->save();

        $this->actingAs($admin)->get('/')->assertRedirect('/login');

        $this->assertFalse(auth()->check(),
            'Die Sitzung läuft weiter, obwohl das Konto gesperrt ist.');
    }

    /**
     * **Ein Kunde genauso** — anders als bei der Netzbeschränkung, und mit
     * Absicht: Ein gekündigter Kunde, der weiterarbeitet, ist derselbe Fehler.
     */
    public function test_a_live_session_of_a_disabled_customer_is_ended(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        $this->actingAs($account)->get('/')->assertOk();

        $account->forceFill(['status' => AccountStatus::Disabled])->save();

        $this->actingAs($account)->get('/')->assertRedirect('/login');
    }

    /**
     * **Ein zurückgezogener Kunde verliert die Sitzung seiner Konten.**
     *
     * Sein Konto bleibt „aktiv" — die Zeile des Kunden trägt den Rückzug, nicht
     * die des Kontos. Ohne diese Frage arbeitete ein gekündigter Kunde weiter
     * und sähe nur nichts, weil die Mandantenklammer leer liefert. „Kommt rein
     * und sieht nichts" ist keine Kündigung.
     */
    public function test_a_live_session_of_a_withdrawn_customer_is_ended(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        $this->actingAs($account)->get('/')->assertOk();

        $customer->delete();

        $this->assertSame(AccountStatus::Active, $account->fresh()?->status,
            'Der Prüfkörper misst nichts: Das Konto ist mitgesperrt worden, '
            .'also entscheidet nicht der Rückzug des Kunden.');

        $this->actingAs($account)->get('/')->assertRedirect('/login');
    }

    /**
     * **Der Fall, den der Bau fast übersehen hätte:** In fremder Sicht ist der
     * Handelnde nicht der, der angemeldet ist.
     *
     * Fragte die Mittelschicht nur `Auth::user()`, bliebe ein gesperrter
     * Administrator so lange unbehelligt, wie er in fremder Sicht
     * weiterarbeitet — also genau so lange, wie es am meisten wehtut.
     */
    public function test_a_disabled_impersonator_loses_the_session(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        $admin->forceFill(['status' => AccountStatus::Disabled])->save();

        $this->actingAs($target)
            ->withSession([Impersonation::SESSION_KEY => (int) $admin->id])
            ->get('/')
            ->assertRedirect('/login');
    }

    /**
     * Die Gegenprobe dazu: Steht ein **aktiver** Administrator dahinter, läuft
     * die fremde Sicht weiter.
     *
     * Ohne sie bestünde der Test darüber auch für eine Mittelschicht, die jede
     * fremde Sicht beendet.
     */
    public function test_an_active_impersonator_keeps_the_session(): void
    {
        $admin = Account::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $target = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        $this->actingAs($target)
            ->withSession([Impersonation::SESSION_KEY => (int) $admin->id])
            ->get('/')
            ->assertOk();
    }

    /**
     * **Die zweite Tür fragt dasselbe wie die erste.**
     *
     * `TwoFactorChallengeController` fragte `status->canSignIn()` und damit die
     * Hälfte: Ein zurückgezogener Kunde kam über den zweiten Faktor herein.
     */
    public function test_the_second_factor_refuses_a_withdrawn_customer(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        /*
         * **Über den echten Weg und nicht über den Sitzungsschlüssel.** Der
         * heisst `two_factor_pending_account_id` und ist `private` — eine
         * abgeschriebene Zeichenkette im Test wäre genau die Fehlerklasse
         * dieses Projekts: ein Verweis, den nichts prüft. Und der echte Weg
         * misst ohnehin mehr: Die erste Tür lässt durch, **dann** wird der
         * Kunde zurückgezogen, und die zweite muss ihn aufhalten.
         */
        $this->post('/login', [
            'email' => $account->email,
            'password' => 'probe-passwort-nur-fuer-tests',
        ])->assertRedirect('/two-factor');

        $customer->delete();

        $this->get('/two-factor')->assertRedirect('/login');
    }
}
