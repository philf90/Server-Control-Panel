<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Der Vertragspartner.
 *
 * Trägt keine Mandantenklammer: Kunden sind das, woraus die Klammer erst
 * entsteht. Wer welche Kunden sehen darf, entscheidet die Policy — hier gibt
 * es nichts zu filtern, weil ein Kunde nicht zu einem Abonnement gehört,
 * sondern umgekehrt.
 *
 * @property int $id
 * @property int|null $parent_customer_id
 * @property string $number
 * @property string|null $company
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $street
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property CustomerStatus $status
 * @property string|null $notes
 * @property-read Customer|null $parent
 * @property-read Collection<int, Customer> $children
 * @property-read Collection<int, Account> $accounts
 * @property-read Collection<int, Subscription> $subscriptions
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'parent_customer_id', 'number', 'company', 'first_name', 'last_name',
        'email', 'phone', 'street', 'postal_code', 'city', 'country', 'status', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_customer_id');
    }

    /** @return HasMany<Customer, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_customer_id');
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Dieser Kunde und alle, die unter ihm hängen.
     *
     * In der 1.0 ist das Ergebnis immer einelementig, weil `parent_customer_id`
     * leer bleibt. Die Methode existiert trotzdem schon und wird auch benutzt
     * — damit die Rechteprüfung von Anfang an über eine Kette läuft. Wenn die
     * Reseller-Ebene kommt, ändert sich hier eine Methode und sonst nichts.
     *
     * Die Tiefe ist begrenzt, weil eine Kette, die sich im Kreis schließt,
     * sonst den Prozess aufessen würde — und ein Kreis ist in einer
     * Elternbeziehung nicht durch ein Schema ausgeschlossen.
     *
     * @return list<int>
     */
    public function descendantIdsIncludingSelf(int $maxDepth = 8): array
    {
        $ids = [(int) $this->id];
        $frontier = [(int) $this->id];

        for ($depth = 0; $depth < $maxDepth && $frontier !== []; $depth++) {
            /** @var list<int> $children */
            $children = self::query()
                ->whereIn('parent_customer_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(intval(...))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }

    public function displayName(): string
    {
        return $this->company !== null && $this->company !== ''
            ? $this->company
            : trim($this->first_name.' '.$this->last_name);
    }
}
