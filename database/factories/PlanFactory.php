<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Support\Plans\Feature;
use App\Support\Plans\Quotas;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Die Werte kommen aus dem Katalog und stehen nicht mehr hier.
     *
     * Sie standen hier, und das war der Anfang eines bekannten Musters: neun
     * Schlüssel als Literale, die niemand mit denen im Formular abglich. Ein
     * Test gegen eine Factory mit einem eigenen Satz Schlüssel prüft die
     * Factory und nicht die Anwendung.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Paket '.fake()->unique()->word(),
            'description' => null,
            'quotas' => Quotas::defaults(),
            'features' => Quotas::featureDefaults(),
            'is_default' => false,
        ];
    }

    /** Ein Plan ohne jede Freigabe — für die Prüfung der Freigabeschranke. */
    public function withoutFeatures(): self
    {
        return $this->state(fn (): array => [
            'features' => array_fill_keys(Feature::keys(), false),
        ]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
