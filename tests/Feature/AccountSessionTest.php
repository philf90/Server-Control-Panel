<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die offenen Sitzungen eines Kontos — sehen und beenden.
 *
 * ## Der Fall, um den es sicherheitlich geht
 *
 * `Sessions::forget()` filtert nach **Konto und Kennung**, nicht nach Kennung
 * allein. Ohne die erste Bedingung beendete eine abgeschriebene Kennung die
 * Sitzung eines fremden Kontos — und die Kennung steht im Cookie des
 * Betroffenen, ist also nicht geheim gegenüber ihm selbst.
 *
 * > **Ein Filter über einen Bezeichner allein ist keine Zuordnung — er ist eine
 * > Suche.**
 */
final class AccountSessionTest extends TestCase
{
    use RefreshDatabase;

    private function openSession(Account $account, string $id, string $ip = '192.0.2.7'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $account->id,
            'ip_address' => $ip,
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    public function test_the_open_sessions_of_an_account_are_shown(): void
    {
        $operator = Account::factory()->admin()->create();
        $other = Account::factory()->administrator()->create();

        $this->openSession($other, 'sitzung-eins', '198.51.100.4');

        $this->actingAs($operator)
            ->get("/accounts/{$other->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sessions.0.ip', '198.51.100.4')
                ->etc());
    }

    public function test_a_session_can_be_ended(): void
    {
        $operator = Account::factory()->admin()->create();
        $other = Account::factory()->administrator()->create();

        $this->openSession($other, 'sitzung-eins');

        $this->actingAs($operator)
            ->delete("/accounts/{$other->id}/sessions", ['session' => 'sitzung-eins'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sitzung-eins']);
    }

    /**
     * **Der Fall, für den die `user_id` in der Bedingung steht.**
     *
     * Die Kennung ist echt, gehört aber einem anderen Konto. Ohne die erste
     * Bedingung wäre sie damit fort — und der Weg dorthin führte über eine
     * Adresse, die ein ganz anderes Konto nennt.
     */
    public function test_a_session_of_another_account_is_not_touched(): void
    {
        $operator = Account::factory()->admin()->create();
        $one = Account::factory()->administrator()->create();
        $two = Account::factory()->administrator()->create();

        $this->openSession($two, 'sitzung-von-zwei');

        $this->actingAs($operator)
            ->delete("/accounts/{$one->id}/sessions", ['session' => 'sitzung-von-zwei']);

        $this->assertDatabaseHas('sessions', ['id' => 'sitzung-von-zwei'],
            'Die Sitzung eines fremden Kontos wurde über die Adresse eines anderen beendet.');
    }

    /**
     * Eine Kennung, die es nicht gibt, ist kein Fehler.
     *
     * Sie ist genau der Zustand, den der Betreiber herstellen wollte — und sie
     * kann zwischen Anzeige und Klick abgelaufen sein.
     */
    public function test_an_unknown_session_is_not_an_error(): void
    {
        $operator = Account::factory()->admin()->create();
        $other = Account::factory()->administrator()->create();

        $this->actingAs($operator)
            ->delete("/accounts/{$other->id}/sessions", ['session' => 'gibt-es-nicht'])
            ->assertSessionHasNoErrors();
    }
}
