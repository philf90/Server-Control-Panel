<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Domain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Deckt das Zertifikat die Domain, für die es ausgeliefert wird?
 *
 * **Warum das ein Wächter ist und keine Sichtprüfung.** Ein Zertifikat mit dem
 * falschen Namen bricht nichts ab. Der Server liefert es aus, die Seite lädt,
 * und der Browser zeigt eine Namenswarnung — die der Betreiber nicht sieht,
 * weil er seine eigene Domain längst im Zertifikatsspeicher hat, und die dem
 * Besucher als „diese Seite ist nicht sicher" begegnet. Genau die Sorte
 * Fehler, die dieses Projekt schon einmal ein Zertifikat gekostet hat
 * (`docs/27 §2`: Der Name stand nur im CommonName, und es fiel erst auf, als
 * ein echter Browser danach fragte.)
 *
 * **Die Regel für Platzhalter ist die, an der man sich verrechnet.**
 * `*.example.de` deckt genau eine Beschriftung: `www.example.de` ja,
 * `example.de` nein, `a.b.example.de` nein. Beide Irrtümer sind naheliegend,
 * und beide zeigen sich erst im Browser.
 *
 * Geprüft wird in zwei Richtungen: was {@see Certificate::covers()} beantwortet,
 * und dass {@see Domain} eine Zuordnung ohne Deckung gar nicht erst zulässt.
 * Die zweite ist die, die im Betrieb zählt — eine Regel, die nur als Methode
 * dasteht, wird beim dritten Aufrufer vergessen.
 */
final class CertificateCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dieser Durchgang prüft die Deckungsregel, nicht die Mandantenklammer.
        app(Tenancy::class)->allowAll();
    }

    /** @return array<string, array{list<string>, string, bool}> */
    public static function namesAndCoverage(): array
    {
        return [
            'derselbe Name' => [['example.de'], 'example.de', true],
            'ein anderer Name' => [['example.de'], 'fremd.de', false],
            'Grossschreibung zählt nicht' => [['Example.DE'], 'example.de', true],
            'Punkt am Ende zählt nicht' => [['example.de'], 'example.de.', true],
            'zweiter Name in der Liste' => [['a.de', 'b.de'], 'b.de', true],

            'Platzhalter deckt eine Beschriftung' => [['*.example.de'], 'www.example.de', true],
            'Platzhalter deckt die nackte Domain nicht' => [['*.example.de'], 'example.de', false],
            'Platzhalter deckt zwei Ebenen nicht' => [['*.example.de'], 'a.b.example.de', false],
            'Platzhalter deckt keine leere Beschriftung' => [['*.example.de'], '.example.de', false],

            // Ohne den Punkt im Vergleich deckte `*.example.de` auch das hier.
            'Platzhalter deckt keinen angehängten Namen' => [['*.example.de'], 'notexample.de', false],

            'Platzhalter und fester Name nebeneinander' => [['example.de', '*.example.de'], 'shop.example.de', true],
        ];
    }

    /** @param  list<string>  $names */
    #[DataProvider('namesAndCoverage')]
    public function test_a_certificate_covers_exactly_what_it_names(array $names, string $wanted, bool $expected): void
    {
        $certificate = new Certificate(['names' => $names]);

        $this->assertSame($expected, $certificate->covers($wanted));
    }

    public function test_all_names_of_a_server_block_must_be_covered(): void
    {
        $certificate = new Certificate(['names' => ['example.de', '*.example.de']]);

        $this->assertTrue($certificate->coversAll(['example.de', 'www.example.de']));
        $this->assertFalse($certificate->coversAll(['example.de', 'fremd.de']));

        // Eine leere Liste ist nicht gedeckt — sonst bestünde eine Domain ohne
        // Namen jede Prüfung.
        $this->assertFalse($certificate->coversAll([]));
    }

    public function test_a_domain_refuses_a_certificate_that_does_not_cover_it(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.de']);
        $fremd = Certificate::factory()
            ->covering(['andere.de'])
            ->create(['subscription_id' => $domain->subscription_id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deckt example.de nicht ab');

        $domain->certificate_id = $fremd->id;
        $domain->save();
    }

    public function test_a_domain_accepts_a_certificate_that_covers_it_by_wildcard(): void
    {
        $domain = Domain::factory()->create(['name' => 'shop.example.de']);
        $wildcard = Certificate::factory()
            ->covering(['*.example.de'])
            ->create(['subscription_id' => $domain->subscription_id]);

        $domain->certificate_id = $wildcard->id;
        $domain->save();

        $this->assertSame($wildcard->id, $domain->fresh()?->certificate?->id);
    }

    /**
     * Und ein Platzhalter aus einem fremden Abonnement gilt trotzdem nicht.
     *
     * **Diesen Fall hat der Kommentar an `Domain::certificate()` vorhergesagt,
     * bevor es ihn gab:** Die Deckungsprüfung fange eine fremde Nummer
     * „meistens" ab — „aber nicht bei einem Wildcard, das den Namen zufällig
     * deckt". Genau das kommt mit dem zweiten Wurf von P4. `*.example.de` deckt
     * jede Unterdomain der Zone, auch die eines anderen Kunden; ab da ist die
     * Zuordnung keine Sorgfaltsfrage mehr, sondern die Grenze zwischen zwei
     * Abonnements (`docs/34 §3`).
     */
    public function test_a_covering_certificate_of_another_subscription_is_refused(): void
    {
        $domain = Domain::factory()->create(['name' => 'shop.example.de']);

        // Dasselbe Zertifikat, dieselbe Deckung — nur gehört es jemand anderem.
        $fremd = Certificate::factory()->covering(['*.example.de'])->create();

        $this->assertNotSame($domain->subscription_id, $fremd->subscription_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gehört nicht zum Abonnement');

        $domain->certificate_id = $fremd->id;
        $domain->save();
    }

    /** Und das Zertifikat der Oberfläche gehört keiner Kundendomain. */
    public function test_the_certificate_of_the_panel_is_not_assigned_to_a_domain(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.de']);
        $panel = Certificate::factory()->forPanel()->covering(['example.de'])->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gehört nicht zum Abonnement');

        $domain->certificate_id = $panel->id;
        $domain->save();
    }

    /**
     * Und die Prüfung greift auch dort, wo die Mandantenklammer zu ist.
     *
     * **Das ist der Fall, in dem ein Wächter beim Arbeiten zubeisst statt beim
     * Fehler.** In einem Kommando oder einem Job steht die Klammer im
     * Grundzustand auf „nichts"; ein gewöhnliches `Certificate::find()`
     * lieferte dort `null`, und die Zuordnung wäre abgewiesen worden, obwohl
     * sie richtig ist. Dasselbe Muster hat das Projekt bei drei Wächtern des
     * Optik-Reworks getroffen — deshalb steht es hier als eigener Durchgang.
     */
    public function test_the_check_still_works_when_no_tenant_is_set(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.de']);
        $certificate = Certificate::factory()
            ->covering(['example.de'])
            ->create(['subscription_id' => $domain->subscription_id]);

        app(Tenancy::class)->reset();

        $domain->certificate_id = $certificate->id;
        $domain->save();

        app(Tenancy::class)->allowAll();

        $this->assertSame($certificate->id, $domain->fresh()?->certificate_id);
    }
}
