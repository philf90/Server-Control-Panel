<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hell oder dunkel — und wer das entscheidet.
 *
 * **Warum es das gibt.** Beide Themes standen seit P1 fertig da; §7.2 verlangt
 * sie ausdrücklich zusammen, und die Kontrastprüfungen laufen über beide.
 * Schalten konnte sie trotzdem niemand: `data-theme` kam aus `SRVPANEL_THEME`
 * in der `.env`, also serverweit und nur für jemanden mit Zugriff auf die
 * Datei. Dasselbe Muster wie beim Zurückziehen eines Kunden und bei
 * `CustomerStatus::Suspended` — die Mechanik war gebaut, es fehlte der Weg
 * dorthin.
 *
 * **Für Admins und Kundenkonten.** Der Fall, für den §7.2 das helle Theme
 * überhaupt verlangt, ist der Kunde am hellen Bürobildschirm. Ein Umschalter
 * nur für Betreiber verfehlte genau ihn.
 */
final class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_choose_a_theme(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/theme', ['theme' => 'light'])
            ->assertRedirect();

        $this->assertSame('light', $admin->fresh()?->theme);
    }

    public function test_a_customer_can_choose_a_theme(): void
    {
        // Der Kunde am hellen Bürobildschirm ist der Fall, für den §7.2 das
        // helle Theme verlangt. Ein Umschalter ohne ihn verfehlt den Zweck.
        $account = Account::factory()->customer(Customer::factory()->create())->create();

        $this->actingAs($account)->put('/settings/theme', ['theme' => 'light'])
            ->assertRedirect();

        $this->assertSame('light', $account->fresh()?->theme);
    }

    public function test_empty_means_follow_the_operating_system(): void
    {
        $admin = Account::factory()->admin()->create(['theme' => 'dark']);

        $this->actingAs($admin)->put('/settings/theme', ['theme' => null])
            ->assertRedirect();

        // Kein Theme ist keine dritte Farbe, sondern die Abwesenheit einer
        // Wahl — und die löst der Browser auf, nicht der Server.
        $this->assertNull($admin->fresh()?->theme);
    }

    public function test_an_unknown_theme_is_refused(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/theme', ['theme' => 'mitternacht'])
            ->assertSessionHasErrors('theme');

        $this->assertNull($admin->fresh()?->theme);
    }

    public function test_the_choice_needs_an_account(): void
    {
        $this->put('/settings/theme', ['theme' => 'light'])->assertRedirect('/login');
    }

    /**
     * Während „Anmelden als" landet die Wahl sonst am fremden Konto.
     *
     * Der Admin sieht die Sicht des Kunden; gespeichert würde sie an dessen
     * Konto. Ein Betreiber, der sein Panel hell mag, stellte damit fremde
     * Konten um — und der Kunde fände seine Oberfläche verändert vor, ohne
     * etwas getan zu haben.
     */
    public function test_it_is_refused_while_impersonating(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)->post('/customers/'.$customer->id.'/impersonate')->assertRedirect();

        $this->put('/settings/theme', ['theme' => 'light']);

        $this->assertNull($account->fresh()?->theme, 'Das Konto des Kunden wurde verändert.');
    }

    public function test_the_chosen_theme_stands_at_the_root_element(): void
    {
        $admin = Account::factory()->admin()->create(['theme' => 'light']);

        $this->actingAs($admin)->get('/settings/profile')
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-theme-mode="light"', false);
    }

    public function test_without_a_choice_the_browser_is_asked(): void
    {
        $admin = Account::factory()->admin()->create(['theme' => null]);

        $this->actingAs($admin)->get('/settings/profile')
            ->assertSee('data-theme-mode="system"', false);
    }

    public function test_a_page_without_an_account_keeps_the_operator_default(): void
    {
        /*
         * Anmeldung und zweiter Faktor haben niemanden, den sie fragen
         * könnten. `SRVPANEL_THEME` behält dort seine Aufgabe — und damit
         * behält die Einstellung überhaupt eine.
         */
        config(['srvpanel.ui.theme' => 'light']);

        $this->get('/login')
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-theme-mode="fixed"', false);
    }

    /**
     * Das falsche Theme darf nicht aufblitzen.
     *
     * **Das ist die Stelle, an der solche Umschalter regelmässig schlecht
     * werden.** Ein Konto, das dem Betriebssystem folgt, kann der Server nicht
     * auflösen — ob dort hell oder dunkel gilt, weiss nur der Browser. Trägt
     * die Anwendung das nach, sieht man bei *jedem* Seitenaufruf für einen
     * Sekundenbruchteil die dunkle Fläche, bevor sie hell wird: Vite lädt sein
     * Bündel mit `defer`, und das läuft erst nach dem ersten Zeichnen.
     *
     * Das Skript muss deshalb im Kopf stehen, ohne `defer` und ohne `async`,
     * und **vor** dem Bündel. Geprüft wird die Reihenfolge und nicht der
     * Inhalt: Wer die Zeile später verschiebt, bekommt das Blinken zurück,
     * ohne dass irgendetwas kaputtgeht — der schlimmste Fehler, weil ihn nichts
     * meldet.
     */
    public function test_the_browser_is_asked_before_the_first_paint(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/app.blade.php');

        $skript = strpos($blade, 'prefers-color-scheme');
        $buendel = strpos($blade, '@vite');

        $this->assertIsInt($skript, 'Niemand fragt mehr das Betriebssystem.');
        $this->assertIsInt($buendel);
        $this->assertLessThan(
            $buendel,
            $skript,
            'Die Abfrage steht hinter dem Bündel — dann blitzt das falsche Theme auf.',
        );

        // Und sie darf nicht auf später verschoben werden.
        $offen = strrpos(substr($blade, 0, $skript), '<script');
        $this->assertIsInt($offen);

        $tag = substr($blade, $offen, strpos($blade, '>', $offen) - $offen);

        $this->assertStringNotContainsString('defer', $tag);
        $this->assertStringNotContainsString('async', $tag);
    }

    /**
     * Die Oberfläche schaltet selbst um — sonst passiert beim Klick nichts.
     *
     * **Der Fehler, den dieser Test festhält, war schon gebaut und grün.**
     * `data-theme` steht am `<html>`, und dieses Gerüst rendert Inertia bei
     * einer Navigation nie neu: Die Seite wechselt, der Rahmen bleibt stehen.
     * Gespeichert wurde also richtig, die Rückmeldung „Darstellung
     * gespeichert." kam — und zu sehen war nichts, bis jemand die Seite neu
     * lud. Aufgefallen ist es erst im Browser.
     *
     * Geprüft wird die Verbindung zwischen den beiden Dateien, weil genau die
     * reissen kann, ohne dass irgendetwas bricht: Wer die Funktion im Kopf
     * umbenennt, bekommt keinen Fehler, sondern einen Umschalter, der nicht
     * umschaltet.
     */
    public function test_the_interface_can_switch_without_a_reload(): void
    {
        $wurzel = dirname(__DIR__, 2);
        $blade = (string) file_get_contents($wurzel.'/resources/views/app.blade.php');
        $seite = (string) file_get_contents($wurzel.'/resources/js/Pages/Settings/Profile.vue');

        $this->assertStringContainsString(
            'window.srvpanelTheme =',
            $blade,
            'Der Kopf der Seite reicht die Umschaltung nicht mehr nach aussen.',
        );

        $this->assertStringContainsString(
            'window.srvpanelTheme',
            $seite,
            'Die Wahl wird gespeichert, aber nichts wendet sie an — sichtbar erst nach einem Neuladen.',
        );
    }
}
