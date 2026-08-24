<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificatePrune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ein Zertifikat überlebt sein Abonnement — und wird danach aufgeräumt.
 *
 * **Der Anlass ist ein Abbruch auf dem Zielserver.** Der Purge aus docs/35 hat
 * angehalten: An zurückgebauten Abonnements hingen noch zwölf Zertifikate, und
 * `certificates.subscription_id` stand auf `cascadeOnDelete`. Die Zeilen wären
 * mitgegangen, die Dateien unter `/etc/srvpanel/tls/certs` nicht — die gehören
 * dem Agenten. Zurückgeblieben wären zwölf Verzeichnisse mit **privaten
 * Schlüsseln**, auf die nichts mehr zeigt.
 *
 * Dahinter steckt ein älterer Fehler: Dieses System konnte ein Zertifikat nie
 * löschen. Ein zurückgebautes Abonnement liess seinen Ablageort liegen, und
 * weil die Zeile bis docs/35 als Grabstein stehenblieb, ist es niemandem
 * aufgefallen.
 *
 * **Die Regel, an der hier alles hängt:** Ein Ablageort geht nur, wenn ihn
 * keine Zeile mehr nennt, die kein Waise ist. Auf dem Zielserver teilte sich
 * `cloudlab24.de` seinen Ablageort zwischen einem zurückgebauten und einem
 * **lebenden** Abonnement — wer dort je Zeile löscht, nimmt eine laufende
 * Website mit.
 */
final class CertificatePruneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     */
    private function unrestricted(callable $work): mixed
    {
        return app(Tenancy::class)->withoutRestriction($work);
    }

    /** Ein Zertifikat, wie es nach dem Rückbau seines Abonnements aussieht. */
    private function orphan(string $storage, string $subscription = 'weg.invalid'): Certificate
    {
        $certificate = Certificate::factory()->create([
            'subscription_id' => Subscription::factory()->create()->id,
            'storage_name' => $storage,
        ]);

        $this->unrestricted(fn (): int => Certificate::query()
            ->whereKey($certificate->id)
            ->update(['subscription_id' => null, 'subscription_name' => $subscription]));

        return $certificate->refresh();
    }

    public function test_a_certificate_copies_the_subscription_name(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'kunde.invalid']);

        $certificate = Certificate::factory()->create(['subscription_id' => $subscription->id]);

        $this->assertSame('kunde.invalid', $certificate->refresh()->subscription_name);
    }

    /**
     * **Das Zertifikat der Oberfläche ist kein Waise — und das ist die
     * gefährlichste Verwechslung dieses Umbaus.**
     *
     * Beide tragen `subscription_id = null`. Hielte eine Aufräumfunktion das
     * eine für das andere, entfernte sie den privaten Schlüssel, mit dem das
     * Panel selbst antwortet.
     */
    public function test_the_panel_certificate_is_not_an_orphan(): void
    {
        $panel = Certificate::factory()->create(['subscription_id' => null]);

        $this->assertNull($panel->refresh()->subscription_name);
        $this->assertTrue($panel->forPanel());
        $this->assertFalse($panel->orphaned());

        $orphan = $this->orphan('weg.invalid');

        $this->assertFalse($orphan->forPanel());
        $this->assertTrue($orphan->orphaned());
    }

    /** Der Rückbau löst die Zertifikate ab, statt sie kaskadieren zu lassen. */
    public function test_a_removed_subscription_leaves_its_certificate_findable(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'kunde.invalid']);
        $certificate = Certificate::factory()->create([
            'subscription_id' => $subscription->id,
            'storage_name' => 'kunde.invalid',
        ]);

        $this->unrestricted(function () use ($subscription): void {
            $lifecycle = app(Lifecycle::class);
            (new ReflectionMethod($lifecycle, 'withdraw'))->invoke($lifecycle, $subscription);
        });

        $survivor = $this->unrestricted(fn (): ?Certificate => Certificate::query()->find($certificate->id));

        $this->assertNotNull($survivor, 'Die Zeile ist der einzige Wegweiser auf die Datei — sie darf nicht kaskadieren.');
        $this->assertNull($survivor->subscription_id);
        $this->assertSame('kunde.invalid', $survivor->subscription_name);
        $this->assertSame('kunde.invalid', $survivor->storage_name);
    }

    /**
     * **Die Regel, die der Zielserver ausgelöst hat.**
     *
     * Ein Ablageort, den noch ein **gebrauchtes** Zertifikat nennt, wird nicht
     * entfernt — nur die verwaiste Zeile geht.
     *
     * **Das lebende Zertifikat bekommt hier seit dem 24. August eine Domain**,
     * und das ist keine Verzierung: Seit dem zweiten Fall der Regel genügt
     * „lebendes Abonnement" nicht mehr, es muss auch jemand darauf zeigen.
     * Ohne die Domain prüfte dieser Fall den anderen Zweig und nicht seinen
     * eigenen — er wäre grün geblieben und hätte etwas anderes gemessen.
     */
    public function test_a_storage_name_shared_with_a_used_certificate_is_kept(): void
    {
        $lebend = Subscription::factory()->create(['name' => 'lebt.invalid']);
        Certificate::factory()->covering(['lebt.invalid'])->create([
            'subscription_id' => $lebend->id,
            'storage_name' => 'geteilt.invalid',
        ]);
        Domain::factory()->main()->create([
            'subscription_id' => $lebend->id,
            'name' => 'lebt.invalid',
        ]);

        $this->orphan('geteilt.invalid');
        $this->orphan('allein.invalid');

        $plan = app(CertificatePrune::class)->plan();

        $this->assertSame(['allein.invalid'], $plan['removable'], 'Nur der Ablageort, den niemand mehr nennt, darf fort.');
        $this->assertSame(['geteilt.invalid'], $plan['shared']);
    }

    /**
     * **Der Fund vom 24. August 2026, und der Grund für den zweiten Fall.**
     *
     * Nach dem Rückbau einer einzelnen Domain blieb ihr Zertifikat liegen: Das
     * Abonnement lebt, die Zeile verwaist also nie, und `--prune` führte sie
     * nie auf. Gemessen auf `cloudsrv24` an `tls.cloudlab24.de` — null
     * verweisende Domains, `privkey.pem` lag da.
     */
    public function test_a_certificate_whose_last_domain_is_gone_is_removable(): void
    {
        $lebend = Subscription::factory()->create(['name' => 'lebt.invalid']);

        Certificate::factory()->covering(['fort.invalid'])->create([
            'subscription_id' => $lebend->id,
            'storage_name' => 'fort.invalid',
        ]);

        // Eine andere Domain desselben Abonnements — das Abonnement lebt also
        // wirklich, und trotzdem zeigt niemand auf dieses Zertifikat.
        Domain::factory()->main()->create([
            'subscription_id' => $lebend->id,
            'name' => 'lebt.invalid',
        ]);

        $plan = app(CertificatePrune::class)->plan();

        $this->assertSame(['fort.invalid'], $plan['removable']);
        $this->assertSame(0, $plan['orphans'], 'Verwaist ist die Zeile nicht — ihr Abonnement lebt.');
        $this->assertSame(1, $plan['abandoned']);
        $this->assertSame('ohne Domain', $plan['reasons']['fort.invalid'] ?? null);
        $this->assertFalse($plan['nothing'], 'Sonst steigt das Kommando aus, bevor es etwas ausgibt.');
    }

    /**
     * **Die gefährliche Richtung, und sie geht über die Deckung.**
     *
     * Ein Platzhalter deckt `www.lebt.invalid`, ohne der Domain zugeordnet zu
     * sein — {@see CertificateChoice} wählt ihn trotzdem
     * jederzeit. Wer nur `domains.certificate_id` fragte, löschte hier den
     * Schlüssel unter einer laufenden Website weg.
     */
    public function test_a_certificate_that_only_covers_a_living_domain_is_kept(): void
    {
        $lebend = Subscription::factory()->create(['name' => 'lebt.invalid']);

        Certificate::factory()->covering(['*.lebt.invalid'])->create([
            'subscription_id' => $lebend->id,
            'storage_name' => 'platzhalter.invalid',
        ]);

        $domain = Domain::factory()->main()->create([
            'subscription_id' => $lebend->id,
            'name' => 'www.lebt.invalid',
        ]);

        $this->assertNull($domain->certificate_id, 'Zugeordnet ist es nicht — genau darum geht es.');

        $plan = app(CertificatePrune::class)->plan();

        $this->assertSame([], $plan['removable'], 'Ein gedeckter lebender Name hält den Ablageort.');
        $this->assertSame(0, $plan['abandoned']);
        $this->assertTrue($plan['nothing'], 'Es ist nichts zu tun — und das Kommando erfährt es von hier.');
    }

    /**
     * Und die Zeile geht mit, nicht nur die Datei.
     *
     * **Sonst bliebe ein Wegweiser auf ein Verzeichnis, das es nicht mehr
     * gibt.** `forget()` prüfte bis zum 24. August die Bedingung eines Waisen
     * ausgeschrieben; für den zweiten Fall hätte der Agent die Datei entfernt
     * und die Zeile wäre stehengeblieben.
     */
    public function test_forget_drops_a_row_whose_domain_is_gone(): void
    {
        $lebend = Subscription::factory()->create(['name' => 'lebt.invalid']);

        $zeile = Certificate::factory()->covering(['fort.invalid'])->create([
            'subscription_id' => $lebend->id,
            'storage_name' => 'fort.invalid',
        ]);

        $this->assertSame(1, app(CertificatePrune::class)->forget('fort.invalid'));

        $this->assertNull(
            $this->unrestricted(fn (): ?Certificate => Certificate::query()->find($zeile->id)),
            'Die Zeile zeigt sonst weiter auf ein Verzeichnis, das der Agent gerade entfernt hat.',
        );
    }

    /** Und eine gebrauchte Zeile bleibt auch dann stehen, wenn jemand sie nennt. */
    public function test_forget_keeps_a_row_that_is_still_used(): void
    {
        $lebend = Subscription::factory()->create(['name' => 'lebt.invalid']);

        $zeile = Certificate::factory()->covering(['lebt.invalid'])->create([
            'subscription_id' => $lebend->id,
            'storage_name' => 'gebraucht.invalid',
        ]);
        Domain::factory()->main()->create([
            'subscription_id' => $lebend->id,
            'name' => 'lebt.invalid',
        ]);

        $this->assertSame(0, app(CertificatePrune::class)->forget('gebraucht.invalid'));

        $this->assertNotNull($this->unrestricted(fn (): ?Certificate => Certificate::query()->find($zeile->id)));
    }

    /** Und der Ablageort der Oberfläche ist ebenfalls gesprochen. */
    public function test_the_storage_name_of_the_panel_certificate_is_kept(): void
    {
        Certificate::factory()->create([
            'subscription_id' => null,
            'storage_name' => 'panel.invalid',
        ]);

        $this->orphan('panel.invalid');

        $plan = app(CertificatePrune::class)->plan();

        $this->assertSame([], $plan['removable'], 'Der Ablageort der Oberfläche darf nie entfernt werden.');
        $this->assertSame(['panel.invalid'], $plan['shared']);
        $this->assertSame(0, $plan['abandoned'], 'Das Zertifikat der Oberfläche ist keine Zeile ohne Domain.');
    }
}
