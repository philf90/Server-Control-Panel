<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\OperationStatus;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wer ein Zertifikat einspielt, schreibt danach den Server-Block neu.
 *
 * **Das ist die Falle aus `docs/32 §8`, und sie ist die unangenehme Sorte: Es
 * bricht nichts ab.** Der Block entsteht bei `web.site.apply`, und ob
 * `Strict-Transport-Security` darin steht, entscheidet sich an dem Zertifikat,
 * das dabei gelesen wird. Wer ein vertrautes Zertifikat ablegt und die
 * Operation nicht ruft, bekommt ein vertrautes Zertifikat **ohne** den Header.
 * Die Seite läuft, das Protokoll ist leer, und niemand sucht danach.
 *
 * **Die Gegenrichtung gehört dazu**, weil sie das Abnahmekriterium der Stufe
 * ist: „ein Kunde erhält ohne Zutun des Admins für seine Domain ein
 * Zertifikat". Der Auslöser ist deshalb kein Knopf, sondern der fertige
 * Server-Block — vorher ist die Domain über Port 80 nicht erreichbar, und die
 * Prüfung könnte gar nicht gelingen.
 *
 * **Und zusammen wären die beiden eine Schleife.** Bestellung → Zuordnung →
 * Block neu → Bestellung. Dass sie aufhört, ist keine Beobachtung, sondern eine
 * Zusage: Bestellt wird nur, wenn die Domain noch kein Zertifikat hat, und die
 * Zuordnung passiert vor dem neuen Block. Ein Wächter, der das nicht prüft,
 * lässt eine Warteschlange laufen, bis die Ratenbegrenzung sie anhält.
 *
 * Die Tests laufen wie der Arbeiter — ohne angemeldetes Konto, also im
 * Grundzustand der Mandantenklammer. Was hier grün ist, läuft auch dort.
 */
final class CertificateReapplyTest extends TestCase
{
    use RefreshDatabase;

    private function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }

    private function domain(): Domain
    {
        $this->tenancy()->allowAll();

        $subscription = Subscription::factory()->create([
            'name' => 'beispiel.de',
            'system_user' => 'p1001',
        ]);

        return Domain::factory()->for($subscription)->create([
            'name' => 'beispiel.de',
            'status' => DomainStatus::Active,
        ]);
    }

    private function withContact(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => AcmeSettings::KEY],
            ['value' => ['contact' => 'post@beispiel.de', 'directory' => 'staging']],
        );
    }

    /**
     * Ein durchgelaufener Vorgang — und danach steht die Klammer wie im Arbeiter.
     *
     * @param  array<string, mixed>  $result
     */
    private function finished(Domain $domain, string $task, array $result = []): Operation
    {
        $this->tenancy()->allowAll();

        $operation = Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => 'domain',
            'subject_id' => $domain->id,
            'type' => $task,
            'task' => $task,
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
            'result' => $result,
        ]);

        // Der Arbeiter kennt keinen Mandanten. Was danach läuft, läuft im
        // Grundzustand der Klammer — genau darum geht es hier.
        $this->tenancy()->reset();

        return $operation;
    }

    /** @return array<string, mixed> */
    private function issued(): array
    {
        return [
            'names' => ['beispiel.de'],
            'certificate' => '/etc/srvpanel/tls/certs/beispiel.de/fullchain.pem',
            'key' => '/etc/srvpanel/tls/certs/beispiel.de/privkey.pem',
            'issuer' => 'Test CA',
            'serial' => 'ab12',
            'not_before' => 1_754_000_000,
            'not_after' => 1_761_000_000,
        ];
    }

    /**
     * Wieviele Vorgänge dieser Art liegen in der Warteschlange?
     *
     * **Nicht `count()`.** Der Name gehört PHPUnit und ist dort `final` — die
     * Datei liess sich damit nicht einmal laden, und zwar mit einem fatalen
     * Fehler statt einer Testmeldung.
     */
    private function operations(string $task): int
    {
        $this->tenancy()->allowAll();

        return Operation::query()->where('task', $task)->count();
    }

    public function test_an_installed_certificate_is_followed_by_a_new_server_block(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'acme.certificate.issue', $this->issued()));

        $this->tenancy()->allowAll();

        $fresh = Domain::query()->findOrFail($domain->id);

        $this->assertNotNull($fresh->certificate_id, 'Das Zertifikat wurde der Domain nicht zugeordnet.');

        $certificate = Certificate::query()->findOrFail($fresh->certificate_id);

        $this->assertSame(['beispiel.de'], $certificate->names);
        $this->assertSame('Test CA', $certificate->issuer);

        // **Die Regel.** Ohne diesen Vorgang gilt ein vertrautes Zertifikat und
        // der Server-Block kennt es nicht — HSTS fehlt, und nichts sagt es.
        $this->assertSame(1, $this->operations('web.site.apply'), 'Der Server-Block wurde nicht neu geschrieben.');
    }

    public function test_a_domain_without_a_certificate_orders_one_after_the_block_is_written(): void
    {
        $domain = $this->domain();
        $this->withContact();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply'));

        $this->assertSame(1, $this->operations('acme.certificate.issue'));

        $bestellung = Operation::query()->where('task', 'acme.certificate.issue')->firstOrFail();
        $payload = $bestellung->payload ?? [];

        // Die Namen kommen aus dem Bestand und nicht aus einer Anfrage.
        $this->assertSame(['beispiel.de'], $payload['names'] ?? null);
        $this->assertSame('post@beispiel.de', $payload['contact'] ?? null);

        // Der Testbetrieb ist die Vorgabe — produktiv sind fünf Fehlversuche
        // je Konto und Stunde die Grenze.
        $this->assertSame('staging', $payload['directory'] ?? null);
    }

    /** Ohne Kontaktadresse bestellt das Panel nichts — sie wird nicht geraten. */
    public function test_without_a_contact_address_nothing_is_ordered(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply'));

        $this->assertSame(0, $this->operations('acme.certificate.issue'));
    }

    /**
     * Und die beiden Regeln jagen einander nicht.
     *
     * Der zweite Server-Block läuft durch dieselbe Prüfung wie der erste — nur
     * hat die Domain jetzt ein Zertifikat, und damit endet die Kette.
     */
    public function test_the_two_rules_do_not_chase_each_other(): void
    {
        $domain = $this->domain();
        $this->withContact();

        $this->tenancy()->reset();

        // Einspielen → Zuordnung → Server-Block.
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'acme.certificate.issue', $this->issued()));
        $this->assertSame(1, $this->operations('web.site.apply'));

        // Und dieser Server-Block läuft durch: keine zweite Bestellung.
        $block = Operation::query()->where('task', 'web.site.apply')->firstOrFail();
        $block->update(['status' => OperationStatus::Succeeded, 'result' => []]);

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($block);

        $this->assertSame(0, $this->operations('acme.certificate.issue'), 'Die Kette hört nicht auf.');
    }
}
