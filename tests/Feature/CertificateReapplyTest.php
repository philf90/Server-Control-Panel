<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\OperationStatus;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\Operations\Lifecycles;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificateRenewal;
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
 * Zusage: Bestellt wird nur, wenn nichts Gültiges die Namen des Server-Blocks
 * deckt, und die Zuordnung passiert vor dem neuen Block. Ein
 * Wächter, der das nicht prüft, lässt eine Warteschlange laufen, bis die
 * Ratenbegrenzung sie anhält.
 *
 * **Seit dem zweiten Wurf von P4 fragt diese Bedingung nach der Deckung und
 * nicht mehr nach dem Verweis** (`docs/34 §2.1`). Ein zugeordnetes Zertifikat
 * genügte, solange jedes für genau eine Domain bestellt wurde; kommt ein Alias
 * nachträglich dazu, steht er im `server_name` und nicht im Zertifikat — der
 * Browser warnt bei ihm, und im Panel sieht alles grün aus.
 *
 * **Und seit Schritt 4 fragt sie überhaupt nicht mehr nach dem zugeordneten**
 * ({@see CertificateChoice::satisfied()}), sondern ob es eines gibt, das gilt
 * und alles deckt. Damit zählt auch der Ablauf — was diesem Durchgang prompt
 * einen festen Zeitstempel von 2025 als Zeitbombe vorgeführt hat.
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

    /**
     * Die Antwort des Agenten auf eine gelungene Bestellung.
     *
     * **Die Laufzeit ist relativ und nicht absolut, und das war ein Fund.**
     * Hier standen zwei feste Zeitstempel aus dem August 2025 — solange nichts
     * nach dem Ablauf fragte, war das folgenlos. Mit der Auswahl aus Schritt 4
     * fragt die Bestellbedingung danach: Ein abgelaufenes Zertifikat gilt nicht
     * mehr als gedeckt, und der Durchgang „die beiden Regeln jagen einander
     * nicht" schlug fehl, weil das Testzertifikat inzwischen abgelaufen war.
     * Ein fester Zeitstempel in einem Datensatz ist eine Zeitbombe mit
     * unbekanntem Zünder — neunzig Tage sind ausserdem das, was Let's Encrypt
     * tatsächlich ausstellt.
     *
     * @return array<string, mixed>
     */
    private function issued(): array
    {
        return [
            'names' => ['beispiel.de'],
            'certificate' => '/etc/srvpanel/tls/certs/beispiel.de/fullchain.pem',
            'key' => '/etc/srvpanel/tls/certs/beispiel.de/privkey.pem',
            'issuer' => 'Test CA',
            'serial' => 'ab12',
            'not_before' => now()->subDay()->getTimestamp(),
            'not_after' => now()->addDays(90)->getTimestamp(),
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

        // **Und der Termin steht mit.** Ein Zertifikat ohne Frist wäre eines,
        // das der Erneuerungslauf nie findet — auffallen würde das erst, wenn
        // ein Browser es meldet. Gerechnet wird die Frist an einer Stelle.
        $this->assertSame(
            CertificateRenewal::due($certificate->not_after)?->toDateTimeString(),
            $certificate->renew_after?->toDateTimeString(),
        );

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

        /*
         * **Gezählt wird der Zuwachs und nicht der Bestand.** Hier stand `0`,
         * und der Test war rot, obwohl die Regel hielt: Der eingespielte
         * Vorgang aus der Zeile darüber heisst selbst `acme.certificate.issue`
         * und steht in derselben Tabelle. Wer eine Bestellung sucht, muss die
         * meinen, die *dazukommt* — dieselbe Falle wie bei den Wächtern, die
         * ihre Treffer dort zählen, wo die Regel schon eingehalten wird.
         */
        $before = $this->operations('acme.certificate.issue');

        // Und dieser Server-Block läuft durch: keine zweite Bestellung.
        $block = Operation::query()->where('task', 'web.site.apply')->firstOrFail();
        $block->update(['status' => OperationStatus::Succeeded, 'result' => []]);

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($block);

        $this->assertSame($before, $this->operations('acme.certificate.issue'), 'Die Kette hört nicht auf.');
    }

    /**
     * Wo das Zertifikat liegt, sagt der Agent — und das Panel merkt es sich.
     *
     * **Ohne diese Angabe gäbe es zwei Wahrheiten zu einer Frage** (`docs/34
     * §2.1`): die Zuordnung in der Datenbank und den Verzeichnisnamen auf dem
     * Server. Der Agent legt ab und berichtet, unter welchem Schlüssel; das
     * Panel nennt genau diesen, wenn der Server-Block geschrieben wird.
     */
    public function test_the_place_of_the_certificate_comes_from_the_agent(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished(
            $domain,
            'acme.certificate.issue',
            $this->issued() + ['storage_name' => 'mehrere.de'],
        ));

        $this->tenancy()->allowAll();

        $fresh = Domain::query()->findOrFail($domain->id);
        $certificate = Certificate::query()->findOrFail($fresh->certificate_id);

        $this->assertSame('mehrere.de', $certificate->storage_name);
    }

    /**
     * Fehlt sie, gilt die Regel, die der Agent bis dahin angewandt hat.
     *
     * Von zwei Ausgängen ist das der günstigere: Stünde `null` in der Spalte,
     * fiele eine Domain mit gültigem Zertifikat beim nächsten Anwenden auf Port
     * 80 zurück — ohne Fehler und ohne Meldung.
     */
    public function test_an_answer_without_the_place_falls_back_to_the_first_name(): void
    {
        $domain = $this->domain();

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'acme.certificate.issue', $this->issued()));

        $this->tenancy()->allowAll();

        $fresh = Domain::query()->findOrFail($domain->id);
        $certificate = Certificate::query()->findOrFail($fresh->certificate_id);

        $this->assertSame('beispiel.de', $certificate->storage_name);
    }

    /**
     * Ein Zertifikat, das nicht jeden Namen deckt, ist keines für diesen Block.
     *
     * Der Fall aus dem Alltag: Die Domain hatte ihr Zertifikat, danach kam ein
     * Alias dazu. Er steht ab sofort im `server_name` — und nicht im
     * Zertifikat. Bis hierher genügte dem Panel der Verweis, und es bestellte
     * nichts nach.
     */
    public function test_a_certificate_that_misses_a_name_is_ordered_again(): void
    {
        $domain = $this->domain();
        $this->withContact();

        $this->tenancy()->allowAll();

        $certificate = Certificate::factory()->covering(['beispiel.de'])->create([
            'subscription_id' => $domain->subscription_id,
        ]);

        $domain->certificate_id = (int) $certificate->id;
        $domain->save();

        // Und jetzt kommt der Alias dazu.
        Domain::factory()->alias($domain)->create(['name' => 'www.beispiel.de']);

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply'));

        $this->assertSame(1, $this->operations('acme.certificate.issue'));

        $this->tenancy()->allowAll();
        $order = Operation::query()->where('task', 'acme.certificate.issue')->firstOrFail();
        $payload = $order->payload ?? [];

        // Bestellt wird für den ganzen Block und nicht nur für den Nachzügler.
        $this->assertSame(['beispiel.de', 'www.beispiel.de'], $payload['names'] ?? null);
    }

    /**
     * Läuft schon eine Bestellung, kommt keine zweite dazu.
     *
     * **Sonst zählt jedes erneute Anwenden mit.** `srvpanel vhost --sites`
     * schreibt alle Blöcke neu; ohne diese Frage wären das ebenso viele
     * Prüfungen wie Domains, und fünf Fehlversuche je Stunde sind die Grenze
     * der Zertifizierungsstelle.
     */
    public function test_while_an_order_is_running_no_second_one_is_placed(): void
    {
        $domain = $this->domain();
        $this->withContact();

        $this->tenancy()->allowAll();

        Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => 'domain',
            'subject_id' => $domain->id,
            'type' => 'acme.certificate.issue',
            'task' => 'acme.certificate.issue',
            'status' => OperationStatus::Queued,
            'progress' => 0,
        ]);

        $this->tenancy()->reset();
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'web.site.apply'));

        $this->assertSame(1, $this->operations('acme.certificate.issue'), 'Es wurde ein zweites Mal bestellt.');
    }

    /**
     * **Der Fund aus dem Abnahmelauf: ein Platzhalter erreicht alle Blöcke.**
     *
     * Am 7. August 2026 auf `cloudlab24.ipv64.de` gesehen. Der Platzhalter war
     * ausgestellt, die Hauptdomain lieferte ihn aus — die drei Unterdomains
     * behielten ihre einzelnen Zertifikate. `CertificateChoice` antwortete für
     * sie längst richtig; nur fragte niemand, weil `install()` genau eine
     * Domain anwandte: die, die bestellt hat.
     *
     * **Dahinter stand eine Annahme, die der Platzhalter bricht.** Ein
     * Zertifikat betreffe die Domain, die es bestellt — das gilt, solange jedes
     * für einen Namen ausgestellt wird. Ein Platzhalter ändert die Antwort für
     * jede Domain der Zone.
     *
     * Der Betreiber musste jede Unterdomain von Hand „übernehmen", und das ist
     * die Sorte Handgriff, die niemand kennt, der die Software nicht gebaut hat.
     */
    public function test_a_wildcard_reaches_every_block_of_the_subscription(): void
    {
        $domain = $this->domain();

        $this->tenancy()->allowAll();

        $sub = Domain::factory()->for($domain->subscription)->create([
            'name' => 'a.beispiel.de',
            'type' => DomainType::Subdomain->value,
            'status' => DomainStatus::Active,
        ]);

        // Die Unterdomain hat ihr eigenes, kürzer laufendes Zertifikat — der
        // Zustand nach den ersten HTTP-01-Bestellungen.
        $eigenes = Certificate::factory()->covering(['a.beispiel.de'])->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => now()->addDays(60),
            'storage_name' => 'a.beispiel.de',
        ]);

        $sub->certificate_id = (int) $eigenes->id;
        $sub->save();

        $this->tenancy()->reset();

        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'acme.certificate.issue', [
            'names' => ['*.beispiel.de', 'beispiel.de'],
            'storage_name' => '_wildcard.beispiel.de',
            'issuer' => 'Test CA',
            'serial' => 'cd34',
            'not_before' => now()->subDay()->getTimestamp(),
            'not_after' => now()->addDays(90)->getTimestamp(),
        ]));

        $this->tenancy()->allowAll();

        $bloecke = Operation::query()
            ->where('task', 'web.site.apply')
            ->pluck('subject_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertContains((int) $domain->id, $bloecke, 'Die bestellende Domain wurde nicht angewandt.');
        $this->assertContains((int) $sub->id, $bloecke, sprintf(
            'Der Block von %s wurde nicht neu geschrieben. Er liefert damit weiter sein einzelnes '.
            'Zertifikat aus, obwohl der Platzhalter gilt — und niemand sieht es ausser im Browser.',
            $sub->name,
        ));

        // Und der Ablageort im Auftrag ist der des Platzhalters, nicht der alte.
        $auftrag = Operation::query()
            ->where('task', 'web.site.apply')
            ->where('subject_id', $sub->id)
            ->firstOrFail();

        $this->assertSame('_wildcard.beispiel.de', $auftrag->payload['certificate'] ?? null);
    }

    /**
     * Eine Erneuerung schreibt die Nachbarblöcke **nicht** neu.
     *
     * **Die Gegenrichtung, und sie ist die teurere.** Eine Erneuerung legt eine
     * neue Zeile an — andere Kennung, derselbe Ablageort. Wer über die Kennung
     * vergleicht, hält jeden Nachbarblock für veraltet: Bei einem Abonnement
     * mit vierzig Domains sind das vierzig Vorgänge alle sechzig Tage für eine
     * Datei, die genauso heisst wie vorher. Entschieden am 7. August 2026.
     */
    public function test_a_renewal_leaves_the_neighbours_alone(): void
    {
        $domain = $this->domain();

        $this->tenancy()->allowAll();

        $sub = Domain::factory()->for($domain->subscription)->create([
            'name' => 'a.beispiel.de',
            'type' => DomainType::Subdomain->value,
            'status' => DomainStatus::Active,
        ]);

        $alt = Certificate::factory()->covering(['*.beispiel.de', 'beispiel.de'])->create([
            'subscription_id' => $domain->subscription_id,
            'not_after' => now()->addDays(20),
            'storage_name' => '_wildcard.beispiel.de',
        ]);

        $sub->certificate_id = (int) $alt->id;
        $sub->save();

        $this->tenancy()->reset();

        // Dieselben Namen, derselbe Ablageort, nur länger gültig.
        app(Lifecycles::class)->afterSuccess($this->finished($domain, 'acme.certificate.issue', [
            'names' => ['*.beispiel.de', 'beispiel.de'],
            'storage_name' => '_wildcard.beispiel.de',
            'issuer' => 'Test CA',
            'serial' => 'ef56',
            'not_before' => now()->subDay()->getTimestamp(),
            'not_after' => now()->addDays(90)->getTimestamp(),
        ]));

        $this->tenancy()->allowAll();

        $this->assertSame(
            0,
            Operation::query()->where('task', 'web.site.apply')->where('subject_id', $sub->id)->count(),
            'Die Erneuerung hat den Nachbarblock neu geschrieben, obwohl sich am Ablageort nichts ändert.',
        );
    }
}
