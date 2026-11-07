<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Kundenansicht zeigt nur Kontingente, die etwas begrenzen.
 *
 * „FTP-Konten: 5" stand bis zum 7. November 2026 vor jedem Kunden, und es
 * gibt keine FTP-Konten (`docs/145 §0`, Befund 9). Der Betreiber sieht das
 * Kontingent weiter, weil es in seinen Plänen steht. Dass es nichts
 * begrenzt, hält `QuotaLimitsTest`.
 */
final class SubscriptionQuotaRowsTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'plan_id' => Plan::factory()->create()->id,
        ]);
    }

    /** @return list<string> */
    private function keys(Account $account, Subscription $subscription): array
    {
        $keys = [];

        $this->actingAs($account)
            ->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$keys): void {
                $page->where('quotas', function (mixed $quotas) use (&$keys): bool {
                    $keys = collect(is_iterable($quotas) ? $quotas : [])
                        ->pluck('key')
                        ->map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
                        ->values()
                        ->all();

                    return true;
                })->etc();
            });

        return $keys;
    }

    public function test_a_customer_does_not_see_the_ftp_accounts(): void
    {
        $subscription = $this->subscription();
        $customer = Customer::query()->findOrFail($subscription->customer_id);
        $keys = $this->keys(Account::factory()->customer($customer)->withoutTwoFactor()->create(), $subscription);

        $this->assertNotContains('ftp_accounts', $keys);
        $this->assertContains('disk_mb', $keys, 'Die Gegenprobe: Der Speicherplatz steht weiter da.');
    }

    public function test_the_operator_still_sees_them(): void
    {
        $subscription = $this->subscription();

        $this->assertContains('ftp_accounts', $this->keys(Account::factory()->admin()->create(), $subscription));
    }
}
