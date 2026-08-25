<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Netzbeschränkung — und der Schutz davor, sich selbst auszusperren.
 *
 * ## Das Abnahmekriterium von Schritt 7, als Test
 *
 * `docs/82 §7` verlangt wörtlich: *eine IP-Beschränkung, die ihren eigenen
 * Urheber nicht aussperrt.* Genau das steht unten — und daneben die
 * Gegenproben, ohne die ein grüner Lauf nichts hiesse.
 *
 * ## Warum beide Fragestellen gemessen werden
 *
 * Die Beschränkung wird an zwei Stellen gefragt, und keine ersetzt die andere:
 * bei der **Anmeldung**, damit das Protokoll die Wahrheit sagt, und bei
 * **jeder Anfrage**, weil eine offene Sitzung die Beschränkung sonst überlebt.
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * Der zweite Fall ist der, an den man beim Bauen nicht denkt: Wer im Büro
 * angemeldet war und den Rechner mitnimmt, arbeitet sonst weiter.
 */
final class AdminNetworkTest extends TestCase
{
    use RefreshDatabase;

    /** Eine Adresse, die in keinem der Testnetze liegt. */
    private const OUTSIDE = '203.0.113.9';

    /** Das Netz, in dem der Testclient sitzt (Laravel meldet 127.0.0.1). */
    private const LOCAL = '127.0.0.0/8';

    private function restrictTo(string ...$networks): void
    {
        app(Settings::class)->saveAdminNetworks(array_values($networks));
    }

    /**
     * **Die Gegenprobe zuerst.** Ohne Liste kommt jeder herein, von überall.
     *
     * Das ist der Zustand jedes Servers, der die Einstellung nie angefasst hat
     * — und ohne diesen Fall bestünden die Tests unten auch für eine Schranke,
     * die grundsätzlich alles abweist.
     */
    public function test_without_a_list_an_admin_gets_in_from_anywhere(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE])
            ->get('/')
            ->assertOk();
    }

    /** Mit einer Liste, die den Betrachter deckt, ändert sich nichts. */
    public function test_a_covered_address_passes(): void
    {
        $this->restrictTo(self::LOCAL);

        $this->actingAs(Account::factory()->admin()->create())
            ->get('/')
            ->assertOk();
    }

    /**
     * **Der Fall, um den es geht:** Die offene Sitzung überlebt die
     * Beschränkung nicht.
     *
     * Gemessen wird an einer Anfrage und nicht an der Anmeldung — wer schon
     * angemeldet ist, kommt an der Anmeldeprüfung nie wieder vorbei.
     */
    public function test_a_live_session_from_a_forbidden_address_is_ended(): void
    {
        $this->restrictTo(self::LOCAL);

        $this->actingAs(Account::factory()->admin()->create())
            ->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE])
            ->get('/')
            ->assertRedirect('/login');

        $this->assertGuest('Die Sitzung läuft weiter, obwohl das Netz nicht mehr zugelassen ist.');
    }

    /**
     * Ein Administrator steht genauso darunter wie ein Betreiber.
     *
     * Gefragt wird die Mandantenachse und nicht die Rolle: Die Beschränkung
     * gilt der Admin-Ebene als ganzer.
     */
    public function test_the_restriction_covers_the_administrator_too(): void
    {
        $this->restrictTo(self::LOCAL);

        $this->actingAs(Account::factory()->administrator()->create())
            ->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE])
            ->get('/')
            ->assertRedirect('/login');
    }

    /**
     * **Ein Kunde ist nie betroffen** (`docs/82 §2.5`).
     *
     * Ein Kunde, der sich aus dem Urlaub nicht anmelden kann, ist ein Ausfall —
     * und zwar einer, den der Betreiber verursacht hat, ohne es zu wollen.
     */
    public function test_a_customer_is_never_restricted(): void
    {
        $this->restrictTo(self::LOCAL);

        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->withoutTwoFactor()->create();

        $this->actingAs($account)
            ->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE])
            ->get('/')
            ->assertOk();
    }

    /**
     * **Das Abnahmekriterium:** Eine Liste, die den Urheber ausschliesst, wird
     * abgewiesen.
     */
    public function test_a_list_that_locks_out_its_own_author_is_refused(): void
    {
        $operator = Account::factory()->admin()->create();

        $this->actingAs($operator)
            ->put('/settings/access', ['networks' => ['198.51.100.0/24']])
            ->assertSessionHasErrors('networks');

        $this->assertSame([], app(Settings::class)->adminNetworks(),
            'Die aussperrende Liste wurde trotzdem gespeichert.');
    }

    /**
     * **Die Gegenprobe dazu.** Eine Liste, die den Urheber trägt, geht durch.
     *
     * Ohne sie bestünde der Test darüber auch für ein Formular, das jede Liste
     * abweist — und dann liesse sich die Beschränkung nie einschalten.
     */
    public function test_a_list_that_keeps_its_author_is_saved(): void
    {
        $operator = Account::factory()->admin()->create();

        $this->actingAs($operator)
            ->put('/settings/access', ['networks' => [self::LOCAL, '198.51.100.0/24']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['127.0.0.0/8', '198.51.100.0/24'], app(Settings::class)->adminNetworks());
    }

    /**
     * Und die leere Liste hebt die Beschränkung auf — ohne Aussperrschutz.
     *
     * **Sie kann niemanden aussperren**, also darf sie nicht an der Prüfung
     * scheitern, die das verhindert. Der naheliegende Fehler wäre eine
     * Bedingung, die „deckt die Liste den Urheber" auch für die leere fragt —
     * dann liesse sich eine Beschränkung nie wieder abschalten.
     */
    public function test_an_empty_list_lifts_the_restriction(): void
    {
        $this->restrictTo('198.51.100.0/24', self::LOCAL);

        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/access', ['networks' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame([], app(Settings::class)->adminNetworks());
    }

    /** Ein Netz mit gesetzten Wirtsbits wird abgewiesen, nicht stillschweigend gelesen. */
    public function test_a_network_with_host_bits_is_refused(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/access', ['networks' => ['192.0.2.7/24']])
            ->assertSessionHasErrors('networks.0');
    }

    /** `/0` beschränkt nichts und wird mit dem Satz abgewiesen, der das sagt. */
    public function test_the_whole_internet_is_refused_with_a_reason(): void
    {
        $this->actingAs(Account::factory()->admin()->create())
            ->put('/settings/access', ['networks' => ['0.0.0.0/0']])
            ->assertSessionHasErrors('networks.0');
    }
}
