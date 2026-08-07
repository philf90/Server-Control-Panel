<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateRenewal;
use App\Support\Tls\DnsCredentials;
use App\Support\Tls\DnsProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ScriptedDnsCredentials;
use Tests\TestCase;

/**
 * Was abläuft, wird erneuert — und zwar genau einmal.
 *
 * **Der Lauf muss enden, und das ist die Aussage, die keine Beobachtung ist.**
 * Beim Erneuern entsteht ein *neues* Zertifikat, die Domain zeigt danach
 * darauf, und die alte Zeile bleibt als Beleg stehen. Ohne die Bedingung „nur
 * Zertifikate, an denen eine Domain hängt" wäre sie in alle Ewigkeit fällig —
 * jeder Lauf bestellte sie neu, bis die Ratenbegrenzung zuschlägt. Das fiele
 * nicht am ersten Tag auf, sondern am dreissigsten.
 *
 * **Und ein Fehlversuch darf nicht zum Dauerversuch werden.** Produktiv sind
 * fünf Fehlversuche je Konto und Stunde die Grenze; wer stündlich wieder
 * anklopft, sperrt sich selbst aus — samt aller Domains, die in dieser Stunde
 * neu angelegt werden.
 *
 * Die Tests laufen wie der Timer: ohne angemeldetes Konto, also im
 * Grundzustand der Mandantenklammer.
 */
final class CertificateRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    private function renewal(): CertificateRenewal
    {
        return app(CertificateRenewal::class);
    }

    private function withContact(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => AcmeSettings::KEY],
            ['value' => ['contact' => 'post@beispiel.de', 'directory' => 'staging']],
        );
    }

    /**
     * Eine Domain mit Zertifikat, dessen Laufzeit sich einstellen lässt.
     *
     * `$rest` sind die verbleibenden Tage: unter dreissig ist fällig.
     */
    private function domainWithCertificate(string $name, int $rest, ?Carbon $lastAttempt = null): Domain
    {
        $this->tenancy()->allowAll();

        // Ohne `system_user`: Die Spalte ist eindeutig, und dieser Durchgang
        // legt bis zu zwölf Abonnements an. Gebraucht wird sie hier von
        // niemandem — bestellt wird für eine Domain, nicht für einen Benutzer.
        $subscription = Subscription::factory()->create(['name' => $name]);

        $domain = Domain::factory()->for($subscription)->create([
            'name' => $name,
            'status' => DomainStatus::Active,
        ]);

        $notAfter = now()->addDays($rest);

        $certificate = Certificate::factory()->covering([$name])->create([
            'subscription_id' => $subscription->id,
            'not_after' => $notAfter,
            'renew_after' => CertificateRenewal::due($notAfter),
            'last_attempt_at' => $lastAttempt,
        ]);

        $domain->certificate_id = (int) $certificate->id;
        $domain->save();

        $this->tenancy()->reset();

        return $domain;
    }

    private function orders(): int
    {
        $this->tenancy()->allowAll();

        return Operation::query()->where('task', 'acme.certificate.issue')->count();
    }

    public function test_a_certificate_close_to_expiry_is_ordered_again(): void
    {
        $this->withContact();
        $domain = $this->domainWithCertificate('beispiel.de', 10);

        $report = $this->renewal()->run();

        $this->assertSame(1, $report->due);
        $this->assertSame(1, $report->ordered);
        $this->assertSame(1, $this->orders());

        $this->tenancy()->allowAll();
        $order = Operation::query()->where('task', 'acme.certificate.issue')->firstOrFail();

        // Die Namen kommen aus dem Bestand, nicht aus dem alten Zertifikat:
        // Ein Alias, der seither dazukam, gehört mit hinein.
        $this->assertSame([$domain->name], $order->payload['names'] ?? null);
    }

    public function test_a_certificate_with_time_left_stays_untouched(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 60);

        $report = $this->renewal()->run();

        $this->assertSame(0, $report->due);
        $this->assertSame(0, $this->orders());
    }

    /**
     * Ein Zertifikat ohne Domain wird nie wieder angefasst.
     *
     * Das ist die Zeile, die den Lauf endlich macht — siehe die
     * Klassenbeschreibung.
     */
    public function test_a_certificate_no_domain_points_at_is_never_renewed(): void
    {
        $this->withContact();
        $domain = $this->domainWithCertificate('beispiel.de', 5);

        // Die Domain zeigt auf ein neues Zertifikat; das alte bleibt liegen.
        $this->tenancy()->allowAll();

        $neu = Certificate::factory()->covering(['beispiel.de'])->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => now()->addDays(90),
            'renew_after' => now()->addDays(60),
        ]);

        $domain->certificate_id = (int) $neu->id;
        $domain->save();

        $this->tenancy()->reset();

        $report = $this->renewal()->run();

        $this->assertSame(0, $report->due, 'Das abgelöste Zertifikat ist weiter fällig — der Lauf hört nicht auf.');
        $this->assertSame(0, $this->orders());
    }

    /** Nach einem Versuch ist erst nach der Frist wieder einer dran. */
    public function test_after_an_attempt_the_next_run_waits(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 10);

        $this->assertSame(1, $this->renewal()->run()->ordered);

        $this->tenancy()->reset();

        $second = $this->renewal()->run();

        $this->assertSame(0, $second->due, 'Der zweite Lauf klopft sofort wieder an.');
        $this->assertSame(1, $this->orders());
    }

    /**
     * Und die Frist läuft ab.
     *
     * Sie soll bremsen, nicht abwürgen: Ein Versuch von gestern darf die
     * Erneuerung nicht dauerhaft verhindern.
     */
    public function test_the_wait_is_over_after_the_retry_window(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 10, now()->subHours(CertificateRenewal::RETRY_HOURS + 1));

        $this->assertSame(1, $this->renewal()->run()->ordered);
    }

    /**
     * Ein Lauf bestellt höchstens seine Grenze — und sagt, was liegen bleibt.
     *
     * **Eine Grenze, die niemand meldet, sieht aus wie „alles erledigt".**
     * Deshalb steht der Rest im Bericht und nicht nur in der Datenbank.
     */
    public function test_a_run_orders_at_most_its_limit_and_says_what_is_left(): void
    {
        $this->withContact();

        $many = CertificateRenewal::PER_RUN + 2;

        for ($i = 0; $i < $many; $i++) {
            $this->domainWithCertificate('beispiel'.$i.'.de', 10);
        }

        $report = $this->renewal()->run();

        $this->assertSame($many, $report->due);
        $this->assertSame(CertificateRenewal::PER_RUN, $report->ordered);
        $this->assertSame(2, $report->left);
        $this->assertSame(CertificateRenewal::PER_RUN, $this->orders());
    }

    /**
     * Ohne Kontaktadresse wird nichts bestellt — und keine Frist verbraucht.
     *
     * Der zweite Teil ist der, den man übersieht: Ein Versuch, der nie
     * stattgefunden hat, darf die Erneuerung nicht sechs Stunden lang
     * blockieren. Sonst wäre die Domain nach dem Eintragen der Adresse
     * weiterhin ungeschützt, und niemand wüsste warum.
     */
    public function test_without_a_contact_address_nothing_is_ordered_and_nothing_is_burnt(): void
    {
        $this->domainWithCertificate('beispiel.de', 10);

        $report = $this->renewal()->run();

        $this->assertSame(1, $report->due);
        $this->assertSame(0, $report->ordered);
        $this->assertSame(0, $this->orders());

        $this->tenancy()->allowAll();
        $this->assertNull(Certificate::query()->firstOrFail()->last_attempt_at);
    }

    /**
     * Liegt im Ablageort ein neueres, wird nachgetragen statt bestellt.
     *
     * Der Fall entsteht, wenn ein Lauf zwischen dem Ablegen der Dateien und
     * dem Eintrag im Bestand abbricht. Ohne diese Frage bestellte das Panel
     * jeden Tag ein weiteres und liefe in die Wochengrenze der
     * Zertifizierungsstelle.
     */
    public function test_a_newer_certificate_in_the_store_is_written_into_the_records(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 10);

        $this->tenancy()->allowAll();
        $certificate = Certificate::query()->firstOrFail();

        $later = now()->addDays(80);

        $this->assertTrue($this->renewal()->adopt($certificate, [
            'present' => true,
            'issuer' => 'R11',
            'valid_from' => now()->subDay()->getTimestamp(),
            'valid_to' => $later->getTimestamp(),
        ]));

        $fresh = Certificate::query()->findOrFail($certificate->id);

        $this->assertSame($later->getTimestamp(), $fresh->not_after?->getTimestamp());
        $this->assertSame('R11', $fresh->issuer);

        // Und die Frist wandert mit — sonst stünde das Zertifikat morgen
        // wieder in der Liste.
        $this->assertSame(
            CertificateRenewal::due($later)?->toDateString(),
            $fresh->renew_after?->toDateString(),
        );
    }

    /** Was älter ist als der Bestand, wird nicht übernommen. */
    public function test_an_older_certificate_in_the_store_changes_nothing(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 10);

        $this->tenancy()->allowAll();
        $certificate = Certificate::query()->firstOrFail();

        $this->assertFalse($this->renewal()->adopt($certificate, [
            'present' => true,
            'valid_to' => now()->addDay()->getTimestamp(),
        ]));

        $this->assertFalse($this->renewal()->adopt($certificate, ['present' => false]));
    }

    /** Ein Zertifikat, das nicht mehr gilt, steht nicht mehr zur Erneuerung an. */
    public function test_only_active_certificates_are_renewed(): void
    {
        $this->withContact();
        $this->domainWithCertificate('beispiel.de', 10);

        $this->tenancy()->allowAll();
        Certificate::query()->firstOrFail()->update(['status' => CertificateStatus::Failed]);
        $this->tenancy()->reset();

        $this->assertSame(0, $this->renewal()->run()->due);
    }

    /**
     * **Der Fund aus dem Abnahmelauf: ein Platzhalter bleibt einer.**
     *
     * Am 7. August 2026 auf `cloudlab24.ipv64.de` gemessen. Die Erneuerung
     * meldete „1 fällig, 1 bestellt" — die Zahl stimmte, das Bestellte nicht:
     * Das neue Zertifikat trug nur `cloudlab24.ipv64.de`, und die drei
     * Unterdomains lieferten weiter das alte aus. `sweep()` rief
     * `place($domain)` ohne `wildcard:` auf.
     *
     * **Der Fehler wäre still und käme mit neunzig Tagen Verzögerung.** Im
     * Panel sieht ein erneuertes Zertifikat aus wie ein erneuertes; dass es eine
     * Zone weniger deckt als vorher, steht nirgends. Auffallen würde es, wenn
     * das alte abläuft und der Browser bei jeder Unterdomain warnt.
     */
    public function test_a_wildcard_is_renewed_as_a_wildcard(): void
    {
        $this->withContact();

        $this->tenancy()->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);

        $domain = Domain::factory()->for($subscription)->create([
            'name' => 'beispiel.de',
            'status' => DomainStatus::Active,
        ]);

        $notAfter = now()->addDays(10);

        $platzhalter = Certificate::factory()->covering(['*.beispiel.de', 'beispiel.de'])->create([
            'subscription_id' => $subscription->id,
            'not_after' => $notAfter,
            'renew_after' => CertificateRenewal::due($notAfter),
            'storage_name' => '_wildcard.beispiel.de',
        ]);

        $domain->certificate_id = (int) $platzhalter->id;
        $domain->save();

        $this->tenancy()->reset();

        // Ohne hinterlegtes Profil sagt `WildcardOrder::obstacle()` nein, und
        // der Lauf zählte den Platzhalter als liegengeblieben statt bestellt.
        // **Das Profil kommt von `DnsProfile` und wird nicht geraten.**
        // `Feature::default()` gibt für alles ausser `CertificateUpload` `true`
        // zurück — der Standardplan der Fabrik hat also `dns_edit`, und damit
        // heisst das Profil `abo-<id>` und nicht `betrieb`. Mit der geratenen
        // Vorgabe fand `WildcardOrder::obstacle()` keine Zugangsdaten, und der
        // Lauf zählte den Platzhalter als liegengeblieben statt bestellt.
        $this->app->instance(DnsCredentials::class, new ScriptedDnsCredentials([
            app(DnsProfile::class)->forSubscription($subscription),
        ]));

        $report = $this->renewal()->run();

        $this->assertSame(1, $report->ordered);

        $this->tenancy()->allowAll();

        $auftrag = Operation::query()->where('task', 'acme.certificate.issue')->firstOrFail();

        $this->assertSame(
            ['*.beispiel.de', 'beispiel.de'],
            $auftrag->payload['names'] ?? null,
            'Die Erneuerung eines Platzhalters bestellt kein Platzhalterzertifikat — die Zone schrumpft '.
            'damit stillschweigend auf einen Namen.',
        );

        // Und die Prüfung geht wieder über DNS: Ein Platzhalter lässt sich über
        // HTTP-01 gar nicht ausstellen.
        $this->assertSame('dns-01', $auftrag->payload['challenge'] ?? null);
    }

    /**
     * Die Basisdomain kommt aus dem Namen, nicht aus der Zuordnung.
     *
     * **Sonst bestellt die Erneuerung `*.a.beispiel.de`.** Hängt an einem
     * Platzhalter gerade nur eine Unterdomain — nach einer Wahl etwa —, dann
     * ist sie die mit der kleinsten Kennung, und über die Zuordnung zu gehen
     * ergäbe einen Platzhalter eine Ebene tiefer. Der wäre nicht falsch, nur
     * für etwas ganz anderes.
     */
    public function test_the_base_domain_comes_from_the_name(): void
    {
        $this->withContact();

        $this->tenancy()->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);

        $basis = Domain::factory()->for($subscription)->create([
            'name' => 'beispiel.de',
            'status' => DomainStatus::Active,
        ]);

        $sub = Domain::factory()->for($subscription)->create([
            'name' => 'a.beispiel.de',
            'type' => DomainType::Subdomain->value,
            'status' => DomainStatus::Active,
        ]);

        $notAfter = now()->addDays(10);

        $platzhalter = Certificate::factory()->covering(['*.beispiel.de', 'beispiel.de'])->create([
            'subscription_id' => $subscription->id,
            'not_after' => $notAfter,
            'renew_after' => CertificateRenewal::due($notAfter),
            'storage_name' => '_wildcard.beispiel.de',
        ]);

        // Nur die Unterdomain zeigt darauf — die Basisdomain hat inzwischen ein
        // eigenes bekommen.
        $sub->certificate_id = (int) $platzhalter->id;
        $sub->save();

        $this->assertNotNull($basis->id);

        $this->tenancy()->reset();

        // **Das Profil kommt von `DnsProfile` und wird nicht geraten.**
        // `Feature::default()` gibt für alles ausser `CertificateUpload` `true`
        // zurück — der Standardplan der Fabrik hat also `dns_edit`, und damit
        // heisst das Profil `abo-<id>` und nicht `betrieb`. Mit der geratenen
        // Vorgabe fand `WildcardOrder::obstacle()` keine Zugangsdaten, und der
        // Lauf zählte den Platzhalter als liegengeblieben statt bestellt.
        $this->app->instance(DnsCredentials::class, new ScriptedDnsCredentials([
            app(DnsProfile::class)->forSubscription($subscription),
        ]));

        $this->renewal()->run();

        $this->tenancy()->allowAll();

        $auftrag = Operation::query()->where('task', 'acme.certificate.issue')->firstOrFail();

        $this->assertSame(['*.beispiel.de', 'beispiel.de'], $auftrag->payload['names'] ?? null);
        $this->assertSame((int) $basis->id, (int) $auftrag->subject_id);
    }

    /**
     * Und ohne Zugangsdaten wird er **gar nicht** erneuert.
     *
     * **Die wichtigere Richtung.** Der naheliegende Ausweg wäre, ihn dann als
     * gewöhnliches Zertifikat nachzuholen — und das ist genau der stille
     * Rückschritt: Danach warnt der Browser bei jeder Unterdomain, und im Panel
     * sieht alles grün aus. Gemeldet wird es stattdessen, und zwar als Fehler.
     */
    public function test_a_wildcard_without_credentials_is_not_renewed_as_a_plain_one(): void
    {
        $this->withContact();

        $this->tenancy()->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'ohnezugang.de']);

        $domain = Domain::factory()->for($subscription)->create([
            'name' => 'ohnezugang.de',
            'status' => DomainStatus::Active,
        ]);

        $notAfter = now()->addDays(10);

        $platzhalter = Certificate::factory()->covering(['*.ohnezugang.de', 'ohnezugang.de'])->create([
            'subscription_id' => $subscription->id,
            'not_after' => $notAfter,
            'renew_after' => CertificateRenewal::due($notAfter),
            'storage_name' => '_wildcard.ohnezugang.de',
        ]);

        $domain->certificate_id = (int) $platzhalter->id;
        $domain->save();

        $this->tenancy()->reset();

        // Kein Profil hinterlegt — `WildcardOrder::obstacle()` sagt das auch.
        $this->app->instance(DnsCredentials::class, new ScriptedDnsCredentials([]));

        $report = $this->renewal()->run();

        $this->assertSame(0, $report->ordered, 'Ein Platzhalter ohne Zugangsdaten wurde trotzdem bestellt.');
        $this->assertSame(1, $report->blocked, 'Der Lauf verschweigt, dass ein Platzhalter liegenbleibt.');
        $this->assertSame(0, $this->orders());
    }
}
