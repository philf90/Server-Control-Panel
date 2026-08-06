<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\DnsProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unter welchem Profil die Zugangsdaten für eine Zone liegen.
 *
 * **Die Unterscheidung gibt es in diesem Panel schon**, ausformuliert und von
 * einer Policy durchgesetzt: `Feature::DnsEdit` trägt den Hinweistext „Ohne
 * diese Freigabe verwaltet der Betreiber die Zone; das Abonnement sieht sie
 * nur." Genau diese Frage ist es (`docs/34 §5`).
 *
 * **Und der Name wird abgeleitet, nicht entgegengenommen.** Käme er aus einer
 * Anfrage, könnte ein Kunde das Profil eines anderen nennen — und damit dessen
 * Zone bearbeiten lassen.
 */
final class DnsProfileTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): DnsProfile
    {
        return app(DnsProfile::class);
    }

    private function subscription(bool $mayEditDns): Subscription
    {
        app(Tenancy::class)->allowAll();

        $subscription = Subscription::factory()->create();

        $subscription->plan->update([
            'features' => [Feature::DnsEdit->value => $mayEditDns] + $subscription->plan->features,
        ]);

        return $subscription->fresh() ?? $subscription;
    }

    /** Wer die Zone selbst führt, hinterlegt sein eigenes Profil. */
    public function test_a_plan_with_the_feature_gets_its_own_profile(): void
    {
        $subscription = $this->subscription(true);

        $this->assertSame('abo-'.$subscription->id, $this->profile()->forSubscription($subscription));
    }

    /** Ohne die Freigabe gilt das Profil des Betreibers. */
    public function test_without_the_feature_the_operator_profile_applies(): void
    {
        $subscription = $this->subscription(false);

        $this->assertSame(DnsProfile::OPERATOR, $this->profile()->forSubscription($subscription));
    }

    public function test_without_a_subscription_the_operator_profile_applies(): void
    {
        $this->assertSame(DnsProfile::OPERATOR, $this->profile()->forSubscription(null));
    }

    /** Und eine Domain fragt über ihr Abonnement. */
    public function test_a_domain_asks_through_its_subscription(): void
    {
        $subscription = $this->subscription(true);

        $domain = app(Tenancy::class)->withoutRestriction(
            fn (): Domain => Domain::factory()->for($subscription)->create(['name' => 'beispiel.de']),
        );

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertSame('abo-'.$subscription->id, $this->profile()->forDomain($domain));
    }
}
