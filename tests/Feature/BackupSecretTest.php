<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Domain;
use App\Models\SshKey;
use App\Models\Subscription;
use App\Support\Backups\Description;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kein Geheimnis reist als Argument eines Vorgangs (`docs/117 §7`).
 *
 * ## Warum das hier scharf ist und nicht bloss ordentlich
 *
 * Die Beschreibung einer Sicherung geht als `payload` an `backup.create`, und
 * **`Operations/Show.vue` rendert `payload` als JSON**. `OperationPolicy::view()`
 * lässt jeden Admin und den Kunden des Abonnements hindurch.
 *
 * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
 * > Vorgangsseite.**
 *
 * `DnsCredentialStore` nennt genau das als Grund, gar keinen Vorgang
 * einzureihen (`docs/116` M7) — die Beschreibung darf einen, weil nichts
 * Geheimes darin steht. Dieser Wächter hält, dass das so bleibt.
 *
 * ## Eine Positivliste und keine Suche nach verdächtigen Wörtern
 *
 * Gemessen am 16. September 2026 über die sechs Quelltabellen: Nur **eine**
 * Spalte trifft ein Muster wie `pass|secret|token|key` — `ssh_keys.public_key`,
 * und die ist öffentlich und gehört hinein. Ein Wächter, der nach solchen
 * Wörtern sucht, wäre heute grün und bliebe es auch, wenn jemand ein Feld
 * `owner_note` mit einem Passwort darin einführte.
 *
 * > **Eine Positivliste und keine Verneinung.** „Alles ausser was nach
 * > Geheimnis klingt" ist die Regel von gestern — das nächste Geheimnis heisst
 * > anders.
 *
 * Deshalb steht hier jeder Schlüssel, den die Beschreibung tragen darf. Ein
 * neues Feld macht diesen Wächter rot, und dann entscheidet jemand, ob es
 * hinein darf — dieselbe Bauform wie `SourceKeyFilterTest` seit A1.
 *
 * ## Und die zweite Richtung
 *
 * Ein Schlüssel, der in der Liste steht und den die Beschreibung nicht mehr
 * trägt, gehört entfernt. Sonst wächst die Liste und sagt immer weniger.
 */
final class BackupSecretTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Was die Beschreibung auf oberster Ebene führt.
     *
     * @var list<string>
     */
    private const SECTIONS = [
        'subscription', 'domains', 'databases', 'db_users', 'cron', 'ssh_keys', 'certificates',
    ];

    /**
     * Und was jeder Abschnitt je Eintrag führen darf.
     *
     * @var array<string, list<string>>
     */
    private const KEYS = [
        'subscription' => ['name', 'plan', 'status'],

        // `parent` ist der **Name** der Domain, unter der eine Subdomain
        // hängt, und keine Kennung: Ohne ihn lässt sich eine Subdomain nicht
        // wiederherstellen (`docs/117 §15`), mit einer Kennung liefe sie auf
        // einem anderen Server ins Leere.
        'domains' => [
            'name', 'parent', 'type', 'document_root', 'php_version', 'php_settings',
            'nginx_directives', 'redirect_target', 'redirect_kind',
        ],

        'databases' => ['name', 'label', 'engine', 'charset', 'collation'],

        // **Ohne Passwort**, und das ist kein Vergessen: Dieses Panel hält
        // keines (`docs/117 §4`). Nach einer Wiederherstellung bekommt jeder
        // Zugang ein neues, und die Seite sagt es.
        'db_users' => ['name', 'label', 'host', 'engine', 'databases', 'networks'],

        'cron' => [
            'label', 'command', 'minute', 'hour', 'day_of_month', 'month',
            'day_of_week', 'active',
        ],

        // `public_key` ist öffentlich — er liegt ohnehin in der
        // `authorized_keys` des Kunden. Der private hat dieses Panel nie
        // gesehen (P6 Schritt 8).
        'ssh_keys' => ['label', 'type', 'bits', 'fingerprint', 'public_key'],

        /*
         * **Die hochgeladenen Zertifikate — und hier steht das Geheimnis
         * ausdrücklich *nicht*.**
         *
         * Was hier steht, ist die **Zeile**: Name der Ablage, wofür das
         * Zertifikat gilt, wer es ausgestellt hat, wie lange es gilt. Das
         * Material selbst — `fullchain.pem` und `privkey.pem` — liegt als
         * **Datei** unter `Manifest::CERTS` im Archiv und geht nicht durch die
         * Beschreibung.
         *
         * Der Unterschied ist nicht kosmetisch: Die Beschreibung steht als
         * `.srvpanel-manifest.json` im Klartext und wird von der
         * Wiederherstellung gelesen, bevor irgendetwas entpackt ist —
         * `Restore::manifest()` liest sie **im Panel**, also unter php-fpm.
         * Ein privater Schlüssel darin wäre ein Geheimnis in einem Wert, den
         * eine Seite anzeigt.
         *
         * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf
         * > der Vorgangsseite.** (`docs/116` M4)
         *
         * `storage_name` ist dabei kein Pfad, sondern der Name, aus dem der
         * Agent ihn baut — dieselbe Trennung wie überall sonst.
         */
        'certificates' => ['storage_name', 'names', 'issuer', 'serial', 'not_before', 'not_after'],
    ];

    /** Ein Abonnement mit je einem Eintrag in jedem Abschnitt. */
    private function fullSubscription(): Subscription
    {
        $subscription = Subscription::factory()->create(['name' => 'shop']);

        Domain::factory()->create(['subscription_id' => $subscription->id]);
        $database = Database::factory()->create(['subscription_id' => $subscription->id]);
        DbUser::factory()->create(['subscription_id' => $subscription->id]);
        CronJob::factory()->create(['subscription_id' => $subscription->id]);
        // **Ohne Factory, weil `SshKey` keine hat** — und eine anzulegen wäre
        // für einen Prüfkörper der falsche Griff: Sie gehört zum Modell und
        // nicht zu diesem Test, und `FactoryDefaultTest` verlangt sie nicht
        // (keine Aufzählungsspalte).
        SshKey::query()->create([
            'subscription_id' => $subscription->id,
            'label' => 'Notebook',
            'type' => 'ssh-ed25519',
            'fingerprint' => 'SHA256:'.str_repeat('a', 43),
            'bits' => 256,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('B', 20).' notebook',
        ]);

        /*
         * **Ein hochgeladenes Zertifikat, und ausdrücklich auch ein ACME.**
         * Ohne das zweite bliebe unbelegt, dass der Abschnitt nur hochgeladene
         * führt — und eine Liste, die alles nimmt, sähe hier genauso aus.
         */
        Certificate::query()->create([
            'subscription_id' => $subscription->id,
            'names' => ['shop.example.de'],
            'storage_name' => '_uploaded.shop.example.de',
            'status' => CertificateStatus::Active,
            'source' => CertificateSource::Uploaded,
            'issuer' => 'Beispiel CA',
            'serial' => '01',
        ]);

        Certificate::query()->create([
            'subscription_id' => $subscription->id,
            'names' => ['acme.example.de'],
            'storage_name' => 'acme.example.de',
            'status' => CertificateStatus::Active,
            'source' => CertificateSource::Acme,
        ]);

        // Ohne diese Zeile prüfte der Fall darunter eine leere Beschreibung.
        $this->assertNotNull($database->id);

        return $subscription;
    }

    /**
     * Jeder Schlüssel der Beschreibung steht in der Liste.
     *
     * **Gemessen an der Wirkung**: Die Beschreibung wird für ein echtes
     * Abonnement gebaut, nicht am Quelltext abgelesen.
     */
    public function test_the_description_carries_only_declared_keys(): void
    {
        $description = app(Description::class)->of($this->fullSubscription());

        $this->assertSame(
            self::SECTIONS,
            array_keys($description),
            'Die Beschreibung hat einen Abschnitt bekommen oder verloren — dann gehört entschieden, was er trägt.',
        );

        $fremd = [];

        foreach (self::KEYS as $abschnitt => $erlaubt) {
            $inhalt = $description[$abschnitt] ?? [];

            // Der Abschnitt `subscription` ist ein Eintrag, die übrigen sind
            // Listen. Beide werden gleich geprüft, indem der eine als Liste
            // von einem gelesen wird.
            $eintraege = $abschnitt === 'subscription' ? [$inhalt] : $inhalt;

            foreach ($eintraege as $eintrag) {
                foreach (array_keys((array) $eintrag) as $schluessel) {
                    if (! in_array((string) $schluessel, $erlaubt, true)) {
                        $fremd[] = sprintf('%s.%s', $abschnitt, $schluessel);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($fremd)), sprintf(
            "Die Beschreibung trägt Felder, über die niemand entschieden hat:\n  %s\n\n".
            'Sie geht als `payload` an `backup.create`, und `Operations/Show.vue` rendert `payload` als JSON — '.
            'jeder Admin und der Kunde des Abonnements sehen ihn. Entweder gehört das Feld in KEYS, oder es '.
            'gehört nicht in die Beschreibung.',
            implode("\n  ", array_unique($fremd)),
        ));
    }

    /**
     * **Die Gegenrichtung.** Ein Schlüssel, den es nicht mehr gibt, fällt raus.
     *
     * Ohne sie wüchse die Liste mit jedem Umbau und sagte immer weniger — bis
     * sie alles erlaubt und nichts mehr hält.
     */
    public function test_the_list_does_not_outlive_the_description(): void
    {
        $description = app(Description::class)->of($this->fullSubscription());

        foreach (self::KEYS as $abschnitt => $erlaubt) {
            $inhalt = $description[$abschnitt] ?? [];
            $eintraege = $abschnitt === 'subscription' ? [$inhalt] : $inhalt;

            $vorhanden = [];

            foreach ($eintraege as $eintrag) {
                foreach (array_keys((array) $eintrag) as $schluessel) {
                    $vorhanden[] = (string) $schluessel;
                }
            }

            foreach ($erlaubt as $schluessel) {
                $this->assertContains($schluessel, $vorhanden, sprintf(
                    'KEYS führt %s.%s, und die Beschreibung trägt es nicht mehr. Der Eintrag gehört entfernt.',
                    $abschnitt,
                    $schluessel,
                ));
            }
        }
    }

    /**
     * Und die Beschreibung trägt **keine Kennungen dieses Panels**.
     *
     * Eine Wiederherstellung legt neue Zeilen an; eine alte `id` darin wäre
     * eine Einladung, sie zu übernehmen — und nach Form A (`docs/117 §3`) ist
     * genau das falsch. Sie führte ausserdem auf einem anderen Server ins
     * Leere.
     *
     * **Geprüft in der Tiefe**, weil eine Kennung an jeder Stelle einsickern
     * kann: Ein `->toArray()` auf ein Modell statt einer ausgeschriebenen
     * Abbildung bringt sie alle auf einmal mit.
     */
    public function test_the_description_carries_no_identifiers(): void
    {
        $description = app(Description::class)->of($this->fullSubscription());

        $gefunden = [];
        $this->collectKeys($description, '', $gefunden);

        $kennungen = array_values(array_filter(
            $gefunden,
            static fn (string $pfad): bool => (bool) preg_match('/(^|\.)(id|[a-z_]+_id)$/', $pfad),
        ));

        $this->assertSame([], $kennungen, sprintf(
            "Die Beschreibung trägt Kennungen dieses Panels:\n  %s\n\n".
            'Eine Wiederherstellung legt neue Zeilen an — eine alte Kennung darin führt ins Leere '.
            'oder verleitet dazu, sie zu übernehmen.',
            implode("\n  ", $kennungen),
        ));

        // Die Untergrenze: Ohne Schlüssel im Baum fände der Filter nichts, und
        // die leere Liste oben bedeutete nichts.
        $this->assertGreaterThan(20, count($gefunden), 'Es werden kaum Schlüssel gelesen — dann prüft dieser Fall nichts.');
    }

    /**
     * Jeden Schlüsselpfad des Baums einsammeln.
     *
     * @param  mixed  $wert
     * @param  list<string>  $gefunden
     */
    private function collectKeys($wert, string $pfad, array &$gefunden): void
    {
        if (! is_array($wert)) {
            return;
        }

        foreach ($wert as $schluessel => $inhalt) {
            $unter = is_int($schluessel) ? $pfad : ($pfad === '' ? (string) $schluessel : $pfad.'.'.$schluessel);

            if (is_string($schluessel)) {
                $gefunden[] = $unter;
            }

            $this->collectKeys($inhalt, $unter, $gefunden);
        }
    }
}
