<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DailyMetric;
use App\Models\Domain;
use App\Models\DomainMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainMetric>
 */
class DomainMetricFactory extends Factory
{
    protected $model = DomainMetric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $domain = Domain::factory();

        return [
            'domain_id' => $domain,

            /*
             * **Der Mandant wird mitgeführt und nicht aus der Domain
             * abgeleitet.** Die Spalte gibt es, weil
             * {@see \App\Models\Concerns\BelongsToSubscription} über sie
             * klammert; eine Factory, die sie leer liesse, baute Zeilen, die
             * niemand mehr sieht — derselbe Grund wie bei
             * {@see CronRunFactory}.
             */
            'subscription_id' => fn (array $attributes): int => (int) Domain::query()
                ->withoutGlobalScopes()
                ->findOrFail($attributes['domain_id'])
                ->subscription_id,

            'day' => '2026-09-20',
            'metric' => DailyMetric::Requests,
            'value' => 42,
        ];
    }
}
