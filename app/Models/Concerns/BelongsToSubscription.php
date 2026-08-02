<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Macht ein Modell mandantengebunden.
 *
 * Jedes Modell mit diesem Trait bekommt einen globalen Filter über
 * `subscription_id`. Was er tut, hängt am Zustand von {@see Tenancy}:
 *
 * - unbeschränkt (Admin) — kein Filter
 * - eingeschränkt — `where subscription_id in (…)`
 * - Grundzustand — `where 0 = 1`, also nichts
 *
 * Der dritte Fall ist der wichtige. Eine Abfrage ohne gesetzten Mandanten
 * liefert eine leere Menge statt aller Datensätze; ein vergessener Aufruf
 * fällt damit sofort auf und nicht erst beim zweiten Kunden.
 *
 * **Warum `whereRaw('0 = 1')` und nicht `whereIn(…, [])`.** Das Ergebnis ist
 * dasselbe, aber die erzeugte Abfrage ist lesbar: Wer im Protokoll der
 * Datenbank `0 = 1` sieht, weiß sofort, dass die Klammer zugeschnappt ist.
 * Ein leeres `IN ()` sieht aus wie ein Versehen.
 */
trait BelongsToSubscription
{
    public static function bootBelongsToSubscription(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $tenancy = app(Tenancy::class);

                if ($tenancy->unrestricted()) {
                    return;
                }

                $ids = $tenancy->subscriptionIds();

                if ($ids === []) {
                    $builder->whereRaw('0 = 1');

                    return;
                }

                $builder->whereIn($model->qualifyColumn('subscription_id'), $ids);
            }
        });

        // Neu angelegte Datensätze bekommen den Mandanten von selbst, wenn
        // genau einer aktiv ist. Bei mehreren wäre die Wahl geraten — dann
        // muss der Aufrufer sie treffen.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('subscription_id') !== null) {
                return;
            }

            $ids = app(Tenancy::class)->subscriptionIds();

            if (count($ids) === 1) {
                $model->setAttribute('subscription_id', $ids[0]);
            }
        });
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
