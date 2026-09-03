<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\DomainName;

/**
 * Ein Zertifikat — der Gegenstand, an dem Domains hängen, nicht umgekehrt.
 *
 * **Die Namensliste ist die eine Wahrheit darüber, was gedeckt ist.** Ein
 * Zertifikat trägt einen oder mehrere Namen, und einer davon kann ein
 * Platzhalter sein. Aus dem blossen Verweis einer Domain auf ein Zertifikat
 * folgt deshalb *nicht*, dass es zu ihr passt — die Frage beantwortet
 * {@see self::covers()}, und {@see Domain} lässt eine Zuordnung ohne Deckung
 * gar nicht erst zu.
 *
 * **Das Zertifikat der Oberfläche hat kein Abonnement.** `subscription_id`
 * bleibt dann null. Wer es anlegt, muss das ausdrücklich hinschreiben:
 * {@see BelongsToSubscription} trägt den Mandanten sonst von selbst ein, wenn
 * gerade genau einer aktiv ist — dieselbe Falle wie bei einem Vorgang des
 * Betreibers, der aus einer Kundenanfrage heraus entsteht.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string|null $subscription_name
 * @property list<string>|null $names
 * @property string|null $storage_name
 * @property CertificateStatus $status
 * @property CertificateSource $source
 * @property string|null $issuer
 * @property string|null $serial
 * @property Carbon|null $not_before
 * @property Carbon|null $not_after
 * @property string|null $last_error
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $renew_after
 * @property-read Subscription|null $subscription
 */
class Certificate extends Model
{
    use BelongsToSubscription;

    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    /*
     * `subscription_name` steht mit Absicht **nicht** darin.
     *
     * Es ist eine Abschrift und keine Eingabe — dieselbe Regel wie bei
     * {@see Operation::$fillable}. Geschrieben wird sie ausschliesslich in
     * {@see self::booted()}.
     */

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'names', 'storage_name', 'status', 'source', 'issuer', 'serial',
        'not_before', 'not_after', 'last_error', 'last_attempt_at', 'renew_after',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'status' => CertificateStatus::class,
            'source' => CertificateSource::class,
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'last_attempt_at' => 'datetime',
            'renew_after' => 'datetime',
        ];
    }

    /**
     * Der Name des Abonnements wird beim Anlegen abgeschrieben.
     *
     * **Damit ein verwaistes Zertifikat nicht aussieht wie das der
     * Oberfläche.** Beide tragen nach dem Rückbau `subscription_id = null`;
     * unterschieden werden sie allein an dieser Abschrift. Ohne sie führe
     * `srvpanel tls prune` das Zertifikat der Oberfläche als Waise — und
     * entfernte den privaten Schlüssel, mit dem das Panel antwortet.
     *
     * Am Modell und nicht an den Aufrufern, aus demselben Grund wie bei
     * {@see Operation}: Zertifikate entstehen an mehreren Stellen.
     */
    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            if ($certificate->subscription_id === null || $certificate->subscription_name !== null) {
                return;
            }

            $name = app(Tenancy::class)->withoutRestriction(
                static fn (): mixed => Subscription::query()
                    ->whereKey($certificate->subscription_id)
                    ->value('name'),
            );

            $certificate->subscription_name = is_string($name) ? $name : null;
        });
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Gehört dieses Zertifikat der Oberfläche und keinem Kunden?
     *
     * **Die Abschrift gehört zur Frage, seit es Waisen gibt.** Bis August 2026
     * reichte `subscription_id === null`; seit docs/35 ein zurückgebautes
     * Abonnement hart gelöscht wird, trägt auch sein Zertifikat diese Null.
     * Ohne den zweiten Teil hielte jede Aufräumfunktion das Zertifikat der
     * Oberfläche für einen Rest — und umgekehrt.
     */
    public function forPanel(): bool
    {
        return $this->subscription_id === null && $this->subscription_name === null;
    }

    /** Ein Zertifikat, dessen Abonnement zurückgebaut wurde — die Datei liegt noch. */
    public function orphaned(): bool
    {
        return $this->subscription_id === null && $this->subscription_name !== null;
    }

    /**
     * Deckt dieses Zertifikat diesen Namen?
     *
     * **Ein Platzhalter deckt genau eine Beschriftung**, und das ist die Regel,
     * an der man sich verrechnet. `*.example.de` gilt für `www.example.de` —
     * aber weder für `example.de` selbst noch für `a.b.example.de`. Wer das
     * falsch annimmt, bestellt ein Zertifikat, das im Browser eine
     * Namenswarnung erzeugt, und sieht den Fehler erst dort: Die Seite lädt ja,
     * nur eben mit einer Warnung, die der Betreiber wegklickt und der Besucher
     * nicht.
     *
     * Der abschliessende Punkt eines vollqualifizierten Namens fällt weg, und
     * verglichen wird kleingeschrieben — beides, weil ein Name aus einem
     * Zertifikat kommt und nicht durch {@see DomainName}
     * gegangen sein muss.
     */
    public function covers(string $name): bool
    {
        return self::nameCovers($this->coveredNames(), $name);
    }

    /**
     * Dieselbe Frage an Namen, die nicht aus dieser Zeile kommen.
     *
     * **Die Bestandsdiagnose fragt die Datei und nicht die Zeile** (A10
     * Schritt 5, `docs/98 §3 E`): Was ein Zertifikat wirklich deckt, steht in
     * seinem `subjectAltName`, und den liest `acme.certificate.info` von der
     * Platte. Die Spalte `names` daneben ist eine Abschrift vom Tag der
     * Ausstellung; wer sie fragte, vergliche die Datenbank mit sich selbst.
     *
     * Die Regel bleibt trotzdem eine. Ein zweiter Platzhaltervergleich in
     * `app/Support/Diagnose` wäre die Fassung, die veraltet — und er verrechnete
     * sich an derselben Stelle wie jeder andere.
     *
     * @param  list<string>  $covered  die Namen, die das Zertifikat führt
     */
    public static function nameCovers(array $covered, string $name): bool
    {
        $wanted = strtolower(rtrim(trim($name), '.'));

        if ($wanted === '') {
            return false;
        }

        foreach ($covered as $entry) {
            // Auch hier normalisiert, obwohl {@see self::coveredNames()} es
            // schon tut: Der zweite Aufrufer bekommt seine Namen aus einem
            // Zertifikat auf der Platte, und die ist durch nichts gegangen.
            $entry = strtolower(rtrim(trim($entry), '.'));

            if ($entry === $wanted) {
                return true;
            }

            if (! str_starts_with($entry, '*.')) {
                continue;
            }

            // `*.example.de` → `.example.de`. Der Punkt bleibt stehen: Ohne ihn
            // deckte der Platzhalter auch `notexample.de`.
            $suffix = substr($entry, 1);

            if (! str_ends_with($wanted, $suffix)) {
                continue;
            }

            $label = substr($wanted, 0, strlen($wanted) - strlen($suffix));

            // Genau eine Beschriftung: nicht leer und ohne weiteren Punkt.
            if ($label !== '' && ! str_contains($label, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deckt es *alle* diese Namen?
     *
     * Für einen Server-Block, der mehrere Namen führt — Domain und ihre
     * Aliasse stehen zusammen in einem `server_name`, und ein Zertifikat, das
     * nur den ersten deckt, warnt bei jedem anderen.
     *
     * **Eine leere Liste ist nicht gedeckt.** Die Alternative wäre, sie als
     * „nichts zu decken, also erfüllt" zu lesen — und damit hätte eine Domain
     * ohne Namen jedes Zertifikat bestanden. Ein Sonderfall, der still
     * durchwinkt, ist schlimmer als einer, der abweist.
     *
     * @param  list<string>  $names
     */
    public function coversAll(array $names): bool
    {
        if ($names === []) {
            return false;
        }

        foreach ($names as $name) {
            if (! $this->covers($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die Namensliste, sauber.
     *
     * Sie kommt als JSON aus der Datenbank und ist damit alles, was jemand
     * hineingeschrieben hat. Was hier herauskommt, sind Zeichenketten in
     * Kleinschreibung — sonst entschiede die Schreibweise über die Deckung.
     *
     * @return list<string>
     */
    public function coveredNames(): array
    {
        $names = [];

        foreach ($this->names ?? [] as $name) {
            if (! is_string($name)) {
                continue;
            }

            $clean = strtolower(rtrim(trim($name), '.'));

            if ($clean !== '') {
                $names[] = $clean;
            }
        }

        return $names;
    }

    /**
     * Deckt es eine ganze Zone?
     *
     * **Der Unterschied, wegen dem jemand überhaupt wählt.** In der Auswahl auf
     * der Domainseite steht sonst zweimal „Let’s Encrypt" mit zwei Daten — und
     * ob ein Eintrag eine Domain deckt oder jede Unterdomain der Zone, ist
     * genau die Frage, die man dort beantwortet. Am 7. August 2026 beim
     * Abnahmelauf aufgefallen: Der Betreiber musste den Platzhalter am Datum
     * erraten.
     */
    public function isWildcard(): bool
    {
        foreach ($this->coveredNames() as $name) {
            if (str_starts_with($name, '*.')) {
                return true;
            }
        }

        return false;
    }
}
