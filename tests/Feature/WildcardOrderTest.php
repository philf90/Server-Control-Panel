<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateOrder;
use App\Support\Tls\DnsProfile;
use App\Support\Tls\WildcardOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedDnsCredentials;
use Tests\TestCase;

/**
 * Ein Platzhalter — wer ihn bestellen darf, was bestellt wird, was er nicht deckt.
 *
 * **Er löst die Wochengrenze und kostet die Trennschärfe** (`docs/34 §3`). Ein
 * Abonnement mit vierzig Unterdomains verbraucht sonst vierzig Einträge je
 * Woche; mit `*.example.de` sind es zwei. Dafür deckt das Zertifikat jede
 * Unterdomain der Zone — auch eine, die einem anderen Abonnement gehört.
 *
 * **Der Fall, für den dieser Durchgang existiert, ist die Reihenfolge der
 * Namen.** Der Ablageort entsteht aus dem ersten; stünde die Basisdomain vorn,
 * läge der Platzhalter unter `example.de` und überschriebe ein einfaches
 * Zertifikat für denselben Namen. Das sieht man einer Bestellung nicht an, und
 * gemerkt hätte man es erst, wenn eine Domain plötzlich das falsche Zertifikat
 * ausliefert.
 */
final class WildcardOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $profiles */
    private function wildcards(array $profiles = [DnsProfile::OPERATOR]): WildcardOrder
    {
        return new WildcardOrder(new DnsProfile, new ScriptedDnsCredentials($profiles));
    }

    private function subscription(bool $mayEditDns = false): Subscription
    {
        app(Tenancy::class)->allowAll();

        $subscription = Subscription::factory()->create();

        $subscription->plan->update([
            'features' => [Feature::DnsEdit->value => $mayEditDns] + $subscription->plan->features,
        ]);

        return $subscription->fresh() ?? $subscription;
    }

    private function domain(Subscription $subscription, string $name, DomainType $type = DomainType::Main): Domain
    {
        $domain = app(Tenancy::class)->withoutRestriction(
            fn (): Domain => Domain::factory()->for($subscription)->create([
                'name' => $name,
                'type' => $type->value,
            ]),
        );

        $this->assertInstanceOf(Domain::class, $domain);

        return $domain;
    }

    /**
     * Der Stern steht zuerst — siehe die Klassenbeschreibung.
     *
     * Und die Basisdomain steht dabei: `*.example.de` deckt `example.de`
     * nicht, wer nur den Stern bestellt, bekommt auf der Hauptdomain eine
     * Browserwarnung.
     */
    public function test_the_star_comes_first_and_the_base_name_is_there(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $this->assertSame(['*.example.de', 'example.de'], WildcardOrder::names($domain));
    }

    /** Und die Bestellung nimmt genau diese Reihenfolge mit. */
    public function test_the_order_carries_the_star_first(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $operation = app(CertificateOrder::class)->place($domain, wildcard: true);

        $this->assertNotNull($operation);
        $this->assertSame(['*.example.de', 'example.de'], $operation->payload['names'] ?? null);
        $this->assertSame('dns-01', $operation->payload['challenge'] ?? null);
        $this->assertSame(DnsProfile::OPERATOR, $operation->payload['profile'] ?? null);
    }

    /**
     * Eine gewöhnliche Bestellung bleibt, was sie war.
     *
     * **Kein `challenge`, kein `profile` im Auftrag.** Der Agent bleibt dann
     * bei HTTP-01 — und ein Zeitplan aus der Fassung vor Schritt 8 bleibt
     * nicht stehen.
     */
    public function test_an_ordinary_order_says_nothing_about_dns(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $operation = app(CertificateOrder::class)->place($domain);

        $this->assertNotNull($operation);
        $this->assertSame(['example.de'], $operation->payload['names'] ?? null);
        $this->assertArrayNotHasKey('challenge', $operation->payload);
        $this->assertArrayNotHasKey('profile', $operation->payload);
    }

    /**
     * Nur zu einer Basisdomain.
     *
     * Ein Platzhalter zu einer Subdomain wäre `*.blog.example.de` — zulässig,
     * aber nicht das, was jemand meint, der auf einer Subdomainseite klickt.
     * Ein Alias hat gar keinen eigenen Block.
     */
    public function test_only_a_base_domain_gets_one(): void
    {
        $subscription = $this->subscription();

        $this->assertTrue(WildcardOrder::isBase($this->domain($subscription, 'example.de')));
        $this->assertTrue(WildcardOrder::isBase($this->domain($subscription, 'zweite.de', DomainType::Addon)));

        $subdomain = $this->domain($subscription, 'blog.example.de', DomainType::Subdomain);

        $this->assertFalse(WildcardOrder::isBase($subdomain));
        $this->assertStringContainsString('Haupt- oder Zusatzdomain', (string) $this->wildcards()->obstacle($subdomain));
    }

    /**
     * Ohne hinterlegte Zugangsdaten geht es nicht — und das steht da.
     *
     * **Eine Auskunft und keine Ausnahme.** Ein Knopf, der eine Bestellung
     * auslöst, die mangels Token scheitert, verbrennt einen Fehlversuch, und
     * die fünf je Stunde gelten für jeden Kunden dieses Servers.
     */
    public function test_without_credentials_it_says_so_instead_of_ordering(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $this->assertTrue($this->wildcards()->possible($domain));

        $without = $this->wildcards([]);

        $this->assertFalse($without->possible($domain));
        $this->assertStringContainsString('DNS-Zugangsdaten', (string) $without->obstacle($domain));
    }

    /**
     * Ein Abonnement mit eigener Zone braucht sein eigenes Profil.
     *
     * Das Profil des Betreibers hilft ihm nicht: Es öffnet die Zone womöglich
     * gar nicht, und die Fehlermeldung dazu käme vom Anbieter statt von hier.
     */
    public function test_a_subscription_with_its_own_zone_needs_its_own_profile(): void
    {
        $subscription = $this->subscription(true);
        $domain = $this->domain($subscription, 'example.de');

        $this->assertSame('abo-'.$subscription->id, $this->wildcards()->profile($domain));
        $this->assertFalse($this->wildcards()->possible($domain));
        $this->assertTrue($this->wildcards(['abo-'.$subscription->id])->possible($domain));
    }

    /**
     * Was eine Ebene tiefer liegt, deckt der Platzhalter nicht.
     *
     * Eine Grenze, die ACME zieht und nicht dieses Panel — aber eine Auskunft,
     * die die Oberfläche geben muss, statt eine Browserwarnung entstehen zu
     * lassen.
     */
    public function test_the_second_level_is_named(): void
    {
        $subscription = $this->subscription();
        $domain = $this->domain($subscription, 'example.de');

        $this->domain($subscription, 'blog.example.de', DomainType::Subdomain);
        $this->domain($subscription, 'shop.intern.example.de', DomainType::Subdomain);
        $this->domain($subscription, 'tief.er.example.de', DomainType::Subdomain);
        $this->domain($subscription, 'fremd.de', DomainType::Addon);

        $this->assertSame(
            ['shop.intern.example.de', 'tief.er.example.de'],
            WildcardOrder::uncovered($domain->fresh() ?? $domain),
        );
    }
}
