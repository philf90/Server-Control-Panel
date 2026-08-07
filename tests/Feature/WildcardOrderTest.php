<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificateOrder;
use App\Support\Tls\DnsProfile;
use App\Support\Tls\WildcardOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        return new WildcardOrder(new DnsProfile, new ScriptedDnsCredentials($profiles), new CertificateChoice);
    }

    /**
     * Ohne Kontaktadresse bestellt {@see CertificateOrder} gar nichts.
     *
     * **Und das ist keine Kulisse, sondern die Zusage aus {@see AcmeSettings}:**
     * Die Adresse gehört gesetzt und nicht aus dem ersten Adminkonto geraten.
     * Genau daran sind hier zwei Durchgänge gescheitert — sie prüften den
     * Auftrag und bekamen `null`.
     */
    private function withContact(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => AcmeSettings::KEY],
            ['value' => ['contact' => 'post@beispiel.de', 'directory' => 'staging']],
        );
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
        $this->withContact();

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
        $this->withContact();

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

    /** Ein Zertifikat für diese Domain ablegen — mit den Namen, die es deckt. */
    private function certificateFor(Domain $domain, string $names, string $bis = '+60 days'): Certificate
    {
        return Certificate::factory()->covering(explode(' ', $names))->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => Carbon::parse($bis),
        ]);
    }

    /**
     * **Der Fund vom Zielserver: Ein Einzelzertifikat ist kein Platzhalter.**
     *
     * Bis zum 7. August 2026 hing das Kästchen an „es gibt noch kein
     * Zertifikat". Die Automatik bestellt aber, sobald der Server-Block steht,
     * und der Arbeiter ist schneller als jeder Mensch — auf `cloudlab24` stand
     * die Seite mit einem gültigen Zertifikat für die Hauptdomain da und bot
     * weder Platzhalter noch Bestellung an. Der Weg von Einzelzertifikaten zu
     * einem Platzhalter existierte über die Oberfläche nicht.
     *
     * **Was das Kästchen jetzt fragt, ist die Deckung** — und die Prüfung
     * dahinter ist dieselbe wie überall sonst.
     */
    public function test_a_certificate_for_the_name_alone_is_not_a_wildcard(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $this->certificateFor($domain, 'example.de');

        $this->assertFalse(
            $this->wildcards()->covered($domain),
            'Ein Zertifikat für die Hauptdomain allein gilt als Platzhalter — dann ist der Umstieg unerreichbar.',
        );
    }

    /**
     * Und liegt er, wird nicht noch einmal bestellt.
     *
     * **Die Gegenrichtung, und sie ist die teurere.** Ein Knopf, der einen
     * Platzhalter nachbestellt, der schon daliegt, verbrennt einen der fünf
     * Fehlversuche je Konto und Stunde — und die gelten für jeden Kunden
     * dieses Servers, nicht nur für den, der geklickt hat.
     */
    public function test_an_existing_wildcard_takes_the_offer_away(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $this->certificateFor($domain, '*.example.de example.de');

        $this->assertTrue($this->wildcards()->covered($domain));
    }

    /**
     * Ein abgelaufener Platzhalter deckt nichts mehr.
     *
     * Ohne diese Richtung bliebe eine Domain mit abgelaufenem Platzhalter ohne
     * Angebot stehen — und genau dann braucht sie eines.
     */
    public function test_an_expired_wildcard_does_not_count(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        $this->certificateFor($domain, '*.example.de example.de', '-1 day');

        $this->assertFalse($this->wildcards()->covered($domain));
    }

    /**
     * Und der Platzhalter eines fremden Abonnements zählt hier nicht.
     *
     * Er deckt den Namen technisch — er gehört trotzdem nicht hierher. Dieselbe
     * Grenze wie bei der Auswahl, und dieselbe Abfrage dahinter.
     */
    public function test_a_wildcard_of_another_subscription_does_not_count(): void
    {
        $domain = $this->domain($this->subscription(), 'example.de');

        Certificate::factory()->covering(['*.example.de', 'example.de'])->create([
            'not_after' => now()->addDays(60),
        ]);

        $this->assertFalse($this->wildcards()->covered($domain));
    }

    /** Zu einer Subdomain gibt es keinen Platzhalter — also auch keine Deckung. */
    public function test_a_subdomain_is_never_covered(): void
    {
        $subscription = $this->subscription();
        $this->domain($subscription, 'example.de');

        $sub = $this->domain($subscription, 'blog.example.de', DomainType::Subdomain);

        $this->certificateFor($sub, '*.blog.example.de blog.example.de');

        $this->assertFalse($this->wildcards()->covered($sub));
    }
}
