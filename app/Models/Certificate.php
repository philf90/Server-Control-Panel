<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Concerns\BelongsToSubscription;
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
 * @property list<string>|null $names
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

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'names', 'status', 'source', 'issuer', 'serial',
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

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** Gehört dieses Zertifikat der Oberfläche und keinem Kunden? */
    public function forPanel(): bool
    {
        return $this->subscription_id === null;
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
        $wanted = strtolower(rtrim(trim($name), '.'));

        if ($wanted === '') {
            return false;
        }

        foreach ($this->coveredNames() as $covered) {
            if ($covered === $wanted) {
                return true;
            }

            if (! str_starts_with($covered, '*.')) {
                continue;
            }

            // `*.example.de` → `.example.de`. Der Punkt bleibt stehen: Ohne ihn
            // deckte der Platzhalter auch `notexample.de`.
            $suffix = substr($covered, 1);

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
}
