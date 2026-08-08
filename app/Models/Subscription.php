<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainType;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Die tragende Einheit — und der Anker der Mandantentrennung.
 *
 * Genau ein Systembenutzer, genau eine Hauptdomain, ein Plan, ein Satz
 * Kontingente, ein Zustand. Alles Weitere hängt hier dran; wer ein Abonnement
 * sehen darf, darf alles darunter sehen, und wer es nicht darf, kommt an
 * nichts darunter.
 *
 * Dieses Modell trägt **nicht** die Klammer aus {@see BelongsToSubscription} —
 * die filtert auf `subscription_id`, und das wäre hier eine Klammer um sich
 * selbst. Es trägt statt dessen dieselbe Klammer auf den eigenen Schlüssel,
 * siehe {@see self::booted()}.
 *
 * Hier stand „die Sichtbarkeit von Abonnements regelt die Policy". Das war zu
 * wenig, und es fiel erst auf, als es die erste Liste gab: Eine Policy
 * entscheidet über *ein* Objekt. Eine Abfrage über alle — `Subscription::query()`
 * in einem Controller — fragt sie nie, und ein Kunde sah damit jedes
 * Abonnement des Servers.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $plan_id
 * @property string $name
 * @property string|null $system_user
 * @property string|null $main_domain
 * @property SubscriptionStatus $status
 * @property array<string, mixed>|null $quota_overrides
 * @property int|null $disk_used_mb
 * @property Carbon|null $disk_usage_measured_at
 * @property Carbon|null $suspended_at
 * @property bool $suspended_with_customer
 * @property-read Customer|null $customer
 * @property-read Plan|null $plan
 * @property-read Collection<int, Account> $additionalAccounts
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, Database> $databases
 * @property-read Domain|null $mainDomain
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /*
     * **Hier stand `use SoftDeletes` — bis August 2026, und der Grund dafür
     * war richtig.** Ein zurückgebautes Abonnement blieb als Zeile liegen,
     * damit sein Systembenutzer verbraucht blieb: `userdel` gibt die UID frei,
     * das nächste `useradd` vergibt sie wieder, und dann erbte ein neuer Kunde
     * alles, was auf dem Dateisystem noch der alten UID gehört.
     *
     * Das Mittel war zu grob. Gelesen wurde der Grabstein im ganzen Panel von
     * genau einer Abfrage; 121 Zeilen auf dem Zielserver existierten für ein
     * einzelnes `MAX()`. Dabei hielten sie einen Fremdschlüssel auf `plans`
     * fest — das war der 500er vom 7. August 2026 — und zwangen jede Zählung,
     * zwei Filter abzuziehen, die die Datenbank nicht kennt.
     *
     * Die Reservierung steht seit docs/35 in {@see SystemUser}. Ein Abonnement
     * enthält jetzt nur noch, was es gibt.
     *
     * **Kunden behalten ihre weiche Löschung**, und das ist keine
     * Inkonsequenz: Ihr Grabstein wird von zwei Stellen gelesen, an ihm hängen
     * ihre Konten, und ihre Nummer steht in Rechnungen. Der
     * Abonnementgrabstein trug eine Zahl.
     */

    /** @var list<string> */
    protected $fillable = [
        'customer_id', 'plan_id', 'name', 'system_user',
        'status', 'quota_overrides',
    ];

    /*
     * `main_domain` steht seit P3 nicht mehr darin.
     *
     * Es ist die Abschrift des Namens der Hauptdomain und wird von
     * {@see Domain} nachgezogen — an dem einen Ereignis, nach dem sie falsch
     * sein könnte. Bliebe die Spalte füllbar, gäbe es einen zweiten Weg, sie
     * zu setzen, und der käme ohne Domain aus: ein Abonnement mit einer
     * Hauptdomain, die es im Bestand nicht gibt, und nichts, das den
     * Widerspruch meldet. Bis P3 wurde sie von nichts beschrieben — die
     * Kundenübersicht zeigte deshalb immer „noch keine Domain".
     */

    /*
     * `disk_used_mb` und `disk_usage_measured_at` stehen mit Absicht **nicht**
     * darin: Sie sind gemessen und nicht eingegeben. Ein Formular, das sie
     * setzen könnte, wäre ein Formular, mit dem sich der Verbrauch
     * herbeischreiben lässt. Geschrieben werden sie von
     * {@see \App\Support\Subscriptions\Usage}, und dort ausdrücklich über
     * `forceFill`.
     */

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'quota_overrides' => 'array',
            'suspended_at' => 'datetime',
            'suspended_with_customer' => 'boolean',
            'disk_usage_measured_at' => 'datetime',
        ];
    }

    /**
     * Die Mandantenklammer auf den eigenen Schlüssel.
     *
     * Wortgleich zu der in {@see BelongsToSubscription}, nur auf `id` statt
     * auf `subscription_id` — samt `whereRaw('0 = 1')` für den Grundzustand
     * „nichts", damit ein vergessener Aufruf eine leere Menge liefert und
     * nicht alle Datensätze.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenancy', static function (Builder $builder): void {
            $tenancy = app(Tenancy::class);

            if ($tenancy->unrestricted()) {
                return;
            }

            $ids = $tenancy->subscriptionIds();

            if ($ids === []) {
                $builder->whereRaw('0 = 1');

                return;
            }

            $builder->whereIn($builder->getModel()->qualifyColumn('id'), $ids);
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Die Datenbanken des Abonnements (P5).
     *
     * Ohne Gegenstück in {@see Database}: Dort steht `subscription()` schon
     * über {@see BelongsToSubscription}. Hier fehlte die Richtung,
     * weil bis zur Messung niemand vom Abonnement aus danach gefragt hat.
     *
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    /**
     * Die eine Hauptdomain (§5.1).
     *
     * Als Beziehung und nicht über `main_domain`: Die Spalte trägt den Namen,
     * dieser Weg trägt die Domain — mit ihrem DocumentRoot, ihrer
     * PHP-Version und ihrem Zustand. Wer nur den Namen braucht, nimmt die
     * Spalte und spart die Abfrage.
     *
     * @return HasOne<Domain, $this>
     */
    public function mainDomain(): HasOne
    {
        return $this->hasOne(Domain::class)->where('type', DomainType::Main->value);
    }

    /** @return BelongsToMany<Account, $this> */
    public function additionalAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)
            ->withPivot(['permissions', 'domain_ids'])
            ->withTimestamps();
    }

    /**
     * Ein Kontingent, mit der Übersteuerung des Abonnements vor dem Plan.
     *
     * `array_key_exists` statt `??`, und das ist der ganze Punkt: Eine
     * Übersteuerung auf `0` oder `null` ist eine Aussage („keine Datenbanken")
     * und darf nicht stillschweigend auf den Planwert zurückfallen.
     */
    public function quota(string $key): mixed
    {
        $overrides = $this->quota_overrides ?? [];

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        // Kein `?->` vor `??`: Der Null-Zusammenführungsoperator hat
        // isset-Semantik und fängt einen fehlenden Plan schon selbst ab.
        return ($this->plan->quotas ?? [])[$key] ?? null;
    }

    /** Weicht dieses Kontingent vom Plan ab? Die Oberfläche markiert das. */
    public function quotaDiffersFromPlan(string $key): bool
    {
        return array_key_exists($key, $this->quota_overrides ?? []);
    }

    /**
     * Der belegte Speicher im Verhältnis zum Kontingent — in Prozent.
     *
     * `null`, wenn eine der beiden Zahlen fehlt: ohne Messung gibt es nichts
     * ins Verhältnis zu setzen, und ohne Grenze (`disk_mb` auf `null`) gibt es
     * kein Verhältnis. Beides ist etwas anderes als „0 %", und die Oberfläche
     * muss den Unterschied zeigen können.
     *
     * Nicht bei 100 abgeschnitten. Eine Quota lässt sich überschreiten — sie
     * wird gesenkt, während Daten liegen, oder ein Prozess schreibt mit
     * root-Rechten daran vorbei. 118 % ist dann die Wahrheit, und ein auf 100
     * gedeckelter Balken wäre genau in dem Moment beruhigend, in dem er es
     * nicht sein darf.
     */
    public function diskUsagePercent(): ?float
    {
        $limit = $this->quota(Quota::DiskMb->value);

        if ($this->disk_used_mb === null || ! is_numeric($limit) || (int) $limit <= 0) {
            return null;
        }

        return round($this->disk_used_mb / (int) $limit * 100, 1);
    }

    /**
     * Der belegte Platz aller Datenbanken dieses Abonnements — in MB.
     *
     * **Gerechnet und nicht abgelegt.** Die Zahl steht als Summe über den
     * Datenbanken; eine zweite, mitgeführte Spalte am Abonnement wäre ein
     * zweiter Wahrheitsort, der auseinandergeht, sobald eine Datenbank entfernt
     * wird, ohne dass jemand nachrechnet. Beide Zahlen sähen dann für sich
     * plausibel aus — und genau solche Abweichungen findet niemand.
     *
     * `null` heisst „noch nie gemessen" und ist etwas anderes als 0 MB: Ein
     * Abonnement ohne Datenbanken hat nichts zu messen, eines mit Datenbanken
     * vor dem ersten Lauf des Zeitgebers hat es noch nicht.
     *
     * **Abgelegt sind Bytes, herausgegeben werden Megabyte** (docs/36 §22.3j).
     * Hier ist das Runden richtig und nicht dieselbe Rundung wie die, die an der
     * einzelnen Datenbank ein Fehler war: Diese Zahl steht neben einem
     * Kontingent, und das Kontingent ist in MB angegeben (`database_mb`,
     * Voreinstellung 2048). Summiert wird **vor** dem Teilen — hundert
     * Datenbanken zu je 300 KB sind 29 MB und nicht hundertmal null.
     */
    public function databaseUsedMb(): ?int
    {
        $databases = $this->databases()->whereNotNull('size_bytes');

        return $databases->exists() ? intdiv((int) $databases->sum('size_bytes'), 1024 * 1024) : null;
    }

    /**
     * Und ins Verhältnis zum Kontingent — dieselben Regeln wie beim Speicher.
     *
     * Auch hier nicht bei 100 abgeschnitten, und hier ist der Grund noch
     * deutlicher: `database_mb` wird **gemessen und nicht erzwungen** (docs/36
     * §9). MariaDB kennt keine Obergrenze je Schema, und `/var/lib/mysql` liegt
     * ausserhalb der Dateisystem-Quota des Systembenutzers. Eine Überschreitung
     * ist hier also kein Randfall, sondern der zu erwartende Verlauf — ein
     * gedeckelter Balken verspräche eine Grenze, die es nicht gibt.
     */
    public function databaseUsagePercent(): ?float
    {
        $limit = $this->quota(Quota::DatabaseMb->value);
        $used = $this->databaseUsedMb();

        if ($used === null || ! is_numeric($limit) || (int) $limit <= 0) {
            return null;
        }

        return round($used / (int) $limit * 100, 1);
    }

    public function feature(string $key): bool
    {
        return (bool) (($this->plan->features ?? [])[$key] ?? false);
    }

    public function usable(): bool
    {
        return $this->status->usable();
    }
}
