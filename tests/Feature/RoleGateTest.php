<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Administrator kommt an die Geheimnisseiten nicht heran.
 *
 * ## Das Abnahmekriterium von Schritt 2, als Test
 *
 * `docs/82 §7` verlangt für Schritt 2: *ein Administrator bekommt auf den sechs
 * Geheimnisseiten 403*. Genau das steht hier — und daneben die Gegenprobe, dass
 * ein Betreiber sie bekommt.
 *
 * **Ohne die Gegenprobe hiesse ein grüner Lauf nichts.** Ein Gate, das jeden
 * abweist, bestünde die eine Hälfte genauso.
 *
 * > **Eine Schranke, die man nur von aussen prüft, ist von einer verschlossenen
 * > Tür nicht zu unterscheiden.**
 *
 * ## Warum die Seiten hier einzeln stehen
 *
 * Sie sind der Gegenstand der Entscheidung: Jede von ihnen trägt ein Geheimnis
 * oder einen Weg zu root (`docs/20 §6.1`), und `AdminAbility` begründet für
 * jede, warum. Eine Schleife über `routes/web.php` wäre bequemer und prüfte die
 * Registratur gegen sich selbst — hier soll die **Wirkung** an der Tür gemessen
 * werden.
 */
final class RoleGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Seiten, die nur der Betreiber sehen darf.
     *
     * `/logs` steht dabei, weil ein Stacktrace in `laravel.log` trägt, was ihn
     * ausgelöst hat — bei einer Ausnahme im Verbindungsaufbau also die
     * Zugangsdaten der Datenbank.
     *
     * @var list<string>
     */
    private const OPERATOR_ONLY = [
        '/settings/php',
        '/settings/database',
        '/settings/mail',
        '/settings/tls',
        '/settings/dns',
        '/logs',
    ];

    public function test_an_administrator_is_refused_on_every_operator_page(): void
    {
        $administrator = Account::factory()->administrator()->create();

        foreach (self::OPERATOR_ONLY as $page) {
            $this->actingAs($administrator)
                ->get($page)
                ->assertForbidden();
        }
    }

    /**
     * Und die Gegenprobe: Der Betreiber kommt durch.
     *
     * Ohne sie bestünde der Test oben auch für ein Gate, das jeden abweist —
     * und das wäre genauso falsch, nur andersherum.
     */
    public function test_an_operator_reaches_every_one_of_them(): void
    {
        $operator = Account::factory()->admin()->create();

        foreach (self::OPERATOR_ONLY as $page) {
            $this->actingAs($operator)
                ->get($page)
                ->assertOk();
        }
    }

    /**
     * Was der Administrator **darf**, darf er weiterhin.
     *
     * **Das ist die Hälfte, die still bricht.** Wer `isAdmin()` beim Bauen zu
     * „ist Betreiber" umdeutet, nimmt ihm die Kundenverwaltung — also genau die
     * Arbeit, für die es ihn gibt (`docs/82 §5.2`). Ein Test, der nur die
     * Ablehnungen misst, bliebe dabei grün.
     */
    public function test_an_administrator_keeps_the_work_he_exists_for(): void
    {
        $administrator = Account::factory()->administrator()->create();

        foreach (['/customers', '/subscriptions', '/domains', '/settings/general'] as $page) {
            $this->actingAs($administrator)
                ->get($page)
                ->assertOk();
        }
    }

    /**
     * Ein Adminkonto ohne Rolle kommt nirgends durch — und das ist die sichere
     * Richtung.
     *
     * Das ist der Zustand nach einem Update, dessen Migration noch nicht
     * gelaufen ist. Eine stille Vollmacht wäre die andere Richtung.
     */
    public function test_an_admin_without_a_role_is_refused(): void
    {
        $account = Account::factory()->admin()->create(['role' => null]);

        $this->actingAs($account)->get('/settings/dns')->assertForbidden();
        $this->actingAs($account)->get('/settings/general')->assertForbidden();
    }
}
