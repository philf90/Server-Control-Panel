<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die absolute Sitzungsdauer (§6.4).
 *
 * Laravel bringt die gleitende mit; die absolute fehlt. Ohne sie läuft eine
 * Sitzung, in der jemand alle zehn Minuten klickt, wochenlang weiter.
 */
final class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_session_stays(): void
    {
        $account = Account::factory()->admin()->create();

        $this->actingAs($account)
            ->withSession(['authenticated_at' => time()])
            ->get('/')
            ->assertOk();

        $this->assertAuthenticated();
    }

    public function test_an_old_session_is_ended(): void
    {
        $account = Account::factory()->admin()->create();
        $maximum = (int) config('srvpanel.session.absolute_lifetime');

        $this->actingAs($account)
            ->withSession(['authenticated_at' => time() - $maximum - 1])
            ->get('/')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_the_end_is_recorded(): void
    {
        $account = Account::factory()->admin()->create();
        $maximum = (int) config('srvpanel.session.absolute_lifetime');

        $this->actingAs($account)
            ->withSession(['authenticated_at' => time() - $maximum - 1])
            ->get('/');

        // Wer plötzlich vor dem Anmeldeformular steht, soll später
        // nachvollziehen können, warum.
        $this->assertNotNull(
            AuditEvent::query()->where('action', 'auth.session.expired')->first()
        );
    }

    public function test_a_missing_timestamp_is_added_instead_of_signing_out(): void
    {
        $account = Account::factory()->admin()->create();

        // Sitzungen aus der Zeit vor dieser Prüfung tragen den Zeitstempel
        // nicht. Jemanden dafür hinauszuwerfen, wäre eine Strafe für einen
        // Versionswechsel.
        $this->actingAs($account)->get('/')->assertOk();

        $this->assertAuthenticated();
        $this->assertIsNumeric(session('authenticated_at'));
    }

    public function test_the_limit_can_be_switched_off(): void
    {
        config(['srvpanel.session.absolute_lifetime' => 0]);
        $account = Account::factory()->admin()->create();

        $this->actingAs($account)
            ->withSession(['authenticated_at' => time() - 999999])
            ->get('/')
            ->assertOk();

        $this->assertAuthenticated();
    }
}
