<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificateSource;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificateRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Welches Zertifikat liefert dieser Server-Block aus?
 *
 * **Ab dem zweiten Wurf von P4 hat eine Domain mehrere zur Auswahl** — das für
 * ihren Namen bestellte, den Platzhalter ihres Abonnements, ein hochgeladenes.
 * Damit wird aus einer Zuweisung eine Frage, und die hat genau eine Antwort:
 * {@see CertificateChoice}.
 *
 * **Wahl und Zuweisung sind zweierlei.** Ohne diesen Unterschied nähme die
 * nächste Bestellung die Wahl still zurück — der Fehlertyp, der in diesem
 * Projekt am teuersten war.
 *
 * **Und der Rückfall ist laut.** Läuft die Wahl ab, wird sie übergangen: Ein
 * hochgeladenes Zertifikat erneuert niemand, und stur daran festzuhalten nähme
 * die Website vom Netz. Übergangen heisst aber nicht verschwiegen — die Wahl
 * bleibt eingetragen, und die Domainseite sagt es (`docs/34 §8`).
 */
final class CertificateChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function choice(): CertificateChoice
    {
        return app(CertificateChoice::class);
    }

    private function domain(): Domain
    {
        app(Tenancy::class)->allowAll();

        $subscription = Subscription::factory()->create(['name' => 'beispiel.de']);

        return Domain::factory()->for($subscription)->create(['name' => 'beispiel.de']);
    }

    private function certificateFor(Domain $domain, string $bis = '+60 days', ?CertificateSource $source = null): Certificate
    {
        return Certificate::factory()->covering([$domain->name])->create([
            'subscription_id' => $domain->subscription_id,
            'source' => $source ?? CertificateSource::Acme,
            'not_after' => Carbon::parse($bis),
            'storage_name' => $domain->name,
        ]);
    }

    private function assign(Domain $domain, Certificate $certificate, bool $pinned): void
    {
        $domain->certificate_id = (int) $certificate->id;
        $domain->certificate_pinned_at = $pinned ? now() : null;
        $domain->save();
    }

    public function test_without_a_choice_the_automatic_decides(): void
    {
        $domain = $this->domain();
        $alt = $this->certificateFor($domain, '+10 days');
        $neu = $this->certificateFor($domain, '+80 days');

        $this->assign($domain, $alt, false);

        // Ohne Wahl gilt das mit der längsten Laufzeit — nicht das zugeordnete.
        $this->assertSame((int) $neu->id, $this->choice()->effective($domain)?->id);
        $this->assertFalse($this->choice()->overridden($domain));
    }

    /**
     * Eine Wahl überlebt eine neue Bestellung.
     *
     * Der Fall, für den `certificate_pinned_at` überhaupt existiert: Die
     * Erneuerung legt ein neues Zertifikat ab, und ohne diese Regel hinge die
     * Domain danach daran — die Wahl wäre still zurückgenommen.
     */
    public function test_a_choice_survives_a_new_order(): void
    {
        $domain = $this->domain();
        $gewaehlt = $this->certificateFor($domain, '+40 days', CertificateSource::Uploaded);

        $this->assign($domain, $gewaehlt, true);

        app(CertificateRecord::class)->store($domain, [
            'names' => [$domain->name],
            'storage_name' => $domain->name,
            'not_after' => now()->addDays(90)->getTimestamp(),
        ], CertificateSource::Acme);

        $frisch = Domain::query()->findOrFail($domain->id);

        $this->assertSame((int) $gewaehlt->id, $frisch->certificate_id);
        $this->assertNotNull($frisch->certificate_pinned_at);
        $this->assertSame((int) $gewaehlt->id, $this->choice()->effective($frisch)?->id);
    }

    /** Ein Hochladen ist selbst eine Wahl — sonst täte das Formular nichts. */
    public function test_an_upload_is_itself_a_choice(): void
    {
        $domain = $this->domain();
        $vorher = $this->certificateFor($domain, '+80 days');

        $this->assign($domain, $vorher, false);

        $hochgeladen = app(CertificateRecord::class)->store($domain, [
            'names' => [$domain->name],
            'storage_name' => '_uploaded.'.$domain->name,
            'not_after' => now()->addDays(30)->getTimestamp(),
        ], CertificateSource::Uploaded);

        $frisch = Domain::query()->findOrFail($domain->id);

        $this->assertSame((int) $hochgeladen->id, $frisch->certificate_id);
        $this->assertNotNull($frisch->certificate_pinned_at);
    }

    /**
     * Läuft die Wahl ab, springt das gültige ein — und es steht dabei.
     *
     * Das ist der laute Rückfall. Stumm zu wechseln wäre die zweite Wahrheit;
     * stur festzuhalten nähme die Website vom Netz.
     */
    public function test_an_expired_choice_is_overridden_loudly(): void
    {
        $domain = $this->domain();
        $abgelaufen = $this->certificateFor($domain, '-1 day', CertificateSource::Uploaded);
        $gueltig = $this->certificateFor($domain, '+60 days');

        $this->assign($domain, $abgelaufen, true);

        $this->assertSame((int) $gueltig->id, $this->choice()->effective($domain)?->id);
        $this->assertTrue($this->choice()->overridden($domain), 'Die übergangene Wahl wird nicht gemeldet.');

        // Die Wahl bleibt eingetragen — sie greift wieder, sobald sie gilt.
        $this->assertSame((int) $abgelaufen->id, Domain::query()->findOrFail($domain->id)->certificate_id);
    }

    /**
     * Gibt es nichts Gültiges, bleibt das Abgelaufene stehen — und es wird
     * bestellt.
     *
     * **Besser als nichts:** Ohne Zertifikat fällt der Block auf Port 80
     * zurück, und eine Adresse, die vorher HTTPS war, ist dann nicht mehr
     * erreichbar, sondern still unverschlüsselt. Ein abgelaufenes warnt
     * wenigstens. Dass nachbestellt wird, sagt `satisfied()`.
     */
    public function test_with_nothing_valid_the_expired_one_stays_and_a_new_one_is_due(): void
    {
        $domain = $this->domain();
        $abgelaufen = $this->certificateFor($domain, '-1 day');

        $this->assign($domain, $abgelaufen, true);

        $this->assertSame((int) $abgelaufen->id, $this->choice()->effective($domain)?->id);
        $this->assertFalse($this->choice()->satisfied($domain), 'Es müsste nachbestellt werden.');
    }

    /** Und mit einem gültigen daneben wird nicht mehr bestellt. */
    public function test_a_valid_fallback_stops_the_ordering(): void
    {
        $domain = $this->domain();
        $abgelaufen = $this->certificateFor($domain, '-1 day');
        $this->certificateFor($domain, '+60 days');

        $this->assign($domain, $abgelaufen, true);

        $this->assertTrue($this->choice()->satisfied($domain));
    }

    /**
     * Zur Wahl steht nur, was dem eigenen Abonnement gehört und alles deckt.
     *
     * Ein Platzhalter eines anderen Kunden deckt den Namen womöglich — er
     * gehört trotzdem nicht hierher (`docs/34 §3`). Und was nur die
     * Hauptdomain deckt und nicht ihre Aliasse, erzeugte eine Warnung im
     * Browser, die im Panel grün aussieht.
     */
    public function test_only_what_belongs_here_and_covers_everything_is_offered(): void
    {
        $domain = $this->domain();
        Domain::factory()->alias($domain)->create(['name' => 'www.beispiel.de']);

        $frisch = Domain::query()->findOrFail($domain->id);

        $passend = Certificate::factory()->covering(['beispiel.de', 'www.beispiel.de'])->create([
            'subscription_id' => $frisch->subscription_id,
            'not_after' => now()->addDays(60),
        ]);

        // Deckt nur die Hauptdomain — der Alias fehlt.
        Certificate::factory()->covering(['beispiel.de'])->create([
            'subscription_id' => $frisch->subscription_id,
            'not_after' => now()->addDays(90),
        ]);

        // Deckt alles, gehört aber jemand anderem.
        Certificate::factory()->covering(['*.beispiel.de', 'beispiel.de'])->create([
            'not_after' => now()->addDays(90),
        ]);

        $ids = array_map(
            static fn (Certificate $c): int => (int) $c->id,
            $this->choice()->candidates($frisch),
        );

        $this->assertSame([(int) $passend->id], $ids);
    }
}
