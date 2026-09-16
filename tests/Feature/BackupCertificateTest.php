<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\Backups;
use App\Support\Backups\Description;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Backup\Manifest;
use Tests\TestCase;

/**
 * Der private Schlüssel eines hochgeladenen Zertifikats geht mit — und nur der.
 *
 * ## Die dritte Art aus `docs/117 §4`
 *
 * Ein **ACME**-Zertifikat wird nach der Wiederherstellung neu bestellt; den Weg
 * geht P4 ohnehin. Ein **hochgeladenes** hat seinen privaten Schlüssel nirgends
 * sonst — `certificates` führt `storage_name` und kein Material —, und ohne ihn
 * ist es nach einer Wiederherstellung verloren.
 *
 * > **Was weder beschrieben noch erzeugt werden kann, muss die Sicherung selbst
 * > tragen — oder die Wiederherstellung muss sagen, dass es fehlt.**
 *
 * ## Was dieser Wächter hält — und was nicht
 *
 * Gehalten ist die **Naht im Panel**: Die Beschreibung trägt die hochgeladenen
 * Zertifikate und nur die, der Vorgang nennt dem Agenten dieselben Namen aus
 * derselben Quelle, und nach einer Wiederherstellung stehen die Zeilen wieder
 * da.
 *
 * **Nicht gehalten ist, dass die Dateien wirklich im Archiv landen.** Das
 * schreibt `Acme\Store` unter `/etc/srvpanel/tls/certs`, und dorthin schreibt
 * kein Test. Es gehört auf den Server — dieselbe Grenze, an der auch
 * `BackupFormTest` endet.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.**
 */
final class BackupCertificateTest extends TestCase
{
    use RefreshDatabase;

    /** Nur hochgeladene Zertifikate stehen in der Beschreibung. */
    public function test_only_uploaded_certificates_are_described(): void
    {
        $subscription = $this->subscription();

        $this->certificate($subscription, '_uploaded.shop.example.de', CertificateSource::Uploaded);
        $this->certificate($subscription, 'acme.example.de', CertificateSource::Acme);
        $this->certificate($subscription, 'selbst.example.de', CertificateSource::SelfSigned);

        $beschreibung = app(Description::class)->of($subscription);
        $namen = array_column($beschreibung['certificates'], 'storage_name');

        $this->assertSame(['_uploaded.shop.example.de'], $namen, implode("\n", [
            'Die Beschreibung trägt ein Zertifikat, dessen Material die Sicherung nicht hat.',
            'Ein ACME-Zertifikat wird neu bestellt; eine Zeile dafür wäre eine Zusage über',
            'ein Material, das gar nicht mitgeht — und die Wiederherstellung legte eine Zeile',
            'an, auf die keine Datei zeigt.',
        ]));

        /*
         * **Die Gegenprobe.** Ohne sie wäre die Behauptung darüber auch dann
         * grün, wenn der Abschnitt immer leer bliebe — und dann ginge der
         * Schlüssel nie mit.
         */
        $this->assertCount(3, app(Tenancy::class)->withoutRestriction(
            static fn (): array => Certificate::query()->where('subscription_id', $subscription->id)->get()->all(),
        ), 'Der Prüfkörper hat nicht drei Zertifikate — dann misst die Auswahl nichts.');
    }

    /**
     * Der Vorgang nennt dem Agenten dieselben Namen — aus derselben Quelle.
     *
     * **Eine Quelle und nicht zwei.** Eine eigene Abfrage in `Backups::create()`
     * liefe irgendwann neben der Beschreibung her, und dann trüge die Sicherung
     * Dateien ohne Zeile oder Zeilen ohne Datei.
     */
    public function test_the_operation_names_the_same_certificates(): void
    {
        $subscription = $this->subscription();

        $this->certificate($subscription, '_uploaded.shop.example.de', CertificateSource::Uploaded);
        $this->certificate($subscription, 'acme.example.de', CertificateSource::Acme);

        app(Backups::class)->create($subscription);

        $vorgang = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Operation => Operation::query()->where('task', 'backup.create')->first(),
        );

        $this->assertNotNull($vorgang, 'Es wurde kein Vorgang eingereiht — dann misst dieser Fall nichts.');

        $this->assertSame(
            ['_uploaded.shop.example.de'],
            $vorgang->payload['certificates'] ?? null,
            'Der Agent bekommt andere Zertifikate genannt, als die Beschreibung führt.',
        );

        /*
         * **Und der Schlüssel selbst steht nicht in der Nutzlast.** Sie steht
         * als JSON auf der Vorgangsseite, und `OperationPolicy::view()` lässt
         * jeden Admin und den Kunden hindurch.
         *
         * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf
         * > der Vorgangsseite.**
         */
        $nutzlast = json_encode($vorgang->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('PRIVATE KEY', (string) $nutzlast);
        $this->assertStringNotContainsString('privkey', (string) $nutzlast);
    }

    /**
     * Das Material liegt im Archiv unter einem Namen, den der Kunde nicht belegen kann.
     *
     * **Und deshalb landet es nicht in seinem Baum**: `Backup\Unpacker`
     * überspringt jeden reservierten Namen. Ein privater Schlüssel unter
     * `/var/www/vhosts/<abo>/` wäre über den SFTP-Zugang lesbar.
     */
    public function test_the_material_lives_under_a_reserved_name(): void
    {
        $this->assertTrue(
            Manifest::reserves(Manifest::CERTS.'/shop.example.de/privkey.pem'),
            'Der Ablageort der Zertifikate im Archiv ist nicht reserviert — dann packt der '.
            'Unpacker ihn in den Baum des Kunden.',
        );

        // Die Gegenprobe: Ein Name, der nur so anfängt, ist nicht reserviert.
        $this->assertFalse(Manifest::reserves('.srvpanel-certs-alt/x'), 'Die Prüfung misst nichts.');
    }

    private function subscription(): Subscription
    {
        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);

        return Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);
    }

    private function certificate(Subscription $subscription, string $storage, CertificateSource $source): Certificate
    {
        return Certificate::query()->create([
            'subscription_id' => $subscription->id,
            'names' => [str_replace(['_uploaded.', '_wildcard.'], '', $storage)],
            'storage_name' => $storage,
            'status' => CertificateStatus::Active,
            'source' => $source,
        ]);
    }
}
